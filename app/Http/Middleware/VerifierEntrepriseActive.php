<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerifierEntrepriseActive
{
    /**
     * Handle an incoming request.
     *
     * If the user is logged in, and their company is blocked, log them out
     * and redirect back with a status error message.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Même une entreprise suspendue doit pouvoir quitter une session
        // d'assistance. Le contrôle d'état ne doit jamais enfermer le superadmin
        // dans l'identité de l'entreprise qu'il est en train d'aider.
        if ($request->routeIs('assistance.quitter*')) {
            return $next($request);
        }

        if (Auth::check()) {
            $user = Auth::user();
            
            // Le superadmin n'est jamais bloqué par le statut d'une entreprise
            if (!$user->estSuperAdmin()) {
                $entreprise = $user->entreprise;
                // En corbeille : supprimée côté plateforme, l'accès est coupé
                // jusqu'à la restauration (16/10/2026).
                if ($entreprise && $entreprise->estEnCorbeille()) {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('connexion')->withErrors([
                        'email' => 'Votre espace a été fermé. Pour le rouvrir, contactez le support.',
                    ]);
                }
                if ($entreprise && $entreprise->statut === 'en_attente') {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    return redirect()->route('connexion')->withErrors([
                        'email' => 'Votre inscription est en attente de validation par notre équipe.',
                    ]);
                }
                if ($entreprise && $entreprise->statut === 'bloque') {
                    Auth::logout();
                    
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    
                    return redirect()->route('connexion')->withErrors([
                        'email' => 'Votre entreprise a été suspendue/bloquée. Veuillez contacter le support.'
                    ]);
                }
            }
        }

        return $next($request);
    }
}
