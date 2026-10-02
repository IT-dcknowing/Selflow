<?php

namespace App\Modules\Authentification\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les écrans comptables ne s'ouvrent qu'à qui a demandé la comptabilité.
 *
 * Décision du propriétaire, le 02/10/2026 : Selflow est vente, achat,
 * facturation, stock. La comptabilité intervient pour ceux qui la veulent.
 *
 * ## Pourquoi un middleware, et pas seulement le menu
 *
 * Retirer un lien de la barre latérale ne ferme rien : **un lien masqué reste
 * une adresse qu'on peut taper**, ou qui dort dans un signet, ou dans
 * l'historique d'un navigateur. Le menu dit ce qu'on propose ; le middleware
 * dit ce qu'on autorise.
 *
 * ## Pourquoi 404 et non 403
 *
 * `modules:comptabilite`, juste à côté, répond **403 (Forbidden — accès
 * interdit)** avec « Le module Comptabilité n'est pas activé pour votre
 * entreprise ». C'est juste pour un module qu'on a fermé soi-même et qu'on
 * peut rouvrir.
 *
 * Ici, non : l'entreprise n'a rien fermé, elle n'a jamais eu ces écrans. Lui
 * dire « interdit » la ferait chercher un droit manquant, appeler le support,
 * fouiller ses habilitations. **404 (Not Found — introuvable)** dit la vérité
 * de son point de vue : cet écran n'existe pas pour elle.
 *
 * C'est la même distinction qu'au lot 8 : un refus d'appartenance rend 404,
 * un refus d'habilitation rend 403.
 */
class VerifierComptabiliteOuverte
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
            return redirect()->route('connexion');
        }

        $utilisateur = Auth::user();

        // Le superadministrateur tient les dossiers de toutes les entreprises.
        if ($utilisateur->estSuperAdmin()) {
            return $next($request);
        }

        $entreprise = $utilisateur->entreprise;

        if (!$entreprise || !$entreprise->comptabiliteOuverte()) {
            abort(404);
        }

        return $next($request);
    }
}
