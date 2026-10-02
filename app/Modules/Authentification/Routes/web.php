<?php

use App\Modules\Authentification\Controleurs\ConnexionControleur;
use Illuminate\Support\Facades\Route;

// -----------------------------------------------------------------------
// La vitrine — page de présentation publique.
//
// Hors du groupe `guest` : elle s'adresse à tout le monde, y compris à un
// utilisateur déjà connecté qui reviendrait la lire. La mettre derrière
// `guest` l'aurait renvoyé vers son tableau de bord au lieu de la lui
// montrer.
// -----------------------------------------------------------------------
Route::get('/presentation', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'accueil'])
    ->name('vitrine');

/*
 * Les images de la vitrine, servies quand `public/storage` n'est pas
 * utilisable.
 *
 * Elles passaient par `admin.media`, qui vit derrière `auth` et
 * `role:admin`. Sur l'hébergement mutualisé — celui-là même où le lien
 * manque, et donc le seul où cette route sert — **la page de présentation
 * s'affichait sans aucune de ses images pour un visiteur anonyme** : la
 * vitrine, dont tout l'objet est d'être vue par qui n'a pas de compte.
 *
 * Elles ont leur propre porte, publique, parce qu'elles n'appartiennent à
 * aucune entreprise : c'est le superadministrateur qui les dépose, et elles
 * sont montrées à tout le monde. Les trois autres dossiers — logos, photos
 * d'articles, avatars — restent derrière `admin.media`, qui vérifie
 * désormais à qui le fichier appartient.
 */
Route::get('/presentation/media/{fichier}', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'servirUnMedia'])
    ->where('fichier', '.*')
    ->name('vitrine.media');

// -----------------------------------------------------------------------
// Routes publiques (accès sans authentification)
// -----------------------------------------------------------------------
Route::middleware('guest')->group(function () {
    Route::get('/connexion', [ConnexionControleur::class, 'afficher'])
        ->name('connexion');

    Route::post('/connexion', [ConnexionControleur::class, 'connecter'])
        ->name('connexion.traitement');

    // ── Inscription ──
    Route::get('/inscription', [\App\Modules\Authentification\Controleurs\InscriptionControleur::class, 'afficher'])
        ->name('inscription');

    Route::post('/inscription', [\App\Modules\Authentification\Controleurs\InscriptionControleur::class, 'inscrire'])
        ->name('inscription.traitement');

    // ── Google OAuth (Socialite) ──
    // Redirection vers Google
    Route::get('/auth/google', [\App\Modules\Authentification\Controleurs\GoogleAuthControleur::class, 'rediriger'])
        ->name('auth.google');

    // Callback local dev : http://127.0.0.1:8003/auth/google/callback
    Route::get('/auth/google/callback', [\App\Modules\Authentification\Controleurs\GoogleAuthControleur::class, 'callback'])
        ->name('auth.google.callback');

    // Callback production : https://selflow.dc-knowing.com/auth/callback
    Route::get('/auth/callback', [\App\Modules\Authentification\Controleurs\GoogleAuthControleur::class, 'callback'])
        ->name('auth.callback');

    // Page de contact & documentation d'informations DC-KNOWING
    Route::get('/contact', [ConnexionControleur::class, 'contact'])
        ->name('contact.info');

    // Mot de passe oublié
    Route::get('/mot-de-passe/oublie', [\App\Modules\Authentification\Controleurs\PasswordResetControleur::class, 'afficherDemande'])
        ->name('password.request');

    // Demander un lien envoie un courriel. Sans borne, on inonde la boîte d'un
    // utilisateur, on épuise le quota du serveur d'envoi, et l'on apprend au
    // passage quelles adresses existent.
    Route::post('/mot-de-passe/oublie', [\App\Modules\Authentification\Controleurs\PasswordResetControleur::class, 'envoyerLien'])
        ->middleware('throttle:mot-de-passe')
        ->name('password.email');

    Route::get('/mot-de-passe/reinitialiser/{token}', [\App\Modules\Authentification\Controleurs\PasswordResetControleur::class, 'afficherReset'])
        ->name('password.reset');

    // Le jeton de réinitialisation est une chaîne : sans borne, il s'éprouve.
    Route::post('/mot-de-passe/reinitialiser', [\App\Modules\Authentification\Controleurs\PasswordResetControleur::class, 'reinitialiser'])
        ->middleware('throttle:mot-de-passe')
        ->name('password.update');
});

// -----------------------------------------------------------------------
// Déconnexion (accès authentifié uniquement)
// -----------------------------------------------------------------------
Route::post('/deconnexion', [ConnexionControleur::class, 'deconnecter'])
    ->name('deconnexion')
    ->middleware('auth');

// -----------------------------------------------------------------------
// Changement de mot de passe obligatoire (accès authentifié uniquement)
// -----------------------------------------------------------------------
Route::middleware('auth')->group(function () {
    Route::get('/changer-mot-de-passe', [\App\Modules\Authentification\Controleurs\ChangementMotDePasseControleur::class, 'afficher'])
        ->name('password.changer.afficher');

    Route::post('/changer-mot-de-passe', [\App\Modules\Authentification\Controleurs\ChangementMotDePasseControleur::class, 'traiter'])
        ->name('password.changer.traiter');
});

// -----------------------------------------------------------------------
// Redirection de la racine vers connexion ou tableau de bord
// -----------------------------------------------------------------------
Route::get('/', function () {
    // **La porte d'entrée est la vitrine, pas le formulaire de connexion.**
    //
    // Un visiteur qui tape l'adresse tombait directement sur la page de
    // connexion : la présentation existait, à `/presentation`, mais rien n'y
    // menait — il fallait connaître l'adresse pour la lire, ce qui est le
    // contraire d'une vitrine.
    if (! auth()->check()) {
        return app(\App\Modules\Admin\Controleurs\VitrineControleur::class)->accueil();
    }

    $utilisateur = auth()->user();
    $role        = $utilisateur->role;

    if ($utilisateur->estSuperAdmin()) {
        return redirect()->route('superadmin.tableau_de_bord');
    }

    // Le propriétaire **et ses délégués** : le même espace. Un accès délégué
    // est un accès à l'espace de celui qui l'a créé — ce sont les
    // habilitations qui décident ensuite de ce qu'on y voit.
    //
    // Ces comptes n'étaient rattachés à rien : `role:admin` comparait à
    // l'identique, et la racine les renvoyait vers `connexion`, page réservée
    // aux visiteurs, qui les renvoyait ici. Le navigateur s'arrêtait au bout
    // de vingt allers-retours sur `ERR_TOO_MANY_REDIRECTS`.
    if ($utilisateur->partageLEspaceAdmin()) {
        return redirect()->route('admin.tableau_de_bord');
    }

    if ($utilisateur->estCaissier()) {
        return redirect()->route('caissier.tableau_de_bord');
    }

    // **Ne jamais renvoyer un utilisateur connecté vers `connexion`.** Un
    // message vaut mieux qu'une boucle : il dit ce qui manque au lieu de
    // laisser croire à une panne du navigateur.
    abort(403, "Votre compte porte le rôle « {$role} », auquel aucun espace de travail "
        . "n'est rattaché. Demandez à votre administrateur de vous attribuer un rôle actif.");
})->name('accueil');
