<?php

namespace App\Mail;

use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * « Votre accès à Selflow est ouvert. »
 *
 * Aucun courriel ne partait à la création d'un compte — ni à l'inscription,
 * ni quand un responsable ouvre l'accès d'un employé, ni quand le
 * superadministrateur crée une entreprise. La règle posée pour Comptaflow
 * vaut ici : un compte ne s'ouvre pas au nom de quelqu'un sans qu'il
 * l'apprenne, et le message dit *lequel* est le mot de passe, jamais *quel*
 * il est.
 */
class CompteSelflowOuvert extends Mailable
{
    public function __construct(
        public readonly Utilisateur $utilisateur,
        public readonly bool $creeParUnTiers,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre accès à Selflow est ouvert');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compte.ouvert',
            with: [
                'prenom'           => trim((string) ($this->utilisateur->prenom ?: $this->utilisateur->nom)),
                'identifiant'      => $this->utilisateur->email,
                'nomEntreprise'    => $this->utilisateur->entreprise?->nom,
                'creeParUnTiers'   => $this->creeParUnTiers,
                'adresseConnexion' => route('connexion'),
            ],
        );
    }
}
