<?php

namespace App\Modules\Admin\Services;

use App\Jobs\NormaliserFactureFne;
use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * La facture née d'un bon de commande — en bloc (« Valider & Facturer »,
 * « → Facture ») ou par l'un de ses bons de livraison.
 *
 * Recette du 08/10/2026 : trois portes menaient du bon de commande à la
 * facture, et chacune calculait à sa façon. L'une ignorait la remise globale,
 * une autre laissait la facture sans écriture comptable et reprenait les prix
 * du catalogue, la troisième transformait le bon sur place sous son numéro
 * `BC-`. Elles passent toutes ici :
 *
 * - **les montants** se calculent comme à la caisse (`VenteControleur::
 *   enregistrer()`) : remise de ligne, puis remise globale au prorata, TVA de
 *   chaque ligne sur son HT net, taxes de ligne et taxes sur le TTC ;
 * - **les prix** sont ceux de la commande, jamais ceux du catalogue du jour ;
 * - **la facture** naît sous son propre numéro `VTE-`, passe en comptabilité,
 *   en trésorerie si elle est réglée, et part à la certification selon le
 *   réglage de l'entreprise — comme toute facture.
 *
 * Rien ici ne touche la construction du payload FNE : la facture est une
 * pièce de vente ordinaire, que `FneService` lit comme les autres.
 */
class FacturationCommandeService
{
    /**
     * Le taux de TVA d'une ligne de commande, tel qu'il a été appliqué.
     *
     * La ligne garde le montant, pas le taux : on le retrouve du HT de la
     * ligne, comme le font l'impression et l'avoir.
     */
    public static function tauxTva(VenteDetail $detail): float
    {
        $remise = (float) ($detail->remise_taux ?? 0);
        $ht = (float) $detail->quantite * (float) $detail->prix_unitaire * (1 - $remise / 100);

        if ($ht <= 0) {
            return (float) ($detail->produit?->taux_tva ?? 0);
        }

        return round((float) $detail->montant_tva / $ht * 100, 2);
    }

    /**
     * Le taux de la remise globale de la commande.
     *
     * Saisie en pourcentage depuis la FNE ; une pièce plus ancienne ne porte
     * que son montant en francs, d'où l'on tire le taux.
     */
    private static function tauxRemise(Vente $bc): float
    {
        if ((float) ($bc->remise_taux ?? 0) > 0) {
            return (float) $bc->remise_taux;
        }

        if ((float) ($bc->remise ?? 0) > 0 && (float) $bc->montant_ht > 0) {
            return (float) $bc->remise / (float) $bc->montant_ht * 100;
        }

        return 0.0;
    }

    /**
     * Les montants d'une facture tirée de la commande pour les quantités
     * données.
     *
     * Quand les quantités sont celles de la commande, la facture reprend ses
     * montants tels quels : refaire le calcul ne pourrait qu'introduire un
     * écart d'arrondi entre deux pièces qui disent la même chose.
     *
     * @param  array<int, float>  $quantites  ligne de commande => quantité facturée
     * @return array{montant_ht: float, remise: float, remise_taux: float, montant_tva: float,
     *               montant_ttc: float, montant_autres_taxes: float, lignes: array<int, array>}
     */
    public static function montants(Vente $bc, array $quantites): array
    {
        $bc->loadMissing(['details.taxes', 'details.produit', 'taxesPersonnalisees']);

        $complete = $bc->details->every(
            fn ($d) => abs((float) ($quantites[$d->id] ?? 0) - (float) $d->quantite) < 0.0005
        );

        $lignes = [];
        $htBrut = 0.0;
        foreach ($bc->details as $detail) {
            $quantite = round((float) ($quantites[$detail->id] ?? 0), 3);
            if ($quantite <= 0) {
                continue;
            }

            $remiseLigne = (float) ($detail->remise_taux ?? 0);
            $ht = $quantite * (float) $detail->prix_unitaire * (1 - $remiseLigne / 100);
            $taux = self::tauxTva($detail);
            $tvaLigne = $complete ? (float) $detail->montant_tva : $ht * $taux / 100;

            $lignes[$detail->id] = [
                'detail'      => $detail,
                'quantite'    => $quantite,
                'ht'          => $ht,
                'taux'        => $taux,
                'montant_tva' => $tvaLigne,
                'montant_ttc' => $complete ? (float) $detail->montant_ttc : $ht + $tvaLigne,
            ];
            $htBrut += $ht;
        }

        if ($complete) {
            return [
                'montant_ht'           => (float) $bc->montant_ht,
                'remise'               => (float) ($bc->remise ?? 0),
                'remise_taux'          => (float) ($bc->remise_taux ?? 0),
                'montant_tva'          => (float) $bc->montant_tva,
                'montant_ttc'          => (float) $bc->montant_ttc,
                'montant_autres_taxes' => (float) ($bc->montant_autres_taxes ?? 0),
                'lignes'               => $lignes,
            ];
        }

        // Même ordre qu'à la caisse : remise de ligne, puis remise globale
        // répartie au prorata du HT de chaque ligne.
        $tauxRemise = self::tauxRemise($bc);
        $remise = round($htBrut * $tauxRemise / 100, 2);
        $htNet = max(0.0, $htBrut - $remise);
        $ratio = $htBrut > 0 ? $htNet / $htBrut : 0.0;

        $tva = 0.0;
        $autresTaxes = 0.0;
        foreach ($lignes as $ligne) {
            $tva += $ligne['ht'] * $ratio * $ligne['taux'] / 100;
            foreach ($ligne['detail']->taxes as $taxe) {
                $autresTaxes += $ligne['ht'] * $ratio * (float) $taxe->taux / 100;
            }
        }

        $ttc = $htNet + $tva;
        foreach ($bc->taxesPersonnalisees as $taxe) {
            $autresTaxes += $ttc * (float) $taxe->taux / 100;
        }

        return [
            'montant_ht'           => $htBrut,
            'remise'               => $remise,
            'remise_taux'          => (float) ($bc->remise_taux ?? 0),
            'montant_tva'          => $tva,
            'montant_ttc'          => $ttc,
            'montant_autres_taxes' => round($autresTaxes, 2),
            'lignes'               => $lignes,
        ];
    }

    /**
     * Ce que le client devra pour ces quantités : le TTC et les taxes
     * collectées pour l'État. Le timbre de quittance dépend du mode de
     * règlement, choisi plus tard ; il s'ajoute à l'encaissement.
     */
    public static function netHorsTimbre(Vente $bc, array $quantites): float
    {
        $m = self::montants($bc, $quantites);

        return round($m['montant_ttc'] + $m['montant_autres_taxes'], 2);
    }

    /**
     * Les quantités de toute la commande, ligne par ligne.
     *
     * @return array<int, float>
     */
    public static function quantitesDeLaCommande(Vente $bc): array
    {
        return $bc->details->mapWithKeys(fn ($d) => [$d->id => (float) $d->quantite])->all();
    }

    /**
     * Les quantités livrées par un bon, rapportées aux lignes de la commande.
     *
     * Le bon garde la ligne d'où vient chaque quantité ; ceux établis avant
     * le 08/10/2026 ne gardaient que l'article, d'où le repli.
     *
     * @return array<int, float>
     */
    public static function quantitesDuBon(BonLivraison $bl, Vente $bc): array
    {
        $bl->loadMissing('details');
        $quantites = [];

        foreach ($bl->details as $ligneBl) {
            $detail = $ligneBl->vente_detail_id
                ? $bc->details->firstWhere('id', $ligneBl->vente_detail_id)
                : $bc->details->first(fn ($d) => $d->produit_id === $ligneBl->produit_id && !isset($quantites[$d->id]));

            if ($detail) {
                $quantites[$detail->id] = ($quantites[$detail->id] ?? 0) + (float) $ligneBl->qte_livree;
            }
        }

        return $quantites;
    }

    /**
     * Ce qui manque en stock pour expédier le reste de la commande, ou `null`.
     */
    public static function manqueDeStockPourLeReste(Vente $bc): ?string
    {
        $bc->loadMissing('details.produit');
        $reste = $bc->resteALivrerParLigne();

        foreach ($bc->details as $detail) {
            $quantite = $reste[$detail->id] ?? 0;
            if ($quantite <= 0 || !$detail->produit || !$detail->produit->estStockable()) {
                continue;
            }

            $dispo = $detail->produit->stockActuel($bc->point_de_vente_id);
            if ($dispo < $quantite) {
                return "Stock insuffisant pour « {$detail->produit->nom} » (Disponible: {$dispo}, Reste à livrer: {$quantite}).";
            }
        }

        return null;
    }

    /**
     * Établit la facture.
     *
     * @param  array<int, float>  $quantites  ligne de commande => quantité facturée
     * @param  array{mode?: ?string, montant?: ?float, banque_id?: ?int,
     *               moyen_bancaire?: ?string, reference_paiement?: ?string}  $reglement
     * @param  array{bon_livraison_id?: ?int, expedier_le_reste?: bool}  $options
     */
    public static function etablir(Vente $bc, array $quantites, array $reglement, array $options = []): Vente
    {
        return DB::transaction(function () use ($bc, $quantites, $reglement, $options) {
            $bc->loadMissing(['details.taxes', 'details.produit', 'taxesPersonnalisees', 'pointDeVente.entreprise']);
            $entreprise = $bc->pointDeVente->entreprise;
            $montants = self::montants($bc, $quantites);

            if ($montants['lignes'] === []) {
                throw new \InvalidArgumentException('Aucune quantité à facturer.');
            }

            $numero = NumerotationService::genererNumeroVente($entreprise->id, 'Facture');

            // ── Le mode de règlement ──
            $mode = $reglement['mode'] ?? null;
            $modeFinal = $mode ?: ($bc->mode_paiement ?: 'Crédit');
            $estBanque = $mode === 'Banque';
            if ($estBanque && !empty($reglement['banque_id'])) {
                $journal = CodeJournal::where('entreprise_id', $entreprise->id)->findOrFail($reglement['banque_id']);
                $modeFinal = 'Banque : ' . $journal->intitule;
            }

            // ── La facture : une pièce nouvelle, sous son propre numéro ──
            $facture = $bc->replicate([
                'archived', 'normalise', 'numero_fne', 'signature_dgi', 'qr_code_data',
                'fichier_fne_pdf_url', 'fne_invoice_id', 'fne_alerte_stickers', 'fne_montant_ttc',
                'fne_montant_tva', 'fne_timbre_fiscal', 'fne_certifie_at', 'converti_en_id',
                'piece_liee_id', 'parent_id', 'bon_livraison_id', 'date_validite',
                'date_acceptation', 'accepte_par', 'montant_recu', 'statut',
            ]);
            $facture->fill([
                'numero_facture'       => $numero,
                'etape'                => 'Facture',
                'statut'               => 'Crédit',
                'date_vente'           => now()->toDateString(),
                'mode_paiement'        => $modeFinal,
                'moyen_bancaire'       => $estBanque ? ($reglement['moyen_bancaire'] ?? null) : ($mode ? null : $bc->moyen_bancaire),
                'reference_paiement'   => $estBanque ? ($reglement['reference_paiement'] ?? null) : ($mode ? null : $bc->reference_paiement),
                'montant_ht'           => $montants['montant_ht'],
                'remise'               => $montants['remise'],
                'remise_taux'          => $montants['remise_taux'],
                'montant_tva'          => $montants['montant_tva'],
                'montant_ttc'          => $montants['montant_ttc'],
                'montant_autres_taxes' => $montants['montant_autres_taxes'],
                'archived'             => false,
                'normalise'            => false,
                'bon_livraison_id'     => $options['bon_livraison_id'] ?? null,
            ]);
            $facture->utilisateur_id = Auth::id() ?? $bc->utilisateur_id;
            $facture->save();

            foreach ($montants['lignes'] as $ligne) {
                $detail = $ligne['detail'];
                $nouvelle = VenteDetail::create([
                    'vente_id'        => $facture->id,
                    'produit_id'      => $detail->produit_id,
                    'libelle_virtuel' => $detail->libelle_virtuel,
                    'quantite'        => $ligne['quantite'],
                    // La marchandise facturée est partie — par son bon, ou à
                    // l'instant pour le reste : la file du stock ne doit pas
                    // la proposer une seconde fois.
                    'quantite_livree' => $ligne['quantite'],
                    'unite'           => $detail->unite,
                    'prix_unitaire'   => $detail->prix_unitaire,
                    'remise_taux'     => $detail->remise_taux,
                    'montant_tva'     => $ligne['montant_tva'],
                    'montant_ttc'     => $ligne['montant_ttc'],
                ]);

                foreach ($detail->taxes as $taxe) {
                    $nouvelle->taxes()->create(['nom' => $taxe->nom, 'taux' => $taxe->taux]);
                }
            }

            foreach ($bc->taxesPersonnalisees as $taxe) {
                $facture->taxesPersonnalisees()->create([
                    'nom'     => $taxe->nom,
                    'taux'    => $taxe->taux,
                    'montant' => round((float) $montants['montant_ttc'] * (float) $taxe->taux / 100, 2),
                ]);
            }

            // ── Le reste de la commande part maintenant ──
            if (!empty($options['expedier_le_reste'])) {
                self::expedierLeReste($bc, $facture);
            }

            // ── Le règlement ──
            $facture->unsetRelation('details');
            $netHorsTimbre = round($montants['montant_ttc'] + $montants['montant_autres_taxes'], 2);
            $netAPayer = $facture->netAPayer();

            // Les acomptes reçus sur la commande — ou sur le devis dont elle
            // est née — deviennent des règlements de la facture : ils
            // portaient le numéro d'une pièce qui n'en reçoit plus.
            $acomptes = self::reprendreLesAcomptes($bc, $facture, $netAPayer);

            $tendu = 0.0;
            if ($mode && $mode !== 'Crédit') {
                $tendu = isset($reglement['montant']) && $reglement['montant'] !== null && $reglement['montant'] !== ''
                    ? (float) $reglement['montant']
                    : max(0.0, $netHorsTimbre - $acomptes);
            }

            // On n'encaisse jamais plus que le dû : un BL partiel ne vaut pas
            // le TTC de toute la commande.
            $encaisse = round(min($tendu, max(0.0, $netAPayer - $acomptes)), 2);
            $regle = $acomptes + $encaisse;

            $statut = match (true) {
                $regle <= 0                          => 'Crédit',
                $regle >= $netHorsTimbre - 0.01      => 'Payé',
                default                              => 'Avance',
            };
            $facture->update([
                'statut'       => $statut,
                'montant_recu' => $tendu > 0 && $acomptes <= 0 ? $tendu : null,
            ]);

            ComptabiliteService::genererEcrituresVente(
                $facture,
                $regle,
                $modeFinal,
                now()->toDateString(),
                $estBanque ? ($reglement['moyen_bancaire'] ?? null) : null,
                $estBanque ? ($reglement['reference_paiement'] ?? null) : null
            );

            if ($encaisse > 0) {
                $solde = TresorerieJournal::where('point_de_vente_id', $facture->point_de_vente_id)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id'  => $facture->point_de_vente_id,
                    'date_operation'     => now()->toDateString(),
                    'type_operation'     => 'Encaissement',
                    'libelle'            => ComptabiliteService::libelleTresorerieVente($facture),
                    'mode_paiement'      => $modeFinal,
                    'moyen_bancaire'     => $estBanque ? ($reglement['moyen_bancaire'] ?? null) : null,
                    'reference_paiement' => $estBanque ? ($reglement['reference_paiement'] ?? null) : null,
                    'montant_entree'     => $encaisse,
                    'montant_sortie'     => 0,
                    'solde_resultat'     => $solde + $encaisse,
                    'reference_document' => $numero,
                ]);
            }

            // ── La certification suit le réglage de l'entreprise, comme à la
            //    caisse : dès l'émission, ou à la main après vérification. ──
            if ($entreprise->normaliseAutomatiquement($facture)) {
                $estRne = empty($facture->client_id);
                NormaliserFactureFne::dispatch($facture, $estRne);
            }

            return $facture->fresh();
        });
    }

    /**
     * Fait sortir du stock ce que la commande n'a pas encore livré.
     *
     * Seulement le reste : ce qu'un bon de livraison a déjà fait sortir ne
     * ressort pas. C'était le défaut le plus grave de la recette du
     * 08/10/2026 — « Valider & Facturer » sur une commande déjà livrée
     * ressortait toute la commande.
     */
    private static function expedierLeReste(Vente $bc, Vente $facture): void
    {
        foreach ($bc->details as $detail) {
            $reste = max(0.0, round((float) $detail->quantite - (float) $detail->quantite_livree, 3));
            if ($reste <= 0 || !$detail->produit_id) {
                continue;
            }

            $produit = Produit::lockForUpdate()->find($detail->produit_id);
            if ($produit && $produit->estStockable()) {
                $disponible = StockService::disponible($produit, (int) $bc->point_de_vente_id);
                if ($disponible < $reste) {
                    throw new \InvalidArgumentException(
                        "Stock insuffisant pour « {$produit->nom} » (Disponible: {$disponible}, Demandé: {$reste})."
                    );
                }

                StockService::sortie($produit, (int) $bc->point_de_vente_id, $reste, MouvementStock::LIVRAISON, [
                    'piece'     => $facture,
                    'reference' => $facture->numero_facture,
                    'client_id' => $bc->client_id,
                ]);
            }

            $detail->update(['quantite_livree' => (float) $detail->quantite]);
        }
    }

    /**
     * Rattache à la facture les acomptes reçus sur la commande et sur le devis
     * qui l'a précédée, dans la limite de ce que la facture doit.
     *
     * @return float le total repris
     */
    private static function reprendreLesAcomptes(Vente $bc, Vente $facture, float $plafond): float
    {
        $numeros = Vente::withoutGlobalScopes()
            ->where('converti_en_id', $bc->id)
            ->pluck('numero_facture')
            ->push($bc->numero_facture)
            ->unique()
            ->all();

        $lignes = TresorerieJournal::whereIn('reference_document', $numeros)
            ->where('point_de_vente_id', $bc->point_de_vente_id)
            ->where('type_operation', 'Encaissement')
            ->where('montant_entree', '>', 0)
            ->orderBy('id')
            ->get();

        $repris = 0.0;
        foreach ($lignes as $ligne) {
            if ($repris + (float) $ligne->montant_entree > $plafond + 0.01) {
                break;
            }
            $ligne->update(['reference_document' => $facture->numero_facture]);
            $repris += (float) $ligne->montant_entree;
        }

        return round($repris, 2);
    }

    /**
     * Ferme la commande quand plus rien n'y reste à facturer : elle sort de
     * la liste des commandes, dit la facture qui la clôt, et ne se facture
     * plus — ni en bloc, ni par un bon déjà facturé.
     */
    public static function clore(Vente $bc, Vente $facture): void
    {
        $bc->update([
            'archived'       => true,
            'converti_en_id' => $facture->id,
            'statut'         => 'Facturé',
        ]);
    }
}
