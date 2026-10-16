<?php

namespace App\Modules\Authentification\Controleurs\Api;

use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConnexionApiControleur
{
    /**
     * Traiter la tentative de connexion et retourner un jeton API.
     */
    public function connecter(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required'    => 'L\'adresse email est obligatoire.',
            'email.email'       => 'L\'adresse email n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
        ]);

        // Protection contre brute force
        $cleThrottling = $this->cléThrottling($request);
        if (RateLimiter::tooManyAttempts($cleThrottling, 5)) {
            $secondes = RateLimiter::availableIn($cleThrottling);
            return response()->json([
                'statut' => 'erreur',
                'message' => sprintf('Trop de tentatives de connexion. Veuillez réessayer dans %d secondes.', $secondes)
            ], 429);
        }

        // Trouver l'utilisateur
        $utilisateur = Utilisateur::where('email', $request->email)->first();

        if (!$utilisateur || !Hash::check($request->password, $utilisateur->password)) {
            RateLimiter::hit($cleThrottling);
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Identifiants incorrects. Veuillez vérifier votre email et mot de passe.'
            ], 401);
        }

        // Vérifier si actif
        if ($utilisateur->statut !== 'actif') {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Votre compte est désactivé. Contactez votre administrateur.'
            ], 403);
        }

        // Réinitialiser la limite
        RateLimiter::clear($cleThrottling);

        // Générer un jeton d'accès unique
        $jeton = Str::random(80);
        // Seule l'empreinte est gardée en base (audit du 16/10/2026) : une
        // copie de la base ne livre plus de quoi se connecter.
        $utilisateur->update([
            'jeton_api' => hash('sha256', $jeton)
        ]);

        return response()->json([
            'statut' => 'succes',
            'token' => $jeton,
            'utilisateur' => [
                'id' => $utilisateur->id,
                'nom' => $utilisateur->nom,
                'prenom' => $utilisateur->prenom,
                'email' => $utilisateur->email,
                'role' => $utilisateur->role,
                'fonction' => $utilisateur->fonction,
                'statut' => $utilisateur->statut,
                'point_de_vente_id' => $utilisateur->point_de_vente_id,
                'entreprise_id' => $utilisateur->entreprise_id,
                'habilitations' => $utilisateur->habilitations ?? []
            ],
            'entreprise' => $utilisateur->entreprise ? [
                'id' => $utilisateur->entreprise->id,
                'nom' => $utilisateur->entreprise->nom,
                'plan_abonnement' => $utilisateur->entreprise->plan_abonnement,
                'quota_points_de_vente' => $utilisateur->entreprise->quota_points_de_vente,
                 'adresse' => $utilisateur->entreprise->adresse,
                'telephone' => $utilisateur->entreprise->telephone,
                'email' => $utilisateur->entreprise->email,
                'ncc' => $utilisateur->entreprise->ncc,
                'compte_contribuable' => $utilisateur->entreprise->compte_contribuable,
                'rccm' => $utilisateur->entreprise->rccm
            ] : null
        ]);
    }

    /**
     * Déconnecter l'utilisateur en invalidant son jeton API.
     */
    public function deconnecter(Request $request): JsonResponse
    {
        $utilisateur = Auth::user();
        
        if ($utilisateur) {
            $utilisateur->update([
                'jeton_api' => null
            ]);
        }

        return response()->json([
            'statut' => 'succes',
            'message' => 'Déconnexion réussie.'
        ]);
    }

    /**
     * Clé unique pour le throttling.
     */
    private function cléThrottling(Request $request): string
    {
        return Str::transliterate(
            Str::lower($request->input('email')) . '|' . $request->ip()
        );
    }
}
