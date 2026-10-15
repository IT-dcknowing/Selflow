<?php

namespace App\Modules\Authentification\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerifierInscriptionComplete
{
    /**
     * Empêcher ventes, achats et devis tant que l'entreprise n'a aucun point de vente.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->route('connexion');
        }

        $utilisateur = Auth::user();

        // Le SuperAdmin n'est pas bloqué par cette restriction
        if ($utilisateur->estSuperAdmin()) {
            return $next($request);
        }

        $entreprise = $utilisateur->entreprise;

        // Ventes et achats ne sont plus retenus par la situation fiscale : on
        // peut stocker, acheter, vendre et facturer sans NCC ni régime, et
        // c'est la normalisation qui réclame ce qui lui manque (retour de
        // production du 15/10/2026). Seul le point de vente bloque encore la
        // saisie, chaque pièce s'y rattachant.
        if ($entreprise && $entreprise->elementsBloquantsLaSaisie() !== []) {
            if ($request->is('admin/ventes*') || $request->is('admin/achats*')) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'statut'  => 'incomplet',
                        'message' => 'Créez d\'abord un point de vente : chaque pièce s\'y rattache.',
                    ], 403);
                }

                return redirect()->route('admin.tableau_de_bord')
                    ->with('ouvrir_modale_incomplet', true);
            }
        }

        return $next($request);
    }
}
