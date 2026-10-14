<?php

namespace App\Modules\Admin\Services;

use App\Mail\B2bEvenement;
use App\Mail\EnvoiCourriel;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\B2bNegotiation;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Le parcours B2B entre deux entreprises inscrites — lot 75.
 *
 * Chaque transition de la négociation passe ici, quelle que soit la porte
 * par laquelle elle arrive (écran B2B, nouvel achat, bon de commande de
 * vente) : la conversation, les pièces de chaque côté et la notification de
 * l'autre partie restent ainsi d'accord entre elles.
 *
 * Ce que chaque partie voit dans ses propres écrans :
 *
 * - le client : un bon de commande fournisseur (BCF-) dès qu'il commande,
 *   qui devient sa facture d'achat à la réception ;
 * - le fournisseur : un bon de commande de vente (BC-) quand il confirme,
 *   facturé par la porte habituelle (`FacturationCommandeService`) quand il
 *   livre — sortie de stock, écritures et certification FNE comprises.
 *
 * Rien ici ne construit de pièce pour la DGI : la facture du fournisseur
 * passe par les mêmes chemins qu'une facture saisie à la caisse.
 */
class B2bService
{
    // ─────────────────────────────────────────────────────────────────
    // Ouvrir une négociation
    // ─────────────────────────────────────────────────────────────────

    /**
     * Une demande de prix ou une commande directe, depuis un achat saisi
     * (nouvel achat avec envoi B2B, ou « Transmettre B2B » de la liste).
     */
    public static function ouvrirDepuisAchat(Achat $achat, Entreprise $fournisseurEntreprise, bool $masquerPrix = false): B2bNegotiation
    {
        $achat->loadMissing('details.produit');
        $lignes = [];
        foreach ($achat->details as $d) {
            $lignes[] = [
                'produit_id_client' => $d->produit_id,
                'achat_detail_id'   => $d->id,
                'reference'         => $d->produit?->reference ?? 'REF-' . $d->produit_id,
                'nom'               => $d->produit?->nom ?? $d->libelle_virtuel ?? 'Article',
                'quantite'          => (float) $d->quantite,
                'prix_propose'      => $masquerPrix ? 0.0 : (float) $d->prix_unitaire,
                'unite'             => $d->unite ?? $d->produit?->unite ?? 'Unité',
            ];
        }

        $commande = $achat->etape === 'Bon de commande';

        $negociation = B2bNegotiation::create([
            'entreprise_client_id'      => $achat->pointDeVente->entreprise_id,
            'entreprise_fournisseur_id' => $fournisseurEntreprise->id,
            'statut'                    => $commande ? 'Commande' : 'RFQ',
            'type_demande'              => $commande ? 'commande' : 'rfq',
            'reference_commande'        => $achat->numero_facture,
            'produits_demandes'         => $lignes,
            'historique_discussions'    => [],
        ]);

        $achat->update(['b2b_negotiation_id' => $negociation->id]);

        self::evenement($negociation, B2bNegotiation::CLIENT, $commande
            ? 'Bon de commande ' . $achat->numero_facture . ' envoyé.'
            : 'Demande de prix ' . $achat->numero_facture . ' envoyée.');

        return $negociation;
    }

    /**
     * Une demande ou une commande directe composée sur l'écran B2B. La
     * commande directe est permise (propriétaire, 14/10/2026) : elle crée
     * tout de suite le bon de commande du client.
     *
     * @param  array<int, array{produit: Produit, quantite: float, prix: float}>  $articles
     */
    public static function ouvrir(Entreprise $client, Entreprise $fournisseur, array $articles, bool $commande, ?string $message = null): B2bNegotiation
    {
        return DB::transaction(function () use ($client, $fournisseur, $articles, $commande, $message) {
            $lignes = [];
            foreach ($articles as $a) {
                $lignes[] = [
                    'produit_id_client' => $a['produit']->id,
                    'reference'         => $a['produit']->reference,
                    'nom'               => $a['produit']->nom,
                    'quantite'          => (float) $a['quantite'],
                    'prix_propose'      => (float) $a['prix'],
                    'unite'             => $a['produit']->unite ?? 'Unité',
                ];
            }

            $negociation = B2bNegotiation::create([
                'entreprise_client_id'      => $client->id,
                'entreprise_fournisseur_id' => $fournisseur->id,
                'statut'                    => 'RFQ',
                'type_demande'              => $commande ? 'commande' : 'rfq',
                'produits_demandes'         => $lignes,
                'historique_discussions'    => [],
            ]);

            if ($commande) {
                self::poserLaCommandeDuClient($negociation);
                $negociation->update(['statut' => 'Commande']);
            }

            self::evenement($negociation, B2bNegotiation::CLIENT,
                ($commande ? 'Commande directe envoyée.' : 'Demande de prix envoyée.') . ($message ? "\n" . $message : ''));

            return $negociation;
        });
    }

    /**
     * Le bon de commande qu'un fournisseur établit dans sa propre caisse pour
     * un client inscrit : le client le reçoit comme une commande déjà
     * confirmée, en attente de livraison.
     *
     * Le lien était posé à l'envers — le vendeur y figurait comme client —,
     * et l'acheteur recevait sa propre commande parmi les demandes à servir
     * (relevé le 14/10/2026).
     */
    public static function ouvrirDepuisVente(Vente $bc, Entreprise $clientEntreprise): ?B2bNegotiation
    {
        $fournisseurId = $bc->pointDeVente->entreprise_id;

        if (B2bNegotiation::where('entreprise_fournisseur_id', $fournisseurId)->where('reference_commande', $bc->numero_facture)->exists()) {
            return null;
        }

        $bc->loadMissing('details.produit');

        return DB::transaction(function () use ($bc, $clientEntreprise, $fournisseurId) {
            $lignes = [];
            foreach ($bc->details as $d) {
                $lignes[] = [
                    'produit_id_fournisseur' => $d->produit_id,
                    'vente_detail_id'        => $d->id,
                    'reference'              => $d->produit?->reference ?? 'REF-' . $d->produit_id,
                    'nom'                    => $d->produit?->nom ?? $d->libelle_virtuel ?? 'Article',
                    'quantite'               => (float) $d->quantite,
                    'quantite_confirmee'     => (float) $d->quantite,
                    'prix_propose'           => (float) $d->prix_unitaire,
                    'unite'                  => $d->unite ?? $d->produit?->unite ?? 'Unité',
                ];
            }

            $negociation = B2bNegotiation::create([
                'entreprise_client_id'      => $clientEntreprise->id,
                'entreprise_fournisseur_id' => $fournisseurId,
                'statut'                    => 'Confirme',
                'type_demande'              => 'commande',
                'reference_commande'        => $bc->numero_facture,
                'produits_demandes'         => $lignes,
                'prix_final'                => $bc->montant_ttc,
                'historique_discussions'    => [],
            ]);

            $bc->update(['b2b_negotiation_id' => $negociation->id]);
            self::poserLaCommandeDuClient($negociation, 'Attente livraison');

            self::evenement($negociation, B2bNegotiation::FOURNISSEUR,
                'Bon de commande ' . $bc->numero_facture . ' établi par le fournisseur : commande confirmée, en attente de livraison.');

            return $negociation;
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 70a — La conversation
    // ─────────────────────────────────────────────────────────────────

    public static function ecrire(B2bNegotiation $negociation, string $role, string $message): void
    {
        self::evenement($negociation, $role, $message, 'message');
    }

    /**
     * Une proposition de prix (et de quantités, côté fournisseur) ligne à
     * ligne, avec son message.
     *
     * @param  array<int, float|string|null>  $prix
     * @param  array<int, float|string|null>  $quantites
     */
    public static function proposer(B2bNegotiation $negociation, string $role, array $prix, array $quantites, ?string $message): void
    {
        self::exiger($negociation, B2bNegotiation::EN_DISCUSSION, 'La négociation n\'est plus ouverte à une proposition.');

        $lignes = $negociation->produits_demandes;
        $changements = [];
        foreach ($lignes as $i => &$l) {
            if (isset($prix[$i]) && $prix[$i] !== '' && (float) $prix[$i] !== (float) $l['prix_propose']) {
                $changements[] = $l['nom'] . ' : ' . self::f((float) $l['prix_propose']) . ' → ' . self::f((float) $prix[$i]);
                $l['prix_propose'] = (float) $prix[$i];
            }
            if (isset($quantites[$i]) && $quantites[$i] !== '' && (float) $quantites[$i] > 0 && (float) $quantites[$i] !== (float) $l['quantite']) {
                $changements[] = $l['nom'] . ' : quantité ' . self::q((float) $l['quantite']) . ' → ' . self::q((float) $quantites[$i]);
                $l['quantite'] = (float) $quantites[$i];
            }
        }
        unset($l);

        $negociation->update([
            'produits_demandes' => $lignes,
            'statut'            => $role === B2bNegotiation::CLIENT ? 'Negociation_Client' : 'Negociation_Fournisseur',
        ]);

        $texte = ($role === B2bNegotiation::CLIENT ? 'Contre-proposition' : 'Proposition de prix')
            . ($changements ? " :\n• " . implode("\n• ", $changements) : ' (prix inchangés).')
            . ($message ? "\n" . $message : '');

        self::evenement($negociation, $role, $texte, 'proposition');
    }

    // ─────────────────────────────────────────────────────────────────
    // 70b — Commander, confirmer, refuser
    // ─────────────────────────────────────────────────────────────────

    /** Le client accepte les prix et commande. */
    public static function commander(B2bNegotiation $negociation, ?string $message = null): void
    {
        self::exiger($negociation, B2bNegotiation::EN_DISCUSSION, 'Cette négociation ne peut plus être commandée.');

        $sansPrix = collect($negociation->produits_demandes)->filter(fn ($l) => (float) ($l['prix_propose'] ?? 0) <= 0)->pluck('nom');
        if ($sansPrix->isNotEmpty()) {
            throw new \DomainException('Prix manquant pour : ' . $sansPrix->join(', ') . '. Attendez la proposition du fournisseur, ou proposez un prix.');
        }

        DB::transaction(function () use ($negociation, $message) {
            self::poserLaCommandeDuClient($negociation);
            $negociation->update(['statut' => 'Commande', 'type_demande' => 'commande', 'prix_final' => $negociation->totalHt()]);
            self::evenement($negociation, B2bNegotiation::CLIENT,
                'Commande passée aux prix convenus (' . self::f($negociation->totalHt()) . ' HT).' . ($message ? "\n" . $message : ''));
        });
    }

    /**
     * Le fournisseur confirme selon son stock : il rattache chaque ligne à un
     * article de son catalogue et dit la quantité qu'il livrera. Son bon de
     * commande de vente naît ici.
     *
     * @param  array<int, int|string|null>  $produits   ligne => article du fournisseur
     * @param  array<int, float|string|null>  $quantites ligne => quantité confirmée
     */
    public static function confirmer(B2bNegotiation $negociation, int $pointDeVenteId, array $produits, array $quantites, ?string $message = null): Vente
    {
        self::exiger($negociation, ['Commande'], 'Seule une commande reçue se confirme.');
        $fournisseurId = $negociation->entreprise_fournisseur_id;

        return DB::transaction(function () use ($negociation, $pointDeVenteId, $produits, $quantites, $message, $fournisseurId) {
            $lignes = $negociation->produits_demandes;
            $aVendre = [];
            foreach ($lignes as $i => &$l) {
                $qte = isset($quantites[$i]) && $quantites[$i] !== '' ? (float) $quantites[$i] : (float) $l['quantite'];
                $qte = max(0.0, min($qte, (float) $l['quantite']));
                $l['quantite_confirmee'] = $qte;
                if ($qte <= 0) {
                    continue;
                }
                $produit = !empty($produits[$i])
                    ? Produit::where('entreprise_id', $fournisseurId)->find((int) $produits[$i])
                    : self::articleDuFournisseur($fournisseurId, $l);
                if (!$produit) {
                    throw new \DomainException("Choisissez l'article de votre catalogue qui correspond à « {$l['nom']} ».");
                }
                $l['produit_id_fournisseur'] = $produit->id;
                $aVendre[$i] = [$produit, $qte, (float) $l['prix_propose']];
            }
            unset($l);

            if ($aVendre === []) {
                throw new \DomainException('Aucune quantité confirmée : refusez la commande plutôt que de la confirmer vide.');
            }

            $client = self::clientChezLeFournisseur($negociation);
            $numero = NumerotationService::genererNumeroVente($fournisseurId, 'Bon de commande');
            $bc = Vente::create([
                'point_de_vente_id'  => $pointDeVenteId,
                'client_id'          => $client->id,
                'utilisateur_id'     => Auth::id(),
                'numero_facture'     => $numero,
                'date_vente'         => now()->toDateString(),
                'mode_paiement'      => 'Crédit',
                'montant_ht'         => 0, 'montant_tva' => 0, 'montant_ttc' => 0,
                'remise'             => 0, 'remise_taux' => 0,
                'statut'             => 'Brouillon',
                'etape'              => 'Bon de commande',
                'b2b_negotiation_id' => $negociation->id,
            ]);

            $ht = $tva = 0.0;
            foreach ($aVendre as $i => [$produit, $qte, $prix]) {
                $ligneHt = round($qte * $prix, 2);
                $ligneTva = round($ligneHt * (float) ($produit->taux_tva ?? 18) / 100, 2);
                $detail = VenteDetail::create([
                    'vente_id'      => $bc->id,
                    'produit_id'    => $produit->id,
                    'quantite'      => $qte,
                    'unite'         => $produit->unite ?? $lignes[$i]['unite'] ?? 'Unité',
                    'prix_unitaire' => $prix,
                    'remise_taux'   => 0,
                    'montant_tva'   => $ligneTva,
                    'montant_ttc'   => $ligneHt + $ligneTva,
                ]);
                $lignes[$i]['vente_detail_id'] = $detail->id;
                $ht += $ligneHt;
                $tva += $ligneTva;
            }
            $bc->update(['montant_ht' => $ht, 'montant_tva' => $tva, 'montant_ttc' => $ht + $tva]);

            $negociation->update([
                'produits_demandes' => $lignes,
                'statut'            => 'Confirme',
                'prix_final'        => $ht + $tva,
            ]);

            // Le bon de commande du client suit : ses quantités sont celles que
            // le fournisseur a confirmées.
            self::alignerLaCommandeDuClient($negociation, 'Attente livraison');

            $partiel = collect($lignes)->contains(fn ($l) => (float) ($l['quantite_confirmee'] ?? 0) < (float) $l['quantite']);
            self::evenement($negociation, B2bNegotiation::FOURNISSEUR,
                'Commande confirmée' . ($partiel ? ' en partie (stock)' : '') . ' — en attente de livraison. Bon de commande ' . $numero . '.'
                . ($message ? "\n" . $message : ''));

            return $bc;
        });
    }

    public static function refuser(B2bNegotiation $negociation, string $role, string $motif): void
    {
        self::exiger($negociation, B2bNegotiation::REFUSABLES, 'Une commande livrée ne se refuse plus : le client établit un retour ou le fournisseur un avoir.');

        DB::transaction(function () use ($negociation, $role, $motif) {
            $negociation->update(['statut' => 'Refuse']);

            // Les bons de commande nés de la négociation sont clos : ils ne
            // seront ni facturés ni livrés.
            Achat::where('b2b_negotiation_id', $negociation->id)->where('etape', '!=', 'Facture')
                ->update(['statut' => 'Annulé B2B', 'archived' => true]);
            Vente::where('b2b_negotiation_id', $negociation->id)->where('etape', 'Bon de commande')
                ->update(['archived' => true]);

            self::evenement($negociation, $role, ($role === B2bNegotiation::CLIENT ? 'Le client' : 'Le fournisseur') . ' a refusé : ' . $motif);
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 70c — Livrer et facturer, réceptionner
    // ─────────────────────────────────────────────────────────────────

    /**
     * Le fournisseur livre et facture : son bon de commande est facturé par
     * la porte habituelle — sortie de stock, écritures, certification FNE.
     */
    public static function livrer(B2bNegotiation $negociation, array $reglement = []): Vente
    {
        self::exiger($negociation, ['Confirme'], 'Seule une commande confirmée se livre.');

        $bc = Vente::where('b2b_negotiation_id', $negociation->id)->where('etape', 'Bon de commande')
            ->where(fn ($q) => $q->whereNull('archived')->orWhere('archived', false))->latest('id')->first();

        // Le bon de commande a pu être facturé depuis l'écran des ventes, par
        // la porte habituelle : la facture porte la négociation (elle copie
        // le bon), il ne reste qu'à prévenir le client.
        if (!$bc) {
            $dejaFacturee = Vente::where('b2b_negotiation_id', $negociation->id)->where('etape', 'Facture')->latest('id')->first();
            if (!$dejaFacturee) {
                throw new \DomainException('Le bon de commande de cette négociation est introuvable.');
            }

            return DB::transaction(function () use ($negociation, $dejaFacturee) {
                $negociation->update(['statut' => 'Livre', 'prix_final' => $dejaFacturee->montant_ttc]);
                self::poserLaCommandeDuClient($negociation, 'En attente B2B');
                self::alignerLaCommandeDuClient($negociation, 'En attente B2B', $dejaFacturee);
                self::evenement($negociation, B2bNegotiation::FOURNISSEUR,
                    'Commande livrée et facturée : facture ' . $dejaFacturee->numero_facture . ' (' . self::f((float) $dejaFacturee->montant_ttc) . ' TTC). À réceptionner.');

                return $dejaFacturee;
            });
        }

        if ($manque = FacturationCommandeService::manqueDeStockPourLeReste($bc)) {
            throw new \DomainException($manque);
        }

        return DB::transaction(function () use ($negociation, $bc, $reglement) {
            $facture = FacturationCommandeService::etablir(
                $bc,
                FacturationCommandeService::quantitesDeLaCommande($bc),
                $reglement + ['mode' => 'Crédit'],
                ['expedier_le_reste' => true]
            );
            // Clos ici plutôt que par `FacturationCommandeService::clore`, qui
            // préviendrait le client une seconde fois (voir `factureeAilleurs`).
            $bc->update(['archived' => true, 'converti_en_id' => $facture->id, 'statut' => 'Facturé']);
            $facture->update(['b2b_negotiation_id' => $negociation->id]);

            $negociation->update(['statut' => 'Livre', 'prix_final' => $facture->montant_ttc]);
            self::poserLaCommandeDuClient($negociation, 'En attente B2B');
            self::alignerLaCommandeDuClient($negociation, 'En attente B2B', $facture);

            self::evenement($negociation, B2bNegotiation::FOURNISSEUR,
                'Commande livrée et facturée : facture ' . $facture->numero_facture . ' (' . self::f((float) $facture->montant_ttc) . ' TTC). À réceptionner.');

            return $facture;
        });
    }

    /**
     * Le bon de commande B2B a été facturé depuis l'écran des ventes, par la
     * porte habituelle : la négociation passe à « livrée » et le client est
     * prévenu, comme s'il avait été livré depuis la page B2B.
     */
    public static function factureeAilleurs(int $negociationId): void
    {
        $negociation = B2bNegotiation::find($negociationId);
        if ($negociation && $negociation->statut === 'Confirme') {
            self::livrer($negociation);
        }
    }

    /**
     * Le client réceptionne : la marchandise entre en stock, le bon de
     * commande devient sa facture d'achat.
     *
     * La facture du fournisseur est certifiée par lui : si elle l'est déjà,
     * l'achat est « déclaré par le fournisseur » (lot 66) et c'est la facture
     * reçue de la DGI qui portera l'écriture ; sinon l'achat s'écrit lui-même.
     */
    public static function receptionner(Achat $achat): void
    {
        if ($achat->statut !== 'En attente B2B') {
            throw new \DomainException('Cet achat B2B a déjà été réceptionné.');
        }

        DB::transaction(function () use ($achat) {
            $negociation = $achat->b2b_negotiation_id ? B2bNegotiation::find($achat->b2b_negotiation_id) : null;
            $factureFournisseur = $negociation
                ? Vente::where('b2b_negotiation_id', $negociation->id)->where('etape', 'Facture')->latest('id')->first()
                : null;

            $modePaiement = $achat->mode_paiement ?: 'Crédit';
            $numero = NumerotationService::genererNumeroAchat($achat->pointDeVente->entreprise_id, 'Facture');
            $ancien = $achat->numero_facture;

            $achat->update([
                'statut'                     => $modePaiement === 'Crédit' ? 'Crédit' : 'Payé',
                'etape'                      => 'Facture',
                'numero_facture'             => $numero,
                'numero_facture_fournisseur' => $factureFournisseur?->numero_facture ?? $achat->numero_facture_fournisseur,
                'declaree_par_fournisseur'   => (bool) $factureFournisseur?->normalise,
            ]);
            if ($negociation && $negociation->reference_commande === $ancien) {
                $negociation->update(['reference_commande' => $numero]);
            }

            foreach ($achat->details as $det) {
                $reste = max(0.0, (float) $det->quantite - (float) $det->quantite_receptionnee);
                if ($det->produit && $det->produit->estStockable() && $reste > 0) {
                    $det->update(['quantite_receptionnee' => $det->quantite]);
                    StockService::entree($det->produit, (int) $achat->point_de_vente_id, $reste, MouvementStock::RECEPTION,
                        ['piece' => $achat, 'reference' => $achat->numero_facture, 'fournisseur_id' => $achat->fournisseur_id,
                         'cout_unitaire' => (float) $det->prix_unitaire]);
                }
            }

            $montantPaye = $modePaiement !== 'Crédit' ? (float) $achat->montant_ttc : 0.0;
            if ($montantPaye > 0) {
                $solde = TresorerieJournal::where('point_de_vente_id', $achat->point_de_vente_id)->orderByDesc('created_at')->value('solde_resultat') ?? 0;
                TresorerieJournal::create([
                    'point_de_vente_id'  => $achat->point_de_vente_id,
                    'date_operation'     => now()->toDateString(),
                    'type_operation'     => 'Décaissement',
                    'libelle'            => ComptabiliteService::libelleTresorerieAchat($achat),
                    'mode_paiement'      => $modePaiement,
                    'montant_entree'     => 0,
                    'montant_sortie'     => $montantPaye,
                    'solde_resultat'     => $solde - $montantPaye,
                    'reference_document' => $achat->numero_facture,
                ]);
            }

            ComptabiliteService::genererEcrituresAchat($achat->fresh(), $montantPaye, $modePaiement, now()->toDateString());

            if ($negociation) {
                $negociation->update(['statut' => 'Termine']);
                self::evenement($negociation, B2bNegotiation::CLIENT, 'Livraison réceptionnée : facture d\'achat ' . $numero . '.');
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // 70d — L'événement, et l'autre partie prévenue
    // ─────────────────────────────────────────────────────────────────

    /**
     * Inscrit un événement au fil, marque qui a agi, et prévient l'autre
     * partie par courriel. Celui qui agit a, par définition, tout lu.
     */
    public static function evenement(B2bNegotiation $negociation, string $role, string $texte, string $type = 'action'): void
    {
        $utilisateur = Auth::user();
        $historique = $negociation->historique_discussions ?? [];
        $historique[] = [
            'date'    => now()->toDateTimeString(),
            'auteur'  => $utilisateur ? trim($utilisateur->prenom . ' ' . $utilisateur->nom) : 'Selflow',
            'role'    => $role === B2bNegotiation::CLIENT ? 'Client' : 'Fournisseur',
            'type'    => $type,
            'message' => $texte,
        ];

        $maintenant = now();
        $negociation->update([
            'historique_discussions' => $historique,
            'derniere_activite_le'   => $maintenant,
            'derniere_activite_par'  => $role,
            'lu_client_le'           => $role === B2bNegotiation::CLIENT ? $maintenant : null,
            'lu_fournisseur_le'      => $role === B2bNegotiation::FOURNISSEUR ? $maintenant : null,
        ]);

        self::prevenir($negociation, $role === B2bNegotiation::CLIENT ? B2bNegotiation::FOURNISSEUR : B2bNegotiation::CLIENT, $texte);
    }

    /** Le courriel à l'autre partie : ses administrateurs, à défaut l'adresse de l'entreprise. */
    private static function prevenir(B2bNegotiation $negociation, string $roleDestinataire, string $texte): void
    {
        $entreprise = $roleDestinataire === B2bNegotiation::CLIENT ? $negociation->entrepriseClient : $negociation->entrepriseFournisseur;
        if (!$entreprise) {
            return;
        }

        $adresses = Utilisateur::where('entreprise_id', $entreprise->id)->where('role', 'admin')
            ->where(fn ($q) => $q->whereNull('statut')->orWhere('statut', 'actif'))
            ->pluck('email')->filter()->unique()->values();
        if ($adresses->isEmpty() && $entreprise->email) {
            $adresses = collect([$entreprise->email]);
        }

        foreach ($adresses as $adresse) {
            EnvoiCourriel::envoyer($adresse, new B2bEvenement($negociation, $roleDestinataire, $texte));
        }
    }

    public static function marquerLu(B2bNegotiation $negociation, string $role): void
    {
        $negociation->forceFill([$role === B2bNegotiation::CLIENT ? 'lu_client_le' : 'lu_fournisseur_le' => now()])->saveQuietly();
    }

    // ─────────────────────────────────────────────────────────────────
    // Les pièces de chaque côté
    // ─────────────────────────────────────────────────────────────────

    /**
     * Le bon de commande du client : celui d'où la demande est partie (une
     * demande de prix devient bon de commande), ou un nouveau.
     */
    private static function poserLaCommandeDuClient(B2bNegotiation $negociation, string $statut = 'En attente de confirmation'): ?Achat
    {
        $clientId = $negociation->entreprise_client_id;
        $achat = Achat::where('b2b_negotiation_id', $negociation->id)->latest('id')->first()
            ?? ($negociation->reference_commande
                ? Achat::whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $clientId))
                    ->where('numero_facture', $negociation->reference_commande)->first()
                : null);

        if ($achat) {
            if ($achat->etape === 'Demande de prix') {
                $nouveau = NumerotationService::genererNumeroAchat($clientId, 'Bon de commande');
                $achat->update(['etape' => 'Bon de commande', 'numero_facture' => $nouveau]);
                $negociation->update(['reference_commande' => $nouveau]);
            }
            $achat->update(['b2b_negotiation_id' => $negociation->id, 'statut' => $statut]);
            self::alignerLaCommandeDuClient($negociation, $statut);

            return $achat;
        }

        // Un client sans point de vente ne peut porter aucune pièce : la
        // négociation vit quand même, et son bon de commande sera posé à la
        // livraison, s'il a ouvert un site d'ici là.
        $pdv = PointDeVente::where('entreprise_id', $clientId)->orderBy('id')->first();
        if (!$pdv) {
            return null;
        }

        $numero = NumerotationService::genererNumeroAchat($clientId, 'Bon de commande');
        $achat = Achat::create([
            'point_de_vente_id'  => $pdv->id,
            'fournisseur_id'     => self::fournisseurChezLeClient($negociation)->id,
            'numero_facture'     => $numero,
            'date_achat'         => now()->toDateString(),
            'mode_paiement'      => 'Crédit',
            'montant_ht'         => 0, 'montant_tva' => 0, 'montant_ttc' => 0,
            'remise'             => 0, 'remise_taux' => 0,
            'statut'             => $statut,
            'etape'              => 'Bon de commande',
            'type_facture'       => 'normale',
            'b2b_negotiation_id' => $negociation->id,
        ]);

        if (!$negociation->reference_commande) {
            $negociation->update(['reference_commande' => $numero]);
        }

        self::alignerLaCommandeDuClient($negociation, $statut);

        return $achat;
    }

    /**
     * Les lignes du bon de commande du client, réécrites d'après la
     * négociation : quantités confirmées, prix convenus, et — une fois
     * facturé — la TVA de la facture du fournisseur.
     */
    private static function alignerLaCommandeDuClient(B2bNegotiation $negociation, string $statut, ?Vente $facture = null): void
    {
        $achat = Achat::where('b2b_negotiation_id', $negociation->id)->where('etape', '!=', 'Facture')->latest('id')->first();
        if (!$achat) {
            return;
        }

        $clientId = $negociation->entreprise_client_id;
        $facture?->loadMissing('details');
        $lignesFacture = $facture ? $facture->details->values() : collect();

        $achat->details()->delete();
        $ht = $tva = 0.0;
        $lignes = $negociation->produits_demandes;
        $rang = 0;
        foreach ($lignes as $i => $l) {
            $qte = (float) ($l['quantite_confirmee'] ?? $l['quantite']);
            if ($qte <= 0) {
                continue;
            }
            $produit = !empty($l['produit_id_client']) ? Produit::where('entreprise_id', $clientId)->find($l['produit_id_client']) : null;
            $produit ??= self::articleDuClient($clientId, $l);
            $prix = (float) $l['prix_propose'];
            $ligneHt = round($qte * $prix, 2);

            $ligneFacture = $lignesFacture->get($rang);
            $ligneTva = $ligneFacture
                ? (float) $ligneFacture->montant_tva
                : round($ligneHt * (float) ($produit->taux_tva ?? 18) / 100, 2);
            $rang++;

            $detail = AchatDetail::create([
                'achat_id'      => $achat->id,
                'produit_id'    => $produit->id,
                'quantite'      => $qte,
                'unite'         => $l['unite'] ?? $produit->unite ?? 'Unité',
                'prix_unitaire' => $prix,
                'montant_tva'   => $ligneTva,
                'montant_ttc'   => $ligneHt + $ligneTva,
            ]);
            $lignes[$i]['produit_id_client'] = $produit->id;
            $lignes[$i]['achat_detail_id'] = $detail->id;
            $ht += $ligneHt;
            $tva += $ligneTva;
        }

        $negociation->update(['produits_demandes' => $lignes]);
        $achat->update([
            'montant_ht'                 => $ht,
            'montant_tva'                => $tva,
            'montant_ttc'                => $ht + $tva,
            'statut'                     => $statut,
            'numero_facture_fournisseur' => $facture?->numero_facture ?? $achat->numero_facture_fournisseur,
        ]);
    }

    /** L'article du fournisseur qui répond à une ligne : même référence, sinon même désignation. */
    public static function articleDuFournisseur(int $fournisseurId, array $ligne): ?Produit
    {
        if (!empty($ligne['produit_id_fournisseur'])) {
            $p = Produit::where('entreprise_id', $fournisseurId)->find($ligne['produit_id_fournisseur']);
            if ($p) {
                return $p;
            }
        }

        return self::parReferenceOuNom($fournisseurId, $ligne);
    }

    private static function articleDuClient(int $clientId, array $ligne): Produit
    {
        return self::parReferenceOuNom($clientId, $ligne) ?? Produit::create([
            'entreprise_id' => $clientId,
            'reference'     => $ligne['reference'] ?? null,
            'nom'           => $ligne['nom'] ?? 'Article B2B',
            'type'          => 'marchandise',
            'unite'         => $ligne['unite'] ?? 'Unité',
            'prix_achat'    => (float) ($ligne['prix_propose'] ?? 0),
            'prix_vente'    => (float) ($ligne['prix_propose'] ?? 0),
            'taux_tva'      => 18,
        ]);
    }

    private static function parReferenceOuNom(int $entrepriseId, array $ligne): ?Produit
    {
        $reference = trim((string) ($ligne['reference'] ?? ''));
        if ($reference !== '') {
            $p = Produit::where('entreprise_id', $entrepriseId)->whereRaw('UPPER(TRIM(reference)) = ?', [mb_strtoupper($reference)])->first();
            if ($p) {
                return $p;
            }
        }
        $nom = trim((string) ($ligne['nom'] ?? ''));

        return $nom !== ''
            ? Produit::where('entreprise_id', $entrepriseId)->whereRaw('LOWER(TRIM(nom)) = ?', [mb_strtolower($nom)])->first()
            : null;
    }

    /** La fiche du client chez le fournisseur, d'après le NCC ; créée si elle manque. */
    private static function clientChezLeFournisseur(B2bNegotiation $negociation): Client
    {
        $acheteur = $negociation->entrepriseClient;

        return Client::where('entreprise_id', $negociation->entreprise_fournisseur_id)
            ->where('ncc', $acheteur->ncc)->first()
            ?? Client::create([
                'entreprise_id' => $negociation->entreprise_fournisseur_id,
                'nom'           => $acheteur->nom,
                'type_facturation' => 'B2B',
                'telephone'     => $acheteur->telephone,
                'email'         => $acheteur->email,
                'adresse'       => $acheteur->adresse,
                'ncc'           => $acheteur->ncc,
            ]);
    }

    /** La fiche du fournisseur chez le client, d'après le NCC ; créée si elle manque. */
    private static function fournisseurChezLeClient(B2bNegotiation $negociation): Fournisseur
    {
        $vendeur = $negociation->entrepriseFournisseur;

        return Fournisseur::where('entreprise_id', $negociation->entreprise_client_id)
            ->where('ncc', $vendeur->ncc)->first()
            ?? Fournisseur::create([
                'entreprise_id' => $negociation->entreprise_client_id,
                'nom'           => $vendeur->nom,
                'telephone'     => $vendeur->telephone,
                'email'         => $vendeur->email,
                'ncc'           => $vendeur->ncc,
            ]);
    }

    private static function exiger(B2bNegotiation $negociation, array $statuts, string $refus): void
    {
        if (!in_array($negociation->statut, $statuts, true)) {
            throw new \DomainException($refus);
        }
    }

    private static function f(float $montant): string
    {
        return number_format($montant, 0, ',', ' ') . ' F';
    }

    private static function q(float $quantite): string
    {
        return rtrim(rtrim(number_format($quantite, 3, ',', ' '), '0'), ',');
    }
}
