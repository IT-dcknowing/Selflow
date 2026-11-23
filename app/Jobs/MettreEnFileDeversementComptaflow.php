<?php

namespace App\Jobs;

use App\Modules\Admin\Modeles\DemandeExercicesAnterieurs;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Prépare le transfert par lots hors de la requête web.
 *
 * Un seul travail est ajouté par clic. Il met au plus 100 opérations en file,
 * puis se programme avec le dernier identifiant traité. La page ne reste donc
 * jamais occupée à créer des milliers de travaux.
 */
class MettreEnFileDeversementComptaflow implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 80;
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $entrepriseId,
        public readonly int $apresId = 0,
        public readonly bool $uniquementEchecs = false,
    ) {
    }

    public function uniqueId(): string
    {
        return 'comptaflow-dispatch-' . $this->entrepriseId . '-' . $this->apresId . '-' . (int) $this->uniquementEchecs;
    }

    public function handle(): void
    {
        $entreprise = Entreprise::find($this->entrepriseId);

        if (!$entreprise || $entreprise->comptaflow_sync_status !== 'active' || !$entreprise->comptaflow_sync_key) {
            return;
        }

        // Le référentiel est transmis une seule fois, au début du traitement,
        // dans le worker. L'utilisateur n'attend pas l'import des comptes,
        // journaux et tiers dans sa requête HTTP.
        if ($this->apresId === 0) {
            $referentiel = \App\Modules\Admin\Services\DeverserReferentielComptaflowService::synchroniser($entreprise);
            if (!$referentiel['success']) {
                EcritureComptable::withoutGlobalScopes()
                    ->where('entreprise_id', $entreprise->id)
                    ->where(function ($q) {
                        $q->whereNull('comptaflow_sync_status')
                            ->orWhere('comptaflow_sync_status', '!=', 'synced');
                    })
                    ->update([
                        'comptaflow_sync_status' => 'failed',
                        'comptaflow_sync_error' => 'Référentiel non synchronisé : ' . $referentiel['message'],
                    ]);

                self::journal()->warning('Déversement des écritures suspendu : référentiel Comptaflow incomplet', [
                    'entreprise_id' => $entreprise->id,
                    'motif' => $referentiel['message'],
                ]);
                return;
            }
        }

        $annees = DemandeExercicesAnterieurs::anneesAccordees($entreprise->id);
        $operations = Operation::where('entreprise_id', $entreprise->id)
            ->where('est_equilibree', true)
            ->where('id', '>', $this->apresId)
            ->where(function ($q) use ($annees) {
                $q->where('date_operation', '>=', now()->startOfYear()->toDateString());
                foreach ($annees as $annee) {
                    $q->orWhere(fn ($periode) => $periode
                        ->where('date_operation', '>=', "{$annee}-01-01")
                        ->where('date_operation', '<', ($annee + 1) . '-01-01'));
                }
            })
            ->whereHas('ecritures', function ($q) {
                $q->withoutGlobalScopes()->where(function ($lignes) {
                    if ($this->uniquementEchecs) {
                        $lignes->where('comptaflow_sync_status', 'failed');
                    } else {
                        $lignes->whereNull('comptaflow_sync_status')
                            ->orWhere('comptaflow_sync_status', '!=', 'synced');
                    }
                });
            })
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        if ($operations->isEmpty()) {
            return;
        }

        EcritureComptable::withoutGlobalScopes()
            ->whereIn('operation_id', $operations)
            // `!= 'synced'` laissait de côté les lignes au statut NULL (23/11/2026).
            ->where(fn ($q) => $q->whereNull('comptaflow_sync_status')->orWhere('comptaflow_sync_status', '!=', 'synced'))
            ->update([
                'comptaflow_sync_status' => 'pending',
                'comptaflow_sync_error' => null,
            ]);

        self::journal()->info('Opérations mises en file pour Comptaflow', ['nombre' => $operations->count(), 'premiere' => $operations->first(), 'derniere' => $operations->last()]);

        foreach ($operations as $operationId) {
            DeverserOperationComptaflow::dispatch($operationId);
        }

        if ($operations->count() === 100) {
            self::dispatch(
                $entreprise->id,
                (int) $operations->last(),
                $this->uniquementEchecs
            );
        }
    }

    /** Le journal du déversement : le fichier général et `logs/comptaflow-deversement.log`. */
    private static function journal(): \Psr\Log\LoggerInterface
    {
        return Log::stack(array_values(array_unique([config('logging.default'), 'comptaflow_deversement'])));
    }
}
