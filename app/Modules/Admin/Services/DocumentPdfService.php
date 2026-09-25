<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Le document, en PDF véritable.
 *
 * ## Pourquoi un second rendu
 *
 * Les écrans `factures/vente` et `factures/achat` dessinent la pièce **en
 * JavaScript**, en quatre modèles au choix : le document n'existe que dans le
 * navigateur. Le bouton « Imprimer / PDF » passe donc par la boîte
 * d'impression, et le « Télécharger » ajouté le 25/09/2026 rendait une page
 * HTML autonome — un fichier, mais pas un PDF.
 *
 * Le propriétaire a demandé un vrai PDF. Il ne pouvait pas venir du même
 * rendu : les quatre modèles reposent sur `flex`, que dompdf n'implémente pas,
 * et convertir leur HTML aurait donné une mise en page effondrée. Ce service
 * établit donc la pièce **côté serveur**, en tableaux, dans une mise en page
 * faite pour le papier.
 *
 * ## Ce qui garantit que les deux disent la même chose
 *
 * Les montants ne sont **pas recalculés** : ils sont lus tels qu'ils ont été
 * enregistrés (`montant_ht`, `montant_tva`, `montant_ttc`), et le timbre vient
 * de `TimbreQuittanceService`, seule autorité en la matière. C'est plus sûr que
 * le rendu de l'écran, qui refait l'addition en JavaScript.
 *
 * ## Ce que ce service ne touche pas
 *
 * Le bloc de certification est reproduit **à l'identique** de ce que l'écran
 * affiche : code QR du jeton de vérification, visuel FNE, numéro FNE, code de
 * vérification, signature. Rien n'y est ajouté, rien n'en est retiré. Le code
 * QR est dessiné à partir de `QrCodeFneService::matrice()`, que ce service lit
 * sans la modifier — la conformité FNE reste gelée.
 *
 * ## Sûreté
 *
 * Le chargement distant de dompdf reste **éteint** : une pièce dont un champ
 * porterait `<img src="http://…">` ferait sinon partir une requête depuis le
 * serveur, à une adresse choisie par celui qui a saisi la pièce. Logo et code
 * QR entrent par des `data:` fabriqués ici, jamais par une adresse.
 */
class DocumentPdfService
{
    /** Ce que le PDF rend quand la pièce n'a pas de logo lisible. */
    private const LOGO_ABSENT = null;

    // ─────────────────────────────────────────────────────────────────
    // CE QUE L'EXTÉRIEUR APPELLE
    // ─────────────────────────────────────────────────────────────────

    /**
     * La pièce de vente : facture, avoir, devis, bon de commande.
     */
    public function vente(Vente $vente, float $dejaPaye = 0): string
    {
        return $this->rendre('admin::factures.pdf.document', $this->donneesVente($vente, $dejaPaye));
    }

    /**
     * Le reçu : la même pièce, au format du ticket de caisse.
     */
    public function recuDeVente(Vente $vente, float $dejaPaye = 0): string
    {
        $donnees = $this->donneesVente($vente, $dejaPaye);
        $donnees['titre'] = $vente->normalise ? 'REÇU NORMALISÉ' : 'REÇU';

        // 80 mm de large, hauteur libre : un ticket ne se pagine pas.
        return $this->rendre('admin::factures.pdf.ticket', $donnees, [80, $this->hauteurDuTicket($donnees)]);
    }

    /**
     * La pièce d'achat : facture fournisseur enregistrée, ou bordereau.
     */
    public function achat(Achat $achat, float $dejaPaye = 0): string
    {
        return $this->rendre('admin::factures.pdf.document', $this->donneesAchat($achat, $dejaPaye));
    }

    /**
     * Le nom du fichier proposé au téléchargement.
     *
     * Le numéro FNE d'abord quand il existe : c'est lui qui identifie la pièce
     * auprès de l'administration, et c'est sous ce nom qu'on la retrouvera.
     */
    public static function nomDuFichier(Vente|Achat $piece, string $suffixe = ''): string
    {
        $base = $piece->numero_fne ?: $piece->numero_facture;
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $base) ?: 'document';

        return trim($base . $suffixe, '-') . '.pdf';
    }

    // ─────────────────────────────────────────────────────────────────
    // LE RENDU
    // ─────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $donnees
     * @param string|array{0:float,1:float} $format A4, ou [largeur, hauteur] en millimètres
     */
    private function rendre(string $vue, array $donnees, string|array $format = 'A4'): string
    {
        $options = new Options();
        // Les polices se cherchent dans le dépôt, jamais sur le réseau.
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(base_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(View::make($vue, $donnees)->render(), 'UTF-8');

        if (is_array($format)) {
            // dompdf raisonne en points typographiques : 1 mm = 72/25.4 pt.
            $enPoints = array_map(fn ($mm) => $mm * 72 / 25.4, $format);
            $dompdf->setPaper([0, 0, $enPoints[0], $enPoints[1]]);
        } else {
            $dompdf->setPaper($format, 'portrait');
        }

        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * La hauteur du ticket, en millimètres.
     *
     * Un ticket de caisse n'a pas de format : il fait la longueur de ce qu'il
     * porte. Une hauteur fixe couperait les pièces longues ou laisserait un
     * demi-mètre de blanc sous les courtes. L'estimation est volontairement
     * large — un ticket un peu long ne gêne personne, un ticket tronqué si.
     *
     * @param array<string,mixed> $donnees
     */
    private function hauteurDuTicket(array $donnees): float
    {
        $base = 130.0;                                   // en-tête, totaux, pied
        $base += count($donnees['lignes']) * 9.0;        // une ligne d'article
        $base += $donnees['certification'] ? 46.0 : 0.0; // le bloc FNE
        $base += $donnees['mentions'] ? 14.0 : 0.0;

        return $base;
    }

    // ─────────────────────────────────────────────────────────────────
    // CE QUE LE DOCUMENT PORTE
    // ─────────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function donneesVente(Vente $vente, float $dejaPaye): array
    {
        $entreprise = $vente->pointDeVente->entreprise;
        $timbre = TimbreQuittanceService::pourVente($vente);

        $lignes = [];
        foreach ($vente->details as $detail) {
            $htLigne = (float) $detail->quantite * (float) $detail->prix_unitaire
                * (1 - (float) ($detail->remise_taux ?? 0) / 100);
            $tauxTva = $htLigne > 0
                ? round((float) $detail->montant_tva / $htLigne * 100, 2)
                : (float) ($detail->produit->taux_tva ?? 0);

            $lignes[] = [
                'reference'   => $detail->produit?->reference ?? '—',
                'designation' => $detail->libelle_virtuel ?? ($detail->produit?->nom ?? 'Article'),
                'quantite'    => (float) $detail->quantite,
                'unite'       => $detail->unite ?? 'Unité',
                'prix'        => (float) $detail->prix_unitaire,
                'remise'      => (float) ($detail->remise_taux ?? 0),
                'ht'          => $htLigne,
                'taux_tva'    => $tauxTva,
                'code_tva'    => Produit::deduireCodeTva($tauxTva, $entreprise->regime_imposition),
                'tva'         => (float) $detail->montant_tva,
            ];
        }

        return [
            'titre'      => mb_strtoupper($vente->etape === 'Facture'
                ? $vente->libelleTypeDocument()
                : $vente->etape),
            'numero'     => $vente->numero_facture,
            'date'       => \Carbon\Carbon::parse($vente->date_vente)->isoFormat('D MMMM YYYY'),
            'entreprise' => $this->entreprise($entreprise, $vente->pointDeVente->nom),
            'tiers'      => [
                'intitule' => 'Client',
                'nom'      => $vente->client?->nom ?? 'Client de passage',
                'adresse'  => $vente->client?->adresse,
                'tel'      => $vente->client?->telephone,
                'ncc'      => $vente->client?->ncc,
                'rccm'     => $vente->client?->rccm,
            ],
            'lignes'        => $lignes,
            'totaux'        => $this->totaux($vente, $timbre, $dejaPaye),
            'reglement'     => [
                'mode'      => $vente->mode_paiement,
                'moyen'     => $vente->moyen_bancaire,
                'reference' => $vente->reference_paiement,
                'statut'    => $vente->statut,
                'recu'      => $vente->montant_recu !== null ? (float) $vente->montant_recu : null,
                'rendu'     => $vente->monnaieRendue(),
            ],
            'certification' => $this->certification($vente),
            'mentions'      => $vente->autres_mentions ?: $entreprise->facture_autres_mentions,
            'pied'          => $vente->pied_de_page ?: $entreprise->pied_de_page_facture,
            'origine'       => $vente->type_facture === 'avoir' && $vente->parent
                ? 'Avoir sur la facture ' . $vente->parent->numero_facture
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function donneesAchat(Achat $achat, float $dejaPaye): array
    {
        $entreprise = $achat->pointDeVente->entreprise;
        $estBordereau = $achat->type_facture === 'bapa';
        $timbre = TimbreQuittanceService::pourAchat($achat);

        $lignes = [];
        foreach ($achat->details as $detail) {
            $htLigne = (float) $detail->quantite * (float) $detail->prix_unitaire
                * (1 - (float) ($detail->remise_taux ?? 0) / 100);
            $tauxTva = $htLigne > 0
                ? round((float) $detail->montant_tva / $htLigne * 100, 2)
                : 0.0;

            $lignes[] = [
                'reference'   => $detail->produit?->reference ?? '—',
                'designation' => $detail->libelle_virtuel ?? ($detail->produit?->nom ?? 'Article'),
                'quantite'    => (float) $detail->quantite,
                'unite'       => $detail->unite ?? 'Unité',
                'prix'        => (float) $detail->prix_unitaire,
                'remise'      => (float) ($detail->remise_taux ?? 0),
                'ht'          => $htLigne,
                // Le bordereau d'achat ne transmet aucune TVA : l'afficher
                // annoncerait au vendeur une taxe que la plateforme ignore.
                'taux_tva'    => $estBordereau ? 0.0 : $tauxTva,
                'code_tva'    => null,
                'tva'         => $estBordereau ? 0.0 : (float) $detail->montant_tva,
            ];
        }

        return [
            'titre'      => $estBordereau
                ? "BORDEREAU D'ACHAT (BAPA)"
                : mb_strtoupper($achat->etape === 'Facture' ? "Facture d'achat" : $achat->etape),
            'numero'     => $achat->numero_facture,
            'date'       => \Carbon\Carbon::parse($achat->date_achat)->isoFormat('D MMMM YYYY'),
            'entreprise' => $this->entreprise($entreprise, $achat->pointDeVente->nom),
            'tiers'      => [
                'intitule' => $estBordereau ? 'Vendeur (tiers non immatriculé)' : 'Fournisseur',
                'nom'      => $achat->fournisseur?->nom ?? '—',
                'adresse'  => $achat->fournisseur?->adresse,
                'tel'      => $achat->fournisseur?->telephone,
                'ncc'      => $estBordereau ? null : $achat->fournisseur?->ncc,
                'rccm'     => $estBordereau ? null : $achat->fournisseur?->rccm,
            ],
            'lignes'        => $lignes,
            'totaux'        => $this->totaux($achat, $timbre, $dejaPaye, $estBordereau),
            'reglement'     => [
                'mode'      => $achat->mode_paiement,
                'moyen'     => $achat->moyen_bancaire,
                'reference' => $achat->reference_paiement,
                'statut'    => $achat->statut,
                'recu'      => $achat->montant_recu !== null ? (float) $achat->montant_recu : null,
                'rendu'     => $achat->monnaieRendue(),
            ],
            'certification' => $this->certification($achat),
            'mentions'      => $achat->autres_mentions,
            'pied'          => $achat->pied_de_page,
            'origine'       => $achat->numero_facture_fournisseur
                ? 'Facture fournisseur n° ' . $achat->numero_facture_fournisseur
                : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function totaux(Vente|Achat $piece, float $timbre, float $dejaPaye, bool $sansTva = false): array
    {
        $autresTaxes = (float) ($piece->montant_autres_taxes ?? 0);
        $net = (float) $piece->montant_ttc + $autresTaxes + $timbre;

        return [
            'ht'           => (float) $piece->montant_ht,
            'remise'       => (float) ($piece->remise ?? 0),
            'remise_taux'  => (float) ($piece->remise_taux ?? 0),
            'tva'          => $sansTva ? 0.0 : (float) $piece->montant_tva,
            'sans_tva'     => $sansTva,
            'autres_taxes' => $autresTaxes,
            'timbre'       => $timbre,
            'ttc'          => (float) $piece->montant_ttc,
            'net'          => $net,
            'deja_paye'    => $dejaPaye,
            'reste'        => max(0.0, $net - $dejaPaye),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function entreprise($entreprise, string $pointDeVente): array
    {
        return [
            'nom'            => $entreprise->nom,
            'point_de_vente' => $pointDeVente,
            'adresse'        => $entreprise->adresse,
            'tel'            => $entreprise->telephone,
            'email'          => $entreprise->email,
            'ncc'            => $entreprise->ncc,
            'rccm'           => $entreprise->rccm,
            'regime'         => $entreprise->regime_imposition,
            'centre_impots'  => $entreprise->centre_impots,
            'logo'           => $this->logo($entreprise),
        ];
    }

    /**
     * Le bloc de certification, ou `null` tant que la pièce n'est pas certifiée.
     *
     * **Rien n'est fabriqué en l'absence de jeton.** Un document non normalisé
     * qui porterait des armoiries, un numéro de repli et la mention « FACTURE
     * NORMALISÉE » usurperait des mentions officielles — c'est le défaut que le
     * lot 8 avait corrigé sur le ticket, et qu'il serait absurde de réintroduire
     * par le PDF.
     *
     * @return array<string,mixed>|null
     */
    private function certification(Vente|Achat $piece): ?array
    {
        if (!$piece->normalise || !$piece->numero_fne) {
            return null;
        }

        return [
            'numero_fne'   => $piece->numero_fne,
            'verification' => $piece->qr_code_data,
            'signature'    => $piece->signature_dgi,
            'qr'           => $this->qrPng($piece->qr_code_data),
            'logo_fne'     => $this->fichierEnDataUri(public_path('logo-FNE.png')),
        ];
    }

    /**
     * Le code QR du jeton de vérification, en PNG.
     *
     * La matrice vient de `QrCodeFneService`, qui reste intouché : c'est lui
     * qui décide de ce que le symbole encode, et sa conformité est gelée. Ce
     * service ne fait que la dessiner — le SVG que l'écran utilise passe mal
     * dans un PDF, un PNG y entre sans interprétation.
     */
    private function qrPng(?string $token, int $module = 4, int $marge = 4): ?string
    {
        $matrice = QrCodeFneService::matrice($token);

        if ($matrice === null || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $modules = count($matrice);
        $cote = ($modules + 2 * $marge) * $module;

        $image = imagecreatetruecolor($cote, $cote);
        $blanc = imagecolorallocate($image, 255, 255, 255);
        $noir = imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $cote, $cote, $blanc);

        foreach ($matrice as $y => $rangee) {
            foreach ($rangee as $x => $sombre) {
                if ($sombre) {
                    $gx = ($x + $marge) * $module;
                    $gy = ($y + $marge) * $module;
                    imagefilledrectangle($image, $gx, $gy, $gx + $module - 1, $gy + $module - 1, $noir);
                }
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * Le logo de l'entreprise, en `data:`.
     *
     * Jamais par son adresse : dompdf irait la chercher sur le réseau, depuis
     * le serveur, et le chargement distant est éteint pour cette raison même.
     */
    private function logo($entreprise): ?string
    {
        $chemin = $entreprise->logo_path;

        if (!$chemin) {
            return self::LOGO_ABSENT;
        }

        // Un logo déposé par adresse ne se rapatrie pas : le serveur n'ira pas
        // chercher un fichier dont l'adresse a été saisie dans un formulaire.
        if (str_starts_with($chemin, 'http://') || str_starts_with($chemin, 'https://')) {
            return self::LOGO_ABSENT;
        }

        try {
            if (!Storage::disk('public')->exists($chemin)) {
                return self::LOGO_ABSENT;
            }

            return $this->contenuEnDataUri(
                Storage::disk('public')->get($chemin),
                Storage::disk('public')->mimeType($chemin) ?: 'image/png'
            );
        } catch (\Throwable) {
            // Un logo illisible ne doit pas empêcher d'éditer la pièce.
            return self::LOGO_ABSENT;
        }
    }

    private function fichierEnDataUri(string $chemin): ?string
    {
        if (!is_file($chemin) || !is_readable($chemin)) {
            return null;
        }

        $type = match (strtolower(pathinfo($chemin, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return $this->contenuEnDataUri((string) file_get_contents($chemin), $type);
    }

    private function contenuEnDataUri(string $contenu, string $type): string
    {
        return 'data:' . $type . ';base64,' . base64_encode($contenu);
    }
}
