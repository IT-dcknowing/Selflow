<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;

class SyncEcrituresToComptaflow extends Command
{
    /**
     * The name and signature of the console command.
     * Usage:
     *   php artisan selflow:sync-ecritures
     *   php artisan selflow:sync-ecritures --entreprise=1
     *   php artisan selflow:sync-ecritures --batch=100
     */
    protected $signature = 'selflow:sync-ecritures
                            {--entreprise= : ID de l\'entreprise à synchroniser (optionnel, sinon toutes)}
                            {--batch=50 : Nombre d\'écritures à traiter par lot}
                            {--all : Re-synchroniser aussi les écritures déjà synchronisées}';

    protected $description = 'Synchronise les écritures comptables Selflow (statut failed/pending) vers COMPTAFLOW.';

    public function handle(): int
    {
        $this->info('🔗 Démarrage de la synchronisation des écritures vers COMPTAFLOW...');

        $batchSize = (int) $this->option('batch');
        $entrepriseId = $this->option('entreprise');
        $resyncAll = $this->option('all');

        // Récupérer les entreprises qui ont une liaison active
        $entreprisesQuery = Entreprise::where('comptaflow_sync_status', 'active')
            ->whereNotNull('comptaflow_sync_key')
            ->whereNotNull('comptaflow_company_id');

        if ($entrepriseId) {
            $entreprisesQuery->where('id', $entrepriseId);
        }

        $entreprises = $entreprisesQuery->get();

        if ($entreprises->isEmpty()) {
            $this->line('  Aucune entreprise avec liaison COMPTAFLOW active trouvée.');
            return self::SUCCESS;
        }

        $totalSynced = 0;
        $totalFailed = 0;

        foreach ($entreprises as $entreprise) {
            $this->line("  → Entreprise : <comment>{$entreprise->nom}</comment> (ID: {$entreprise->id})");

            $comptaflowUrl = config('selflow.comptaflow_api_url', 'http://127.0.0.1:8000');
            $secret = config('selflow.comptaflow_api_secret');

            // Récupérer les écritures à synchroniser
            $ecrituresQuery = EcritureComptable::withoutGlobalScopes()
                ->where('entreprise_id', $entreprise->id);

            if ($resyncAll) {
                $ecrituresQuery->whereIn('comptaflow_sync_status', ['pending', 'failed', 'synced']);
            } else {
                $ecrituresQuery->whereIn('comptaflow_sync_status', ['pending', 'failed']);
            }

            $ecritures = $ecrituresQuery->limit($batchSize)->get();

            if ($ecritures->isEmpty()) {
                $this->line('     <info>✓ Aucune écriture en attente.</info>');
                continue;
            }

            $this->line("     Traitement de {$ecritures->count()} écriture(s)...");

            // Préparer le payload
            $payload = $ecritures->map(function ($ec) {
                $dateStr = $ec->date_ecriture instanceof \Carbon\Carbon
                    ? $ec->date_ecriture->toDateString()
                    : (is_string($ec->date_ecriture) ? $ec->date_ecriture : \Carbon\Carbon::parse($ec->date_ecriture)->toDateString());

                return [
                    'date_ecriture' => $dateStr,
                    'libelle' => $ec->libelle,
                    'reference_document' => $ec->reference_document,
                    'code_journal' => $ec->code_journal,
                    'compte_debit' => $ec->compte_debit,
                    'compte_credit' => $ec->compte_credit,
                    'debit' => (float) $ec->debit,
                    'credit' => (float) $ec->credit,
                ];
            })->values()->toArray();

            try {
                $response = Http::timeout(30)->post($comptaflowUrl . '/api/external/ecritures/deverser', [
                    'secret' => $secret,
                    'selflow_company_id' => $entreprise->id,
                    'ecritures' => $payload,
                ]);

                if ($response->successful() && $response->json('success')) {
                    $count = $response->json('count', 0);
                    // Marquer toutes comme synchronisées
                    EcritureComptable::withoutGlobalScopes()
                        ->whereIn('id', $ecritures->pluck('id'))
                        ->update(['comptaflow_sync_status' => 'synced']);

                    $totalSynced += $count;
                    $this->info("     <info>✓ {$count} écriture(s) synchronisée(s) avec succès.</info>");
                } else {
                    $msg = $response->json('message', 'Erreur inconnue');
                    EcritureComptable::withoutGlobalScopes()
                        ->whereIn('id', $ecritures->pluck('id'))
                        ->update(['comptaflow_sync_status' => 'failed']);

                    $totalFailed += $ecritures->count();
                    $this->error("     ✗ Échec : {$msg}");
                    Log::error("selflow:sync-ecritures - entreprise {$entreprise->id}: {$msg}");
                }
            } catch (\Exception $e) {
                EcritureComptable::withoutGlobalScopes()
                    ->whereIn('id', $ecritures->pluck('id'))
                    ->update(['comptaflow_sync_status' => 'failed']);

                $totalFailed += $ecritures->count();
                $this->error("     ✗ Connexion impossible : " . $e->getMessage());
                Log::error("selflow:sync-ecritures - exception: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("🏁 Synchronisation terminée : <comment>{$totalSynced}</comment> synchronisée(s), <error>{$totalFailed}</error> échouée(s).");

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
