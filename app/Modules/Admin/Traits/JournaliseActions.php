<?php

namespace App\Modules\Admin\Traits;

use App\Modules\Admin\Modeles\JournalAudit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Trait JournaliseActions
 *
 * À utiliser dans les contrôleurs ou les modèles pour journaliser
 * toutes les actions sensibles de l'application.
 *
 * Usage :
 *   use JournaliseActions;
 *   $this->journaliser('connexion', 'Utilisateur', $user->id);
 *   $this->journaliser('creation_vente', 'Vente', $vente->id, null, $vente->toArray());
 *   $this->journaliser('modification_role', 'Utilisateur', $user->id, ['role' => $ancienRole], ['role' => $nouveauRole]);
 */
trait JournaliseActions
{
    /**
     * Enregistre une entrée dans le journal d'audit.
     *
     * @param string $action       Identifiant de l'action (ex: 'connexion', 'creation_vente')
     * @param string $entite       Nom de l'entité concernée (ex: 'Vente', 'Utilisateur')
     * @param int|null $entiteId   ID de l'entité
     * @param mixed $avant         Valeur/état avant (sera encodé en JSON)
     * @param mixed $apres         Valeur/état après (sera encodé en JSON)
     */
    protected function journaliser(
        string $action,
        string $entite = null,
        int $entiteId = null,
        mixed $avant = null,
        mixed $apres = null
    ): void {
        try {
            $user = Auth::user();

            // En assistance, l'action est faite par le superadministrateur sous
            // le compte du client : le journal le dit (19/10/2026).
            $incarnation = session('incarnation');
            if (is_array($incarnation) && !empty($incarnation['superadmin_email'])) {
                $apres = (is_array($apres) ? $apres : ($apres !== null ? ['valeur' => $apres] : []))
                    + ['_assistance_par' => $incarnation['superadmin_email']];
            }

            JournalAudit::create([
                'utilisateur_id'    => $user?->id,
                'entreprise_id'     => $user?->entreprise_id,
                'action'            => $action,
                'entite'            => $entite,
                'entite_id'         => $entiteId,
                'ancienne_valeur'   => $avant ? (is_array($avant) ? $avant : ['valeur' => $avant]) : null,
                'nouvelle_valeur'   => $apres  ? (is_array($apres)  ? $apres  : ['valeur' => $apres])  : null,
                'adresse_ip'        => Request::ip(),
                'point_de_vente_id' => $user?->point_de_vente_id ?? null,
            ]);
        } catch (\Throwable $e) {
            // Le journal ne doit JAMAIS faire planter l'application principale
            \Log::warning('JournalAudit: échec écriture — ' . $e->getMessage());
        }
    }
}
