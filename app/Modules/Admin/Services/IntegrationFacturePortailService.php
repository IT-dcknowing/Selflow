<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\PropositionStock;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Les factures relevées sur le portail FNE deviennent des ventes de Selflow.
 *
 * Demandé par le propriétaire du projet le 23/11/2026 : une facture émise hors
 * de Selflow, directement sur la plateforme de la DGI, est une facture comme
 * une autre — elle compte dans les indicateurs, le solde du client et la
 * comptabilité, et s'affiche normalisée, sans bouton « Normaliser ».
 *
 * ## Ce que cela touche du périmètre FNE gelé
 *
 * Exception n° 3 de `CLAUDE.md`, sur demande explicite du propriétaire. Rien
 * ne part à la DGI : `FneService`, les payloads, le code QR et le timbre ne
 * sont ni appelés ni modifiés. On écrit sur la vente ce que la plateforme a
 * **déjà certifié** — son numéro FNE, son lien de vérification, ses montants
 * et son timbre — exactement comme le fait une normalisation réussie.
 *
 * ## Ce qui n'est jamais repris
 *
 * Une facture dont le numéro FNE existe déjà sur une vente de Selflow, dans
 * n'importe quelle entreprise du même NCC : c'est une pièce que Selflow a
 * normalisée lui-même. Elle est marquée rapprochée, et la vente reste seule.
 */
class IntegrationFacturePortailService
{
    /** Ce que le portail écrit pour un client sans identité. */
    private const CLIENTS_DE_PASSAGE = ['clientdepassage', 'passage', 'clientpassage', ''];

    /** `paymentMethod` du portail → mode de paiement de Selflow. */
    private const MODES = [
        'cash'         => 'Espèces',
        'mobile-money' => 'Mobile Money',
        'mobilemoney'  => 'Mobile Money',
        'card'         => 'Banque',
        'transfer'     => 'Banque',
        'check'        => 'Banque',
        'deferred'     => 'Crédit',
    ];

    public const VENTE       = 'vente';
    public const AVOIR_VENTE = 'avoir_vente';
    public const ACHAT       = 'achat';
    public const AVOIR_ACHAT = 'avoir_achat';

    public const LIBELLES = [
        self::VENTE       => 'Factures de vente émises',
        self::AVOIR_VENTE => 'Avoirs émis à vos clients',
        self::ACHAT       => 'Factures d’achat reçues',
        self::AVOIR_ACHAT => 'Avoirs reçus de vos fournisseurs',
    ];

    /** La nature d'une pièce du portail, d'après sa liste et son sous-type. */
    public static function nature(PortailFneFactureRecue $facture): ?string
    {
        $emise = $facture->liste_portail === PortailFneFactureRecue::LISTE_EMISES;
        $recue = $facture->liste_portail === 'received';

        return match (true) {
            $emise && $facture->subtype === 'normal' => self::VENTE,
            $emise && $facture->subtype === 'refund' => self::AVOIR_VENTE,
            $recue && $facture->subtype === 'normal' => self::ACHAT,
            $recue && $facture->subtype === 'refund' => self::AVOIR_ACHAT,
            default => null,
        };
    }

    /**
     * Les pièces du portail qui attendent d'être intégrées : factures et
     * avoirs, émis et reçus (23/11/2026, « tous les types dans le même
     * style »).
     */
    public function enAttente(Entreprise $entreprise): Collection
    {
        $this->rapprocherCeQueSelflowConnait($entreprise);

        return PortailFneFactureRecue::query()
            ->with('lignes')
            ->where('entreprise_id', $entreprise->id)
            ->whereIn('liste_portail', [PortailFneFactureRecue::LISTE_EMISES, 'received'])
            ->whereIn('subtype', ['normal', 'refund'])
            ->whereNull('achat_id')
            ->where(fn ($q) => $q->whereNull('statut_rapprochement')
                ->orWhereNotIn('statut_rapprochement', [PortailFneFactureRecue::RAPPROCHEE, PortailFneFactureRecue::ECARTEE]))
            ->orderBy('date_facture')
            ->get()
            // Seule une pièce REÇUE peut être notre propre BAPA revenu par le
            // relevé. Sur une pièce émise, l'émetteur est toujours notre NCC :
            // le test l'écartait, et la page restait vide alors que 158
            // factures attendaient (23/11/2026).
            ->reject(fn (PortailFneFactureRecue $f) => $f->liste_portail === 'received'
                && EcritureFactureRecueService::estNotrePropreBapa($f))
            ->values();
    }

    /**
     * Les factures que Selflow a normalisées lui-même : rapprochées, jamais
     * reprises. On cherche dans toutes les entreprises du même NCC — le
     * 23/11/2026, douze des vingt-neuf factures relevées pour DC-KNOWING CGA
     * (n° 29) avaient été normalisées sous l'ancienne fiche (n° 10).
     */
    public function rapprocherCeQueSelflowConnait(Entreprise $entreprise): int
    {
        $memeNcc = Entreprise::query()->whereNotNull('ncc')->get()
            ->filter(fn (Entreprise $e) => EntreprisePortailFne::comparable($e->ncc) === EntreprisePortailFne::comparable($entreprise->ncc))
            ->pluck('id');

        $factures = PortailFneFactureRecue::query()
            ->where('entreprise_id', $entreprise->id)
            ->where('liste_portail', PortailFneFactureRecue::LISTE_EMISES)
            ->where(fn ($q) => $q->whereNull('statut_rapprochement')
                ->orWhere('statut_rapprochement', '!=', PortailFneFactureRecue::RAPPROCHEE))
            ->get();

        if ($factures->isEmpty()) {
            return 0;
        }

        $ventes = Vente::query()
            ->with('pointDeVente:id,entreprise_id')
            ->whereIn('numero_fne', $factures->pluck('reference')->filter())
            ->whereHas('pointDeVente', fn ($q) => $q->whereIn('entreprise_id', $memeNcc))
            ->get()
            ->keyBy('numero_fne');

        // Les pièces reçues déjà saisies : l'achat porte le numéro du fournisseur.
        $achats = Achat::query()
            ->whereIn('numero_facture_fournisseur', $factures->where('liste_portail', 'received')->pluck('reference')->filter())
            ->whereHas('pointDeVente', fn ($q) => $q->whereIn('entreprise_id', $memeNcc))
            ->get()
            ->keyBy('numero_facture_fournisseur');

        $n = 0;
        foreach ($factures as $facture) {
            if ($facture->liste_portail === 'received') {
                $achat = $achats->get($facture->reference);
                if ($achat && !$facture->achat_id && (int) $achat->pointDeVente?->entreprise_id === (int) $entreprise->id) {
                    $facture->update(['achat_id' => $achat->id, 'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
                        'note_rapprochement' => "Déjà dans Selflow : {$achat->numero_facture}."]);
                    EcritureFactureRecueService::synchroniser($facture->refresh());
                    $n++;
                }
                continue;
            }

            $vente = $ventes->get($facture->reference);
            if (!$vente) {
                continue;
            }
            $facture->update([
                'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
                'note_rapprochement'   => "Déjà dans Selflow : {$vente->numero_facture}"
                    . ($vente->pointDeVente?->entreprise_id !== $entreprise->id ? " (entreprise n° {$vente->pointDeVente?->entreprise_id})" : '')
                    . '.',
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * Ce que l'intégration ferait de cette facture : point de vente, client,
     * article et compte de chaque ligne. Rien n'est écrit.
     *
     * @return array<string, mixed>
     */
    public function proposer(PortailFneFactureRecue $facture, Entreprise $entreprise): array
    {
        $brut = (array) ($facture->contenu_brut ?? []);

        $nature = self::nature($facture);
        $estAchat = in_array($nature, [self::ACHAT, self::AVOIR_ACHAT], true);

        $lignes = $facture->lignes->map(function ($ligne) use ($entreprise, $brut, $estAchat) {
            $produit = $this->articlePour($entreprise, $ligne->reference_article, $ligne->designation);

            return [
                'ligne'   => $ligne,
                'produit' => $produit,
                'taux'    => $this->tauxTva($ligne->taxes),
                'compte'  => $produit
                    ? ($estAchat ? ImputationService::compteAchat($produit, $entreprise->id) : ImputationService::compteVente($produit, $entreprise->id))
                    : ($estAchat ? $this->compteAchatLigneLibre($entreprise) : $this->compteLigneLibre($entreprise, $brut)),
            ];
        });

        // Une pièce reçue ne dit pas lequel de nos sites l'a reçue : à choisir,
        // sauf si l'entreprise n'en a qu'un.
        $nomSite = $estAchat ? '' : (string) ($brut['clientPointOfSale'] ?? '');

        return [
            'nature'         => $nature,
            'point_de_vente' => $this->pointDeVentePour($entreprise, $nomSite),
            'nom_point_de_vente' => $nomSite,
            'client'         => $estAchat ? null : $this->clientPour($entreprise, $brut),
            'client_nom'     => $estAchat
                ? ($facture->emetteur_nom ?: 'Fournisseur')
                : (trim((string) ($brut['clientCompanyName'] ?? '')) ?: 'Client de passage'),
            'client_ncc'     => $estAchat ? (string) $facture->emetteur_ncc : trim((string) ($brut['clientNcc'] ?? '')),
            'mode_paiement'  => $this->mode($brut['paymentMethod'] ?? $facture->moyen_paiement),
            'origine'        => trim((string) ($brut['parentReference'] ?? '')),
            'lignes'         => $lignes,
        ];
    }

    /**
     * Le compte d'une ligne hors catalogue.
     *
     * 1. la configuration globale générale de l'entreprise, si elle en a une ;
     * 2. sinon 706000 (prestations de services) pour une entreprise de
     *    services — ce que dit le secteur de sa fiche DGI —, 701000
     *    (ventes de marchandises) pour les autres.
     */
    public function compteLigneLibre(Entreprise $entreprise, array $brut = []): string
    {
        $global = ImputationService::configurationGlobale(null, 'compte_vente', $entreprise->id);
        if ($global !== null) {
            return $global;
        }

        $secteur = Str::lower(Str::ascii((string) ($brut['company']['sector'] ?? '')));

        return str_contains($secteur, 'service')
            ? (string) config('selflow.plan_comptable_defaut.service_defaut', '706000')
            : (string) config('selflow.plan_comptable_defaut.vente_defaut', '701000');
    }

    /** Le compte d'une ligne d'achat hors catalogue : configuration globale, sinon 601000. */
    public function compteAchatLigneLibre(Entreprise $entreprise): string
    {
        return ImputationService::configurationGlobale(null, 'compte_achat', $entreprise->id)
            ?? (string) config('selflow.plan_comptable_defaut.achat_defaut', '601000');
    }

    /**
     * Intègre la pièce selon sa nature : vente, avoir de vente, achat, avoir d'achat.
     *
     * @param  array<string, mixed>  $choix
     */
    public function integrer(PortailFneFactureRecue $facture, Entreprise $entreprise, ?int $utilisateurId, array $choix = []): Vente|Achat
    {
        return match (self::nature($facture)) {
            self::VENTE       => $this->integrerVente($facture, $entreprise, $utilisateurId, $choix),
            self::AVOIR_VENTE => $this->integrerAvoirVente($facture, $entreprise, $utilisateurId, $choix),
            self::ACHAT       => $this->integrerAchat($facture, $entreprise, $utilisateurId, $choix, false),
            self::AVOIR_ACHAT => $this->integrerAchat($facture, $entreprise, $utilisateurId, $choix, true),
            default           => throw new \DomainException("La pièce {$facture->reference} n'est ni une facture ni un avoir."),
        };
    }

    /**
     * Intègre la facture comme une vente normalisée.
     *
     * @param  array{point_de_vente_id?: int|null, lignes?: array<int, array{produit_id?: int|null, compte?: string|null}>}  $choix
     */
    public function integrerVente(PortailFneFactureRecue $facture, Entreprise $entreprise, ?int $utilisateurId, array $choix = []): Vente
    {
        if ((int) $facture->entreprise_id !== (int) $entreprise->id) {
            throw new \DomainException('Cette facture du portail appartient à une autre entreprise.');
        }

        $proposition = $this->proposer($facture, $entreprise);

        $pdvId = (int) ($choix['point_de_vente_id'] ?? $proposition['point_de_vente']?->id ?? 0);
        $pdv = PointDeVente::where('entreprise_id', $entreprise->id)->find($pdvId);
        if (!$pdv) {
            throw new \DomainException("Choisissez le point de vente de la facture {$facture->reference}.");
        }

        return DB::transaction(function () use ($facture, $entreprise, $utilisateurId, $choix, $proposition, $pdv) {
            // Verrou et seconde lecture : deux clics simultanés ne créent
            // pas deux ventes pour une seule pièce certifiée.
            $facture = PortailFneFactureRecue::whereKey($facture->id)->lockForUpdate()->firstOrFail();
            if (Vente::where('numero_fne', $facture->reference)->exists()) {
                throw new \DomainException("La facture {$facture->reference} est déjà dans Selflow.");
            }

            $brut   = (array) ($facture->contenu_brut ?? []);
            $client = $proposition['client'] ?? $this->creerClient($entreprise, $brut);
            $mode   = $proposition['mode_paiement'];
            $credit = $mode === 'Crédit';
            $numero = NumerotationService::genererNumeroVente($entreprise->id, 'Facture', null, $pdv->id);
            $date   = $facture->date_facture?->toDateString() ?? now()->toDateString();
            $lien   = $facture->urlDeVerification();

            $vente = Vente::create([
                'point_de_vente_id'    => $pdv->id,
                'utilisateur_id'       => $utilisateurId,
                'client_id'            => $client?->id,
                'numero_facture'       => $numero,
                'date_vente'           => $date,
                'mode_paiement'        => $mode,
                'montant_ht'           => (float) $facture->montant_ht,
                'montant_tva'          => (float) $facture->montant_tva,
                'montant_ttc'          => (float) $facture->montant_ttc,
                'montant_autres_taxes' => (float) ($facture->autres_taxes ?? 0),
                'remise'               => 0,
                'statut'               => $credit ? 'Crédit' : 'Payé',
                'etape'                => 'Facture',
                'type_piece'           => Vente::TYPE_FACTURE,
                // Ce que la plateforme a déjà certifié, recopié tel quel.
                'normalise'            => true,
                'numero_fne'           => $facture->reference,
                'fne_invoice_id'       => $facture->fne_id,
                'qr_code_data'         => $lien,
                'fichier_fne_pdf_url'  => $lien,
                'fne_certifie_at'      => $facture->date_facture,
                'fne_montant_ttc'      => (float) $facture->montant_ttc,
                'fne_montant_tva'      => (float) $facture->montant_tva,
                'fne_timbre_fiscal'    => (float) ($facture->timbre_fiscal ?? 0),
                'autres_mentions'      => 'Facture émise sur le portail FNE de la DGI, reprise dans Selflow.',
            ]);

            $avecCompte = Schema::hasColumn('vente_details', 'compte_vente');

            foreach ($proposition['lignes'] as $i => $p) {
                $ligne   = $p['ligne'];
                $produit = $p['produit'];

                if (array_key_exists('produit_id', $choix['lignes'][$i] ?? [])) {
                    $choisi  = $choix['lignes'][$i]['produit_id'];
                    $produit = $choisi ? Produit::where('entreprise_id', $entreprise->id)->find((int) $choisi) : null;
                }

                $quantite = (float) $ligne->quantite;
                $prix     = (float) $ligne->prix_unitaire;
                $remise   = (float) ($ligne->remise ?? 0);
                $ht       = round($quantite * $prix * (1 - $remise / 100), 2);
                $tva      = round($ht * $p['taux'] / 100, 2);
                $compte   = trim((string) ($choix['lignes'][$i]['compte'] ?? '')) ?: $p['compte'];

                $detail = VenteDetail::create(array_filter([
                    'vente_id'          => $vente->id,
                    'produit_id'        => $produit?->id,
                    'reference_produit' => $produit?->reference ?? $ligne->reference_article,
                    'libelle_virtuel'   => $produit ? null : ($ligne->designation ?: 'Ligne du portail FNE'),
                    'quantite'          => $quantite,
                    // La sortie de stock passe par une proposition à valider :
                    // marquée livrée, la ligne ne revient pas dans la file des
                    // livraisons, où elle serait sortie une seconde fois.
                    'quantite_livree'   => $quantite,
                    'unite'             => $ligne->unite ?: 'Unité',
                    'prix_unitaire'     => $prix,
                    'remise_taux'       => $remise,
                    'montant_tva'       => $tva,
                    'montant_ttc'       => $ht + $tva,
                    'compte_vente'      => ($avecCompte && !$produit) ? $compte : null,
                ], fn ($v) => $v !== null));

                if ($produit && $produit->estStockable() && PropositionStock::disponible()) {
                    PropositionStock::create([
                        'entreprise_id'     => $entreprise->id,
                        'point_de_vente_id' => $pdv->id,
                        'produit_id'        => $produit->id,
                        'vente_id'          => $vente->id,
                        'vente_detail_id'   => $detail->id,
                        'sens'              => 'sortie',
                        'quantite'          => $quantite,
                        'origine'           => 'portail_fne',
                        'reference'         => $numero,
                        'statut'            => PropositionStock::PROPOSEE,
                    ]);
                }
            }

            $vente->refresh();
            $netAPayer = $vente->netAPayer();

            ComptabiliteService::genererEcrituresVente($vente, $credit ? 0 : $netAPayer, $mode, $date);

            if (!$credit && $netAPayer > 0) {
                $solde = TresorerieJournal::where('point_de_vente_id', $pdv->id)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id'  => $pdv->id,
                    'date_operation'     => $date,
                    'type_operation'     => 'Encaissement',
                    'libelle'            => ComptabiliteService::libelleTresorerieVente($vente),
                    'mode_paiement'      => $mode,
                    'montant_entree'     => $netAPayer,
                    'montant_sortie'     => 0,
                    'solde_resultat'     => $solde + $netAPayer,
                    'reference_document' => $numero,
                ]);
            }

            $facture->update([
                'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
                'note_rapprochement'   => "Intégrée comme {$numero}.",
                'point_de_vente_id'    => $pdv->id,
            ]);

            return $vente;
        });
    }

    /**
     * L'avoir émis sur le portail devient l'avoir de sa facture de vente.
     *
     * La facture d'origine doit être dans Selflow (normalisée par Selflow ou
     * déjà intégrée) : un avoir s'écrit contre une vente. Les lignes sont
     * celles que la DGI a certifiées ; la marchandise qui revient passe par
     * une proposition d'entrée en stock.
     */
    private function integrerAvoirVente(PortailFneFactureRecue $facture, Entreprise $entreprise, ?int $utilisateurId, array $choix): Vente
    {
        $this->appartient($facture, $entreprise);
        $brut = (array) ($facture->contenu_brut ?? []);
        $origine = trim((string) ($brut['parentReference'] ?? ''));

        $parent = $origine === '' ? null : Vente::query()
            ->where('numero_fne', $origine)
            ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
            ->first();

        if (!$parent) {
            throw new \DomainException("L'avoir {$facture->reference} corrige la facture « " . ($origine ?: 'inconnue')
                . " », qui n'est pas dans Selflow : intégrez-la d'abord.");
        }

        $proposition = $this->proposer($facture, $entreprise);

        return DB::transaction(function () use ($facture, $entreprise, $utilisateurId, $choix, $proposition, $parent) {
            $facture = $this->verrouiller($facture);
            if (Vente::where('numero_fne', $facture->reference)->exists()) {
                throw new \DomainException("L'avoir {$facture->reference} est déjà dans Selflow.");
            }

            $pdvId  = (int) $parent->point_de_vente_id;
            $numero = NumerotationService::genererNumeroVente($entreprise->id, 'Facture', 'avoir', $pdvId);
            $date   = $facture->date_facture?->toDateString() ?? now()->toDateString();
            $lien   = $facture->urlDeVerification();

            $avoir = Vente::create([
                'point_de_vente_id'   => $pdvId,
                'utilisateur_id'      => $utilisateurId,
                'client_id'           => $parent->client_id,
                'numero_facture'      => $numero,
                'date_vente'          => $date,
                'mode_paiement'       => $parent->mode_paiement,
                'montant_ht'          => (float) $facture->montant_ht,
                'montant_tva'         => (float) $facture->montant_tva,
                'montant_ttc'         => (float) $facture->montant_ttc,
                'montant_autres_taxes'=> (float) ($facture->autres_taxes ?? 0),
                'remise'              => 0,
                'statut'              => 'Payé',
                'type_facture'        => 'avoir',
                'etape'               => 'Facture',
                'parent_id'           => $parent->id,
                'raison_avoir'        => 'Avoir émis sur le portail FNE de la DGI',
                'normalise'           => true,
                'numero_fne'          => $facture->reference,
                'fne_invoice_id'      => $facture->fne_id,
                'qr_code_data'        => $lien,
                'fichier_fne_pdf_url' => $lien,
                'fne_certifie_at'     => $facture->date_facture,
                'fne_montant_ttc'     => (float) $facture->montant_ttc,
                'fne_montant_tva'     => (float) $facture->montant_tva,
                'fne_timbre_fiscal'   => (float) ($facture->timbre_fiscal ?? 0),
                'autres_mentions'     => 'Avoir émis sur le portail FNE de la DGI, repris dans Selflow.',
            ]);

            $this->lignesDeVente($avoir, $proposition, $choix, $entreprise, $pdvId, 'entree', $numero);

            ComptabiliteService::genererEcritureAvoirVente($avoir->fresh());

            $facture->update([
                'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
                'note_rapprochement'   => "Intégré comme {$numero}, avoir de {$parent->numero_facture}.",
                'point_de_vente_id'    => $pdvId,
            ]);

            return $avoir;
        });
    }

    /**
     * La facture (ou l'avoir) reçue d'un fournisseur devient un achat (ou un
     * avoir d'achat) de Selflow.
     *
     * Elle était déjà passée en écriture par `EcritureFactureRecueService` :
     * rattachée à l'achat, cette écriture est contre-passée et c'est l'achat
     * qui porte la charge, une seule fois. La marchandise passe par une
     * proposition d'entrée (ou de sortie, pour un avoir).
     */
    private function integrerAchat(PortailFneFactureRecue $facture, Entreprise $entreprise, ?int $utilisateurId, array $choix, bool $estAvoir): Achat
    {
        $this->appartient($facture, $entreprise);
        $proposition = $this->proposer($facture, $entreprise);

        $pdvId = (int) ($choix['point_de_vente_id'] ?? $proposition['point_de_vente']?->id ?? 0);
        $pdv = PointDeVente::where('entreprise_id', $entreprise->id)->find($pdvId);

        $parent = null;
        if ($estAvoir) {
            $origine = $proposition['origine'];
            $parent = $origine === '' ? null : Achat::query()
                ->where('numero_facture_fournisseur', $origine)
                ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
                ->first();
            if (!$parent) {
                throw new \DomainException("L'avoir {$facture->reference} corrige la facture fournisseur « " . ($origine ?: 'inconnue')
                    . " », qui n'est pas dans Selflow : intégrez-la d'abord.");
            }
            $pdv = $pdv ?? $parent->pointDeVente;
        }

        if (!$pdv) {
            throw new \DomainException("Choisissez le point de vente qui a reçu la pièce {$facture->reference}.");
        }

        return DB::transaction(function () use ($facture, $entreprise, $utilisateurId, $choix, $proposition, $pdv, $parent, $estAvoir) {
            $facture = $this->verrouiller($facture);
            if (Achat::where('numero_facture_fournisseur', $facture->reference)
                ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))->exists()) {
                throw new \DomainException("La pièce {$facture->reference} est déjà dans Selflow.");
            }

            $fournisseur = EcritureFactureRecueService::fournisseurDe($facture, $entreprise);
            $mode   = $estAvoir ? ($parent->mode_paiement ?: 'Crédit') : $proposition['mode_paiement'];
            $credit = $estAvoir || $mode === 'Crédit';
            $numero = NumerotationService::genererNumeroAchat($entreprise->id, 'Facture', $estAvoir ? 'avoir' : null, $pdv->id);
            $date   = $facture->date_facture?->toDateString() ?? now()->toDateString();

            $achat = Achat::create(array_filter([
                'point_de_vente_id'          => $pdv->id,
                'utilisateur_id'             => $utilisateurId,
                'fournisseur_id'             => $fournisseur->id,
                'numero_facture'             => $numero,
                'numero_facture_fournisseur' => $facture->reference,
                'date_achat'                 => $date,
                'mode_paiement'              => $mode,
                'montant_ht'                 => (float) $facture->montant_ht,
                'montant_tva'                => (float) $facture->montant_tva,
                'montant_ttc'                => (float) $facture->montant_ttc,
                'remise'                     => 0,
                'statut'                     => $estAvoir ? 'Payé' : ($credit ? 'Crédit' : 'Payé'),
                'etape'                      => 'Facture',
                'type_facture'               => $estAvoir ? 'avoir' : 'normale',
                'parent_id'                  => $parent?->id,
                'autres_mentions'            => 'Pièce certifiée reçue sur le portail FNE de la DGI, reprise dans Selflow.',
            ], fn ($v) => $v !== null));

            $avecCompte = Schema::hasColumn('achat_details', 'compte_achat');

            foreach ($proposition['lignes'] as $i => $p) {
                $ligne = $p['ligne'];
                $produit = $this->produitChoisi($p, $choix, $i, $entreprise);
                $quantite = (float) $ligne->quantite;
                $prix     = (float) $ligne->prix_unitaire;
                $remise   = (float) ($ligne->remise ?? 0);
                $ht       = round($quantite * $prix * (1 - $remise / 100), 2);
                $tva      = round($ht * $p['taux'] / 100, 2);
                $compte   = trim((string) ($choix['lignes'][$i]['compte'] ?? '')) ?: $p['compte'];

                $detail = AchatDetail::create(array_filter([
                    'achat_id'              => $achat->id,
                    'produit_id'            => $produit?->id,
                    'reference_produit'     => $produit?->reference ?? $ligne->reference_article,
                    'libelle_virtuel'       => $produit ? null : ($ligne->designation ?: 'Ligne du portail FNE'),
                    'quantite'              => $quantite,
                    // L'entrée en stock passe par une proposition : marquée
                    // reçue, la ligne ne revient pas dans la file des réceptions.
                    'quantite_receptionnee' => $quantite,
                    'unite'                 => $ligne->unite ?: 'Unité',
                    'prix_unitaire'         => $prix,
                    'remise_taux'           => $remise,
                    'montant_tva'           => $tva,
                    'montant_ttc'           => $ht + $tva,
                    'compte_achat'          => ($avecCompte && !$produit) ? $compte : null,
                ], fn ($v) => $v !== null));

                $this->proposerMouvement($entreprise, $pdv->id, $produit, $estAvoir ? 'sortie' : 'entree', $quantite, $numero, null);
            }

            $achat->refresh();

            if ($estAvoir) {
                ComptabiliteService::genererEcritureAvoirAchat($achat);
            } else {
                $paye = $credit ? 0.0 : (float) $achat->montant_ttc;
                ComptabiliteService::genererEcrituresAchat($achat, $paye, $mode, $date);

                if ($paye > 0) {
                    $solde = TresorerieJournal::where('point_de_vente_id', $pdv->id)->orderByDesc('created_at')->value('solde_resultat') ?? 0;
                    TresorerieJournal::create([
                        'point_de_vente_id'  => $pdv->id,
                        'date_operation'     => $date,
                        'type_operation'     => 'Décaissement',
                        'libelle'            => ComptabiliteService::libelleTresorerieAchat($achat),
                        'mode_paiement'      => $mode,
                        'montant_entree'     => 0,
                        'montant_sortie'     => $paye,
                        'solde_resultat'     => $solde - $paye,
                        'reference_document' => $numero,
                    ]);
                }
            }

            $facture->update([
                'achat_id'             => $achat->id,
                'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE,
                'note_rapprochement'   => "Intégrée comme {$numero}.",
                'point_de_vente_id'    => $pdv->id,
            ]);

            // L'écriture passée à la réception du relevé est contre-passée :
            // l'achat porte désormais la charge.
            EcritureFactureRecueService::synchroniser($facture->refresh());

            return $achat;
        });
    }

    /** Les lignes d'une vente ou d'un avoir de vente, et leurs propositions de stock. */
    private function lignesDeVente(Vente $vente, array $proposition, array $choix, Entreprise $entreprise, int $pdvId, string $sens, string $numero): void
    {
        $avecCompte = Schema::hasColumn('vente_details', 'compte_vente');

        foreach ($proposition['lignes'] as $i => $p) {
            $ligne = $p['ligne'];
            $produit = $this->produitChoisi($p, $choix, $i, $entreprise);
            $quantite = (float) $ligne->quantite;
            $prix     = (float) $ligne->prix_unitaire;
            $remise   = (float) ($ligne->remise ?? 0);
            $ht       = round($quantite * $prix * (1 - $remise / 100), 2);
            $tva      = round($ht * $p['taux'] / 100, 2);
            $compte   = trim((string) ($choix['lignes'][$i]['compte'] ?? '')) ?: $p['compte'];

            $detail = VenteDetail::create(array_filter([
                'vente_id'          => $vente->id,
                'produit_id'        => $produit?->id,
                'reference_produit' => $produit?->reference ?? $ligne->reference_article,
                'libelle_virtuel'   => $produit ? null : ($ligne->designation ?: 'Ligne du portail FNE'),
                'quantite'          => $quantite,
                'quantite_livree'   => $quantite,
                'unite'             => $ligne->unite ?: 'Unité',
                'prix_unitaire'     => $prix,
                'remise_taux'       => $remise,
                'montant_tva'       => $tva,
                'montant_ttc'       => $ht + $tva,
                'compte_vente'      => ($avecCompte && !$produit) ? $compte : null,
            ], fn ($v) => $v !== null));

            $this->proposerMouvement($entreprise, $pdvId, $produit, $sens, $quantite, $numero, $vente, $detail->id);
        }
    }

    private function produitChoisi(array $p, array $choix, int $i, Entreprise $entreprise): ?Produit
    {
        if (array_key_exists('produit_id', $choix['lignes'][$i] ?? [])) {
            $choisi = $choix['lignes'][$i]['produit_id'];

            return $choisi ? Produit::where('entreprise_id', $entreprise->id)->find((int) $choisi) : null;
        }

        return $p['produit'];
    }

    private function proposerMouvement(Entreprise $entreprise, int $pdvId, ?Produit $produit, string $sens, float $quantite, string $numero, ?Vente $vente, ?int $detailId = null): void
    {
        if (!$produit || !$produit->estStockable() || !PropositionStock::disponible()) {
            return;
        }

        PropositionStock::create([
            'entreprise_id'     => $entreprise->id,
            'point_de_vente_id' => $pdvId,
            'produit_id'        => $produit->id,
            'vente_id'          => $vente?->id,
            'vente_detail_id'   => $detailId,
            'sens'              => $sens,
            'quantite'          => $quantite,
            'origine'           => 'portail_fne',
            'reference'         => $numero,
            'statut'            => PropositionStock::PROPOSEE,
        ]);
    }

    private function appartient(PortailFneFactureRecue $facture, Entreprise $entreprise): void
    {
        if ((int) $facture->entreprise_id !== (int) $entreprise->id) {
            throw new \DomainException('Cette pièce du portail appartient à une autre entreprise.');
        }
    }

    private function verrouiller(PortailFneFactureRecue $facture): PortailFneFactureRecue
    {
        return PortailFneFactureRecue::whereKey($facture->id)->lockForUpdate()->firstOrFail();
    }

    /** Écarter une facture : elle ne sera plus proposée. */
    public function ignorer(PortailFneFactureRecue $facture, string $motif = ''): void
    {
        $facture->update([
            'statut_rapprochement' => PortailFneFactureRecue::ECARTEE,
            'note_rapprochement'   => trim('Écartée de l’intégration. ' . $motif),
        ]);
    }

    /* ------------------------------------------------------------------ */

    private static function cle(?string $texte): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii((string) $texte))) ?? '';
    }

    private function pointDeVentePour(Entreprise $entreprise, string $nom): ?PointDeVente
    {
        $sites = PointDeVente::where('entreprise_id', $entreprise->id)->get();
        $cle = self::cle($nom);

        if ($cle !== '') {
            $trouve = $sites->first(fn (PointDeVente $p) => self::cle($p->nom) === $cle);
            if ($trouve) {
                return $trouve;
            }
        }

        return $sites->count() === 1 ? $sites->first() : null;
    }

    private function clientPour(Entreprise $entreprise, array $brut): ?Client
    {
        $ncc = EntreprisePortailFne::comparable($brut['clientNcc'] ?? '');
        $clients = Client::where('entreprise_id', $entreprise->id)->get();

        if ($ncc !== '') {
            $trouve = $clients->first(fn (Client $c) => EntreprisePortailFne::comparable($c->ncc) === $ncc);
            if ($trouve) {
                return $trouve;
            }
        }

        $nom = self::cle($brut['clientCompanyName'] ?? '');
        if ($ncc === '' && in_array($nom, self::CLIENTS_DE_PASSAGE, true)) {
            return null;
        }

        return $nom === '' ? null : $clients->first(fn (Client $c) => self::cle($c->nom) === $nom);
    }

    /**
     * Le client inconnu de Selflow est créé normalement (23/11/2026, propriétaire) :
     * dès qu'il porte un NCC, même si le portail l'appelle « Client de passage »,
     * ou dès qu'il a un vrai nom. Seule une vente sans NCC ni nom reste de passage.
     */
    private function creerClient(Entreprise $entreprise, array $brut): ?Client
    {
        $nom = trim((string) ($brut['clientCompanyName'] ?? ''));
        $ncc = trim((string) ($brut['clientNcc'] ?? ''));

        if ($ncc === '' && in_array(self::cle($nom), self::CLIENTS_DE_PASSAGE, true)) {
            return null;
        }

        if ($ncc !== '' && in_array(self::cle($nom), self::CLIENTS_DE_PASSAGE, true)) {
            $nom = 'Client NCC ' . $ncc;
        }

        return Client::create(array_filter([
            'entreprise_id'    => $entreprise->id,
            'nom'              => $nom,
            'ncc'              => $ncc ?: null,
            'telephone'        => trim((string) ($brut['clientPhone'] ?? '')) ?: null,
            'email'            => trim((string) ($brut['clientEmail'] ?? '')) ?: null,
            'type_facturation' => $ncc !== '' ? 'B2B' : 'B2C',
            'source'           => 'portail_fne',
        ], fn ($v) => $v !== null));
    }

    private function articlePour(Entreprise $entreprise, ?string $reference, ?string $designation): ?Produit
    {
        $reference = trim((string) $reference);

        if ($reference !== '') {
            $parCode = Produit::where('entreprise_id', $entreprise->id)->where('reference', $reference)->first();
            if ($parCode) {
                return $parCode;
            }
        }

        $cle = self::cle($designation);
        if ($cle === '') {
            return null;
        }

        return Produit::where('entreprise_id', $entreprise->id)->get(['id', 'nom', 'reference', 'type', 'categorie_id', 'compte_vente', 'entreprise_id'])
            ->first(fn (Produit $p) => self::cle($p->nom) === $cle);
    }

    /** Le taux de TVA d'une ligne : le portail met le taux dans `amount`. */
    private function tauxTva(mixed $taxes): float
    {
        foreach ((array) $taxes as $taxe) {
            if (is_array($taxe) && str_starts_with(strtoupper((string) ($taxe['shortName'] ?? '')), 'TVA')) {
                return (float) ($taxe['amount'] ?? 0);
            }
        }

        return 0.0;
    }

    private function mode(mixed $methode): string
    {
        $cle = Str::lower(trim((string) $methode));

        return self::MODES[$cle] ?? 'Espèces';
    }
}
