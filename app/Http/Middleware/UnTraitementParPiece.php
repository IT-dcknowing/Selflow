<?php

namespace App\Http\Middleware;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\Vente;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une pièce ne se transforme qu'une fois à la fois (26/10/2026).
 *
 * « → Commande », « Valider & Facturer », facturer un bon de livraison, créer
 * un avoir, annuler… Chaque action vérifie bien que la pièce n'est pas déjà
 * transformée, mais deux clics simultanés passaient la vérification
 * ensemble : deux factures pour une même commande, deux avoirs pour une même
 * annulation.
 *
 * Toute requête qui modifie une vente, un achat ou un bon de livraison
 * désigné dans l'adresse prend un verrou sur cette pièce pour la durée du
 * traitement. Le second clic, arrivé pendant ce temps, est refusé sans rien
 * écrire. La pièce est relue une fois le verrou pris : l'état vérifié par le
 * contrôleur est celui qu'a laissé le traitement précédent.
 */
class UnTraitementParPiece
{
    private const DUREE_SECONDES = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if ($request->isMethodSafe() || !$route) {
            return $next($request);
        }

        $verrous = [];
        foreach ($route->parameters() as $nom => $valeur) {
            if (!($valeur instanceof Vente || $valeur instanceof Achat || $valeur instanceof BonLivraison)) {
                continue;
            }

            $verrou = Cache::lock('piece:' . class_basename($valeur) . ':' . $valeur->getKey(), self::DUREE_SECONDES);
            if (!$verrou->get()) {
                foreach ($verrous as $pris) {
                    $pris->release();
                }

                return $this->refus($request);
            }
            $verrous[] = $verrou;

            // L'état d'après le traitement précédent, et non celui lu avant
            // le verrou.
            $route->setParameter($nom, $valeur->fresh() ?? $valeur);
        }

        try {
            return $next($request);
        } finally {
            foreach ($verrous as $verrou) {
                $verrou->release();
            }
        }
    }

    private function refus(Request $request): Response
    {
        $message = 'Cette pièce est déjà en cours de traitement : le second clic a été ignoré, rien n\'a été enregistré en double.';

        return $request->expectsJson()
            ? response()->json(['statut' => 'en_cours', 'message' => $message], 409)
            : back()->with('info', $message);
    }
}
