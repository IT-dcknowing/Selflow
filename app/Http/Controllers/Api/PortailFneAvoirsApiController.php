<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportAvoirsService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Le relevé des factures d'avoir (notes de crédit), commandé et consulté de l'extérieur.
 *
 *   POST /api/portail-fne/avoirs/relever
 *   GET  /api/portail-fne/avoirs
 */
class PortailFneAvoirsApiController extends Controller
{
    private function tracer(string $niveau, string $message, array $contexte = []): void
    {
        try {
            Log::channel('portail_fne')->{$niveau}($message, $contexte);
        } catch (\Throwable) {
            // Volontairement muet
        }
    }

    /**
     * Lancer un relevé des factures d'avoir, en arrière-plan.
     */
    public function relever(Request $request): JsonResponse
    {
        if (!config('selflow.portail_fne.scraper.actif')
            || !config('selflow.portail_fne.scraper.avoirs_actif', true)) {
            $this->tracer('notice', 'api[relever_avoirs] : refusé 503 — la chaîne est éteinte.', [
                'appelant'     => $request->ip(),
                'actif'        => (bool) config('selflow.portail_fne.scraper.actif'),
                'avoirs_actif' => (bool) config('selflow.portail_fne.scraper.avoirs_actif', true),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => "Le relevé des factures d'avoir est éteint sur ce serveur "
                    . '(PORTAIL_FNE_SCRAPER_ACTIF, PORTAIL_FNE_SCRAPER_AVOIRS_ACTIF).',
            ], 503);
        }

        $login = $this->loginDemande($request);

        if ($login instanceof JsonResponse) {
            return $login;
        }

        $minutes = max(1, (int) config('selflow.portail_fne.scraper.avoirs_minutes', 5));

        if (Cache::has(ScraperPortailFneService::verrouAvoirs($login))) {
            $this->tracer('info', 'api[relever_avoirs] : refusé 429 — un relevé est déjà en route.', [
                'appelant' => $request->ip(),
                'login'    => $login ?: 'tous',
            ]);

            return response()->json([
                'status'   => 'success',
                'lance'    => false,
                'message'  => 'Un relevé des avoirs est déjà en route ; il ne sera pas doublé.',
                'login'    => $login ?: 'tous',
                'reessayer_dans_minutes' => $minutes,
            ], 429);
        }

        if (!ScraperPortailFneService::lancerAvoirs($login)) {
            $this->tracer('error', 'api[relever_avoirs] : lancement impossible, rendu 500.', [
                'appelant' => $request->ip(),
                'login'    => $login ?: 'tous',
            ]);

            return response()->json([
                'status'  => 'error',
                'lance'   => false,
                'message' => "Le relevé des avoirs n'a pas pu être lancé. Le détail est dans "
                    . 'storage/logs/portail-fne.log.',
                'login'   => $login ?: 'tous',
            ], 500);
        }

        $this->tracer('info', 'api[relever_avoirs] : relevé lancé, rendu 202.', [
            'appelant' => $request->ip(),
            'login'    => $login ?: 'tous',
        ]);

        return response()->json([
            'status'  => 'success',
            'lance'   => true,
            'login'   => $login ?: 'tous',
            'message' => 'Relevé des avoirs lancé en arrière-plan. Le résultat apparaîtra au '
                . 'ramassage suivant (GET /api/portail-fne/avoirs).',
        ], 202);
    }

    /**
     * Ce que la chaîne des avoirs a relevé, et quand.
     */
    public function statut(Request $request): JsonResponse
    {
        $login = $this->loginDemande($request);

        if ($login instanceof JsonResponse) {
            return $login;
        }

        $releves = PortailFneImport::query()
            ->where('type', ImportAvoirsService::TYPE)
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

        $avoirs = PortailFneFactureRecue::query()
            ->where('subtype', 'refund')
            ->when($login, fn ($q) => $q->where('login', $login))
            ->selectRaw('statut_rapprochement, count(*) as nombre')
            ->groupBy('statut_rapprochement')
            ->pluck('nombre', 'statut_rapprochement');

        return response()->json([
            'status' => 'success',
            'data'   => [
                'scraper' => [
                    'actif'           => (bool) config('selflow.portail_fne.scraper.actif'),
                    'avoirs_actif'    => (bool) config('selflow.portail_fne.scraper.avoirs_actif', true),
                    'pas_minutes'     => (int) config('selflow.portail_fne.scraper.avoirs_minutes', 5),
                    'releve_en_cours' => Cache::has(ScraperPortailFneService::verrouAvoirs($login)),
                ],
                'login'   => $login ?: 'tous',
                'releves' => $releves,
                'avoirs'  => $avoirs,
            ],
        ]);
    }

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
