<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\TransfertStock;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Regles\Quantite;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Services\StockService;
use Illuminate\Support\Facades\DB;

class StockControleur
{
    use \App\Modules\Admin\Traits\JournaliseActions;

    public function index(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        
        // Liste de tous les points de vente
        $pointsDeVente = $entreprise->pointsDeVente()->orderBy('nom')->get();

        // Récupérer le point de vente à filtrer (session, requête ou par défaut 'tout')
        $pointDeVenteId = $request->input('point_de_vente_id') ?? $request->input('site_id');
        if ($pointDeVenteId === null) {
            $pointDeVenteId = session('point_de_vente_actif_id') 
                ?? Auth::user()->point_de_vente_id 
                ?? 'tout';
        }

        // L'ecran de stock listait tout le catalogue : un cabinet, qui ne vend
        // que des prestations, y voyait chacune de ses lignes en « Rupture » et
        // son compteur d'alertes egal a son catalogue. Un service n'a pas de
        // stock — il n'a rien a faire ici.
        // Un article archivé qui porte encore de la marchandise n'a pas quitté
        // le magasin : le taire ferait tomber la valeur de l'inventaire sans
        // que rien ne l'explique, alors que la quantité reste aux écritures.
        $query = Produit::where('produits.entreprise_id', $entreprise->id)
            ->stockables()
            ->visiblesEnStock()
            ->with(['stocks']);

        $produits = $query
            ->leftJoin('categories', 'produits.categorie_id', '=', 'categories.id')
            ->select('produits.*')
            ->orderBy('categories.nom')
            ->orderBy('produits.nom')
            ->get();

        // Surcharger stock_actuel et stock_minimum pour la compatibilité avec la vue
        foreach ($produits as $p) {
            if ($pointDeVenteId === 'tout') {
                $totalStock = $p->stocks->sum('quantite_disponible');
                $totalMin = $p->stocks->sum('stock_minimum');
                $p->setAttribute('stock_actuel', $totalStock);
                $p->setAttribute('stock_minimum', $totalMin);
            } else {
                $stockObj = $p->stocks->where('point_de_vente_id', $pointDeVenteId)->first();
                $p->setAttribute('stock_actuel', $stockObj ? $stockObj->quantite_disponible : 0);
                $p->setAttribute('stock_minimum', $stockObj ? $stockObj->stock_minimum : 5);
            }
        }

        $categories = $produits->pluck('categorie')->unique()->sort()->values();

        // Compteurs interactifs Odoo filtrés par point de vente actif
        /*
         * Ce qui reste à recevoir, et à livrer.
         *
         * **Une ligne de service ne se réceptionne pas.** Sa
         * `quantite_receptionnee` reste à zéro pour toujours, puisque rien
         * n'entre en stock : une prestation de conseil figurait donc
         * indéfiniment dans « à réceptionner », et le compteur de l'écran de
         * stock annonçait un travail qui n'existait pas. Pire, la fiche
         * n'offrait aucun champ à saisir — l'article n'étant pas stockable —
         * et le formulaire répondait « le champ reception est obligatoire ».
         *
         * Le filtre porte donc sur l'article autant que sur la quantité.
         */
        $qReceptions = Achat::whereIn('etape', ['Bon de commande', 'Facture'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->whereHas('details', function($q) {
                $q->whereColumn('quantite', '>', 'quantite_receptionnee')
                  ->whereHas('produit', fn($qp) => $qp->stockables());
            });

        $qLivraisons = Vente::whereIn('etape', ['Bon de commande', 'Facture'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->whereHas('details', function($q) {
                $q->whereColumn('quantite', '>', 'quantite_livree')
                  ->whereHas('produit', fn($qp) => $qp->stockables());
            });

        $qTransferts = TransfertStock::enAttente()
            ->whereHas('produit', fn($q) => $q->where('entreprise_id', $entreprise->id));

        if ($pointDeVenteId !== 'tout') {
            $qReceptions->where('point_de_vente_id', $pointDeVenteId);
            $qLivraisons->where('point_de_vente_id', $pointDeVenteId);
            $qTransferts->where(function($q) use ($pointDeVenteId) {
                $q->where('point_de_vente_source_id', $pointDeVenteId)
                  ->orWhere('point_de_vente_destination_id', $pointDeVenteId);
            });
        }

        $receptionsATraiter = $qReceptions->count();
        $livraisonsATraiter = $qLivraisons->count();
        $transfertsATraiter = $qTransferts->count();

        return view('admin::stock.index', compact(
            'produits', 
            'categories', 
            'pointsDeVente', 
            'pointDeVenteId',
            'receptionsATraiter',
            'livraisonsATraiter',
            'transfertsATraiter'
        ));
    }

    public function mouvements(Request $request): View
    {
        $entreprise  = Auth::user()->entreprise;
        $pointDeVenteId = Auth::user()->estCaissier()
            ? Auth::user()->point_de_vente_id
            : session('point_de_vente_actif_id');

        $section = $request->input('section', 'tous');

        $query = MouvementStock::with([
            'produit', 'pointDeVente', 'pointDeVenteContrepartie', 'utilisateur', 'fournisseur', 'client'
        ]);

        if ($pointDeVenteId) {
            $query->where('point_de_vente_id', $pointDeVenteId);
        } else {
            $query->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));
        }

        // Filtrer selon la section. Les motifs sont des constantes de
        // MouvementStock : ces comparaisons portaient la casse d'origine
        // (« Reception ») quand l'écran comparait en minuscules, et rien ne
        // signalait qu'un contrôleur écrivant une autre forme rendait la
        // section vide.
        $motifs = [
            'achats'      => MouvementStock::RECEPTION,
            'ventes'      => MouvementStock::LIVRAISON,
            'transferts'  => MouvementStock::TRANSFERT,
            'rebuts'      => MouvementStock::REBUT,
        ];

        if (isset($motifs[$section])) {
            $query->where('sous_type', $motifs[$section]);
        }

        $mouvements = $query->latest()->paginate(30);

        return view('admin::stock.mouvements', compact('mouvements', 'section'));
    }

    /**
     * L'écran de comptage physique.
     *
     * Le stock théorique est ce que l'application croit ; l'inventaire est ce
     * que l'on trouve dans le magasin. L'écart existe toujours — casse non
     * déclarée, erreur de saisie, vol — et il n'avait jusqu'ici aucun moyen
     * d'entrer dans Selflow : la seule correction possible était de passer un
     * rebut, ce qui ne sait pas dire « il y en a plus que prévu ».
     */
    public function inventaire(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        // Un comptage se fait dans un magasin, pas dans une entreprise : sans
        // site désigné, il n'y a rien à compter.
        $pointDeVenteId = $this->siteDeTravail($request);

        $produits = collect();

        if ($pointDeVenteId) {
            // Un inventaire se compte sur ce qui est en rayon : l'article
            // archivé qui porte encore une quantité doit pouvoir être compté,
            // sans quoi l'écart resterait au stock sans moyen de le solder.
            $produits = Produit::where('entreprise_id', $entreprise->id)
                ->whereIn('type', Produit::TYPES_STOCKABLES)
                ->visiblesEnStock()
                ->with(['stocks' => fn ($q) => $q->where('point_de_vente_id', $pointDeVenteId)])
                ->orderBy('nom')
                ->get();
        }

        $pointsDeVente = $entreprise->pointsDeVente()->orderBy('nom')->get();

        return view('admin::stock.inventaire', compact('produits', 'pointDeVenteId', 'pointsDeVente'));
    }

    /**
     * Enregistrer un comptage physique.
     *
     * Seules les lignes réellement comptées sont retenues : un champ laissé
     * vide veut dire « pas compté », et non « zéro ». Confondre les deux
     * viderait le magasin de tout ce que l'inventaire n'a pas parcouru.
     *
     * Chaque écart devient un mouvement, dans le sens qu'il faut. Un comptage
     * conforme n'écrit rien.
     */
    public function enregistrerInventaire(Request $request): \Illuminate\Http\RedirectResponse
    {
        $donnees = $request->validate([
            'point_de_vente_id'  => ['required', 'integer', Appartenance::a('points_de_vente', 'id')],
            'comptages'          => ['required', 'array', 'min:1'],
            'comptages.*'        => Quantite::facultative(),
        ]);

        $entreprise = Auth::user()->entreprise;

        // `Appartenance` a deja ecarte le site d'une autre entreprise ; la
        // verification ci-dessous est la ceinture apres les bretelles.
        $site = PointDeVente::where('id', $donnees['point_de_vente_id'])
            ->where('entreprise_id', $entreprise->id)
            ->firstOrFail();

        // Les identifiants d'articles viennent du formulaire : ils sont
        // confrontes au catalogue de l'entreprise avant tout. Sans cela, il
        // suffirait de poster l'identifiant du produit du voisin pour ecrire
        // dans son stock.
        $comptages = array_filter(
            $donnees['comptages'],
            fn ($valeur) => $valeur !== null && $valeur !== ''
        );

        if ($comptages === []) {
            return back()->with('erreur', "Aucune quantité comptée : rien n'a été enregistré.");
        }

        $produits = Produit::where('entreprise_id', $entreprise->id)
            ->whereIn('id', array_keys($comptages))
            ->get()
            ->keyBy('id');

        $ecarts = 0;

        DB::transaction(function () use ($comptages, $produits, $site, &$ecarts) {
            foreach ($comptages as $produitId => $quantite) {
                $produit = $produits->get((int) $produitId);

                if (!$produit) {
                    continue;
                }

                $mouvement = StockService::inventorier(
                    $produit,
                    (int) $site->id,
                    round((float) $quantite, Stock::DECIMALES),
                    ['reference' => 'INV-' . now()->format('YmdHis')]
                );

                $ecarts += $mouvement ? 1 : 0;
            }
        });

        $this->journaliser('inventaire_physique', 'PointDeVente', $site->id);

        return back()->with('succes', $ecarts === 0
            ? 'Comptage enregistré : aucun écart, le stock théorique était juste.'
            : "Comptage enregistré : {$ecarts} écart(s) ajusté(s) sur « {$site->nom} ».");
    }

    /**
     * Le site sur lequel on travaille, ou `null` si l'utilisateur regarde
     * « tous les sites » — auquel cas aucune écriture n'est possible.
     */
    private function siteDeTravail(Request $request): ?int
    {
        $demande = $request->input('point_de_vente_id')
            ?? session('point_de_vente_actif_id')
            ?? Auth::user()->point_de_vente_id;

        if (!$demande || $demande === 'tout') {
            return null;
        }

        // Un identifiant venu de l'URL est confronte a l'entreprise avant
        // d'etre retenu : sans cela, `?point_de_vente_id=` afficherait le stock
        // du voisin.
        return PointDeVente::where('id', $demande)
            ->where('entreprise_id', Auth::user()->entreprise_id)
            ->value('id');
    }

    /**
     * Liste des produits périmés ou proches de la péremption (Page Rebut).
     */
    public function rebut(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = session('point_de_vente_actif_id') 
            ?? Auth::user()->point_de_vente_id 
            ?? 'tout';

        // Produits avec date de péremption définie. Seuls ceux qui ont un
        // stock : on ne met pas une prestation au rebut.
        $query = Produit::where('entreprise_id', $entreprise->id)
            ->stockables()
            ->whereNotNull('date_peremption')
            ->with(['stocks']);

        $produits = $query->get();

        // Séparer les produits en deux collections : périmés et bientôt périmés (< 30 jours)
        $perimes = collect();
        $proches = collect();

        foreach ($produits as $p) {
            // Surcharger le stock disponible pour le site actif
            if ($pointDeVenteId === 'tout') {
                $p->setAttribute('stock_actuel', $p->stocks->sum('quantite_disponible'));
            } else {
                $stockObj = $p->stocks->where('point_de_vente_id', $pointDeVenteId)->first();
                $p->setAttribute('stock_actuel', $stockObj ? $stockObj->quantite_disponible : 0);
            }

            if ($p->estPerime()) {
                $perimes->push($p);
            } elseif ($p->bientotPerime()) {
                $proches->push($p);
            }
        }

        // **Les lots, là où la vraie péremption se joue.**
        //
        // `produits.date_peremption` ne porte qu'une date par article : une
        // pharmacie qui reçoit trois arrivages de paracétamol n'en enregistre
        // qu'un, la saisie du troisième écrasant les deux premiers, et les
        // boîtes de mars restent en rayon. Les deux listes ci-dessus restent
        // pour les articles qui ne sont pas suivis par lot, où une date unique
        // suffit ; celles-ci portent le suivi juste.
        $site = $pointDeVenteId === 'tout' ? null : (int) $pointDeVenteId;

        $lotsPerimes = \App\Modules\Admin\Services\LotService::perimes($entreprise->id, $site);
        $lotsProches = \App\Modules\Admin\Services\LotService::bientotPerimes($entreprise->id, $site);

        return view('admin::stock.rebut', compact(
            'perimes', 'proches', 'lotsPerimes', 'lotsProches', 'pointDeVenteId'
        ));
    }

    /**
     * Action de retrait d'un produit périmé (Rebut).
     */
    public function retirerRebut(Request $request): \Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'produit_id' => ['required', 'integer', Appartenance::a('produits', 'id')],
            'quantite'   => Quantite::physique(),
        ]);

        $produit = Produit::findOrFail($request->produit_id);
        abort_unless($produit->entreprise_id === Auth::user()->entreprise_id, 404);

        $pointDeVenteId = session('point_de_vente_actif_id') 
            ?? Auth::user()->point_de_vente_id;

        if (!$pointDeVenteId || $pointDeVenteId === 'tout') {
            return back()->with('erreur', 'Veuillez sélectionner un point de vente spécifique pour effectuer un retrait.');
        }

        $quantiteRetrait = round((float) $request->quantite, Stock::DECIMALES);

        // La disponibilité se lit sous le verrou qui servira au retrait : lue
        // hors transaction, elle est déjà périmée au moment où l'on s'en sert.
        $insuffisant = DB::transaction(function () use ($produit, $pointDeVenteId, $quantiteRetrait) {
            $disponible = StockService::disponible($produit, (int) $pointDeVenteId);

            if ($quantiteRetrait > $disponible) {
                return $disponible;
            }

            StockService::sortie($produit, (int) $pointDeVenteId, $quantiteRetrait, MouvementStock::REBUT, [
                'reference' => 'REBUT-' . now()->format('YmdHis'),
            ]);

            return null;
        });

        if ($insuffisant !== null) {
            return back()->with('erreur', "Quantité insuffisante en stock (Disponible: {$insuffisant}).");
        }

        // Journaliser
        \App\Modules\Admin\Modeles\JournalAudit::create([
            'entreprise_id'  => $produit->entreprise_id,
            'utilisateur_id' => Auth::id(),
            'action'         => 'retrait_rebut',
            'details'        => "Retrait de {$quantiteRetrait} {$produit->unite} de {$produit->nom} pour rebut",
        ]);

        return back()->with('succes', 'Le produit a été retiré du stock avec succès.');
    }

    /**
     * Liste des réceptions fournisseur en attente (Flux Réception).
     */
    public function receptions(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        $achats = Achat::with(['fournisseur', 'pointDeVente', 'details.produit'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->whereIn('etape', ['Bon de commande', 'Facture'])
            // Même règle que le compteur : une commande de services n'a rien
            // à recevoir, et ne doit pas encombrer la file.
            ->whereHas('details', function ($q) {
                $q->whereColumn('quantite', '>', 'quantite_receptionnee')
                  ->whereHas('produit', fn($qp) => $qp->stockables());
            })
            ->latest()
            ->paginate(20);

        return view('admin::stock.receptions', compact('achats'));
    }

    /**
     * Fiche de réception fournisseur.
     */
    public function ficheReception(Achat $achat): View
    {
        abort_unless($achat->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        $achat->load(['fournisseur', 'pointDeVente', 'details.produit']);

        return view('admin::stock.reception_fiche', compact('achat'));
    }

    /**
     * Valider la réception physique d'une commande d'achat.
     */
    public function validerReception(Request $request, Achat $achat): \Illuminate\Http\RedirectResponse
    {
        abort_unless($achat->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        
        // `required` sur un formulaire qui n'offre aucun champ : c'est le
        // message qu'on lisait sur une commande de services — « le champ
        // reception est obligatoire », sans rien à remplir. La file ne montre
        // plus ces commandes ; si l'on force l'adresse, on le dit en clair.
        $request->validate([
            'reception'   => ['nullable', 'array'],
            'reception.*' => Quantite::facultative(),
        ]);

        $aRecevoir = collect($request->input('reception', []))
            ->filter(fn ($q) => (float) $q > 0);

        if ($aRecevoir->isEmpty()) {
            return back()->with('erreur', "Aucune quantité à réceptionner. Une prestation de service n'entre pas en stock : il n'y a rien à recevoir sur cette commande.");
        }

        $pointDeVenteId = $achat->point_de_vente_id;

        DB::transaction(function () use ($request, $achat, $pointDeVenteId) {
            foreach ($request->reception as $detailId => $qtyAReceptionner) {
                $qtyAReceptionner = round((float) $qtyAReceptionner, Stock::DECIMALES);
                if ($qtyAReceptionner <= 0) continue;

                $detail = AchatDetail::findOrFail($detailId);
                abort_unless($detail->achat_id === $achat->id, 403);

                $dejaRecu = $detail->quantite_receptionnee;
                $cmd      = $detail->quantite;
                $maxPossible = $cmd - $dejaRecu;

                if ($qtyAReceptionner > $maxPossible) {
                    throw new \InvalidArgumentException("La quantité reçue pour {$detail->produit->nom} ne peut dépasser {$maxPossible}.");
                }

                $produit = $detail->produit;
                if ($produit && $produit->estStockable()) {
                    $detail->increment('quantite_receptionnee', $qtyAReceptionner);

                    StockService::entree($produit, (int) $pointDeVenteId, (float) $qtyAReceptionner,
                        MouvementStock::RECEPTION, [
                            'piece'          => $achat,
                            'reference'      => $achat->numero_facture,
                            'fournisseur_id' => $achat->fournisseur_id,
                            // Le cout d'entree est le prix reellement paye sur
                            // la ligne, remise deduite.
                            'cout_unitaire'  => (float) $detail->prix_unitaire
                                * (1 - (float) ($detail->remise_taux ?? 0) / 100),
                        ]);
                }
            }

            // Mettre à jour l'étape ou le statut si tout est entièrement réceptionné
            $toutRecu = true;
            foreach ($achat->details as $d) {
                if ($d->quantite_receptionnee < $d->quantite) {
                    $toutRecu = false;
                    break;
                }
            }
            if ($toutRecu && $achat->etape === 'Bon de commande') {
                $achat->update(['etape' => 'Facture', 'statut' => 'Payé']);
            }
        });

        // Journaliser
        \App\Modules\Admin\Modeles\JournalAudit::create([
            'entreprise_id'  => $achat->pointDeVente->entreprise_id,
            'utilisateur_id' => Auth::id(),
            'action'         => 'reception_stock',
            'details'        => "Réception de stock effectuée pour l'achat #{$achat->id} ({$achat->numero_facture})",
        ]);

        return redirect()->route('admin.stock.receptions')
            ->with('succes', 'Réception validée et enregistrée en stock.');
    }

    /**
     * Liste des livraisons client en attente (Flux Livraison).
     */
    public function livraisons(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        $ventes = Vente::with(['client', 'pointDeVente', 'details.produit'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->whereIn('etape', ['Bon de commande', 'Facture'])
            // Même règle qu'à la réception : une prestation de service ne se
            // livre pas depuis un stock, et sa ligne resterait « à livrer »
            // pour toujours.
            ->whereHas('details', function ($q) {
                $q->whereColumn('quantite', '>', 'quantite_livree')
                  ->whereHas('produit', fn($qp) => $qp->stockables());
            })
            ->latest()
            ->paginate(20);

        return view('admin::stock.livraisons', compact('ventes'));
    }

    /**
     * Fiche de livraison client.
     */
    public function ficheLivraison(Vente $vente): View
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        $vente->load(['client', 'pointDeVente', 'details.produit']);

        return view('admin::stock.livraison_fiche', compact('vente'));
    }

    /**
     * Valider la livraison physique d'une commande de vente.
     */
    public function validerLivraison(Request $request, Vente $vente): \Illuminate\Http\RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);

        $request->validate([
            'livraison'   => ['nullable', 'array'],
            'livraison.*' => Quantite::facultative(),
        ]);

        $aLivrer = collect($request->input('livraison', []))
            ->filter(fn ($q) => (float) $q > 0);

        if ($aLivrer->isEmpty()) {
            return back()->with('erreur', "Aucune quantité à livrer. Une prestation de service ne sort pas d'un stock : il n'y a rien à expédier sur cette pièce.");
        }

        $pointDeVenteId = $vente->point_de_vente_id;

        DB::transaction(function () use ($request, $vente, $pointDeVenteId) {
            foreach ($request->livraison as $detailId => $qtyALivrer) {
                $qtyALivrer = round((float) $qtyALivrer, Stock::DECIMALES);
                if ($qtyALivrer <= 0) continue;

                $detail = VenteDetail::findOrFail($detailId);
                abort_unless($detail->vente_id === $vente->id, 403);

                $dejaLivre = $detail->quantite_livree;
                $cmd       = $detail->quantite;
                $maxPossible = $cmd - $dejaLivre;

                if ($qtyALivrer > $maxPossible) {
                    throw new \InvalidArgumentException("La quantité livrée pour {$detail->produit->nom} ne peut dépasser {$maxPossible}.");
                }

                $produit = $detail->produit;
                if ($produit && $produit->estStockable()) {
                    // La disponibilite se lit sous le verrou qui servira a la
                    // sortie : lue avant, elle est deja perimee au moment ou
                    // l'on s'en sert, et deux livraisons simultanees la
                    // trouveraient toutes deux suffisante.
                    $disponible = StockService::disponible($produit, (int) $pointDeVenteId);

                    if ($qtyALivrer > $disponible) {
                        throw new \InvalidArgumentException("Stock insuffisant pour livrer {$produit->nom} (Disponible: {$disponible}, Demandé: {$qtyALivrer}).");
                    }

                    $detail->increment('quantite_livree', $qtyALivrer);

                    StockService::sortie($produit, (int) $pointDeVenteId, (float) $qtyALivrer,
                        MouvementStock::LIVRAISON, [
                            'piece'     => $vente,
                            'reference' => $vente->numero_facture,
                            'client_id' => $vente->client_id,
                        ]);
                }
            }

            // Mettre à jour l'étape ou le statut si tout est entièrement livré
            $toutLivre = true;
            foreach ($vente->details as $d) {
                if ($d->quantite_livree < $d->quantite) {
                    $toutLivre = false;
                    break;
                }
            }
            if ($toutLivre && $vente->etape === 'Bon de commande') {
                $vente->update(['etape' => 'Facture', 'statut' => 'Payé']);
            }
        });

        // Journaliser
        \App\Modules\Admin\Modeles\JournalAudit::create([
            'entreprise_id'  => $vente->pointDeVente->entreprise_id,
            'utilisateur_id' => Auth::id(),
            'action'         => 'livraison_stock',
            'details'        => "Livraison de stock effectuée pour la vente #{$vente->id} ({$vente->numero_facture})",
        ]);

        return redirect()->route('admin.stock.livraisons')
            ->with('succes', 'Livraison validée et stock mis à jour.');
    }
}
