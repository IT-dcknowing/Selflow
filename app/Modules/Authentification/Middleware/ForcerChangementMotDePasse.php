<?php

namespace App\Modules\Authentification\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware : Force le changement de mot de passe à la première connexion.
 *
 * Si l'utilisateur connecté a le flag doit_changer_password = true,
 * il est redirigé vers la page de changement de mot de passe obligatoire,
 * quelle que soit la route qu'il tente d'accéder.
 */
class ForcerChangementMotDePasse
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = auth()->user();

        // En assistance, le superadministrateur n'a pas à changer le mot de
        // passe du client à sa place (19/10/2026).
        if ($utilisateur && $utilisateur->doit_changer_password && ! session()->has('incarnation')) {
            // Autoriser uniquement les routes de changement de mot de passe
            // pour éviter une boucle de redirection infinie
            $routesAutorisees = [
                'password.changer.afficher',
                'password.changer.traiter',
                'deconnexion',
            ];

            if (!in_array($request->route()?->getName(), $routesAutorisees)) {
                return redirect()->route('password.changer.afficher')
                    ->with('info', 'Vous devez définir un nouveau mot de passe avant de continuer.');
            }
        }

        return $next($request);
    }
}
