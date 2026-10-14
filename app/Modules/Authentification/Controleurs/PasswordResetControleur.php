<?php

namespace App\Modules\Authentification\Controleurs;

use App\Http\Controllers\Controller;
use App\Mail\EnvoiCourriel;
use App\Mail\LienReinitialisationMotDePasse;
use App\Modules\Authentification\Modeles\Utilisateur;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetControleur extends Controller
{
    /**
     * Afficher le formulaire de demande de réinitialisation.
     */
    public function afficherDemande(): View
    {
        return view('authentification::mot_de_passe_oublie');
    }

    /** Durée de validité d'un lien, en minutes. */
    public const VALIDITE_MINUTES = 60;

    /**
     * Traiter la demande de lien de réinitialisation.
     *
     * Le lien s'affichait à l'écran, en production comme en local : quiconque
     * tapait l'adresse d'un autre recevait de quoi changer son mot de passe.
     * Il ne part désormais que par courriel, et ne s'affiche qu'en local.
     *
     * La réponse est la même que l'adresse existe ou non : la page
     * n'apprend plus à un inconnu quelles adresses ont un compte.
     */
    public function envoyerLien(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ], [
            'email.required' => 'L\'adresse e-mail est requise.',
            'email.email'    => 'L\'adresse e-mail n\'est pas valide.',
        ]);

        // Un serveur sans transport réel n'enverra rien, à personne : le dire
        // plutôt qu'annoncer un courriel qui n'arrivera jamais.
        if (! EnvoiCourriel::transportReel()) {
            logger()->error('Réinitialisation demandée, mais aucun transport de courriel n\'est configuré (MAIL_MAILER).');

            return back()->withInput()->withErrors([
                'email' => 'L\'envoi de courriels n\'est pas encore configuré sur ce serveur. Contactez DC-Knowing pour réinitialiser votre mot de passe.',
            ]);
        }

        $email = trim((string) $request->email);
        $utilisateur = Utilisateur::where('email', $email)->first();

        if ($utilisateur) {
            $token = Str::random(60);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $utilisateur->email],
                ['token' => Hash::make($token), 'created_at' => now()]
            );

            $lien = route('password.reset', ['token' => $token, 'email' => $utilisateur->email]);

            $parti = EnvoiCourriel::envoyer(
                $utilisateur->email,
                new LienReinitialisationMotDePasse($utilisateur, $lien, self::VALIDITE_MINUTES)
            );

            if (! $parti) {
                return back()->withInput()->withErrors([
                    'email' => 'Le courriel n\'a pas pu partir. Réessayez dans quelques minutes ; si cela persiste, contactez DC-Knowing.',
                ]);
            }

            // Sur un poste de développement seulement : aucune boîte ne reçoit.
            if (app()->environment('local')) {
                session()->flash('lien_developpement', $lien);
            }
        }

        return back()->with('succes', 'Si un compte existe pour cette adresse, un lien de réinitialisation vient de lui être envoyé. Pensez à regarder dans les indésirables.');
    }

    /**
     * Afficher le formulaire de réinitialisation — seulement si le lien est
     * valide. Il s'ouvrait pour n'importe quel jeton, et l'erreur n'arrivait
     * qu'après la saisie du nouveau mot de passe.
     */
    public function afficherReset(string $token, Request $request): View|RedirectResponse
    {
        $email = (string) $request->query('email');

        if (! $this->jetonValide($email, $token)) {
            return redirect()->route('password.request')->withErrors([
                'email' => 'Ce lien de réinitialisation est invalide ou a expiré. Demandez-en un nouveau.',
            ]);
        }

        return view('authentification::reinitialiser_mot_de_passe', compact('token', 'email'));
    }

    private function jetonValide(string $email, string $token): bool
    {
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        return $record
            && ! Carbon::parse($record->created_at)->addMinutes(self::VALIDITE_MINUTES)->isPast()
            && Hash::check($token, $record->token);
    }

    /**
     * Traiter le changement de mot de passe.
     */
    public function reinitialiser(Request $request): RedirectResponse
    {
        $request->validate([
            'token'                 => ['required', 'string'],
            'email'                 => ['required', 'email', 'exists:utilisateurs,email'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.required'        => 'L\'adresse e-mail est requise.',
            'email.email'           => 'L\'adresse e-mail n\'est pas valide.',
            'email.exists'          => 'Aucun compte n\'est associé à cette adresse e-mail.',
            'password.required'     => 'Le nouveau mot de passe est obligatoire.',
            'password.min'          => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed'    => 'Les deux mots de passe ne correspondent pas.',
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'email' => 'Jeton de réinitialisation invalide ou expiré.',
            ]);
        }

        // Vérifier l'expiration du jeton (60 minutes)
        if (Carbon::parse($record->created_at)->addMinutes(self::VALIDITE_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            throw ValidationException::withMessages([
                'email' => 'Ce jeton de réinitialisation a expiré.',
            ]);
        }

        // Vérifier la correspondance du jeton
        if (! Hash::check($request->token, $record->token)) {
            throw ValidationException::withMessages([
                'email' => 'Jeton de réinitialisation invalide ou incorrect.',
            ]);
        }

        // Mettre à jour le mot de passe
        $utilisateur = Utilisateur::where('email', $request->email)->firstOrFail();
        $utilisateur->update([
            'password' => Hash::make($request->password),
        ]);

        // Supprimer le jeton
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return redirect()->route('connexion')->with('succes', 'Votre mot de passe a été modifié avec succès. Vous pouvez maintenant vous connecter.');
    }
}
