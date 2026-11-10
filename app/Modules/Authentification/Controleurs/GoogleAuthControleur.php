<?php

namespace App\Modules\Authentification\Controleurs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use App\Modules\Admin\Services\TrousseauEntrepriseService;

class GoogleAuthControleur
{
    /**
     * Rediriger vers Google pour l'authentification.
     */
    public function rediriger(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Traiter le retour de Google (callback).
     */
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect()->route('connexion')
                ->withErrors(['connexion_erreur' => 'Authentification Google échouée. Veuillez réessayer.']);
        }

        // 1. Chercher un utilisateur existant avec cet email
        $utilisateur = Utilisateur::where('email', $googleUser->getEmail())->first();

        if ($utilisateur) {
            // ── Connexion directe ──────────────────────────────────────────
            if ($utilisateur->statut === 'en_attente') {
                return redirect()->route('connexion')
                    ->withErrors(['connexion_erreur' => 'Votre inscription est en attente de validation par notre équipe.']);
            }
            if ($utilisateur->statut !== 'actif') {
                return redirect()->route('connexion')
                    ->withErrors(['connexion_erreur' => 'Votre compte est désactivé. Veuillez contacter le service client.']);
            }

            Auth::login($utilisateur, true);
            request()->session()->regenerate();

            return $this->redirigerSelonRole($utilisateur);
        }

        // 2. Nouveau compte — créer entreprise + admin ─────────────────────
        DB::beginTransaction();
        try {
            // Décomposer le nom complet Google
            $nomComplet = $googleUser->getName() ?? '';
            $parties    = explode(' ', trim($nomComplet), 2);
            $prenom     = $parties[0] ?? 'Utilisateur';
            $nom        = $parties[1] ?? 'Selflow';

            // Créer entreprise temporaire [PENDING_ONBOARDING] pour le flux d'onboarding
            $entreprise = Entreprise::create([
                // Normalisation automatique décochée par défaut (16/10/2026).
                ...Entreprise::REGLAGES_DE_DEPART,
                'nom'                 => '[PENDING_ONBOARDING]',
                'email'               => $googleUser->getEmail(),
                'modules_actifs'      => ['principal', 'ventes', 'achats', 'stock', 'production', 'comptabilite', 'points_de_vente', 'personnel', 'b2b', 'fne'],
                'secteur_activite'    => [],
                'quota_points_de_vente' => \App\Modules\Admin\Regles\Abonnements::QUOTA_PAR_DEFAUT,
                'plan_abonnement'     => \App\Modules\Admin\Regles\Abonnements::A_CHOISIR,
                'statut'              => 'en_attente',
            ]);
            $entreprise->forceFill(['quota_utilisateurs' => \App\Modules\Admin\Regles\Abonnements::QUOTA_PAR_DEFAUT])->save();

            // Sans plan comptable ni journal, la premiere vente s'impute sur des
            // comptes inventes a la volee. L'entreprise recoit donc de quoi
            // travailler des le premier jour ; ce qui ne lui sert pas, elle
            // l'archivera.
            TrousseauEntrepriseService::doter($entreprise);


            // Créer l'utilisateur admin
            $utilisateur = Utilisateur::create([
                'entreprise_id'         => $entreprise->id,
                'nom'                   => strtoupper($nom),
                'prenom'                => $prenom,
                'email'                 => $googleUser->getEmail(),
                'password'              => Hash::make(Str::random(32)), // mdp aléatoire — compte Google
                'role'                  => 'admin',
                'statut'                => 'en_attente',
                'doit_changer_password' => false,
                'avatar_path'           => $googleUser->getAvatar(),
            ]);

            DB::commit();

            try {
                \App\Modules\Admin\Services\AlerteSuperadminService::alerter(
                    \App\Modules\Admin\Services\AlerteSuperadminService::CREATION_ENTREPRISE,
                    'Nouvelle entreprise inscrite via Google : ' . $entreprise->nom,
                    "Entreprise : {$entreprise->nom}\nAdministrateur : {$prenom} {$nom}\nCourriel : {$utilisateur->email}\nLe " . now()->format('d/m/Y à H:i') . '.'
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Alerte inscription Google non envoyée : ' . $e->getMessage());
            }

            return redirect()->route('connexion')
                ->with('inscription_en_attente', 'Votre demande d’inscription Google a bien été reçue. Votre espace sera accessible après validation par notre équipe.');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('inscription')
                ->withErrors(['inscription_erreur' => 'Impossible de créer votre compte Google : ' . $e->getMessage()]);
        }
    }

    /**
     * Rediriger selon le rôle.
     */
    private function redirigerSelonRole(Utilisateur $utilisateur): RedirectResponse
    {
        return match ($utilisateur->role) {
            'superadmin' => redirect()->route('superadmin.tableau_de_bord'),
            'admin'      => redirect()->route('admin.tableau_de_bord'),
            'caissier'   => redirect()->route('caissier.tableau_de_bord'),
            default      => redirect()->route('connexion'),
        };
    }
}
