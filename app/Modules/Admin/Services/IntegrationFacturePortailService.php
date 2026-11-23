<?php

namespace App\Modules\Admin\Services;

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

    /** Les factures du portail qui attendent d'être intégrées. */
    public function enAttente(Entreprise $entreprise): Collection
    {
        $this->rapprocherCeQueSelflowConnait($entreprise);

        return PortailFneFactureRecue::query()
            ->with('lignes')
            ->where('entreprise_id', $entreprise->id)
            ->where('liste_portail', PortailFneFactureRecue::LISTE_EMISES)
            ->where('subtype', 'normal')
            ->where(fn ($q) => $q->whereNull('statut_rapprochement')
                ->orWhereNotIn('statut_rapprochement', [PortailFneFactureRecue::RAPPROCHEE, PortailFneFactureRecue::ECARTEE]))
            ->orderBy('date_facture')
            ->get();
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

        $n = 0;
        foreach ($factures as $facture) {
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

        $lignes = $facture->lignes->map(function ($ligne) use ($entreprise, $brut) {
            $produit = $this->articlePour($entreprise, $ligne->reference_article, $ligne->designation);

            return [
                'ligne'   => $ligne,
                'produit' => $produit,
                'taux'    => $this->tauxTva($ligne->taxes),
                'compte'  => $produit
                    ? ImputationService::compteVente($produit, $entreprise->id)
                    : $this->compteLigneLibre($entreprise, $brut),
            ];
        });

        return [
            'point_de_vente' => $this->pointDeVentePour($entreprise, (string) ($brut['clientPointOfSale'] ?? '')),
            'nom_point_de_vente' => (string) ($brut['clientPointOfSale'] ?? ''),
            'client'         => $this->clientPour($entreprise, $brut),
            'client_nom'     => trim((string) ($brut['clientCompanyName'] ?? '')) ?: 'Client de passage',
            'client_ncc'     => trim((string) ($brut['clientNcc'] ?? '')),
            'mode_paiement'  => $this->mode($brut['paymentMethod'] ?? $facture->moyen_paiement),
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

    /**
     * Intègre la facture comme une vente normalisée.
     *
     * @param  array{point_de_vente_id?: int|null, lignes?: array<int, array{produit_id?: int|null, compte?: string|null}>}  $choix
     */
    public function integrer(PortailFneFactureRecue $facture, Entreprise $entreprise, ?int $utilisateurId, array $choix = []): Vente
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
        if (in_array($nom, self::CLIENTS_DE_PASSAGE, true)) {
            return null;
        }

        return $clients->first(fn (Client $c) => self::cle($c->nom) === $nom);
    }

    /** Un client nommé par la facture et inconnu de Selflow est créé ; un passage reste sans client. */
    private function creerClient(Entreprise $entreprise, array $brut): ?Client
    {
        $nom = trim((string) ($brut['clientCompanyName'] ?? ''));
        if (in_array(self::cle($nom), self::CLIENTS_DE_PASSAGE, true)) {
            return null;
        }

        $ncc = trim((string) ($brut['clientNcc'] ?? ''));

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
