<?php

namespace App\Mail;

use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Le lien de réinitialisation, envoyé à la seule adresse du compte.
 *
 * Il partait en texte brut (`Mail::raw`), et surtout il s'affichait aussi à
 * l'écran de qui le demandait : le courriel était la seule preuve que l'on
 * détient l'adresse, et l'écran la rendait inutile.
 */
class LienReinitialisationMotDePasse extends Mailable
{
    public function __construct(
        public readonly Utilisateur $utilisateur,
        public readonly string $lien,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Réinitialisation de votre mot de passe Selflow');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compte.reinitialisation',
            with: [
                'prenom'      => trim((string) ($this->utilisateur->prenom ?: $this->utilisateur->nom)),
                'identifiant' => $this->utilisateur->email,
                'lien'        => $this->lien,
                'minutes'     => $this->minutes,
            ],
        );
    }
}
