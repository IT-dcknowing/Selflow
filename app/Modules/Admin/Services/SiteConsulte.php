<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\PointDeVente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Le point de vente que l'on consulte sur un écran de suivi (20/10/2026) :
 * créances, clients, fournisseurs.
 *
 * - un membre affecté à un site ne voit que le sien ;
 * - les autres choisissent « tous les points de vente » ou un seul
 *   (`?site=tout` ou `?site=<id>`) ; par défaut, le site actif.
 *
 * `null` veut dire « tous ».
 */
class SiteConsulte
{
    public static function resoudre(Request $request): ?int
    {
        $utilisateur = Auth::user();
        if (!$utilisateur?->entreprise_id) {
            return null;
        }

        if ($utilisateur->estAffecteAUnSite()) {
            return (int) $utilisateur->point_de_vente_id;
        }

        $choix = $request->input('site', session('point_de_vente_actif_id') ?: 'tout');
        if ($choix === 'tout' || $choix === null || $choix === '') {
            return null;
        }

        return PointDeVente::where('entreprise_id', $utilisateur->entreprise_id)->whereKey((int) $choix)->exists()
            ? (int) $choix
            : null;
    }

    /** Ce que le filtre affiche : les sites, le choix courant, et s'il est verrouillé. */
    public static function pourLaVue(?int $site): array
    {
        $utilisateur = Auth::user();

        return [
            'sites'      => PointDeVente::where('entreprise_id', $utilisateur?->entreprise_id)->orderBy('nom')->get(['id', 'nom']),
            'site'       => $site,
            'verrouille' => (bool) $utilisateur?->estAffecteAUnSite(),
        ];
    }

    /** Le site actif de la session, s'il appartient bien à l'entreprise donnée. */
    public static function actifPour(?int $entrepriseId): ?int
    {
        $actif = (int) session('point_de_vente_actif_id');
        if (!$actif || !$entrepriseId) {
            return null;
        }

        return PointDeVente::where('entreprise_id', $entrepriseId)->whereKey($actif)->exists() ? $actif : null;
    }
}
