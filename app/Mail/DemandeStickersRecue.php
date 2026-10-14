<?php

namespace App\Mail;

use App\Modules\Admin\Modeles\DemandeSticker;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Une demande de stickers : à DC-Knowing pour qu'elle achète, et à
 * l'entreprise pour qu'elle garde trace de ce qu'elle a demandé.
 */
class DemandeStickersRecue extends Mailable
{
    public function __construct(
        public readonly DemandeSticker $demande,
        public readonly bool $pourDcKnowing,
    ) {}

    public function envelope(): Envelope
    {
        $entreprise = $this->demande->entreprise?->nom;

        return new Envelope(subject: $this->pourDcKnowing
            ? ($this->demande->urgence === 'urgente' ? '[URGENT] ' : '') . "Demande de stickers FNE — {$entreprise} — " . number_format($this->demande->quantite, 0, ',', ' ')
            : 'Votre demande de stickers est transmise à DC-Knowing');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.compte.demande-stickers',
            with: ['demande' => $this->demande->loadMissing(['entreprise', 'pointDeVente', 'utilisateur']), 'pourDcKnowing' => $this->pourDcKnowing],
        );
    }
}
