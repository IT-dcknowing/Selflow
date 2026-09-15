<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Le relevé des factures reçues, commandé et consulté de l'extérieur.
 *
 * Demandé par le propriétaire du projet le 08/09/2026, en même temps que le
 * passage du relevé à cinq minutes : le planificateur suffit au fonctionnement
 * ordinaire, mais rien ne permettait de déclencher un relevé depuis autre chose
 * qu'un écran de Selflow, ni de savoir de l'extérieur si la chaîne tournait.
 *
 * ## Ce que ces routes ne font pas
 *
 * Elles ne relèvent pas elles-mêmes. Une requête HTTP qui attendrait un
 * navigateur ouvert sur le portail de la DGI tiendrait la connexion des
 * dizaines de secondes, et un client qui renonce ne l'arrêterait pas pour
 * autant. Le lancement est **détaché** : la réponse dit qu'il est parti, pas
 * qu'il a réussi. Ce qui aura été déposé sera rangé par
 * `portail-fne:importer-achats`, et `GET .../achats` le dira.
 *
 * Elles ne créent aucun achat et n'écrivent dans aucune colonne gelée : le
 * relevé est un constat, et il le reste — la règle du lot 16 ne bouge pas parce
 * qu'on l'appelle par HTTP.
 *
 * ## L'accès
 *
 * `hub.token`, comme le reste de `routes/api.php` : ces routes ouvrent un
 * navigateur sur le portail de la DGI avec le mot de passe d'un client, et
 * rendent des données fiscales nominatives. Aucune ne doit être joignable sans
 * jeton.
 */
class PortailFneAchatsApiController extends Controller
{
    /**
     * Une ligne dans le journal du cycle du portail, qui ne casse jamais l'appel.
     *
     * Le canal `portail_fne` range ces traces avec celles du service et de
     * l'import : « qui a lancé ce relevé de 3 h 12 ? » se lit alors d'un seul
     * fichier.
     *
     * **Le `try` n'est pas de la prudence de principe.** Monolog lève quand il
     * ne peut pas ouvrir son fichier, et cela arrive pour de vraies raisons :
     * disque plein, droits changés, ou — sous Windows — un processus détaché qui
     * a hérité du handle du parent et le tient encore. La suite entière l'a
     * montré le 08/09/2026 : neuf appels rendaient 500 alors que le relevé
     * partait très bien. Une trace sert à expliquer une panne, pas à en créer.
     *
     * @param  array<string, mixed>  $contexte
     */
    private function tracer(string $niveau, string $message, array $contexte = []): void
    {
        try {
            Log::channel('portail_fne')->{$niveau}($message, $contexte);
        } catch (\Throwable) {
            // Volontairement muet : signaler ici demanderait d'écrire quelque
            // part, et c'est précisément ce qui vient d'échouer.
        }
    }

    /**
     * Lancer un relevé des factures reçues, en arrière-plan.
     *
     *   POST /api/portail-fne/achats/relever
     *   POST /api/portail-fne/achats/relever?company_id=3
     *   POST /api/portail-fne/achats/relever?login=1864699A
     *
     * Sans entreprise désignée, ce sont **tous** les logins d'`identifiants.json`
     * qui sont relevés — le même passage que celui du planificateur.
     */
    public function relever(Request $request): JsonResponse
    {
        // L'interrupteur d'abord : répondre 503 dit que la chaîne est éteinte,
        // là où un 500 ferait chercher une panne. Les deux réglages comptent —
        // le scraper peut être armé sans que le relevé des achats le soit.
        if (!config('selflow.portail_fne.scraper.actif')
            || !config('selflow.portail_fne.scraper.achats_actif')) {
            $this->tracer('notice', 'api[relever] : refusé 503 — la chaîne est éteinte.', [
                'appelant'     => $request->ip(),
                'actif'        => (bool) config('selflow.portail_fne.scraper.actif'),
                'achats_actif' => (bool) config('selflow.portail_fne.scraper.achats_actif'),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => "Le relevé des factures reçues est éteint sur ce serveur "
                    . '(PORTAIL_FNE_SCRAPER_ACTIF, PORTAIL_FNE_SCRAPER_ACHATS_ACTIF).',
            ], 503);
        }

        $login = $this->loginDemande($request);

        // `null` veut dire « tous les logins » ; une chaîne vide rendue par une
        // entreprise sans NCC ne le veut pas, et lancer un passage complet parce
        // qu'un identifiant manque serait exactement l'inverse de ce qu'on
        // demande.
        if ($login instanceof JsonResponse) {
            return $login;
        }

        $minutes = max(1, (int) config('selflow.portail_fne.scraper.achats_minutes', 5));

        // Le verrou est regardé avant l'appel pour pouvoir le dire. `lancerAchats`
        // rend « faux » aussi bien pour un verrou tenu que pour un lancement
        // raté, et ce ne sont pas les mêmes nouvelles.
        if (Cache::has(ScraperPortailFneService::verrouAchats($login))) {
            $this->tracer('info', 'api[relever] : refusé 429 — un relevé est déjà en route.', [
                'appelant' => $request->ip(),
                'login'    => $login ?: 'tous',
            ]);

            return response()->json([
                'status'   => 'success',
                'lance'    => false,
                'message'  => 'Un relevé est déjà en route ; il ne sera pas doublé.',
                'login'    => $login ?: 'tous',
                'reessayer_dans_minutes' => $minutes,
            ], 429);
        }

        if (!ScraperPortailFneService::lancerAchats($login)) {
            // `lancerAchats()` a déjà écrit la cause exacte dans le même
            // journal, avec les chemins de Node et du script. Cette ligne-ci dit
            // seulement qu'un appel extérieur en est reparti bredouille.
            $this->tracer('error', 'api[relever] : lancement impossible, rendu 500.', [
                'appelant' => $request->ip(),
                'login'    => $login ?: 'tous',
            ]);

            return response()->json([
                'status'  => 'error',
                'lance'   => false,
                'message' => "Le relevé n'a pas pu être lancé. Le détail est dans "
                    . 'storage/logs/portail-fne.log.',
                'login'   => $login ?: 'tous',
            ], 500);
        }

        // 202 et non 200 : le relevé est parti, il n'est pas fini. Le portail
        // met des dizaines de secondes, et le fichier déposé sera rangé au
        // ramassage suivant.
        $this->tracer('info', 'api[relever] : relevé lancé, rendu 202.', [
            'appelant' => $request->ip(),
            'login'    => $login ?: 'tous',
        ]);

        return response()->json([
            'status'  => 'success',
            'lance'   => true,
            'login'   => $login ?: 'tous',
            'message' => 'Relevé lancé en arrière-plan. Le résultat apparaîtra au '
                . 'ramassage suivant (GET /api/portail-fne/achats).',
        ], 202);
    }

    /**
     * Ce que la chaîne a relevé, et quand.
     *
     *   GET /api/portail-fne/achats
     *   GET /api/portail-fne/achats?company_id=3
     *
     * Trois choses, et c'est ce qu'on vient chercher pour savoir si la chaîne
     * tourne : la date du dernier relevé, ce qu'il portait, et le compte des
     * pièces par état de rapprochement.
     */
    public function statut(Request $request): JsonResponse
    {
        $login = $this->loginDemande($request);

        if ($login instanceof JsonResponse) {
            return $login;
        }

        $releves = PortailFneImport::query()
            ->where('type', ImportFacturesRecuesService::TYPE)
            ->when($login, fn ($q) => $q->where('login', $login))
            ->orderByDesc('date_scraping')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (PortailFneImport $i) => [
                'login'             => $i->login,
                'date_scraping'     => $i->date_scraping?->toDateString(),
                'dernier_releve_le' => $i->dernier_releve_le?->toDateString(),
                'releves'           => $i->releves,
                'factures'          => $i->lignes_importees,
                'statut'            => $i->statut,
                'message'           => $i->message,
            ]);

        $factures = PortailFneFactureRecue::query()
            ->when($login, fn ($q) => $q->where('login', $login))
            ->selectRaw('statut_rapprochement, count(*) as nombre')
            ->groupBy('statut_rapprochement')
            ->pluck('nombre', 'statut_rapprochement');

        return response()->json([
            'status' => 'success',
            'data'   => [
                'scraper' => [
                    'actif'         => (bool) config('selflow.portail_fne.scraper.actif'),
                    'achats_actif'  => (bool) config('selflow.portail_fne.scraper.achats_actif'),
                    'pas_minutes'   => (int) config('selflow.portail_fne.scraper.achats_minutes', 5),
                    'releve_en_cours' => Cache::has(ScraperPortailFneService::verrouAchats($login)),
                ],
                'login'    => $login ?: 'tous',
                'releves'  => $releves,
                'factures' => $factures,
            ],
        ]);
    }

    /**
     * Le login du portail visé par la requête, ou `null` pour tous.
     *
     * Trois façons de le désigner, dans cet ordre : `login` en clair,
     * `company_id`, l'en-tête `X-Company-Id` que le Hub pose déjà sur les autres
     * routes. Rend une réponse d'erreur — et non un login — quand l'entreprise
     * demandée n'existe pas ou n'a pas de NCC : relever tout le parc parce qu'un
     * identifiant est faux ouvrirait des sessions que personne n'a demandées.
     *
     * @return string|null|JsonResponse
     */
    private function loginDemande(Request $request): string|null|JsonResponse
    {
        $login = trim((string) $request->input('login', ''));

        if ($login !== '') {
            return $login;
        }

        $entrepriseId = $request->input('company_id') ?? $request->header('X-Company-Id');

        if (!$entrepriseId) {
            return null;
        }

        $entreprise = Entreprise::find($entrepriseId);

        if (!$entreprise) {
            $this->tracer('warning', 'api : entreprise introuvable, rendu 404.', [
                'appelant'   => $request->ip(),
                'company_id' => $entrepriseId,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => "Entreprise {$entrepriseId} introuvable.",
            ], 404);
        }

        // Le login du portail est le NCC. Sans lui, il n'y a rien à relever, et
        // le dire vaut mieux que de partir sur tout le parc.
        if (trim((string) $entreprise->ncc) === '') {
            $this->tracer('warning', 'api : entreprise sans NCC, rendu 422.', [
                'appelant'   => $request->ip(),
                'company_id' => $entreprise->id,
                'entreprise' => $entreprise->nom,
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => "L'entreprise « {$entreprise->nom} » n'a pas de NCC : "
                    . 'aucun compte du portail ne lui correspond.',
            ], 422);
        }

        return trim((string) $entreprise->ncc);
    }
}
