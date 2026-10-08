<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\BonLivraisonDetail;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Services\NumerotationService;
use App\Modules\Admin\Traits\JournaliseActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Modules\Admin\Services\StockService;
use App\Modules\Admin\Services\FacturationCommandeService;

class BonLivraisonControleur extends Controller
{
    use JournaliseActions;

    // ──────────────────────────────────────────────────────────────────────────
    // LISTE DES BL
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Liste tous les Bons de Livraison de l'entreprise.
     */
    public function index(): RedirectResponse
    {
        $route = request()->routeIs('caissier.*') ? 'caissier.ventes.factures' : 'admin.ventes.factures';
        return redirect()->route($route, array_merge(['etape' => 'Bon de livraison'], request()->query()));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CRÉATION D'UN BL DEPUIS UN BON DE COMMANDE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Ce qui interdit d'établir un bon de livraison sur ce document, ou `null`.
     */
    private static function obstacleALaLivraison(Vente $vente): ?string
    {
        if ($vente->etape !== 'Bon de commande') {
            return 'Ce document n\'est pas un bon de commande.';
        }
        if ($vente->estConverti()) {
            return 'Cette commande a déjà été facturée : il n\'y a plus rien à livrer sur elle.';
        }
        if ($vente->estEntierementLivree()) {
            return 'Cette commande est entièrement livrée : il ne reste rien à livrer.';
        }

        return null;
    }

    /**
     * Formulaire de création du BL pré-rempli depuis le BC.
     *
     * Une commande se livre en autant de bons qu'il le faut ; chaque bon
     * propose ce qui reste à livrer, borné par le stock disponible.
     */
    public function creerDepuisBC(Vente $vente): View|RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        // Sécurité : le BC doit appartenir à l'entreprise
        abort_unless($vente->pointDeVente->entreprise_id === $entreprise->id, 404);

        if ($obstacle = self::obstacleALaLivraison($vente)) {
            return back()->with('erreur', $obstacle);
        }

        $pointDeVenteId = $vente->point_de_vente_id;
        $vente->load('details.produit');
        $reste = $vente->resteALivrerParLigne();

        // Construire la liste des lignes avec contrôle de stock
        $lignes = $vente->details->filter(fn ($d) => ($reste[$d->id] ?? 0) > 0)->values()->map(function ($detail) use ($pointDeVenteId, $reste) {
            $stock = \App\Modules\Admin\Modeles\Stock::where('produit_id', $detail->produit_id)
                ->where('point_de_vente_id', $pointDeVenteId)
                ->first();

            // En float : le stock se compte aussi en kilos et en litres, et
            // (int) faisait apparaitre 12,5 kg comme 12 disponibles.
            $stockDispo    = $stock ? max(0, (float) $stock->quantite_disponible) : 0;
            $qteReste      = (float) $reste[$detail->id];
            $estStockable  = $detail->produit?->estStockable() ?? false;
            $qteSuggestion = $estStockable ? min($qteReste, $stockDispo) : $qteReste;
            $estInsuffisant = $estStockable && $stockDispo < $qteReste;

            return [
                'detail_id'      => $detail->id,
                'produit_id'     => $detail->produit_id,
                'libelle'        => $detail->libelle_virtuel ?? $detail->produit?->nom ?? '(article supprimé)',
                'unite'          => $detail->unite,
                'qte_commandee'  => (float) $detail->quantite,
                'qte_deja_livree'=> (float) $detail->quantite_livree,
                'qte_reste'      => $qteReste,
                'stock_dispo'    => $stockDispo,
                'qte_suggere'    => $qteSuggestion,
                'est_insuffisant'=> $estInsuffisant,
            ];
        });

        $stockInsuffisant = $lignes->where('est_insuffisant', true)->count();

        $livreurs = \App\Modules\Authentification\Modeles\Utilisateur::where('entreprise_id', $entreprise->id)
            ->orderBy('nom')->get(['id', 'nom', 'prenom']);

        return view('admin::ventes.livraison_creer', compact(
            'vente', 'lignes', 'stockInsuffisant', 'livreurs'
        ));
    }

    /**
     * Enregistre le Bon de Livraison.
     *
     * Les lignes se reconstruisent depuis la commande : la requête ne dit que
     * la quantité livrée sur chacune. Elle portait aussi l'article, la
     * quantité commandée et le libellé, et le bon les prenait tels quels —
     * 25 sacs livrés pour 10 commandés passaient sans rien dire.
     */
    public function enregistrer(Request $request, Vente $vente): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($vente->pointDeVente->entreprise_id === $entreprise->id, 404);

        $vente->load('details.produit');

        if ($obstacle = self::obstacleALaLivraison($vente)) {
            return back()->with('erreur', $obstacle);
        }

        // Validation
        $request->validate(\App\Modules\Admin\Services\TransportLivraisonService::reglesDuDepart($entreprise) + [
            'date_livraison'  => 'required|date',
            'notes'           => 'nullable|string|max:1000',
            'lignes'          => 'required|array|min:1',
            'lignes.*.detail_id'     => 'nullable|integer',
            'lignes.*.produit_id'    => 'nullable|integer',
            // Décimale : le ciment se livre au sac, le sable à la tonne et
            // l'huile au litre. La règle `integer` refusait 2,5.
            'lignes.*.qte_livree'    => \App\Modules\Admin\Regles\Quantite::facultative(),
        ], \App\Modules\Admin\Services\TransportLivraisonService::messages());

        // Chaque quantité livrée, rapportée à sa ligne de commande.
        $reste = $vente->resteALivrerParLigne();
        $aLivrer = [];
        foreach ((array) $request->input('lignes', []) as $ligne) {
            $qte = round((float) ($ligne['qte_livree'] ?? 0), \App\Modules\Admin\Modeles\Stock::DECIMALES);
            if ($qte <= 0) {
                continue;
            }

            $detail = !empty($ligne['detail_id'])
                ? $vente->details->firstWhere('id', (int) $ligne['detail_id'])
                : $vente->details->first(fn ($d) => (int) $d->produit_id === (int) ($ligne['produit_id'] ?? 0)
                    && !isset($aLivrer[$d->id]) && isset($reste[$d->id]));

            if (!$detail || !isset($reste[$detail->id])) {
                return back()->withInput()->with('erreur', 'Une ligne livrée ne figure pas sur cette commande.');
            }

            $aLivrer[$detail->id] = ($aLivrer[$detail->id] ?? 0) + $qte;
        }

        if ($aLivrer === []) {
            return back()->withInput()->with('erreur', 'Aucune quantité à livrer.');
        }

        // Plafond : le reste à livrer de chaque ligne, puis le stock.
        foreach ($aLivrer as $detailId => $qteL) {
            $detail = $vente->details->firstWhere('id', $detailId);
            $nom = $detail->produit?->nom ?? $detail->libelle_virtuel;
            if ($qteL > $reste[$detailId] + 0.0005) {
                return back()->withInput()->with('erreur',
                    "❌ « {$nom} » : {$qteL} à livrer pour un reste de {$reste[$detailId]} sur la commande."
                );
            }

            if ($detail->produit && $detail->produit->estStockable()) {
                $dispo = $detail->produit->stockActuel($vente->point_de_vente_id);
                if ($dispo < $qteL) {
                    return back()->withInput()->with('erreur',
                        "❌ Stock insuffisant pour livrer « {$nom} » (Disponible: {$dispo}, Demandé: {$qteL})."
                    );
                }
            }
        }

        $blId = null;
        $blCle = null;

        DB::transaction(function () use ($request, $vente, $entreprise, $aLivrer, &$blId, &$blCle) {
            $numeroBL = NumerotationService::genererNumeroBL($entreprise->id);

            // Créer le BL
            $bl = BonLivraison::create([
                'numero_bl'           => $numeroBL,
                'vente_id'            => $vente->id,
                'point_de_vente_id'   => $vente->point_de_vente_id,
                'client_id'           => $vente->client_id,
                'created_by'          => Auth::id(),
                'date_livraison'      => $request->date_livraison,
                'statut'              => 'en_preparation',
                'livraison_partielle' => false,
                'notes'               => $request->notes,
            ] + \App\Modules\Admin\Services\TransportLivraisonService::depart($request));

            foreach ($aLivrer as $detailId => $qteL) {
                $detail = $vente->details->firstWhere('id', $detailId);

                BonLivraisonDetail::create([
                    'bon_livraison_id' => $bl->id,
                    'vente_detail_id'  => $detail->id,
                    'produit_id'       => $detail->produit_id,
                    'libelle'          => $detail->libelle_virtuel ?? $detail->produit?->nom ?? 'Article',
                    'unite'            => $detail->unite,
                    'qte_commandee'    => (float) $detail->quantite,
                    'qte_livree'       => $qteL,
                ]);

                // Déduire du stock (sortie), avec verrou, contrôle final et traçabilité
                $produit = Produit::lockForUpdate()->find($detail->produit_id);
                if ($produit && $produit->estStockable()) {
                    // La disponibilite se lit sous le verrou de la fiche,
                    // celui-la meme qui servira a la sortie.
                    $disponible = StockService::disponible($produit, (int) $vente->point_de_vente_id);

                    if ($disponible < $qteL) {
                        throw new \InvalidArgumentException(
                            "Stock insuffisant pour livrer « {$produit->nom} » (Disponible: {$disponible}, Demandé: {$qteL})."
                        );
                    }

                    StockService::sortie($produit, (int) $vente->point_de_vente_id, (float) $qteL,
                        MouvementStock::LIVRAISON, [
                            'piece'     => $bl,
                            'reference' => $numeroBL,
                            'client_id' => $vente->client_id,
                        ]);
                }

                // La ligne de commande retient ce qui est parti : c'est le
                // reste à livrer du prochain bon, et ce que la file du stock
                // (StockControleur::livraisons()) ne proposera plus.
                $detail->increment('quantite_livree', $qteL);
            }

            // Partiel : quelque chose reste à livrer sur la commande après ce
            // bon — et non « ce bon livre moins que la commande », ce qui
            // marquait partiel le bon qui solde une livraison.
            $vente->load('details');
            $estPartiel = !$vente->estEntierementLivree();
            $bl->update(['statut' => $estPartiel ? 'partiel' : 'en_preparation', 'livraison_partielle' => $estPartiel]);

            // Mettre à jour le statut logistique du BC
            $vente->update(['statut' => $estPartiel ? 'Partiel' : 'En livraison']);

            $blId = $bl->id;
            // Le journal retient le numéro de ligne ; l'adresse, elle, se lie
            // par `uuid` — `BonLivraison` porte `IdentifiantOpaque`.
            $blCle = $bl->getRouteKey();
        });

        $this->journaliser('creation_bon_livraison', 'BonLivraison', $blId);

        $routeVoir = request()->routeIs('caissier.*')
            ? 'caissier.ventes.livraison.voir'
            : 'admin.ventes.livraison.voir';

        return redirect()->route($routeVoir, $blCle)
            ->with('succes', 'Bon de livraison créé avec succès.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // IMPRESSION / VUE DU BL
    // ──────────────────────────────────────────────────────────────────────────

    public function imprimer(BonLivraison $bl): View
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($bl->pointDeVente->entreprise_id === $entreprise->id, 404);

        $bl->load(['details.produit', 'bonDeCommande.utilisateur', 'bonDeCommande.details.produit', 'bonDeCommande.details.taxes',
            'bonDeCommande.taxesPersonnalisees', 'facture', 'client', 'pointDeVente']);

        $vente = $bl->bonDeCommande;
        $vendeur = $vente->utilisateur;
        $dejaPaye = TresorerieJournal::where('reference_document', $vente->numero_facture)->sum('montant_entree');

        // Ce que la facture de ce bon demandera au client : les quantités
        // livrées, aux prix et remises de la commande. La modale proposait le
        // TTC de toute la commande, et un bon partiel encaissait le tout.
        $montantAFacturer = FacturationCommandeService::netHorsTimbre(
            $vente, FacturationCommandeService::quantitesDuBon($bl, $vente)
        );

        $banques = CodeJournal::where('type', 'Banque')->where('entreprise_id', $entreprise->id)->orderBy('intitule')->get();

        return view('admin::factures.vente', compact('bl', 'vente', 'vendeur', 'dejaPaye', 'banques', 'montantAFacturer'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MARQUER LIVRÉ
    // ──────────────────────────────────────────────────────────────────────────

    public function marquerLivre(Request $request, BonLivraison $bl): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($bl->pointDeVente->entreprise_id === $entreprise->id, 404);

        if ($bl->statut === 'facture') {
            return back()->with('info', 'Ce BL est déjà facturé.');
        }

        // L'arrivée ne se confirme plus d'un clic : l'heure, le réceptionnaire
        // et sa signature sont ce qui prouve la remise (chantier 15.3).
        $request->validate(
            \App\Modules\Admin\Services\TransportLivraisonService::reglesDeLArrivee($bl),
            \App\Modules\Admin\Services\TransportLivraisonService::messages()
        );

        $statut = $bl->livraison_partielle ? 'partiel' : 'livre';
        $bl->update(['statut' => $statut] + \App\Modules\Admin\Services\TransportLivraisonService::arrivee($request));

        $this->journaliser('livraison_confirmee', 'BonLivraison', $bl->id);

        return back()->with('succes', 'Bon de livraison marqué comme ' . ($bl->livraison_partielle ? 'partiel' : 'livré') . '.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CONVERTIR EN FACTURE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Ce qui interdit de facturer ce bon, ou `null`.
     */
    private static function obstacleALaFacturation(BonLivraison $bl, Vente $bc, string $base): ?string
    {
        if ($bl->statut === 'facture' || $bl->facture_vente_id) {
            return 'Ce BL est déjà facturé.';
        }

        // La commande a déjà sa facture — en bloc, ou par le dernier de ses
        // bons : la facturer encore, c'est facturer deux fois le client.
        if ($bc->estConverti()) {
            return 'La commande ' . $bc->numero_facture . ' est déjà facturée'
                . ($bc->convertiEn ? ' (' . $bc->convertiEn->numero_facture . ')' : '') . '.';
        }

        // « Quantités de la commande » facture toute la commande : ce n'est
        // possible que tant qu'aucun de ses bons n'a été facturé.
        if ($base === 'commandee' && $bc->aUnBonDeLivraisonFacture()) {
            return 'Un autre bon de cette commande est déjà facturé : facturez celui-ci sur les quantités livrées.';
        }

        return null;
    }

    /**
     * Génère une Facture depuis ce BL.
     *
     * Deux bases :
     *
     * - **les quantités livrées** — la facture porte ce que ce bon a remis ;
     *   la commande se clôt quand elle est entièrement livrée et que tous ses
     *   bons sont facturés ;
     * - **les quantités de la commande** — la facture porte toute la
     *   commande, le reste non livré part à l'instant, et la commande se clôt.
     *
     * Les montants se calculent comme à la caisse, remise globale et taxes
     * comprises (`FacturationCommandeService`).
     */
    public function convertirEnFacture(Request $request, BonLivraison $bl): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($bl->pointDeVente->entreprise_id === $entreprise->id, 404);

        if ($bl->statut === 'facture' || $bl->facture_vente_id) {
            return back()->with('erreur', 'Ce BL est déjà facturé.');
        }

        // Valider les champs de règlement
        $request->validate([
            'base_facturation'   => 'required|in:livree,commandee',
            'mode_paiement'      => 'required|in:Caisse,Banque,Crédit',
            'montant_paye'       => 'nullable|numeric|min:0',
            'banque_id'          => ['required_if:mode_paiement,Banque', 'nullable', 'integer', \App\Modules\Admin\Regles\Appartenance::a('codes_journaux', 'id')],
            'moyen_bancaire'     => 'required_if:mode_paiement,Banque|nullable|in:carte,virement,cheque',
            'reference_paiement' => 'required_if:mode_paiement,Banque|nullable|string|max:100',
        ]);

        $baseQte = $request->input('base_facturation', 'livree');
        $bc = $bl->bonDeCommande->load('details.produit', 'details.taxes', 'taxesPersonnalisees');

        if ($obstacle = self::obstacleALaFacturation($bl, $bc, $baseQte)) {
            return back()->with('erreur', $obstacle);
        }

        if ($baseQte === 'commandee' && ($manque = FacturationCommandeService::manqueDeStockPourLeReste($bc))) {
            return back()->with('erreur', $manque);
        }

        $quantites = $baseQte === 'commandee'
            ? FacturationCommandeService::quantitesDeLaCommande($bc)
            : FacturationCommandeService::quantitesDuBon($bl, $bc);

        $facture = DB::transaction(function () use ($bl, $bc, $baseQte, $request, $quantites) {
            $facture = FacturationCommandeService::etablir($bc, $quantites, [
                'mode'               => $request->mode_paiement,
                'montant'            => $request->input('montant_paye'),
                'banque_id'          => $request->input('banque_id'),
                'moyen_bancaire'     => $request->input('moyen_bancaire'),
                'reference_paiement' => $request->input('reference_paiement'),
            ], [
                'bon_livraison_id'  => $bl->id,
                'expedier_le_reste' => $baseQte === 'commandee',
            ]);

            // Le bon est facturé. Sa remise au client, elle, garde sa propre
            // preuve — l'heure d'arrivée et la signature du réceptionnaire —,
            // qu'aucune case à cocher ne remplace : la case « livraison
            // immédiate et finale » marquait livré un bon que personne n'avait
            // signé, et déclarait complet un bon partiel.
            $bl->update(['statut' => 'facture', 'facture_vente_id' => $facture->id]);

            if ($baseQte === 'commandee') {
                // Toute la commande est sur cette facture : ses autres bons
                // aussi.
                BonLivraison::where('vente_id', $bc->id)->whereNull('facture_vente_id')
                    ->update(['statut' => 'facture', 'facture_vente_id' => $facture->id]);
            }

            $bc->refresh()->load('details');
            $resteNonFacture = BonLivraison::where('vente_id', $bc->id)->whereNull('facture_vente_id')->exists();
            if ($bc->estEntierementLivree() && !$resteNonFacture) {
                FacturationCommandeService::clore($bc, $facture);
            }

            return $facture;
        });

        $this->journaliser('facturation_depuis_bl', 'BonLivraison', $bl->id);

        $route = request()->routeIs('caissier.*') ? 'caissier.ventes.imprimer' : 'admin.ventes.imprimer';
        return redirect()->route($route, $facture)
            ->with('succes', 'Facture ' . $facture->numero_facture . ' générée depuis le bon ' . $bl->numero_bl . '.');
    }
}
