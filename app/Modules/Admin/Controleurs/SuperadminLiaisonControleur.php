<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Services\LiaisonComptaflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Les liaisons Selflow ↔ Comptaflow, vues du superadministrateur.
 *
 * Trois chemins ont disparu d'ici, et c'est le fond du lot :
 *
 *  - **`lierEntreprise()`** liait une entreprise à un dossier Comptaflow en
 *    collant une clé et un identifiant. Rien ne vérifiait que le dossier
 *    appartenait à cette entreprise. La route qui y menait référençait par
 *    ailleurs une méthode `lier` qui n'existait pas : le formulaire tombait
 *    en erreur 500 (Internal Server Error — erreur du serveur) depuis qu'il
 *    avait été renommé, sans que personne s'en aperçoive ;
 *  - **`creerComptaflow()`** demandait au superadministrateur de choisir le
 *    **mot de passe** du compte d'un client, et le transmettait en clair dans
 *    le corps de la requête ;
 *  - **`delierEntreprise()`** effaçait la clé chez Selflow sans rien dire à
 *    Comptaflow, où elle continuait d'ouvrir le dossier. Une clé oubliée chez
 *    l'autre est une clé qui marche.
 *
 * À la place : une file de demandes, que le superadministrateur valide ou
 * refuse. La clé est délivrée par Comptaflow, jamais saisie, jamais affichée.
 */
class SuperadminLiaisonControleur extends Controller
{
    /**
     * Le tableau des liaisons, demandes en attente en tête.
     */
    public function index()
    {
        $entreprises = Entreprise::with(['utilisateurs' => fn ($q) => $q->where('role', 'admin')])
            ->orderBy('nom')
            ->get();

        // Ce qui attend une décision passe devant : c'est la seule chose sur
        // cet écran qui demande une action, et elle se perdait au milieu de
        // toutes les entreprises.
        $demandes = $entreprises->filter(fn (Entreprise $e) => $e->demandeComptaflowEnAttente())->values();

        $comptaflowCompanies = $this->fetchComptaflowEntreprises();

        // Le nom du dossier Comptaflow, par identifiant : une liaison montre
        // à quel dossier elle mène, et le choix « lier » se fait sur des noms.
        $dossiersComptaflow = collect($comptaflowCompanies)
            ->filter(fn ($d) => is_array($d) && isset($d['id']))
            ->mapWithKeys(fn ($d) => [(int) $d['id'] => (string) ($d['nom'] ?? $d['name'] ?? $d['raison_sociale'] ?? ('Dossier #' . $d['id']))])
            ->all();

        // Les exercices de chaque entreprise, et ceux qu'elle a demandés.
        $exercices = LiaisonComptaflowService::exercicesParEntreprise();
        $anneesDemandees = \App\Modules\Admin\Modeles\DemandeExercicesAnterieurs::where('statut', \App\Modules\Admin\Modeles\DemandeExercicesAnterieurs::EN_ATTENTE)
            ->get(['entreprise_id', 'annees_demandees'])
            ->groupBy('entreprise_id')
            ->map(fn ($l) => $l->pluck('annees_demandees')->flatten()->map(fn ($a) => (int) $a)->unique()->values()->all())
            ->all();

        return view('admin::superadmin.liaisons.index', compact(
            'entreprises', 'demandes', 'comptaflowCompanies', 'dossiersComptaflow', 'exercices', 'anneesDemandees'
        ));
    }

    /**
     * Ouvrir le dossier Comptaflow d'une entreprise.
     *
     * Deux chemins, un seul geste : valider une demande que l'entreprise a
     * faite, ou la lier de sa propre initiative — un client qui souscrit
     * Comptaflow par téléphone n'a pas à cliquer dans un écran pour que le
     * superadministrateur puisse lui ouvrir son dossier.
     *
     * Ce que cela n'est pas : l'ancien « Lier manuellement », qui demandait de
     * coller un identifiant et une clé sans que rien ne vérifie que ce dossier
     * appartenait à cette entreprise. La clé continue d'être délivrée par
     * Comptaflow, et personne ne la saisit.
     */
    public function validerDemande(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate([
            'mode'                  => ['nullable', 'in:creer,lier'],
            'comptaflow_company_id' => ['nullable', 'required_if:mode,lier', 'integer', 'min:1'],
            'deverser'              => ['nullable', 'boolean'],
            'annees'                => ['nullable', 'array'],
            'annees.*'              => ['integer', 'between:2000,2100'],
        ], [
            'comptaflow_company_id.required_if' => 'Choisissez le dossier Comptaflow auquel lier l\'entreprise.',
        ]);

        $spontanee = !$entreprise->demandeComptaflowEnAttente();

        $resultat = LiaisonComptaflowService::valider($entreprise, false, [
            'comptaflow_company_id' => ($donnees['mode'] ?? 'creer') === 'lier' ? (int) $donnees['comptaflow_company_id'] : null,
            'deverser'              => (bool) ($donnees['deverser'] ?? false),
            'annees'                => $donnees['annees'] ?? [],
            'par'                   => $request->user()?->id,
        ]);

        if (!$resultat['success']) {
            return back()->with('error', "❌ « {$entreprise->nom} » : " . $resultat['message']);
        }

        \Illuminate\Support\Facades\Cache::forget('comptaflow.dossiers');

        // L'entreprise n'a rien demandé : elle doit lire, en ouvrant ses
        // paramètres, qu'un dossier lui a été ouvert et avec quels accès.
        $mot = ($spontanee ? "Dossier ouvert à l'initiative du support ; l'entreprise le voit dans ses paramètres. " : '')
            . $resultat['message'];

        return back()->with('success', "✅ « {$entreprise->nom} » : " . $mot);
    }

    /**
     * Refuser une demande, avec un motif que l'entreprise lit dans ses
     * paramètres. Un refus muet la laisserait redemander sans fin.
     */
    public function refuserDemande(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $request->validate([
            'motif' => ['required', 'string', 'max:255'],
        ], [
            'motif.required' => 'Dites pourquoi : l\'entreprise ne verra que ce motif.',
        ]);

        LiaisonComptaflowService::refuser($entreprise, $request->input('motif'));

        return back()->with('success', "Demande de « {$entreprise->nom} » refusée.");
    }

    /**
     * Renouveler la clé d'un dossier, sans attendre la rotation mensuelle.
     *
     * Il y a des jours où l'on ne veut pas attendre le premier du mois : un
     * prestataire qui part, un journal de serveur retrouvé sur un poste
     * partagé, un doute. Le renouvellement est alors immédiat.
     *
     * Il ne coupe rien : l'ancienne clé reste en place tant que la nouvelle
     * n'est pas en main, et Comptaflow la tient valable quelques minutes de
     * plus pour les requêtes déjà parties.
     */
    public function renouvelerLaCle(Entreprise $entreprise): RedirectResponse
    {
        $resultat = LiaisonComptaflowService::renouvelerLaCle($entreprise);

        return $resultat['success']
            ? back()->with('success', "🔑 « {$entreprise->nom} » : clé renouvelée. L'ancienne ne vaut plus.")
            : back()->with('error', "❌ « {$entreprise->nom} » : " . $resultat['message']);
    }

    /**
     * Délier : la clé est révoquée des deux côtés.
     */
    public function delierEntreprise(Entreprise $entreprise): RedirectResponse
    {
        $resultat = LiaisonComptaflowService::revoquer($entreprise);

        return redirect()->route('superadmin.liaisons.index')
            ->with('success', "🔌 « {$entreprise->nom} » — " . $resultat['message']);
    }

    /**
     * Vérifier qu'une liaison répond encore.
     *
     * L'ancienne version se contentait d'écrire `comptaflow_last_sync_at = now()`
     * et d'annoncer « Liaison active » : elle datait une vérification qui
     * n'avait pas eu lieu. Elle interroge maintenant Comptaflow.
     */
    public function verifierLiaison(Entreprise $entreprise): RedirectResponse
    {
        if (!$entreprise->liaisonComptaflowActive()) {
            return back()->with('error', "❌ « {$entreprise->nom} » n'est pas liée à Comptaflow.");
        }

        try {
            $reponse = Http::timeout(10)
                ->withHeaders(LiaisonComptaflowService::enTete($entreprise))
                ->post($this->url('/api/external/companies/verify'), [
                    'secret'                => config('selflow.comptaflow_api_secret'),
                    'selflow_company_id'    => $entreprise->id,
                    'comptaflow_company_id' => $entreprise->comptaflow_company_id,
                ]);
        } catch (\Throwable $e) {
            return back()->with('error', "❌ Comptaflow injoignable : " . $e->getMessage());
        }

        if (in_array($reponse->status(), [404, 405], true)) {
            return back()->with('error', "❌ Comptaflow n'expose pas encore /api/external/companies/verify.");
        }

        if (!$reponse->successful() || !$reponse->json('success')) {
            return back()->with('error', "❌ « {$entreprise->nom} » : Comptaflow ne reconnaît pas cette clé.");
        }

        $entreprise->update(['comptaflow_last_sync_at' => now()]);

        return back()->with('success', "✅ Liaison vérifiée pour « {$entreprise->nom} ».");
    }

    /**
     * Les dossiers que Comptaflow connaît, pour rapprochement à l'œil.
     *
     * @return array<int, mixed>
     */
    private function fetchComptaflowEntreprises(): array
    {
        if (!config('selflow.comptaflow_api_secret')) {
            return [];
        }

        // Deux minutes de mémoire : l'écran se recharge à chaque action, et
        // attendre Comptaflow cinq secondes à chaque fois ralentissait tout.
        // Un échec n'est pas retenu : le prochain affichage réessaie.
        if (is_array($connus = \Illuminate\Support\Facades\Cache::get('comptaflow.dossiers'))) {
            return $connus;
        }

        try {
            $response = Http::timeout(5)->post($this->url('/api/external/list-companies'), [
                'secret' => config('selflow.comptaflow_api_secret'),
            ]);

            if ($response->successful() && $response->json('success')) {
                $dossiers = (array) $response->json('companies', []);
                \Illuminate\Support\Facades\Cache::put('comptaflow.dossiers', $dossiers, 120);

                return $dossiers;
            }
        } catch (\Throwable $e) {
            Log::warning('[LIAISON] Impossible de contacter Comptaflow list-companies : ' . $e->getMessage());
        }

        return [];
    }

    private function url(string $chemin): string
    {
        return rtrim(config('selflow.comptaflow_api_url', 'http://127.0.0.1:8000'), '/') . $chemin;
    }
}
