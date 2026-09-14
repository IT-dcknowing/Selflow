<?php

namespace App\Modules\Authentification\Controleurs;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Modules\Admin\Traits\JournaliseActions;

class ConnexionControleur
{
    use JournaliseActions;
    /**
     * Afficher le formulaire de connexion.
     */
    public function afficher(): View
    {
        return view('authentification::connexion');
    }

    /**
     * Afficher la page de contact et d'informations DC-KNOWING.
     */
    public function contact(): View
    {
        return view('authentification::contact');
    }


    /**
     * Traiter la tentative de connexion de manière sécurisée.
     *
     * @throws ValidationException
     */
    public function connecter(Request $request): RedirectResponse
    {
        $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required'    => 'L\'adresse email est obligatoire.',
            'email.email'       => 'L\'adresse email n\'est pas valide.',
            'password.required' => 'Le mot de passe est obligatoire.',
        ]);

        // Protection contre les attaques par force brute (5 tentatives / minute)
        $this->verifierLimiteTentatives($request);

        $identifiants = [
            'email'    => $request->email,
            'password' => $request->password,
        ];

        if (! Auth::attempt($identifiants, $request->boolean('se_souvenir'))) {
            // Incrémenter le compteur de tentatives
            RateLimiter::hit($this->cléThrottling($request));

            // Journaliser la tentative échouée
            $this->journaliser('connexion_echec', 'Utilisateur', null, null, ['email' => $request->email]);

            throw ValidationException::withMessages([
                'connexion_erreur' => 'Les informations de connexion saisies sont incorrectes.',
            ]);
        }

        // Vérifier que le compte est actif
        if (Auth::user()->statut !== 'actif') {
            Auth::logout();
            throw ValidationException::withMessages([
                'connexion_erreur' => 'Votre compte est désactivé. Veuillez contacter le service client.',
            ]);
        }

        // Réinitialiser le compteur de tentatives après une connexion réussie
        RateLimiter::clear($this->cléThrottling($request));

        $request->session()->regenerate();

        // Le point de vente choisi ne vivait qu'en session, et la déconnexion
        // l'effaçait avec elle. Un responsable de trois magasins repartait donc
        // chaque matin sur le premier venu — et le point de vente est ce que la
        // plateforme de la DGI reçoit : une pièce certifiée sous le mauvais
        // magasin ne se corrige pas, elle s'annule par un avoir.
        $this->reprendreLePointDeVente();

        // Le portail FNE a-t-il été lu récemment ? Sinon, on va voir — en
        // arrière-plan, sans retarder d'une seconde l'ouverture de la session.
        // Le passage horaire n'y va que si une pièce a été refusée, et le
        // passage complet attend 02:30 : un point de facturation déclaré au
        // portail à midi n'était visible que le lendemain matin. Le service
        // pose son propre verrou et ne lève jamais.
        \App\Modules\Admin\Services\ScraperPortailFneService::relancerSiLeReleveEstVieux(
            Auth::user()->entreprise
        );

        // Journaliser la connexion réussie
        $this->journaliser('connexion', 'Utilisateur', Auth::id());

        // Rediriger selon le rôle de l'utilisateur
        $role = Auth::user()->role;

        return match ($role) {
            'superadmin' => redirect()->intended(route('superadmin.tableau_de_bord')),
            'admin'      => redirect()->intended(route('admin.tableau_de_bord')),
            'caissier'   => redirect()->intended(route('caissier.tableau_de_bord')),
            default      => redirect()->route('connexion'),
        };
    }

    /**
     * Rouvrir la session sur le point de vente d'hier.
     *
     * Le point de vente reste **vérifié à chaque reprise** : celui qui a été
     * retenu peut avoir été supprimé, ou rattaché depuis à une autre
     * entreprise. On ne réouvre que ce qui appartient encore à l'utilisateur.
     */
    private function reprendreLePointDeVente(): void
    {
        $utilisateur = Auth::user();
        $souhaite    = $utilisateur->pointDeVenteDOuverture();

        if (!$souhaite) {
            return;
        }

        $pdv = \App\Modules\Admin\Modeles\PointDeVente::where('entreprise_id', $utilisateur->entreprise_id)
            ->find($souhaite);

        if (!$pdv) {
            return;
        }

        session([
            'point_de_vente_actif_id'  => $pdv->id,
            'point_de_vente_actif_nom' => $pdv->nom,
        ]);
    }

    /**
     * Déconnecter l'utilisateur de l'application.
     */
    public function deconnecter(Request $request): RedirectResponse
    {
        // Journaliser la déconnexion
        $userId = Auth::id();
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->journaliser('deconnexion', 'Utilisateur', $userId);

        return redirect()->route('connexion');
    }

    /**
     * Vérifier la limite de tentatives de connexion.
     *
     * @throws ValidationException
     */
    private function verifierLimiteTentatives(Request $request): void
    {
        $cle = $this->cléThrottling($request);

        if (! RateLimiter::tooManyAttempts($cle, 5)) {
            return;
        }

        $secondes = RateLimiter::availableIn($cle);

        throw ValidationException::withMessages([
            'connexion_erreur' => sprintf(
                'Trop de tentatives de connexion. Veuillez réessayer dans %d secondes.',
                $secondes
            ),
        ]);
    }

    /**
     * Générer une clé unique pour le throttling.
     */
    private function cléThrottling(Request $request): string
    {
        return Str::transliterate(
            Str::lower($request->input('email')) . '|' . $request->ip()
        );
    }
}
