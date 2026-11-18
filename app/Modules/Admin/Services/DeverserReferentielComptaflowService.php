<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PlanComptable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoie le référentiel propre à Selflow vers le dossier Comptaflow lié.
 *
 * Comptaflow reste maître de ses données : les lignes déjà présentes ne sont
 * pas remplacées ; les références absentes sont créées par son import natif.
 * Les lignes reçues de Comptaflow ne repartent jamais en sens inverse.
 */
class DeverserReferentielComptaflowService
{
    private const DELAI = 90;

    public static function synchroniser(Entreprise $entreprise): array
    {
        if (!$entreprise->liaisonComptaflowActive() || !$entreprise->comptaflow_company_id) {
            return ['success' => false, 'message' => 'La liaison Comptaflow n’est pas active.'];
        }

        $secret = config('selflow.comptaflow_api_secret');
        $cle = $entreprise->comptaflow_sync_key;
        if (blank($secret) || blank($cle)) {
            return ['success' => false, 'message' => 'La configuration sécurisée de la liaison Comptaflow est incomplète.'];
        }

        $plan = PlanComptable::query()
            ->where('entreprise_id', $entreprise->id)
            ->whereNull('archive_le')
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'comptaflow'))
            ->orderBy('numero')
            ->get()
            ->map(fn ($c) => [
                'numero_de_compte' => (string) $c->numero,
                'intitule' => (string) $c->libelle,
                'numero_original' => $c->numero_original ?: $c->numero,
            ])->filter(fn ($c) => $c['numero_de_compte'] !== '' && $c['intitule'] !== '')
            ->values()->all();

        $journaux = CodeJournal::query()
            ->where('entreprise_id', $entreprise->id)
            ->whereNull('archive_le')
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'comptaflow'))
            ->orderBy('code')
            ->get()
            ->map(function ($j) {
                $type = match ($j->type) {
                    'Vente' => 'Ventes',
                    'Achat' => 'Achats',
                    'Banque', 'Caisse' => 'Trésorerie',
                    default => 'Opérations Diverses',
                };

                return [
                    'code_journal' => strtoupper(trim((string) $j->code)),
                    'intitule' => trim((string) $j->intitule),
                    'type' => $type,
                    'compte_numero' => $j->compte ?: null,
                    'numero_original' => $j->numero_original ?: $j->code,
                ];
            })->filter(fn ($j) => $j['code_journal'] !== '' && $j['intitule'] !== '')
            ->values()->all();

        $tiers = [];
        foreach ([
            ['modele' => Client::class, 'type' => 'client'],
            ['modele' => Fournisseur::class, 'type' => 'fournisseur'],
        ] as $definition) {
            $definition['modele']::query()
                ->where('entreprise_id', $entreprise->id)
                ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'comptaflow'))
                ->orderBy('id')
                ->get()
                ->each(function ($t) use (&$tiers, $definition) {
                    $numero = trim((string) $t->numero_tiers);
                    $nom = trim((string) $t->nom);
                    if ($numero === '' || $nom === '') {
                        return;
                    }

                    $informations = array_filter([
                        'telephone' => $t->telephone,
                        'email' => $t->email,
                        'adresse' => $t->adresse,
                        'ncc' => $t->ncc,
                        'rccm' => $t->rccm,
                        'regime_imposition' => $t->regime_imposition,
                    ], fn ($v) => filled($v));

                    $tiers[] = [
                        'numero_de_tiers' => $numero,
                        'intitule' => $nom,
                        'type_de_tiers' => $definition['type'],
                        'compte_general' => $t->compte_comptable,
                        'informations' => $informations,
                        'numero_original' => $t->numero_original ?: (string) $t->id,
                    ];
                });
        }

        try {
            $reponse = Http::timeout(self::DELAI)
                ->withHeaders(['X-Company-Key' => $cle])
                ->post(self::url('/api/external/referentiel/deverser'), [
                    'secret' => $secret,
                    'selflow_company_id' => $entreprise->id,
                    'comptaflow_company_id' => $entreprise->comptaflow_company_id,
                    'plan_comptable' => $plan,
                    'codes_journaux' => $journaux,
                    'tiers' => $tiers,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Déversement du référentiel vers Comptaflow impossible', [
                'entreprise_id' => $entreprise->id,
                'comptaflow_company_id' => $entreprise->comptaflow_company_id,
                'erreur' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Comptaflow est injoignable : ' . $e->getMessage()];
        }

        if (!$reponse->successful() || !$reponse->json('success')) {
            $refus = collect((array) $reponse->json('refus', []))->filter()->unique()->values();
            $message = $reponse->json('message') ?: 'Comptaflow a refusé le référentiel.';
            if ($refus->isNotEmpty()) {
                $message .= ' ' . $refus->take(8)->implode(' ; ');
            }

            Log::warning('Référentiel Selflow refusé par Comptaflow', [
                'entreprise_id' => $entreprise->id,
                'http_status' => $reponse->status(),
                'message' => $message,
            ]);

            return ['success' => false, 'message' => $message];
        }

        return [
            'success' => true,
            'message' => sprintf(
                'Référentiel transmis à Comptaflow : %d compte(s), %d journal(aux) et %d tiers.',
                (int) $reponse->json('comptes', count($plan)),
                (int) $reponse->json('journaux', count($journaux)),
                (int) $reponse->json('tiers', count($tiers))
            ),
        ];
    }

    private static function url(string $chemin): string
    {
        return rtrim(config('selflow.comptaflow_api_url', 'http://127.0.0.1:8000'), '/') . $chemin;
    }
}
