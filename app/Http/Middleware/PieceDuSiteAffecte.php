<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un membre affecté à un site ne touche que les pièces de son site.
 *
 * L'audit du 23/11/2026 a trouvé des actions par identifiant — `{vente}`,
 * `{achat}`, `{bl}`, `{ordre}`, `{transfert}`, `{proposition}`… — qui ne
 * vérifiaient que l'entreprise : un responsable de Marcory validait la
 * livraison d'une vente de Plateau, et le stock de Plateau sortait.
 *
 * Une garde unique, posée sur les espaces admin et caissier : toute pièce de
 * l'adresse qui porte un point de vente doit être celle du site du membre.
 * Un transfert passe s'il part de son site ou y arrive. Les administrateurs
 * d'entreprise, qui voient tous leurs sites, ne sont pas concernés : le
 * cloisonnement entre entreprises reste celui des contrôleurs.
 */
class PieceDuSiteAffecte
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (!$utilisateur || !$utilisateur->estAffecteAUnSite()) {
            return $next($request);
        }

        $site = (int) $utilisateur->point_de_vente_id;

        foreach ($request->route()?->parameters() ?? [] as $piece) {
            if (!$piece instanceof Model) {
                continue;
            }

            $attributs = $piece->getAttributes();

            if (array_key_exists('point_de_vente_source_id', $attributs)) {
                $permis = in_array($site, [(int) $piece->point_de_vente_source_id, (int) $piece->point_de_vente_destination_id], true);
            } elseif (array_key_exists('point_de_vente_id', $attributs) && $piece->point_de_vente_id !== null) {
                $permis = (int) $piece->point_de_vente_id === $site;
            } else {
                continue;
            }

            abort_unless($permis, 403, 'Accès refusé : cette pièce appartient à un autre point de vente.');
        }

        return $next($request);
    }
}
