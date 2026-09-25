<?php

namespace App\Modules\Admin\Services;

use App\Jobs\DeverserOperationComptaflow;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;

/**
 * Envoyer chez Comptaflow ce que Selflow détient déjà.
 *
 * ## Ce qui manquait
 *
 * Le déversement ne partait que sur une écriture **nouvelle**. Une entreprise
 * qui tenait sa caisse depuis six mois avant d'être reliée gardait ses six
 * mois : rien, dans aucun écran, ne permettait de dire « envoie tout ce que
 * j'ai déjà ». Il fallait une ligne de commande, que le client n'a pas.
 *
 * ## L'ordre, et pourquoi il n'est pas négociable
 *
 * **Le référentiel d'abord.** Comptaflow refuse une écriture dont il ne
 * connaît pas le journal — et il a raison : un code inconnu rattrapé au hasard
 * mettrait une vente au journal de caisse. Envoyer les écritures avant le plan
 * comptable et les journaux, c'est les faire toutes refuser.
 *
 * **Puis les opérations, dans l'ordre où elles ont été passées.** Une opération
 * est un ensemble équilibré ; elle part d'un bloc, par
 * `DeverserOperationComptaflow`. Les envoyer dans le désordre ne casserait
 * rien — chacune est autonome — mais un journal lu dans l'ordre se relit.
 *
 * ## Ce que ce service ne fait pas
 *
 * Il ne renvoie pas ce qui est déjà passé : une opération dont toutes les
 * lignes portent `synced` est laissée tranquille. Et même s'il le faisait,
 * `cle_selflow` empêcherait le doublon côté Comptaflow. Les deux gardes valent
 * mieux qu'une.
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

        // ── 1. Le référentiel ──
        $referentiel = DeversementReferentielService::deverser($entreprise);

        if (!$referentiel['success']) {
            return [
                'success'    => false,
                'message'    => "Le référentiel n'a pas pu être déversé, et sans lui chaque écriture serait refusée : "
                    . $referentiel['message'],
                'operations' => 0,
            ];
        }

        // ── 2. Les opérations qui n'ont pas encore abouti ──
        $aEnvoyer = Operation::where('entreprise_id', $entreprise->id)
            ->where('est_equilibree', true)
            ->whereHas('ecritures', fn ($q) => $q->where(function ($qs) {
                $qs->whereNull('comptaflow_sync_status')
                   ->orWhere('comptaflow_sync_status', '!=', 'synced');
            }))
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
            ? "Le référentiel est à jour, et toutes les opérations étaient déjà déversées."
            : sprintf(
                "Le référentiel est passé. %d opération(s) sont en cours de déversement ; le planificateur les envoie dans la minute.",
                $aEnvoyer->count()
            );

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

    /**
     * Ce qui reste à déverser, pour l'écran.
     *
     * @return array{operations: int, lignes: int, en_echec: int}
     */
    public static function reste(Entreprise $entreprise): array
    {
        $lignes = EcritureComptable::where('entreprise_id', $entreprise->id)
            ->where(function ($q) {
                $q->whereNull('comptaflow_sync_status')
                  ->orWhere('comptaflow_sync_status', '!=', 'synced');
            });

        return [
            'operations' => (clone $lignes)->distinct('operation_id')->count('operation_id'),
            'lignes'     => (clone $lignes)->count(),
            'en_echec'   => EcritureComptable::where('entreprise_id', $entreprise->id)
                ->where('comptaflow_sync_status', 'failed')->count(),
        ];
    }
}
