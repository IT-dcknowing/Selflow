<?php

namespace App\Http\Middleware;

use App\Modules\Admin\Modeles\ParametrePlateforme;
use App\Modules\Admin\Regles\MiseHorsLigne;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plateforme mise hors ligne par le superadministrateur : tout le monde voit la
 * page de maintenance évolutive, sauf lui. La connexion reste ouverte, sans
 * quoi il ne pourrait plus entrer pour rallumer.
 */
class BloquerSiHorsLigne
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!MiseHorsLigne::active()) {
            return $next($request);
        }

        if ($request->routeIs('connexion', 'connexion.traitement', 'deconnexion')
            || $request->user()?->role === 'superadmin') {
            return $next($request);
        }

        $etat = MiseHorsLigne::etat();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Selflow est en maintenance évolutive. Réessayez dans un moment.',
            ], 503, ['Retry-After' => '600']);
        }

        return response()->view('errors.hors-ligne', [
            'message'    => $etat['message'],
            'assistance' => [
                'email'     => ParametrePlateforme::lire(ParametrePlateforme::ASSISTANCE_EMAIL),
                'telephone' => ParametrePlateforme::lire(ParametrePlateforme::ASSISTANCE_TELEPHONE),
            ],
        ], 503, ['Retry-After' => '600']);
    }
}
