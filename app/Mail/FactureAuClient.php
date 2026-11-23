<?php

namespace App\Mail;

use App\Modules\Admin\Modeles\Vente;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * La facture envoyée au client, en PDF (23/11/2026, Selflow sur mesure).
 * Le courriel porte le nom et la couleur de l'entreprise qui facture.
 */
class FactureAuClient extends Mailable
{
    public function __construct(
        public Vente $vente,
        public string $pdf,
        public string $nomEntreprise,
        public string $couleur = '#002B5C',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre facture ' . ($this->vente->numero_fne ?: $this->vente->numero_facture) . ' · ' . $this->nomEntreprise,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.facture.client');
    }

    public function attachments(): array
    {
        $nom = 'Facture-' . preg_replace('/[^A-Za-z0-9-]/', '-', (string) ($this->vente->numero_fne ?: $this->vente->numero_facture)) . '.pdf';

        return [Attachment::fromData(fn () => $this->pdf, $nom)->withMime('application/pdf')];
    }
}
