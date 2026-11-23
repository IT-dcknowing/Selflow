<?php

namespace App\Http\Middleware;

use App\Modules\Admin\Modeles\EspaceSurMesure;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selflow sur mesure (23/11/2026) : l'adresse demandée désigne l'entreprise.
 *
 * Pas de redirection : le domaine de l'entreprise pointe sur ce serveur, et
 * c'est ici qu'on reconnaît à qui il appartient (correspondance en cache, sans
 * requête). L'adresse reste la sienne du début à la fin.
 *
 * Sur cette adresse, seuls les utilisateurs de l'entreprise (et le
 * superadministrateur) restent connectés.
 */
class ReconnaitreEspaceSurMesure
{
    public function handle(Request $request, Closure $next): Response
    {
        $espace = EspaceSurMesure::pourHote($request->getHost());

        app()->instance('espace.sur_mesure', $espace);
        View::share('espaceSurMesure', $espace);

        if ($espace && Auth::check()) {
            $utilisateur = Auth::user();
            $estSuperAdmin = method_exists($utilisateur, 'estSuperAdmin') && $utilisateur->estSuperAdmin();

            if (! $estSuperAdmin && (int) $utilisateur->entreprise_id !== (int) $espace->entreprise_id) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('connexion')->withErrors([
                    'connexion_erreur' => 'Ce compte n\'appartient pas à cet espace.',
                ]);
            }
        }

        return $next($request);
    }
}
