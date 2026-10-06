<?php

namespace App\Console\Commands;

use App\Jobs\DeverserOperationComptaflow;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Services\LiaisonComptaflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Regrouper chez Comptaflow les opérations parties une ligne par pièce.
 *
 * Jusqu'au 06/10/2026, Comptaflow rangeait chaque ligne déversée sous son
 * propre numéro de saisie : une vente arrivait en quatre pièces d'une ligne,
 * aucune équilibrée (chantier 7.1). Le déversement est corrigé pour ce qui
 * part ; ce qui est déjà parti reste éclaté.
 *
 * **Rejouer le déversement n'y changerait rien** : chaque ligne est reconnue à
 * sa `cle_selflow` et ignorée — c'est l'idempotence vérifiée avant de rejouer
 * (chantier 7.4). Cette commande ne renvoie donc aucune écriture : elle dit à
 * Comptaflow quelles lignes forment quelle opération, et Comptaflow leur donne
 * un numéro commun. Rien n'est créé, rien n'est supprimé, aucun montant ne
 * bouge. La relancer est sans effet sur ce qui est déjà regroupé.
 */
class RegrouperOperationsComptaflow extends Command
{
    protected $signature = 'selflow:regrouper-comptaflow {--entreprise= : Limiter à une entreprise} {--paquet=200}';

    protected $description = 'Regroupe chez Comptaflow, sous un seul numéro de saisie, les lignes des opérations déjà déversées';

    public function handle(): int
    {
        $entreprises = Entreprise::where('comptaflow_sync_status', 'active')
            ->whereNotNull('comptaflow_sync_key')
            ->when($this->option('entreprise'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        foreach ($entreprises as $entreprise) {
            $bilan = ['regroupees' => 0, 'deja' => 0, 'refus' => 0];

            Operation::where('entreprise_id', $entreprise->id)
                ->whereHas('ecritures', fn ($q) => $q->where('comptaflow_sync_status', 'synced'))
                ->with('ecritures:id,operation_id,comptaflow_sync_status')
                ->orderBy('id')
                ->chunk((int) $this->option('paquet'), function ($operations) use ($entreprise, &$bilan) {
                    $charge = $operations
                        // Une opération d'une seule ligne n'a rien à regrouper.
                        ->filter(fn ($op) => $op->ecritures->count() > 1)
                        ->map(fn ($op) => [
                            'operation' => DeverserOperationComptaflow::cleOperation($entreprise, $op),
                            'cles'      => $op->ecritures
                                ->map(fn ($e) => 'SELFLOW-' . $entreprise->id . '-' . $e->id)
                                ->values()->all(),
                        ])->values()->all();

                    if ($charge === []) {
                        return;
                    }

                    $reponse = Http::timeout(60)
                        ->withHeaders(LiaisonComptaflowService::enTete($entreprise))
                        ->post(rtrim(config('selflow.comptaflow_api_url', 'http://127.0.0.1:8000'), '/') . '/api/external/ecritures/regrouper', [
                            'secret'             => config('selflow.comptaflow_api_secret'),
                            'selflow_company_id' => $entreprise->id,
                            'operations'         => $charge,
                        ]);

                    if (!$reponse->successful()) {
                        $this->error("Entreprise {$entreprise->id} : Comptaflow a répondu {$reponse->status()}.");

                        return false;
                    }

                    $bilan['regroupees'] += (int) $reponse->json('regroupees');
                    $bilan['deja']       += (int) $reponse->json('deja');
                    $bilan['refus']      += count($reponse->json('refus') ?? []);

                    foreach ($reponse->json('refus') ?? [] as $refus) {
                        $this->warn("  {$refus}");
                    }
                });

            $this->line("Entreprise {$entreprise->id} : {$bilan['regroupees']} regroupée(s), {$bilan['deja']} déjà en ordre, {$bilan['refus']} refusée(s).");
        }

        return self::SUCCESS;
    }
}
