<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\ComptabiliteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repasser les écritures d'une pièce — retour du 16/10/2026.
 *
 * Pour une facture (de vente ou d'achat) dont les écritures ne disent plus ce
 * que dit la pièce : opération déséquilibrée, ou passée par une version
 * antérieure du calcul. Une pièce normalisée ne se modifie plus à l'écran ;
 * ses écritures, elles, se reprennent ici, sans toucher à la pièce ni à ce
 * qui a été transmis à la DGI.
 *
 * - une opération **déséquilibrée et jamais partie chez Comptaflow** est
 *   supprimée : elle n'a jamais quitté Selflow, rien n'est à défaire ailleurs ;
 * - toute autre opération est **contre-passée** (la contre-passation part chez
 *   Comptaflow) ;
 * - les écritures sont repassées sur la pièce telle qu'elle est, avec ce que
 *   la trésorerie a réellement encaissé ou décaissé.
 *
 *   php artisan selflow:recomptabiliser "DC-KNOWING CGA" VTE-250926-003
 *   php artisan selflow:recomptabiliser "DC-KNOWING CGA" VTE-250926-003 --appliquer
 */
class Recomptabiliser extends Command
{
    protected $signature = 'selflow:recomptabiliser {entreprise : identifiant ou nom} {piece : numéro de la facture} {--appliquer : écrire}';

    protected $description = 'Contre-passe et repasse les écritures d\'une facture de vente ou d\'achat (aperçu par défaut).';

    public function handle(): int
    {
        $cle = (string) $this->argument('entreprise');
        $entreprise = ctype_digit($cle) ? Entreprise::find((int) $cle) : Entreprise::where('nom', $cle)->first();
        if (!$entreprise) {
            $this->error("Entreprise introuvable : {$cle}");

            return self::FAILURE;
        }

        $numero = (string) $this->argument('piece');
        $sites = $entreprise->pointsDeVente()->pluck('id');
        $vente = Vente::withoutGlobalScopes()->whereIn('point_de_vente_id', $sites)->where('numero_facture', $numero)->first();
        $achat = $vente ? null : Achat::withoutGlobalScopes()->whereIn('point_de_vente_id', $sites)->where('numero_facture', $numero)->first();

        if (!$vente && !$achat) {
            $this->error("Pièce introuvable : {$numero}");

            return self::FAILURE;
        }
        if ($vente && $vente->type_facture === 'avoir') {
            $this->error('Les avoirs ne se reprennent pas ici.');

            return self::FAILURE;
        }

        $types = $vente ? ['FactureVente', 'ReglementVente'] : ['FactureAchat', 'ReglementAchat'];
        $operations = Operation::withoutGlobalScopes()->with(['ecritures' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('entreprise_id', $entreprise->id)->where('reference_document', $numero)
            ->whereIn('type_operation', $types)->get();

        $aSupprimer = $operations->filter(fn ($op) => !$op->est_equilibree
            && $op->ecritures->where('comptaflow_sync_status', 'synced')->isEmpty());

        $this->info("{$numero} : " . $operations->count() . ' opération(s) — '
            . $aSupprimer->count() . ' déséquilibrée(s) jamais déversée(s), à supprimer ; les autres seront contre-passées.');

        if (!$this->option('appliquer')) {
            $this->warn('Aperçu seulement. Relancez avec --appliquer pour écrire.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($vente, $achat, $aSupprimer, $entreprise, $numero, $types, $sites) {
            foreach ($aSupprimer as $op) {
                foreach ($op->ecritures as $e) {
                    $e->delete();
                }
                $op->delete();
            }

            $tresorerie = TresorerieJournal::withoutGlobalScopes()->whereIn('point_de_vente_id', $sites)->where('reference_document', $numero);

            if ($vente) {
                ComptabiliteService::contrePasserLaPiece($entreprise->id, $numero, $types, ComptabiliteService::TYPE_CONTRE_PASSATION_VENTE);
                ComptabiliteService::genererEcrituresVente(
                    $vente->fresh(), (float) $tresorerie->sum('montant_entree'), (string) ($vente->mode_paiement ?: 'Espèces')
                );
            } else {
                ComptabiliteService::contrePasserLaPiece($entreprise->id, $numero, $types, ComptabiliteService::TYPE_CONTRE_PASSATION_ACHAT);
                ComptabiliteService::genererEcrituresAchat(
                    $achat->fresh(), (float) $tresorerie->sum('montant_sortie'), (string) ($achat->mode_paiement ?: 'Espèces'),
                    $achat->date_achat?->toDateString()
                );
            }
        });

        $desequilibrees = Operation::withoutGlobalScopes()->where('entreprise_id', $entreprise->id)
            ->where('reference_document', $numero)->where('est_equilibree', false)->count();

        $desequilibrees === 0
            ? $this->info('Écritures repassées ; toutes les opérations de la pièce sont équilibrées.')
            : $this->error("Écritures repassées, mais {$desequilibrees} opération(s) restent déséquilibrées : envoyez cette sortie pour analyse.");

        return self::SUCCESS;
    }
}
