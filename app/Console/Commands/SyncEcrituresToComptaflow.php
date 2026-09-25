<?php

namespace App\Console\Commands;

use App\Jobs\DeverserOperationComptaflow;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use Illuminate\Console\Command;

/**
 * Reprendre ce qui n'est pas passé.
 *
 * ## Ce que cette commande construisait elle-même, et ce que ça coûtait
 *
 * Elle bâtissait **sa propre** charge utile, et ce n'était pas la même que
 * celle du déversement ordinaire. Trois champs y manquaient, et chacun avait
 * une conséquence :
 *
 * | Champ absent | Ce qui se passait |
 * |---|---|
 * | `cle_selflow` | aucune clé d'idempotence : `n_saisie` retombait sur la référence de pièce, et un second passage pouvait **dupliquer** l'écriture. La balance doublait sans que rien ne le signale |
 * | `compte_tiers` | l'écriture se rattachait au seul compte collectif 411000, et le relevé d'un client particulier devenait impossible |
 * | `exercice_debut` / `_fin` | Comptaflow ne pouvait plus vérifier l'accord des exercices : une pièce d'un exercice clos se rangeait dans l'exercice courant |
 *
 * Une écriture partie par le chemin ordinaire arrivait donc complète, et la
 * même écriture reprise ici arrivait amputée. Deux chemins pour une même
 * chose, et l'un des deux faux.
 *
 * ## Ce qu'elle fait maintenant
 *
 * Rien, ou presque : elle repère les **opérations** dont des lignes ne sont
 * pas passées, et laisse `DeverserOperationComptaflow` les envoyer — le même
 * travail que le déversement ordinaire, donc le même format, la même clé, le
 * même exercice, et le même refus atomique.
 *
 * L'opération, et non la ligne : reprendre une ligne seule reconstituerait
 * exactement le défaut qu'on vient de fermer — une opération à moitié chez
 * Comptaflow.
 */
class SyncEcrituresToComptaflow extends Command
{
    protected $signature = 'selflow:sync-ecritures
                            {--entreprise= : ID de l\'entreprise à synchroniser (optionnel, sinon toutes)}
                            {--batch=50 : Nombre d\'opérations à remettre en file par passage}
                            {--all : Remettre aussi les opérations déjà déversées}';

    protected $description = 'Remet en file les opérations comptables que Comptaflow n\'a pas encore reçues.';

    public function handle(): int
    {
        $this->info('Reprise du déversement vers COMPTAFLOW…');

        $parLot       = max(1, (int) $this->option('batch'));
        $entrepriseId = $this->option('entreprise');
        $toutRejouer  = (bool) $this->option('all');

        $entreprises = Entreprise::where('comptaflow_sync_status', 'active')
            ->whereNotNull('comptaflow_sync_key')
            ->whereNotNull('comptaflow_company_id')
            ->when($entrepriseId, fn ($q) => $q->where('id', $entrepriseId))
            ->get();

        if ($entreprises->isEmpty()) {
            $this->line('  Aucune entreprise avec une liaison COMPTAFLOW active.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($entreprises as $entreprise) {
            $this->line("  → {$entreprise->nom} (n° {$entreprise->id})");

            // Les opérations qui portent au moins une ligne non aboutie. Une
            // opération déséquilibrée est écartée ici comme elle l'est dans le
            // travail : la déverser porterait le déséquilibre chez Comptaflow.
            $operations = Operation::withoutGlobalScopes()
                ->where('entreprise_id', $entreprise->id)
                ->where('est_equilibree', true)
                ->when(
                    !$toutRejouer,
                    fn ($q) => $q->whereHas('ecritures', fn ($qe) => $qe->where(function ($qs) {
                        $qs->whereNull('comptaflow_sync_status')
                           ->orWhere('comptaflow_sync_status', '!=', 'synced');
                    }))
                )
                ->orderBy('id')
                ->limit($parLot)
                ->pluck('id');

            if ($operations->isEmpty()) {
                $this->line('     <info>Rien en attente.</info>');

                continue;
            }

            // `--all` renvoie tout : les lignes repassent en attente pour que
            // le travail les reprenne. L'idempotence de Comptaflow empêche le
            // doublon.
            if ($toutRejouer) {
                EcritureComptable::withoutGlobalScopes()
                    ->whereIn('operation_id', $operations)
                    ->update(['comptaflow_sync_status' => 'pending']);
            }

            foreach ($operations as $operationId) {
                DeverserOperationComptaflow::dispatch($operationId);
            }

            $total += $operations->count();
            $this->line("     {$operations->count()} opération(s) remise(s) en file.");
        }

        $this->info("{$total} opération(s) en file. Le planificateur les envoie dans la minute.");

        return self::SUCCESS;
    }
}
