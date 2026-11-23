<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\PointDeVente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Le point de vente qu'un utilisateur a le droit de toucher (23/11/2026).
 *
 * L'audit du cloisonnement a trouvé l'API qui prenait le site dans l'en-tête
 * `X-Point-De-Vente-Id` sans rien vérifier : un responsable de Marcory
 * écrivait un mouvement de stock sur le site d'une autre entreprise. La règle,
 * écrite une fois :
 *
 * - un membre affecté à un site (caissier, responsable de point de vente)
 *   travaille sur **son** site, quoi que demande la requête ;
 * - les autres ne touchent qu'un site de **leur** entreprise ;
 * - tout le reste est refusé (403), jamais ramené en silence sur un autre.
 */
class SiteAutorise
{
    /** Le site de la requête (en-tête, paramètre, session, affectation), vérifié. */
    public static function pourRequete(Request $request): ?int
    {
        $utilisateur = Auth::user();
        if (!$utilisateur) {
            return null;
        }

        if ($utilisateur->estAffecteAUnSite()) {
            return (int) $utilisateur->point_de_vente_id;
        }

        $demande = $request->header('X-Point-De-Vente-Id')
            ?? $request->query('point_de_vente_id')
            ?? session('point_de_vente_actif_id')
            ?? $utilisateur->point_de_vente_id;

        if ($demande === null || $demande === '' || $demande === 'tout') {
            return null;
        }

        self::verifier((int) $demande);

        return (int) $demande;
    }

    /** Le site auquel les listes sont limitées : celui du membre affecté, sinon aucun. */
    public static function siteImpose(): ?int
    {
        $utilisateur = Auth::user();

        return $utilisateur && $utilisateur->estAffecteAUnSite() ? (int) $utilisateur->point_de_vente_id : null;
    }

    /** Refuse un site hors de l'entreprise, ou hors de l'affectation du membre. */
    public static function verifier(?int $pointDeVenteId): void
    {
        $utilisateur = Auth::user();
        if (!$utilisateur || $utilisateur->estSuperAdmin()) {
            return;
        }

        abort_unless(
            $pointDeVenteId && PointDeVente::where('entreprise_id', $utilisateur->entreprise_id)->whereKey($pointDeVenteId)->exists(),
            403,
            'Accès refusé : ce point de vente n’appartient pas à votre entreprise.'
        );

        abort_if(
            $utilisateur->estAffecteAUnSite() && (int) $utilisateur->point_de_vente_id !== (int) $pointDeVenteId,
            403,
            'Accès refusé : ce point de vente n’est pas le vôtre.'
        );
    }
}
