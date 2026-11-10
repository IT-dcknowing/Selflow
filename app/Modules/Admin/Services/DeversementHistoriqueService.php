<?php

namespace App\Modules\Admin\Services;

use App\Jobs\DeverserOperationComptaflow;
use App\Modules\Admin\Modeles\DemandeExercicesAnterieurs;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;

/**
 * Envoyer chez Comptaflow les écritures comptables déjà produites par Selflow.
 *
 * Le plan comptable, les journaux et les tiers sont détenus par Comptaflow et
 * sont reçus dans Selflow par RecevoirReferentielComptaflowService. Ce service
 * ne transmet aucun référentiel : il ne remet en file que les opérations
 * comptables non encore déversées.
 *
 * Une opération est un ensemble équilibré ; elle part d'un bloc, par
 * DeverserOperationComptaflow. Les opérations déjà reçues restent protégées
 * par leur clé d'idempotence.
 */
class DeversementHistoriqueService
{
    /**
     * @return array{success: bool, message: string, operations: int}
     */
    public static function lancer(Entreprise $entreprise): array
    {
        if ($entreprise->comptaflow_sync_status !== 'active' || !$entreprise->comptaflow_sync_key) {
            return [
                'success'    => false,
                'message'    => "La liaison Comptaflow n'est pas active. Demandez-la d'abord ; le déversement suivra.",
                'operations' => 0,
            ];
        }

        // Le référentiel appartient à Comptaflow et est reçu dans
        // Selflow par RecevoirReferentielComptaflowService. Cette commande
        // n'envoie que les opérations comptables.
        // ── Les opérations qui n'ont pas encore abouti ──

        // L'exercice en cours part sans rien demander ; un exercice antérieur
        // seulement s'il a été accordé (propriétaire, 08/10/2026).
        $aEnvoyer = self::nonDeversees($entreprise)
            ->where(fn ($q) => self::exercicesPermis($q, $entreprise))
            ->orderBy('id')
            ->pluck('id');

        foreach ($aEnvoyer as $operationId) {
            DeverserOperationComptaflow::dispatch($operationId);
        }

        // Ce qui ne partira pas, et pourquoi : une opération déséquilibrée
        // n'est pas un oubli, c'est une erreur de Selflow qu'il ne faut pas
        // exporter. La taire ferait chercher longtemps.
        $desequilibrees = Operation::where('entreprise_id', $entreprise->id)
            ->where('est_equilibree', false)
            ->count();

        $message = $aEnvoyer->isEmpty()
            ? "Toutes les opérations étaient déjà déversées."
            : sprintf(
                "%d opération(s) sont en cours de déversement ; le planificateur les envoie dans la minute.",
                $aEnvoyer->count()
            );

        $retenues = self::anneesAnterieuresNonDeversees($entreprise);
        $nonAccordees = array_values(array_diff($retenues, DemandeExercicesAnterieurs::anneesAccordees($entreprise->id)));
        if ($nonAccordees !== []) {
            $message .= ' Les exercices ' . implode(', ', $nonAccordees)
                . " ne partent pas : la comptabilité d'un exercice antérieur se demande, et l'administrateur de la plateforme l'accorde.";
        }

        if ($desequilibrees > 0) {
            $message .= sprintf(
                " %d opération(s) déséquilibrée(s) ne sont pas envoyées : les déverser porterait le déséquilibre chez Comptaflow.",
                $desequilibrees
            );
        }

        return [
            'success'    => true,
            'message'    => $message,
            'operations' => $aEnvoyer->count(),
        ];
    }

    /** Les opérations équilibrées dont une ligne n'est pas encore passée. */
    private static function nonDeversees(Entreprise $entreprise)
    {
        return Operation::where('entreprise_id', $entreprise->id)
            ->where('est_equilibree', true)
            // Sans la période active : les écritures portent un filtre par
            // période, et « tout l'historique » se réduisait à la période
            // affichée à l'écran.
            ->whereHas('ecritures', fn ($q) => $q->withoutGlobalScopes()->where(function ($qs) {
                $qs->whereNull('comptaflow_sync_status')
                   ->orWhere('comptaflow_sync_status', '!=', 'synced');
            }));
    }

    /** L'exercice en cours, plus les exercices antérieurs accordés. */
    private static function exercicesPermis($requete, Entreprise $entreprise): void
    {
        $requete->where('date_operation', '>=', now()->startOfYear()->toDateString());

        foreach (DemandeExercicesAnterieurs::anneesAccordees($entreprise->id) as $annee) {
            // Borne haute exclusive : `date_operation` porte une heure, et
            // « entre le 1er janvier et le 31 décembre » laissait dehors tout le
            // 31 décembre passé minuit.
            $requete->orWhere(fn ($q) => $q
                ->where('date_operation', '>=', "{$annee}-01-01")
                ->where('date_operation', '<', ($annee + 1) . '-01-01'));
        }
    }

    /**
     * Les exercices antérieurs à l'exercice en cours qui portent encore des
     * opérations à déverser — ceux qu'une entreprise peut demander.
     *
     * @return array<int, int>
     */
    public static function anneesAnterieuresNonDeversees(Entreprise $entreprise): array
    {
        return self::nonDeversees($entreprise)
            ->where('date_operation', '<', now()->startOfYear()->toDateString())
            ->distinct()->pluck('date_operation')
            ->map(fn ($d) => (int) \Illuminate\Support\Carbon::parse($d)->year)
            ->unique()->sort()->values()->all();
    }

    /**
     * Ce qui reste à déverser, pour l'écran.
     *
     * @return array{operations: int, lignes: int, en_echec: int}
     */
    public static function reste(Entreprise $entreprise): array
    {
        $lignes = EcritureComptable::withoutGlobalScopes()->where('entreprise_id', $entreprise->id)
            ->where(function ($q) {
                $q->whereNull('comptaflow_sync_status')
                  ->orWhere('comptaflow_sync_status', '!=', 'synced');
            });

        return [
            'operations' => (clone $lignes)->distinct('operation_id')->count('operation_id'),
            'lignes'     => (clone $lignes)->count(),
            'en_echec'   => EcritureComptable::withoutGlobalScopes()->where('entreprise_id', $entreprise->id)
                ->where('comptaflow_sync_status', 'failed')->count(),
        ];
    }
}
