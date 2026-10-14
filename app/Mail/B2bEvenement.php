<?php

namespace App\Mail;

use App\Modules\Admin\Modeles\B2bNegotiation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * « Votre partenaire B2B a répondu » — 70d.
 *
 * Une négociation entre deux entreprises ne vit que si chacune apprend que
 * l'autre a agi : sans courriel, la demande de prix attendait qu'on pense à
 * ouvrir l'écran.
 */
class B2bEvenement extends Mailable
{
    public function __construct(
        public readonly B2bNegotiation $negociation,
        public readonly string $roleDestinataire,
        public readonly string $texte,
    ) {}

    public function envelope(): Envelope
    {
        $autre = $this->roleDestinataire === B2bNegotiation::CLIENT
            ? $this->negociation->entrepriseFournisseur?->nom
            : $this->negociation->entrepriseClient?->nom;

        return new Envelope(subject: "B2B — {$autre} : " . $this->negociation->libelleStatut($this->roleDestinataire));
    }

    public function content(): Content
    {
        $route = $this->roleDestinataire === B2bNegotiation::CLIENT ? 'admin.b2b.client.voir' : 'admin.b2b.fournisseur.voir';

        return new Content(
            view: 'emails.compte.b2b-evenement',
            with: [
                'negociation' => $this->negociation,
                'role'        => $this->roleDestinataire,
                'texte'       => $this->texte,
                'lien'        => route($route, $this->negociation),
            ],
        );
    }
}
