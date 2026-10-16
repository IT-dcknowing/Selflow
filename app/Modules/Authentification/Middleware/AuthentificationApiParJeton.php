<?php

namespace App\Modules\Authentification\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Modules\Authentification\Modeles\Utilisateur;
use Symfony\Component\HttpFoundation\Response;

class AuthentificationApiParJeton
{
    /**
     * Gérer la requête d'API sécurisée par jeton.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');

        if (!$authorization || !str_starts_with($authorization, 'Bearer ')) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Non authentifié. Jeton manquant ou mal formé.'
            ], 401);
        }

        $jeton = substr($authorization, 7);

        // La base ne garde que l'empreinte du jeton (audit du 16/10/2026). Un
        // jeton délivré avant, conservé en clair, est encore reconnu une fois
        // et remplacé par son empreinte : l'application mobile n'a pas à se
        // reconnecter.
        $empreinte = hash('sha256', $jeton);
        $utilisateur = Utilisateur::where('jeton_api', $empreinte)
            ->where('statut', 'actif')
            ->first();

        // Jamais pour une valeur au format d'une empreinte : sinon l'empreinte
        // lue dans une copie de la base servirait elle-même de jeton.
        if (!$utilisateur && $jeton !== '' && !preg_match('/^[0-9a-f]{64}$/', $jeton)) {
            $utilisateur = Utilisateur::where('jeton_api', $jeton)
                ->where('statut', 'actif')
                ->first();
            $utilisateur?->forceFill(['jeton_api' => $empreinte])->save();
        }

        if (!$utilisateur) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Non authentifié. Jeton d\'accès invalide ou compte inactif.'
            ], 401);
        }

        // Authentifier l'utilisateur pour la requête courante
        Auth::login($utilisateur);

        return $next($request);
    }
}
