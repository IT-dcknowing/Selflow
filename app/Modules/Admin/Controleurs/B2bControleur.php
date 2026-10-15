<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Jobs\NormaliserFactureFne;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\B2bNegotiation;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Modeles\MouvementStock;
// Employe a trois endroits du parcours B2B sans etre importe : PHP le
// cherchait dans l'espace de noms du controleur et tombait en 500 (Internal
// Server Error -- erreur interne du serveur) des la premiere negociation.
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Services\FneService;
use App\Modules\Admin\Services\ComptabiliteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Regles\Quantite;
use App\Modules\Admin\Services\StockService;

class B2bControleur extends Controller
{
    /**
     * Négociations côté Client (Acheteur)
     */
    public function negociationsClient(Request $request): View
    {
        $entrepriseId = Auth::user()->entreprise_id;
        $tab = $request->input('tab', 'devis'); // 'devis' ou 'commandes'

        $baseQuery = B2bNegotiation::with(['entrepriseFournisseur'])
            ->where('entreprise_client_id', $entrepriseId)
            ->latest();

        // Devis demandés
        $devis = (clone $baseQuery)->where('type_demande', 'rfq')->paginate(15, ['*'], 'page_devis');

        // Commandes demandées
        $commandes = (clone $baseQuery)->where('type_demande', 'commande')->paginate(15, ['*'], 'page_cmd');

        $negociations = $tab === 'commandes' ? $commandes : $devis;

        // Pour composer une demande ou une commande directe : les fournisseurs
        // inscrits dans Selflow (même NCC), et le catalogue de l'entreprise.
        $nccInscrits = Entreprise::whereNotNull('ncc')->where('id', '!=', $entrepriseId)->pluck('ncc');
        $fournisseursB2b = Fournisseur::where('entreprise_id', $entrepriseId)->whereIn('ncc', $nccInscrits)->orderBy('nom')->get();
        $produits = Produit::where('entreprise_id', $entrepriseId)->actifs()->orderBy('nom')->get(['id', 'nom', 'reference', 'prix_achat', 'unite']);
        $role = B2bNegotiation::CLIENT;

        return view('admin::b2b.negociations_client', compact('negociations', 'devis', 'commandes', 'tab', 'fournisseursB2b', 'produits', 'role'));
    }

    /**
     * Négociations côté Fournisseur (Vendeur)
     */
    public function negociationsFournisseur(Request $request): View
    {
        $entrepriseId = Auth::user()->entreprise_id;
        $tab = $request->input('tab', 'devis'); // 'devis' ou 'commandes'

        $baseQuery = B2bNegotiation::with(['entrepriseClient'])
            ->where('entreprise_fournisseur_id', $entrepriseId)
            ->latest();

        // Devis reçus (RFQ = type_demande rfq ou statut RFQ)
        $devis = (clone $baseQuery)->where('type_demande', 'rfq')->paginate(15, ['*'], 'page_devis');

        // Bons de commande reçus
        $commandes = (clone $baseQuery)->where('type_demande', 'commande')->paginate(15, ['*'], 'page_cmd');

        // Rétro-compat : $negociations pointe sur la collection active
        $negociations = $tab === 'commandes' ? $commandes : $devis;
        $role = B2bNegotiation::FOURNISSEUR;

        return view('admin::b2b.negociations_fournisseur', compact('negociations', 'devis', 'commandes', 'tab', 'role'));
    }

    /**
     * Envoyer une demande de prix (RFQ)
     */
    public function creerRfq(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'fournisseur_id' => ['required', 'integer', Appartenance::a('fournisseurs', 'id')],
            'articles'       => ['required', 'array', 'min:1'],
            'articles.*.produit_id' => ['required', 'integer', Appartenance::a('produits', 'id')],
            'articles.*.quantite'   => Quantite::physique(),
            'articles.*.prix'       => ['nullable', 'numeric', 'min:0'],
            'articles.*.prix_unitaire' => ['nullable', 'numeric', 'min:0'],
            'type_demande'   => ['nullable', 'in:rfq,commande'],
            'message'        => ['nullable', 'string', 'max:1000'],
        ]);

        $fournisseur = Fournisseur::where('entreprise_id', $entreprise->id)->findOrFail($request->fournisseur_id);

        if (empty($fournisseur->ncc)) {
            return back()->with('erreur', "Ce fournisseur n'a pas de NCC enregistré. La liaison B2B n'est pas possible.");
        }

        // Trouver l'entreprise destinataire via son NCC
        $fournisseurEntreprise = Entreprise::where('ncc', $fournisseur->ncc)->first();

        if (!$fournisseurEntreprise) {
            return back()->with('erreur', "Aucune entreprise sur Selflow ne correspond au NCC {$fournisseur->ncc} de ce fournisseur.");
        }

        if ($fournisseurEntreprise->id === $entreprise->id) {
            return back()->with('erreur', "Vous ne pouvez pas initier une relation commerciale B2B avec votre propre entreprise.");
        }

        // Une commande directe est permise (propriétaire, 14/10/2026) : elle
        // crée tout de suite le bon de commande du client.
        $commande = $request->input('type_demande') === 'commande';
        $masquerPrix = $request->input('masquer_prix_conseilles') == '1';
        $articles = [];
        foreach ($request->articles as $art) {
            $prix = $art['prix_unitaire'] ?? $art['prix'] ?? 0;
            $articles[] = [
                'produit'  => Produit::where('entreprise_id', $entreprise->id)->findOrFail($art['produit_id']),
                'quantite' => (float) $art['quantite'],
                'prix'     => $masquerPrix && !$commande ? 0.0 : (float) $prix,
            ];
        }

        try {
            $negociation = \App\Modules\Admin\Services\B2bService::ouvrir(
                $entreprise, $fournisseurEntreprise, $articles, $commande, $request->input('message')
            );
        } catch (\DomainException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return redirect()->route('admin.b2b.client.voir', $negociation)
            ->with('succes', $commande ? 'Commande envoyée au fournisseur.' : 'Demande de prix envoyée au fournisseur.');
    }

    /**
     * Envoyer une contre-proposition de prix ou un commentaire
     */
    public function proposerPrix(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        $entrepriseId = Auth::user()->entreprise_id;
        abort_unless($negociation->entreprise_client_id === $entrepriseId || $negociation->entreprise_fournisseur_id === $entrepriseId, 403);

        $request->validate([
            'message'     => ['nullable', 'string', 'max:1000'],
            'prix'        => ['required', 'array'],
            'prix.*'      => ['required', 'numeric', 'min:0'],
            'statut_action'=> ['required', 'string', 'in:Negociation_Client,Negociation_Fournisseur,Refuse,Valide']
        ]);

        $role = ($negociation->entreprise_client_id === $entrepriseId) ? 'Client' : 'Fournisseur';

        // Mettre à jour les prix proposés dans la structure JSON
        $produits = $negociation->produits_demandes;
        foreach ($produits as $index => &$p) {
            if (isset($request->prix[$index])) {
                $p['prix_propose'] = (float)$request->prix[$index];
            }
        }

        // Historique des messages
        $historique = $negociation->historique_discussions ?? [];
        $historique[] = [
            'date'    => now()->toDateTimeString(),
            'auteur'  => Auth::user()->nom . ' ' . Auth::user()->prenom,
            'role'    => $role,
            'message' => $request->message ?? 'Mise à jour des propositions de prix.'
        ];

        $negociation->update([
            'statut'                 => $request->statut_action,
            'produits_demandes'      => $produits,
            'historique_discussions' => $historique,
        ]);

        $route = ($role === 'Client') ? 'admin.b2b.negociations.client' : 'admin.b2b.negociations.fournisseur';

        return redirect()->route($route)
            ->with('succes', 'Votre proposition a été transmise avec succès.');
    }

    /**
     * Vérifier le stock fournisseur en direct
     */
    public function verifierStock(B2bNegotiation $negociation)
    {
        $entrepriseId = Auth::user()->entreprise_id;
        abort_unless($negociation->entreprise_fournisseur_id === $entrepriseId, 403);

        $produits = $negociation->produits_demandes;
        $verif = [];

        // Choisir le point de vente actif du fournisseur
        $pdvId = session('point_de_vente_actif_id') ?? PointDeVente::where('entreprise_id', $entrepriseId)->first()->id;

        foreach ($produits as $p) {
            // Trouver le produit correspondant chez le fournisseur par référence
            $fournisseurProduit = Produit::where('entreprise_id', $entrepriseId)
                ->where('reference', $p['reference'])
                ->first();

            $dispo = $fournisseurProduit ? $fournisseurProduit->stockActuel($pdvId) : 0;
            $verif[] = [
                'reference' => $p['reference'],
                'nom'       => $p['nom'],
                'requis'    => $p['quantite'],
                'dispo'     => $dispo,
                'statut'    => $dispo >= $p['quantite'] ? 'OK' : 'Insuffisant'
            ];
        }

        return response()->json([
            'point_de_vente' => PointDeVente::find($pdvId)->nom,
            'verifications'  => $verif
        ]);
    }

    /**
     * Finaliser la transaction (Vente chez le fournisseur, Achat en attente chez le client)
     */
    public function finaliserB2b(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        $entrepriseId = Auth::user()->entreprise_id;
        abort_unless($negociation->entreprise_fournisseur_id === $entrepriseId, 403);

        $request->validate([
            'type_facturation'   => ['required', 'string', 'in:disponible,commande'],
            'point_de_vente_id'  => ['required', 'integer', Appartenance::a('points_de_vente', 'id')],
            'mode_paiement'      => ['required', 'string'],
        ]);

        $produits = $negociation->produits_demandes;
        $pdvId = $request->point_de_vente_id;

        // 1. Déterminer le client correspondant à l'acheteur chez le fournisseur
        $acheteurEntreprise = $negociation->entrepriseClient;
        $client = Client::where('entreprise_id', $entrepriseId)
            ->where('ncc', $acheteurEntreprise->ncc)
            ->first();

        if (!$client) {
            // Créer le client à la volée s'il n'existe pas
            $client = Client::create([
                'entreprise_id' => $entrepriseId,
                'nom'           => $acheteurEntreprise->nom,
                'telephone'     => $acheteurEntreprise->telephone ?? '0000000000',
                'email'         => $acheteurEntreprise->email,
                'adresse'       => $acheteurEntreprise->adresse ?? 'Abidjan',
                'ncc'           => $acheteurEntreprise->ncc,
            ]);
        }

        // 2. Traitement transactionnel côté Fournisseur (Vente)
        $vente = DB::transaction(function () use ($negociation, $produits, $pdvId, $client, $request, $entrepriseId) {
            $montantHt = 0;
            $montantTva = 0;

            // Préparer les lignes de détails
            $detailsAAjouter = [];
            foreach ($produits as $p) {
                // Trouver le produit fournisseur correspondant
                $produitFournisseur = Produit::where('entreprise_id', $entrepriseId)
                    ->where('reference', $p['reference'])
                    ->first();

                if (!$produitFournisseur) {
                    throw new \Exception("Le produit avec la référence {$p['reference']} n'existe pas dans le catalogue fournisseur.");
                }

                $qteVendue = $p['quantite'];
                if ($request->type_facturation === 'disponible') {
                    $dispo = $produitFournisseur->stockActuel($pdvId);
                    $qteVendue = min($p['quantite'], $dispo);
                }

                if ($qteVendue <= 0) {
                    continue; // Rien à livrer/vendre pour cette ligne
                }

                $prix = $p['prix_propose'];
                $ht = $qteVendue * $prix;
                $tva = $ht * (($produitFournisseur->taux_tva ?? 18) / 100);

                $detailsAAjouter[] = [
                    'produit'      => $produitFournisseur,
                    'quantite'     => $qteVendue,
                    'prix_unitaire'=> $prix,
                    'montant_ht'   => $ht,
                    'montant_tva'  => $tva,
                    'montant_ttc'  => $ht + $tva,
                ];

                $montantHt += $ht;
                $montantTva += $tva;
            }

            if (empty($detailsAAjouter)) {
                throw new \Exception("Aucun article disponible en stock pour effectuer la facturation.");
            }

            // Créer la vente
            $numero = 'VE-' . now()->format('d-m-Y') . '-' . str_pad(
                Vente::whereYear('created_at', now()->year)->count() + 1,
                4, '0', STR_PAD_LEFT
            );

            $vente = Vente::create([
                'point_de_vente_id'    => $pdvId,
                'client_id'            => $client->id,
                'numero_facture'       => $numero,
                'date_vente'           => now()->toDateString(),
                'mode_paiement'        => $request->mode_paiement,
                'montant_ht'           => $montantHt,
                'montant_tva'          => $montantTva,
                'montant_ttc'          => $montantHt + $montantTva,
                'statut'               => 'Facture',
                'etape'                => 'Facture',
                'b2b_negotiation_id'   => $negociation->id,
            ]);

            foreach ($detailsAAjouter as $det) {
                VenteDetail::create([
                    'vente_id'             => $vente->id,
                    'produit_id'           => $det['produit']->id,
                    'quantite'             => $det['quantite'],
                    'unite'                => $det['produit']->unite ?? 'Unité',
                    'prix_unitaire'        => $det['prix_unitaire'],
                    'montant_tva'          => $det['montant_tva'],
                    'montant_ttc'          => $det['montant_ttc'],
                    'quantite_livree'      => $det['quantite'],
                    'quantite_receptionnee'=> 0,
                ]);

                // Sortie de stock chez le vendeur.
                //
                // Le sous-type etait « vente », valeur que l'ecran des
                // mouvements ne connait pas : la ligne existait, mais aucune
                // section ne l'affichait. C'est une livraison, comme une autre.
                if ($det['produit']->estStockable()) {
                    StockService::sortie($det['produit'], (int) $pdvId, (float) $det['quantite'],
                        MouvementStock::LIVRAISON,
                        ['piece' => $vente, 'reference' => $numero, 'client_id' => $vente->client_id]);
                }
            }

            // Normalisation FNE en arrière-plan (Section 18.5)
            // Sans situation fiscale, la facture reste à normaliser.
            if (\App\Modules\Admin\Modeles\Entreprise::find($entrepriseId)?->peutNormaliser()) {
                NormaliserFactureFne::dispatch($vente, false);
            }

            // Comptabilisation de la vente chez le fournisseur : décide seule si
            // vente comptant (aucune ligne 411) ou à crédit (411 pour le TTC).
            $montantPayeB2b = $request->mode_paiement !== 'Crédit' ? $vente->montant_ttc : 0;

            ComptabiliteService::genererEcrituresVente($vente, $montantPayeB2b, $request->mode_paiement, now()->toDateString());

            if ($montantPayeB2b > 0) {
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pdvId)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id'  => $pdvId,
                    'date_operation'     => now()->toDateString(),
                    'type_operation'     => 'Encaissement',
                    'libelle'            => ComptabiliteService::libelleTresorerieVente($vente),
                    'mode_paiement'      => $request->mode_paiement,
                    'montant_entree'     => $montantPayeB2b,
                    'montant_sortie'     => 0,
                    'solde_resultat'     => $soldeActuel + $montantPayeB2b,
                    'reference_document' => $numero,
                ]);
            }

            return $vente;
        });

        // 3. Création automatique de la fiche Achat "en attente" chez le Client
        DB::transaction(function () use ($negociation, $vente, $acheteurEntreprise, $entrepriseId, $request) {
            // Trouver le fournisseur correspondant chez l'acheteur (l'émetteur actuel)
            $fournisseurChezAcheteur = Fournisseur::where('entreprise_id', $acheteurEntreprise->id)
                ->where('ncc', Auth::user()->entreprise->ncc)
                ->first();

            if (!$fournisseurChezAcheteur) {
                // Créer le fournisseur à la volée chez l'acheteur
                $fournisseurChezAcheteur = Fournisseur::create([
                    'entreprise_id' => $acheteurEntreprise->id,
                    'nom'           => Auth::user()->entreprise->nom,
                    'telephone'     => Auth::user()->entreprise->telephone ?? '0000000000',
                    'email'         => Auth::user()->entreprise->email,
                    'ncc'           => Auth::user()->entreprise->ncc,
                ]);
            }

            // Choisir le point de vente siège/actif de l'acheteur
            $pdvClient = PointDeVente::where('entreprise_id', $acheteurEntreprise->id)->first();

            $numeroAchat = 'AC-B2B-' . now()->format('d-m-Y') . '-' . str_pad(
                Achat::whereYear('created_at', now()->year)->count() + 1,
                4, '0', STR_PAD_LEFT
            );

            // Achat créé en statut "en_attente_b2b" et étape "Bon de Commande" pour ne pas toucher aux stocks/comptes avant validation de l'acheteur
            $achat = Achat::create([
                'point_de_vente_id'    => $pdvClient->id,
                'fournisseur_id'       => $fournisseurChezAcheteur->id,
                'numero_facture'       => $numeroAchat,
                'date_achat'           => now()->toDateString(),
                'mode_paiement'        => $request->mode_paiement,
                'montant_ht'           => $vente->montant_ht,
                'montant_tva'          => $vente->montant_tva,
                'montant_ttc'          => $vente->montant_ttc,
                'statut'               => 'En attente B2B',
                'etape'                => 'Bon de commande',
                'b2b_negotiation_id'   => $negociation->id,
            ]);

            foreach ($vente->details as $det) {
                // Retrouver le produit acheteur par référence
                $produitAcheteur = Produit::where('entreprise_id', $acheteurEntreprise->id)
                    ->where('reference', $det->produit->reference)
                    ->first();

                if (!$produitAcheteur) {
                    // Créer l'article à la volée dans le catalogue du client s'il n'existe pas
                    $produitAcheteur = Produit::create([
                        'entreprise_id' => $acheteurEntreprise->id,
                        'reference'     => $det->produit->reference,
                        'nom'           => $det->produit->nom,
                        'type'          => $det->produit->type,
                        'unite'         => $det->produit->unite ?? 'Unité',
                        'prix_achat'    => $det->prix_unitaire,
                        'prix_vente'    => $det->produit->prix_vente ?? ($det->prix_unitaire * 1.25),
                        'taux_tva'      => $det->produit->taux_tva ?? 18,
                        'compte_achat'  => config('selflow.plan_comptable_defaut.achat_defaut'),
                        'compte_vente'  => config('selflow.plan_comptable_defaut.vente_defaut'),
                    ]);
                }

                AchatDetail::create([
                    'achat_id'             => $achat->id,
                    'produit_id'           => $produitAcheteur->id,
                    'quantite'             => $det->quantite,
                    'unite'                => $det->unite,
                    'prix_unitaire'        => $det->prix_unitaire,
                    'montant_tva'          => $det->montant_tva,
                    'montant_ttc'          => $det->montant_ttc,
                ]);
            }

            // Mettre à jour la négociation
            $negociation->update([
                'statut'     => 'Termine',
                'prix_final' => $vente->montant_ttc
            ]);
        });

        return redirect()->route('admin.b2b.negociations.fournisseur')
            ->with('succes', 'Transaction finalisée ! Facture de vente émise et commande acheteur envoyée.');
    }

    /**
     * L'acheteur réceptionne la livraison (depuis la liste des achats).
     */
    public function accepterAchatB2b(Achat $achat): RedirectResponse
    {
        abort_unless($achat->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);

        try {
            \App\Modules\Admin\Services\B2bService::receptionner($achat);
        } catch (\DomainException $e) {
            return back()->with('info', $e->getMessage());
        }

        return redirect()->route('admin.achats.factures')
            ->with('succes', 'Livraison B2B réceptionnée : stock mis à jour et facture d\'achat établie.');
    }

    // ═════════════════════════════════════════════════════════════════
    // Le parcours approfondi (lot 75) — une page par négociation
    // ═════════════════════════════════════════════════════════════════

    public function voirClient(B2bNegotiation $negociation): View
    {
        return $this->voir($negociation, B2bNegotiation::CLIENT);
    }

    public function voirFournisseur(B2bNegotiation $negociation): View
    {
        return $this->voir($negociation, B2bNegotiation::FOURNISSEUR);
    }

    private function voir(B2bNegotiation $negociation, string $role): View
    {
        $this->partie($negociation, $role);
        \App\Modules\Admin\Services\B2bService::marquerLu($negociation, $role);
        $negociation->load(['entrepriseClient', 'entrepriseFournisseur']);

        $entrepriseId = Auth::user()->entreprise_id;
        $catalogue = collect();
        $correspondances = [];
        $stocks = [];
        $pointsDeVente = collect();

        if ($role === B2bNegotiation::FOURNISSEUR) {
            $catalogue = Produit::where('entreprise_id', $entrepriseId)->actifs()->orderBy('nom')->get(['id', 'nom', 'reference', 'type', 'unite']);
            $pointsDeVente = PointDeVente::where('entreprise_id', $entrepriseId)->orderBy('nom')->get();
            $pdv = session('point_de_vente_actif_id') ?? $pointsDeVente->first()?->id;
            foreach ($negociation->produits_demandes ?? [] as $i => $ligne) {
                $produit = \App\Modules\Admin\Services\B2bService::articleDuFournisseur($entrepriseId, $ligne);
                $correspondances[$i] = $produit?->id;
                $stocks[$i] = $produit && $pdv ? ($produit->estStockable() ? $produit->stockActuel($pdv) : null) : null;
            }
        }

        $achat = Achat::where('b2b_negotiation_id', $negociation->id)->latest('id')->first();
        $ventes = Vente::where('b2b_negotiation_id', $negociation->id)->orderBy('id')->get();

        return view('admin::b2b.negociation', compact(
            'negociation', 'role', 'catalogue', 'correspondances', 'stocks', 'pointsDeVente', 'achat', 'ventes'
        ));
    }

    public function ecrireClient(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        return $this->ecrire($request, $negociation, B2bNegotiation::CLIENT);
    }

    public function ecrireFournisseur(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        return $this->ecrire($request, $negociation, B2bNegotiation::FOURNISSEUR);
    }

    private function ecrire(Request $request, B2bNegotiation $negociation, string $role): RedirectResponse
    {
        $this->partie($negociation, $role);
        $request->validate(['message' => ['required', 'string', 'max:2000']]);
        \App\Modules\Admin\Services\B2bService::ecrire($negociation, $role, trim($request->message));

        return back()->with('succes', 'Message envoyé.');
    }

    public function proposerClient(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        return $this->proposer($request, $negociation, B2bNegotiation::CLIENT);
    }

    public function proposerFournisseur(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        return $this->proposer($request, $negociation, B2bNegotiation::FOURNISSEUR);
    }

    private function proposer(Request $request, B2bNegotiation $negociation, string $role): RedirectResponse
    {
        $this->partie($negociation, $role);
        $request->validate([
            'prix'        => ['required', 'array'],
            'prix.*'      => ['nullable', 'numeric', 'min:0'],
            'quantites'   => ['nullable', 'array'],
            'quantites.*' => ['nullable', 'numeric', 'min:0'],
            'message'     => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->agir(fn () => \App\Modules\Admin\Services\B2bService::proposer(
            $negociation, $role, $request->input('prix', []),
            // Le client discute le prix ; la quantité, c'est le fournisseur qui la confirme.
            $role === B2bNegotiation::FOURNISSEUR ? [] : $request->input('quantites', []),
            $request->input('message')
        ), 'Proposition envoyée.');
    }

    public function commander(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        $this->partie($negociation, B2bNegotiation::CLIENT);

        return $this->agir(fn () => \App\Modules\Admin\Services\B2bService::commander($negociation, $request->input('message')),
            'Commande envoyée au fournisseur : il la confirmera selon son stock.');
    }

    public function confirmer(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        $this->partie($negociation, B2bNegotiation::FOURNISSEUR);
        $request->validate([
            'point_de_vente_id' => ['required', 'integer', Appartenance::a('points_de_vente', 'id')],
            'produits'          => ['nullable', 'array'],
            'produits.*'        => ['nullable', 'integer', Appartenance::a('produits', 'id')],
            'quantites'         => ['nullable', 'array'],
            'quantites.*'       => ['nullable', 'numeric', 'min:0'],
            'message'           => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->agir(fn () => \App\Modules\Admin\Services\B2bService::confirmer(
            $negociation, (int) $request->point_de_vente_id, $request->input('produits', []), $request->input('quantites', []), $request->input('message')
        ), 'Commande confirmée : le client est prévenu. Livrez, puis facturez depuis cette page.');
    }

    public function refuserClient(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        return $this->refuser($request, $negociation, B2bNegotiation::CLIENT);
    }

    public function refuserFournisseur(Request $request, B2bNegotiation $negociation): RedirectResponse
    {
        return $this->refuser($request, $negociation, B2bNegotiation::FOURNISSEUR);
    }

    private function refuser(Request $request, B2bNegotiation $negociation, string $role): RedirectResponse
    {
        $this->partie($negociation, $role);
        $request->validate(['motif' => ['required', 'string', 'max:1000']], ['motif.required' => 'Dites pourquoi : l\'autre partie le lira.']);

        return $this->agir(fn () => \App\Modules\Admin\Services\B2bService::refuser($negociation, $role, trim($request->motif)), 'Négociation refusée.');
    }

    public function livrer(B2bNegotiation $negociation): RedirectResponse
    {
        $this->partie($negociation, B2bNegotiation::FOURNISSEUR);

        return $this->agir(fn () => \App\Modules\Admin\Services\B2bService::livrer($negociation),
            'Livraison facturée : stock sorti, facture établie (et certifiée si la normalisation est automatique). Le client est prévenu.');
    }

    public function receptionner(B2bNegotiation $negociation): RedirectResponse
    {
        $this->partie($negociation, B2bNegotiation::CLIENT);
        $achat = Achat::where('b2b_negotiation_id', $negociation->id)->where('statut', 'En attente B2B')->latest('id')->first();
        if (!$achat) {
            return back()->with('erreur', 'Aucune livraison à réceptionner pour cette négociation.');
        }

        return $this->agir(fn () => \App\Modules\Admin\Services\B2bService::receptionner($achat),
            'Livraison réceptionnée : stock mis à jour et facture d\'achat établie.');
    }

    /** L'entreprise connectée tient-elle bien ce rôle dans la négociation ? */
    private function partie(B2bNegotiation $negociation, string $role): void
    {
        abort_unless($negociation->roleDe(Auth::user()->entreprise_id) === $role, 404);
    }

    /** Une action du parcours : un refus du parcours devient un message, jamais une erreur 500. */
    private function agir(callable $action, string $succes): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException|\InvalidArgumentException $e) {
            return back()->withInput()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', $succes);
    }
}
