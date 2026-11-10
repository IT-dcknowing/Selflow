<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PlanComptable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reçoit dans Selflow le référentiel détenu par le dossier Comptaflow lié.
 *
 * Le plan, les journaux et les tiers de Comptaflow sont la référence de la
 * vue « COMPTAFLOW ». Les lignes locales restent séparées et ne sont jamais
 * renommées, reclassées ou supprimées par cette réception.
 *
 * L'endpoint historique /api/external/link-company renvoie les trois listes.
 * On ne lui transmet que les identifiants et la clé de liaison : aucun compte,
 * journal, client ou fournisseur Selflow ne repart vers Comptaflow.
 */
class RecevoirReferentielComptaflowService
{
    private const DELAI = 25;

    /**
     * @return array{success: bool, message: string, bilan?: array<string, int>, refus?: array<int, string>}
     */
    public static function synchroniser(Entreprise $entreprise): array
    {
        if (!$entreprise->liaisonComptaflowActive() || empty($entreprise->comptaflow_company_id)) {
            return ['success' => false, 'message' => 'La liaison Comptaflow n’est pas active.'];
        }

        $secret = config('selflow.comptaflow_api_secret');
        if (empty($secret)) {
            return ['success' => false, 'message' => 'Le secret serveur est absent (EXTERNAL_SYNC_SECRET).'];
        }

        try {
            // Cet endpoint renvoie le référentiel du dossier lié. Ne surtout
            // pas lui transmettre les clients/fournisseurs Selflow : son ancien
            // comportement fusionnait ces fiches dans Comptaflow.
            $reponse = Http::timeout(self::DELAI)->post(self::url('/api/external/link-company'), [
                'secret'                => $secret,
                'selflow_sync_key'      => $entreprise->comptaflow_sync_key,
                'selflow_company_id'    => $entreprise->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Réception du référentiel Comptaflow impossible', [
                'entreprise_id' => $entreprise->id,
                'erreur' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Comptaflow est injoignable : ' . $e->getMessage()];
        }

        if (!$reponse->successful() || !$reponse->json('success')) {
            return [
                'success' => false,
                'message' => $reponse->json('message')
                    ?? 'Comptaflow a refusé la lecture du référentiel (HTTP ' . $reponse->status() . ').',
            ];
        }

        foreach (['plan_comptable', 'codes_journaux', 'tiers'] as $cle) {
            if (!is_array($reponse->json($cle))) {
                return ['success' => false, 'message' => 'Réponse Comptaflow incomplète : liste « ' . $cle . ' » absente.'];
            }
        }

        $bilan = [
            'comptes_recus' => count($reponse->json('plan_comptable', [])),
            'comptes_crees' => 0,
            'comptes_actualises' => 0,
            'journaux_recus' => count($reponse->json('codes_journaux', [])),
            'journaux_crees' => 0,
            'journaux_actualises' => 0,
            'clients_recus' => 0,
            'clients_crees' => 0,
            'clients_actualises' => 0,
            'fournisseurs_recus' => 0,
            'fournisseurs_crees' => 0,
            'fournisseurs_actualises' => 0,
            'autres_tiers_non_pris_en_charge' => 0,
            'conflits_locaux' => 0,
        ];
        $refus = [];

        try {
            DB::transaction(function () use ($entreprise, $reponse, &$bilan, &$refus) {
                // 1. Plan comptable. Une ligne locale de même numéro est
                // conservée telle quelle ; seule une ligne déjà issue de CF
                // peut être actualisée.
                foreach ($reponse->json('plan_comptable', []) as $ligne) {
                    $numero = trim((string) ($ligne['numero_de_compte'] ?? ''));
                    $libelle = trim((string) ($ligne['intitule'] ?? ''));
                    if ($numero === '' || $libelle === '') {
                        $refus[] = 'Compte ignoré : numéro ou intitulé manquant.';
                        continue;
                    }

                    $existant = PlanComptable::where('entreprise_id', $entreprise->id)
                        ->where('source', 'comptaflow')
                        ->where('numero', $numero)
                        ->first();

                    $donnees = [
                        'libelle' => $libelle,
                        'numero_original' => $ligne['numero_original'] ?? null,
                        'source' => 'comptaflow',
                    ];

                    if ($existant) {
                        $existant->update($donnees);
                        $bilan['comptes_actualises']++;
                        continue;
                    }

                    $collision = PlanComptable::where('entreprise_id', $entreprise->id)
                        ->where('numero', $numero)
                        ->exists();
                    if ($collision) {
                        $bilan['conflits_locaux']++;
                        $refus[] = "Compte {$numero} : déjà présent dans le plan local ; aucune donnée locale n’a été modifiée.";
                        continue;
                    }

                    PlanComptable::create($donnees + [
                        'entreprise_id' => $entreprise->id,
                        'numero' => $numero,
                    ]);
                    $bilan['comptes_crees']++;
                }

                // 2. Codes journaux. Le type « Trésorerie » est distingué
                // entre Banque et Caisse selon le compte réellement associé.
                foreach ($reponse->json('codes_journaux', []) as $ligne) {
                    $code = strtoupper(trim((string) ($ligne['code_journal'] ?? '')));
                    $intitule = trim((string) ($ligne['intitule'] ?? ''));
                    if ($code === '' || $intitule === '') {
                        $refus[] = 'Journal ignoré : code ou intitulé manquant.';
                        continue;
                    }

                    $typeSource = (string) ($ligne['type'] ?? '');
                    $compte = trim((string) ($ligne['compte_numero'] ?? ''));
                    $type = match ($typeSource) {
                        'Ventes' => 'Vente',
                        'Achats' => 'Achat',
                        'Trésorerie' => str_starts_with($compte, '57') ? 'Caisse' : 'Banque',
                        default => 'Général',
                    };

                    $existant = CodeJournal::where('entreprise_id', $entreprise->id)
                        ->where('source', 'comptaflow')
                        ->where('code', $code)
                        ->first();
                    $donnees = [
                        'intitule' => $intitule,
                        'type' => $type,
                        'compte' => $compte !== '' ? $compte : null,
                        'numero_original' => $ligne['numero_original'] ?? null,
                        'source' => 'comptaflow',
                    ];

                    if ($existant) {
                        $existant->update($donnees);
                        $bilan['journaux_actualises']++;
                        continue;
                    }

                    $collision = CodeJournal::where('entreprise_id', $entreprise->id)
                        ->where('code', $code)
                        ->exists();
                    if ($collision) {
                        $bilan['conflits_locaux']++;
                        $refus[] = "Journal {$code} : déjà présent dans les journaux locaux ; aucune donnée locale n’a été modifiée.";
                        continue;
                    }

                    CodeJournal::create($donnees + [
                        'entreprise_id' => $entreprise->id,
                        'code' => $code,
                        'systeme' => false,
                    ]);
                    $bilan['journaux_crees']++;
                }

                // 3. Tiers. Les identifiants et numéros de Comptaflow sont
                // conservés ; aucune fiche locale n'est réaffectée à la source CF.
                foreach ($reponse->json('tiers', []) as $ligne) {
                    $numero = trim((string) ($ligne['numero_de_tiers'] ?? ''));
                    $nom = trim((string) ($ligne['intitule'] ?? ''));
                    $type = strtolower(trim((string) ($ligne['type_de_tiers'] ?? '')));
                    if ($numero === '' || $nom === '') {
                        $refus[] = 'Tiers ignoré : numéro ou intitulé manquant.';
                        continue;
                    }

                    $compte = trim((string) ($ligne['compte_general'] ?? $ligne['compte_numero'] ?? ''));
                    $donnees = [
                        'nom' => $nom,
                        'numero_tiers' => $numero,
                        'numero_original' => isset($ligne['numero_original']) ? (string) $ligne['numero_original'] : null,
                        'compte_comptable' => $compte !== '' ? $compte : null,
                        'source' => 'comptaflow',
                    ];

                    if ($type === 'client') {
                        $bilan['clients_recus']++;
                        $existant = Client::where('entreprise_id', $entreprise->id)
                            ->where('source', 'comptaflow')
                            ->where('numero_tiers', $numero)
                            ->first();
                        if ($existant) {
                            $existant->update($donnees);
                            $bilan['clients_actualises']++;
                            continue;
                        }
                        if (Client::where('entreprise_id', $entreprise->id)->where('numero_tiers', $numero)->exists()) {
                            $bilan['conflits_locaux']++;
                            $refus[] = "Client {$numero} : numéro déjà utilisé localement ; fiche locale conservée.";
                            continue;
                        }
                        Client::create($donnees + ['entreprise_id' => $entreprise->id]);
                        $bilan['clients_crees']++;
                        continue;
                    }

                    if ($type === 'fournisseur') {
                        $bilan['fournisseurs_recus']++;
                        $existant = Fournisseur::where('entreprise_id', $entreprise->id)
                            ->where('source', 'comptaflow')
                            ->where('numero_tiers', $numero)
                            ->first();
                        if ($existant) {
                            $existant->update($donnees);
                            $bilan['fournisseurs_actualises']++;
                            continue;
                        }
                        if (Fournisseur::where('entreprise_id', $entreprise->id)->where('numero_tiers', $numero)->exists()) {
                            $bilan['conflits_locaux']++;
                            $refus[] = "Fournisseur {$numero} : numéro déjà utilisé localement ; fiche locale conservée.";
                            continue;
                        }
                        Fournisseur::create($donnees + ['entreprise_id' => $entreprise->id]);
                        $bilan['fournisseurs_crees']++;
                        continue;
                    }

                    $bilan['autres_tiers_non_pris_en_charge']++;
                    $refus[] = "Tiers {$numero} ({$nom}) : type « {$type} » non représenté par les fiches clients/fournisseurs de Selflow.";
                }

                $entreprise->update([
                    'comptaflow_company_id' => $reponse->json('company_id') ?: $entreprise->comptaflow_company_id,
                    'comptaflow_sync_status' => 'active',
                    'comptaflow_last_sync_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('Réception du référentiel Comptaflow interrompue', [
                'entreprise_id' => $entreprise->id,
                'erreur' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Réception interrompue : ' . $e->getMessage()];
        }

        $message = sprintf(
            'Référentiel reçu de Comptaflow : %d compte(s) créé(s), %d journal/journaux créé(s), %d client(s) et %d fournisseur(s) créé(s).',
            $bilan['comptes_crees'],
            $bilan['journaux_crees'],
            $bilan['clients_crees'],
            $bilan['fournisseurs_crees']
        );

        if ($bilan['comptes_actualises'] + $bilan['journaux_actualises']
            + $bilan['clients_actualises'] + $bilan['fournisseurs_actualises'] > 0) {
            $message .= ' Les lignes Comptaflow déjà présentes ont été actualisées.';
        }
        if ($refus !== []) {
            $message .= ' ' . count($refus) . ' ligne(s) nécessitent une vérification ; aucune donnée locale n’a été écrasée.';
        }

        return ['success' => true, 'message' => $message, 'bilan' => $bilan, 'refus' => $refus];
    }

    private static function url(string $chemin): string
    {
        return rtrim(config('selflow.comptaflow_api_url', 'http://127.0.0.1:8000'), '/') . $chemin;
    }
}
