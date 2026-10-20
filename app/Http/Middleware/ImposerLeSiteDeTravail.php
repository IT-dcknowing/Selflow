<?php

namespace App\Http\Middleware;

use App\Modules\Admin\Modeles\PointDeVente;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le site sur lequel on travaille (retour du 19/10/2026).
 *
 * « Le point de vente activé ne doit pas servir qu'à normaliser » : ce qu'on
 * saisit — ventes, entrées en stock, inventaire, encaissements — concerne le
 * site actif. Deux règles, posées ici une fois pour tous les écrans :
 *
 * - **un membre affecté à un site** (caissier, responsable de point de vente)
 *   y est lié : la session porte toujours son site, et un formulaire qui
 *   désigne un autre site (`point_de_vente_id`, `site_id`) est ramené au
 *   sien. Il peut transférer vers un autre dépôt, pas agir depuis lui ;
 * - **le site actif doit appartenir à l'entreprise** : un site resté en
 *   session après un changement de compte est oublié.
 */
class ImposerLeSiteDeTravail
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (!$utilisateur || $utilisateur->estSuperAdmin() || !$utilisateur->entreprise_id) {
            return $next($request);
        }

        if ($utilisateur->estAffecteAUnSite()) {
            $site = PointDeVente::where('entreprise_id', $utilisateur->entreprise_id)->find($utilisateur->point_de_vente_id);

            if ($site) {
                if ((int) session('point_de_vente_actif_id') !== (int) $site->id) {
                    session(['point_de_vente_actif_id' => $site->id, 'point_de_vente_actif_nom' => $site->nom]);
                }

                foreach (['point_de_vente_id', 'site_id'] as $champ) {
                    if ($request->filled($champ) && (string) $request->input($champ) !== (string) $site->id) {
                        $request->merge([$champ => $site->id]);
                        $request->query->set($champ, $site->id);
                    }
                }
            }

            return $next($request);
        }

        $actif = session('point_de_vente_actif_id');
        if ($actif && $actif !== 'tout'
            && !PointDeVente::where('entreprise_id', $utilisateur->entreprise_id)->whereKey($actif)->exists()) {
            session()->forget(['point_de_vente_actif_id', 'point_de_vente_actif_nom']);
            $actif = null;
        }

        // Un seul site : il est actif d'office, sans rien demander
        // (20/10/2026). Plusieurs : la page propose de choisir.
        if (!$actif) {
            $sites = PointDeVente::where('entreprise_id', $utilisateur->entreprise_id)->limit(2)->get(['id', 'nom']);
            if ($sites->count() === 1) {
                session(['point_de_vente_actif_id' => $sites[0]->id, 'point_de_vente_actif_nom' => $sites[0]->nom]);
            }
        }

        return $next($request);
    }
}
