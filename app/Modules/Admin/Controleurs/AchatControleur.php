<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\CodeJournal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Modules\Admin\Traits\GereLesChampsFne;
use App\Modules\Admin\Traits\JournaliseActions;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Jobs\NormaliserAchatBapaJob;
use App\Modules\Admin\Modeles\B2bNegotiation;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Regles\Quantite;
use App\Modules\Admin\Services\StockService;

class AchatControleur
{
    use JournaliseActions;
    use GereLesChampsFne;

    public function nouveau(): View
    {
        $entreprise  = Auth::user()->entreprise;
        $fournisseurs = Fournisseur::obtenirFournisseursPrioritaires($entreprise->id);
        // Un article rangé ne se rachète pas : le formulaire d'achat le
        // proposait encore, le filtre ne vivant que sur le catalogue.
        $produits     = Produit::where('entreprise_id', $entreprise->id)
            ->selectionnables()
            ->orderBy('nom')
            ->get();
        // Repli sur un point de vente EXISTANT plutot que sur un « Siege » cree
        // a la volee : un point de vente inconnu de la plateforme FNE fait
        // rejeter toute normalisation avec « Point of sale is invalid », et
        // l'utilisateur n'a aucun moyen de deviner qu'une fiche fantome a ete
        // creee dans son dos.
        $pointDeVenteId = session('point_de_vente_actif_id')
            ?? Auth::user()->point_de_vente_id
            ?? \App\Modules\Admin\Modeles\PointDeVente::where('entreprise_id', $entreprise->id)
                ->orderBy('id')
                ->value('id');
        $banques = CodeJournal::where('type', 'Banque')
            ->where('entreprise_id', $entreprise->id)
            ->orderBy('intitule')
            ->get();

        return view('admin::achats.nouveau', compact('fournisseurs', 'produits', 'pointDeVenteId', 'banques'));
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        // Repli sur un point de vente EXISTANT plutot que sur un « Siege » cree
        // a la volee : un point de vente inconnu de la plateforme FNE fait
        // rejeter toute normalisation avec « Point of sale is invalid », et
        // l'utilisateur n'a aucun moyen de deviner qu'une fiche fantome a ete
        // creee dans son dos.
        $pointDeVenteId = session('point_de_vente_actif_id')
            ?? Auth::user()->point_de_vente_id
            ?? \App\Modules\Admin\Modeles\PointDeVente::where('entreprise_id', $entreprise->id)
                ->orderBy('id')
                ->value('id');

        // Sans point de vente, aucune piece ne peut etre etablie ni certifiee.
        // Mieux vaut le dire que de laisser echouer l'enregistrement sur une
        // contrainte de base de donnees.
        if (!$pointDeVenteId) {
            return back()->withErrors([
                'point_de_vente' => 'Aucun point de vente n\'est enregistré. Créez-le d\'abord, '
                    . 'en le nommant exactement comme sur votre espace FNE : la plateforme rejette '
                    . 'toute pièce dont le point de vente lui est inconnu.',
            ])->withInput();
        }

        $isBapa = $request->input('type_facture') === 'bapa';

        $request->validate([
            'fournisseur_id'             => $isBapa ? ['nullable'] : ['required', 'integer', Appartenance::a('fournisseurs', 'id')],
            'fournisseur_nom_bapa'        => $isBapa ? ['required', 'string', 'max:255'] : ['nullable'],
            'date_achat'                 => ['required', 'date'],
            'mode_paiement'              => ['nullable', 'string'], // optionnel hors bloc Facture physique/BAPA
            'numero_facture_fournisseur' => ['nullable', 'string', 'max:100'],
            'type_facture'               => ['nullable', 'string', 'in:normale,bapa'],
            'articles'                   => ['required', 'array', 'min:1'],
            'articles.*.produit_id'      => ['nullable', 'integer', Appartenance::a('produits', 'id')],
            'articles.*.libelle_virtuel' => ['nullable', 'string', 'max:255'],
            'articles.*.quantite'        => Quantite::physique(),
            'articles.*.prix_unitaire'   => ['required', 'numeric', 'min:0'],
            'articles.*.unite'           => ['nullable', 'string', 'max:50'],
            // L'arrivage, pour les articles suivis par lot. Une date de
            // péremption déjà passée à la réception se saisit : on reçoit
            // parfois de la marchandise courte, et la refuser ici empêcherait
            // de la constater pour la retourner.
            'articles.*.numero_lot'       => ['nullable', 'string', 'max:100'],
            'articles.*.date_peremption'  => ['nullable', 'date'],
            'articles.*.date_fabrication' => ['nullable', 'date', 'before_or_equal:today'],
        ] + self::reglesChampsFne(), [
            'fournisseur_id.required'    => 'Veuillez sélectionner un fournisseur.',
            'fournisseur_nom_bapa.required' => 'Veuillez saisir le nom du vendeur (tiers non immatriculé).',
            'articles.required'          => 'Veuillez ajouter au moins un article.',
        ] + self::messagesChampsFne());

        // Pour le mode BAPA : résoudre (ou créer) le fournisseur "tiers" à partir du nom libre
        if ($isBapa) {
            $nomTiers = trim($request->input('fournisseur_nom_bapa'));
            $fournisseurTiers = Fournisseur::firstOrCreate(
                [
                    'entreprise_id' => $entreprise->id,
                    'nom'           => $nomTiers,
                ],
                [
                    'ncc'     => null,
                    'adresse' => 'Tiers non immatriculé (BAPA)',
                ]
            );
            $request->merge(['fournisseur_id' => $fournisseurTiers->id]);
        }

        if ($request->mode_paiement === 'Banque') {
            $request->validate([
                'banque_id'          => ['required', 'integer', Appartenance::a('codes_journaux', 'id')],
                'moyen_bancaire'     => ['required', 'string', 'in:carte,virement,cheque'],
                'reference_paiement' => ['required', 'string', 'max:255'],
            ], [
                'banque_id.required'          => 'Veuillez sélectionner la banque.',
                'moyen_bancaire.required'     => 'Veuillez sélectionner le moyen de paiement bancaire.',
                'reference_paiement.required' => 'Veuillez saisir le numéro ou référence de paiement.',
            ]);
        }

        $achat = DB::transaction(function () use ($request, $pointDeVenteId, $entreprise, $isBapa) {
            $montantHt  = 0;
            $montantTva = 0;
            $etape = $request->input('etape', 'Facture');
            $remiseTaux = self::tauxBorne($request->input('remise_taux', 0));

            // --- Calcul HT et TVA ligne par ligne depuis les taux produits ---
            //     La remise d'article s'applique avant la remise globale,
            //     comme l'exige le récapitulatif de la FNE.
            foreach ($request->articles as $article) {
                $remiseLigne = self::tauxBorne($article['remise_taux'] ?? 0);
                $ht = (float)$article['quantite'] * (float)$article['prix_unitaire'] * (1 - $remiseLigne / 100);
                $montantHt += $ht;

                // Récupérer le taux TVA du produit sélectionné.
                //
                // Un bordereau d'achat en est exempté : il constate un achat
                // auprès d'un tiers non immatriculé, qui ne facture aucune TVA.
                // Le taux du catalogue s'appliquait pourtant, et le document
                // affichait des lignes TTC sous un total annoncé « exonéré ».
                // Le payload envoyé à la FNE ne portant aucune taxe pour ce
                // type de pièce, le bordereau certifié divergeait du nôtre.
                if (!$isBapa && !empty($article['produit_id'])) {
                    $produit = Produit::find($article['produit_id']);
                    $tauxTva = $produit ? (float)($produit->taux_tva ?? 0) : 0;
                    if ($tauxTva > 0) {
                        $montantTva += round($ht * ($tauxTva / 100), 2);
                    }
                }
                // Pour les lignes libres (sans produit), pas de TVA automatique
            }

            // La remise globale est saisie en pourcentage (format DGI) ; son
            // équivalent en francs est conservé pour la comptabilité.
            $remise = round($montantHt * $remiseTaux / 100, 2);
            $montantHtNet = max(0, $montantHt - $remise);
            $ratio = $montantHt > 0 ? $montantHtNet / $montantHt : 0;
            $montantTva = round($montantTva * $ratio, 2);

            $montantTtc = $montantHtNet + $montantTva;

            // Déterminer le mode de paiement final (par défaut Caisse si non fourni)
            $modePaiementFinal = $request->input('mode_paiement', 'Caisse');
            if ($request->mode_paiement === 'Banque' && $request->filled('banque_id')) {
                $codeJournal = CodeJournal::where('entreprise_id', Auth::user()->entreprise_id)->findOrFail($request->banque_id);
                $modePaiementFinal = 'Banque : ' . $codeJournal->intitule;
            }

            // Générer le numéro de facture INTERNE (référentiel système)
            $numero = \App\Modules\Admin\Services\NumerotationService::genererNumeroAchat($entreprise->id, $etape);

            // Numéro de facture fournisseur (saisi manuellement pour achats externes)
            $numeroFournisseur = $request->filled('numero_facture_fournisseur')
                ? trim($request->numero_facture_fournisseur)
                : null;

            // Statut de départ de l'achat : "En attente de confirmation" par défaut
            $statutInitial = ($etape === 'Facture') ? (($request->mode_paiement === 'Crédit') ? 'Crédit' : 'Payé') : 'En attente de confirmation';

            $achat = Achat::create([
                'point_de_vente_id'          => $pointDeVenteId,
                'fournisseur_id'             => $request->fournisseur_id,
                'numero_facture'             => $numero,
                'numero_facture_fournisseur' => $numeroFournisseur,
                'date_achat'                 => $request->date_achat,
                'mode_paiement'              => $modePaiementFinal,
                'moyen_bancaire'             => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                'reference_paiement'         => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                'mobile_money_operateur'     => $request->mode_paiement === 'Mobile Money' ? $request->mobile_money_operateur : null,
                'devise'                     => $request->devise ?: 'XOF',
                'taux_change'                => ($request->devise && $request->devise !== 'XOF') ? floatval($request->taux_change) : null,
                'montant_ht'                 => $montantHt,
                'montant_tva'                => $montantTva,
                'montant_ttc'                => $montantTtc,
                'remise'                     => $remise,
                'remise_taux'                => $remiseTaux,
                'statut'                     => $statutInitial,
                'etape'                      => $etape,
                'type_facture'               => $request->input('type_facture', 'normale'),
                'est_rne'                    => $request->boolean('est_rne'),
                'numero_rne'                 => $request->boolean('est_rne') ? trim($request->input('numero_rne')) : null,
            ]);

            // `enregistrerTaxesSurTtc()` etait appelee ici. Elle ecrivait dans
            // `achat_taxes`, que rien ne relisait : ni le payload du bordereau
            // d'achat -- qui ne transmet aucune taxe, et dont la conformite est
            // gelee --, ni la comptabilite, ni le document imprime. La taxe
            // saisie gonflait le total a l'ecran sans entrer dans aucun montant
            // enregistre. Table et bloc de saisie retires le 24/08/2026.

            foreach ($request->articles as $article) {
                $produit = !empty($article['produit_id']) ? Produit::lockForUpdate()->find($article['produit_id']) : null;
                $remiseLigne = self::tauxBorne($article['remise_taux'] ?? 0);
                $ht      = (float)$article['quantite'] * (float)$article['prix_unitaire'] * (1 - $remiseLigne / 100);
                // Exonération du bordereau d'achat : voir le calcul du total.
                $tvaDeLigne = 0;
                if (!$isBapa && $produit) {
                    $tauxTvaProduit = (float)($produit->taux_tva ?? 0);
                    if ($tauxTvaProduit > 0) {
                        $tvaDeLigne = round($ht * ($tauxTvaProduit / 100), 2);
                    }
                }

                $detail = AchatDetail::create([
                    'achat_id'       => $achat->id,
                    'produit_id'     => $produit ? $produit->id : null,
                    'libelle_virtuel'=> $produit ? null : ($article['libelle_virtuel'] ?? 'Saisie libre'),
                    'quantite'       => $article['quantite'],
                    'unite'          => $article['unite'] ?? 'Unité',
                    'prix_unitaire'  => $article['prix_unitaire'],
                    'remise_taux'    => $remiseLigne,
                    'montant_tva'    => $tvaDeLigne,
                    'montant_ttc'    => $ht + $tvaDeLigne,
                ]);

                // Instantané des taxes personnalisées du produit sur la ligne
                self::copierTaxesProduitSurLigne($detail, $produit);

                // Augmenter le stock + mouvement uniquement si Facture et stockable
                if ($produit && $etape === 'Facture' && $produit->estStockable()) {
                    // Marquer la ligne comme entierement receptionnee : facturer
                    // directement vaut reception immediate, symetrique de ce que
                    // la vente au comptant fait pour la livraison.
                    //
                    // C'etait la seconde porte du meme stock : sans cette ligne,
                    // la commande restait dans la file « Receptions a traiter »
                    // (StockControleur::receptions()), qui se fonde sur
                    // quantite_receptionnee, et valider la reception incrementait
                    // le stock UNE SECONDE FOIS. Rien ne l'interdisait.
                    $detail->update(['quantite_receptionnee' => $article['quantite']]);

                    StockService::entree($produit, (int) $pointDeVenteId, (float) $article['quantite'],
                        MouvementStock::RECEPTION,
                        ['piece' => $achat, 'reference' => $numero,
                         'fournisseur_id' => $achat->fournisseur_id,
                         // Le cout d'entree est le prix reellement paye, remise
                         // deduite — pas le prix de catalogue de la fiche.
                         'cout_unitaire' => self::coutDEntree($article['prix_unitaire'], $article['remise_taux'] ?? 0),
                         // L'arrivage, pour les articles suivis par lot. Sans
                         // numero, rien n'est ecrit : le service laisse passer,
                         // et l'ecart entre le stock et la somme des lots dit
                         // ce qui reste a regulariser.
                         'lot' => [
                             'numero'           => $article['numero_lot'] ?? null,
                             'date_peremption'  => $article['date_peremption'] ?? null,
                             'date_fabrication' => $article['date_fabrication'] ?? null,
                             'fournisseur_id'   => $achat->fournisseur_id,
                         ]]);
                }
            }

            // Trésorerie et Comptabilité (uniquement si Facture)
            if ($etape === 'Facture') {
                // NB (correctif) : le code précédent décaissait systématiquement le montant
                // TTC total, y compris pour un achat "Crédit" (statutInitial === 'Crédit'),
                // ce qui payait à tort une dette fournisseur censée rester impayée.
                // On ne décaisse désormais que si l'achat n'est pas à crédit.
                $montantPaye = $statutInitial === 'Crédit' ? 0 : $montantTtc;

                // Écritures comptables : décide seule si achat comptant (aucune ligne 401)
                // ou achat à crédit (401 pour le montant non payé immédiatement).
                \App\Modules\Admin\Services\ComptabiliteService::genererEcrituresAchat(
                    $achat,
                    $montantPaye,
                    $modePaiementFinal,
                    $request->date_achat,
                    $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    $request->mode_paiement === 'Banque' ? $request->reference_paiement : null
                );

                if ($montantPaye > 0) {
                    $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pointDeVenteId)
                        ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                    TresorerieJournal::create([
                        'point_de_vente_id'  => $pointDeVenteId,
                        'date_operation'     => $request->date_achat,
                        'type_operation'     => 'Décaissement',
                        'libelle'            => \App\Modules\Admin\Services\ComptabiliteService::libelleTresorerieAchat($achat),
                        'mode_paiement'      => $modePaiementFinal,
                        'moyen_bancaire'     => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                        'reference_paiement' => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                        'montant_entree'     => 0,
                        'montant_sortie'     => $montantPaye,
                        'solde_resultat'     => $soldeActuel - $montantPaye,
                        'reference_document' => $numero,
                    ]);
                }
            }

            return $achat;
        });

        // Si c'est un achat de type BAPA, normalisation BAPA asynchrone
        if ($achat && $achat->etape === 'Facture' && $achat->type_facture === 'bapa') {
            NormaliserAchatBapaJob::dispatch($achat);
        }

        // ── Envoi B2B automatique si case cochée ──
        if ($achat && $request->input('envoyer_rfq_b2b') == '1') {
            $fournisseur = Fournisseur::findOrFail($achat->fournisseur_id);
            if (!empty($fournisseur->ncc)) {
                $fournisseurEntreprise = Entreprise::where('ncc', $fournisseur->ncc)->first();
                if ($fournisseurEntreprise && $fournisseurEntreprise->id !== $entreprise->id) {
                    $masquerPrix = $request->input('masquer_prix_conseilles') == '1';
                    $produitsDemandes = [];
                    foreach ($achat->details as $d) {
                        $produitsDemandes[] = [
                            'produit_id_client' => $d->produit_id,
                            'reference'         => $d->produit?->reference ?? 'REF-' . $d->produit_id,
                            'nom'               => $d->produit?->nom ?? $d->libelle_virtuel ?? 'Produit #' . $d->produit_id,
                            'quantite'          => (float)$d->quantite,
                            'prix_propose'      => $masquerPrix ? 0.0 : (float)$d->prix_unitaire,
                            'unite'             => $d->unite ?? $d->produit?->unite ?? 'pcs'
                        ];
                    }

                    $historique = [[
                        'date'    => now()->toDateTimeString(),
                        'auteur'  => Auth::user()->nom . ' ' . Auth::user()->prenom,
                        'role'    => 'Client',
                        'message' => $achat->etape === 'Bon de commande'
                            ? 'Bon de commande direct envoyé via B2B.'
                            : 'Demande de prix initiale (RFQ) envoyée via B2B.'
                    ]];

                    B2bNegotiation::create([
                        'entreprise_client_id'      => $entreprise->id,
                        'entreprise_fournisseur_id' => $fournisseurEntreprise->id,
                        'statut'                    => 'RFQ',
                        'type_demande'              => $achat->etape === 'Bon de commande' ? 'commande' : 'rfq',
                        'reference_commande'        => $achat->numero_facture,
                        'produits_demandes'         => $produitsDemandes,
                        'historique_discussions'    => $historique,
                    ]);
                }
            }
        }

        // Journaliser la création de l'achat
        $this->journaliser('creation_achat', 'Achat', $achat->id);

        $routeRedirect = request()->routeIs('caissier.*') ? 'caissier.achats.factures' : 'admin.achats.factures';
        $successLabel = $achat->etape === 'Facture' ? 'Achat enregistré et facture générée avec succès.' : $achat->etape . ' enregistré(e) avec succès.';
        return redirect()->route($routeRedirect, ['type' => strtolower($achat->etape)])
            ->with('succes', $successLabel);
    }

    public function factures(): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = session('point_de_vente_actif_id') ?? Auth::user()->point_de_vente_id;

        $etapeActive = request('etape', 'Facture');
        $type = request('type');

        $baseQuery = Achat::with(['fournisseur', 'pointDeVente', 'details.produit'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));

        if ($pointDeVenteId) {
            $baseQuery->where('point_de_vente_id', $pointDeVenteId);
        }

        if ($type === 'avoir') {
            $baseQuery->where('type_facture', 'avoir');
            $etapeActive = 'Facture';
        } else {
            $baseQuery->where(function($q) {
                $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
            });
            $baseQuery->where('etape', $etapeActive);
        }

        // Filtres additionnels (recherche, statut, période) — appliqués
        // automatiquement dès la saisie côté vue, aucun bouton requis.
        if (request()->filled('recherche')) {
            $recherche = request('recherche');
            $baseQuery->where(function ($q) use ($recherche) {
                $q->where('numero_facture', 'like', "%{$recherche}%")
                  ->orWhere('numero_fne', 'like', "%{$recherche}%")
                  ->orWhereHas('fournisseur', fn($qf) => $qf->where('nom', 'like', "%{$recherche}%"));
            });
        }
        if (request()->filled('statut_filtre')) {
            $baseQuery->where('statut', request('statut_filtre'));
        }
        if (request()->filled('dgi_filtre')) {
            $dgiFiltre = request('dgi_filtre');
            $baseQuery->where('normalise', $dgiFiltre === 'oui');
        }
        if (request()->filled('date_debut')) {
            $baseQuery->whereDate('date_achat', '>=', request('date_debut'));
        }
        if (request()->filled('date_fin')) {
            $baseQuery->whereDate('date_achat', '<=', request('date_fin'));
        }

        // Calcul des totaux par étape
        $compteQuery = Achat::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->where(function($q) {
                $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
            });
        if ($pointDeVenteId) {
            $compteQuery->where('point_de_vente_id', $pointDeVenteId);
        }
        
        $totaux = $compteQuery->select('etape', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
            ->groupBy('etape')
            ->pluck('total', 'etape')
            ->toArray();

        $nbDP = $totaux['Demande de prix'] ?? 0;
        $nbBC = $totaux['Bon de commande'] ?? 0;
        $nbFacture = $totaux['Facture'] ?? 0;

        $achats = $baseQuery->latest()->paginate(20)->appends(request()->query());

        $facturesDispo = collect();
        if ($type === 'avoir') {
            $facturesDispoQuery = Achat::with('fournisseur')
                ->whereHas('pointDeVente', fn($queryPdv) => $queryPdv->where('entreprise_id', $entreprise->id))
                ->where('etape', 'Facture')
                ->where(function($queryNum) {
                    // Accepte l'ancien préfixe (AC-, avant le 22/07/2026) ET le
                    // nouveau (ACH-, depuis le changement de convention de
                    // numérotation), pour ne pas exclure les factures récentes.
                    // `BA-` en est sorti : c'est le préfixe des bordereaux, et
                    // la DGI ne normalise pas leur avoir.
                    $queryNum->where('numero_facture', 'LIKE', 'AC-%')
                             ->orWhere('numero_facture', 'LIKE', 'ACH-%');
                })
                ->where(function($queryType) {
                    $queryType->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
                });
            self::ecarterLesBapa($facturesDispoQuery);
            if ($pointDeVenteId) {
                $facturesDispoQuery->where('point_de_vente_id', $pointDeVenteId);
            }
            $facturesDispo = $facturesDispoQuery->latest()->get();
        }

        return view('admin::achats.factures', compact('achats', 'etapeActive', 'nbDP', 'nbBC', 'nbFacture', 'type', 'facturesDispo'));
    }



    public function imprimer(Achat $achat): View
    {
        $this->autoriserAcces($achat);
        $achat->load(['fournisseur', 'pointDeVente.entreprise', 'details.produit']);
        $dejaPaye = \App\Modules\Admin\Modeles\TresorerieJournal::where('reference_document', $achat->numero_facture)->sum('montant_sortie');
        return view('admin::factures.achat', compact('achat', 'dejaPaye'));
    }

    public function imprimerBapa(Achat $achat): View
    {
        $this->autoriserAcces($achat);
        $achat->load(['fournisseur', 'pointDeVente.entreprise', 'details.produit']);
        $dejaPaye = \App\Modules\Admin\Modeles\TresorerieJournal::where('reference_document', $achat->numero_facture)->sum('montant_sortie');
        return view('admin::factures.bapa', compact('achat', 'dejaPaye'));
    }

    /**
     * Le sort de la marchandise rendue au fournisseur, ligne par ligne.
     *
     * Deux cas seulement, malgré le nom du code d'origine :
     *
     * - **`reinject`** — la marchandise repart physiquement. C'est une sortie
     *   de stock. Le mot vient de l'écran des avoirs de vente, où il désigne
     *   une entrée ; sur un achat il désigne l'inverse, et c'est trompeur.
     * - **tout le reste** — avoir purement financier : remise obtenue après
     *   coup, erreur de facturation. La marchandise reste, le stock ne bouge
     *   pas.
     *
     * Le choix était consigné dans une clé `notes` que `mouvements_stock` n'a
     * pas : Eloquent la laissait tomber sans rien dire.
     */
    private static function rendreAuFournisseur(
        ?Produit $produit,
        Achat $achatOrigine,
        Achat $avoir,
        string $numAvoir,
        float $quantite,
        string $sort
    ): void {
        if (!$produit || !$produit->estStockable() || $quantite <= 0 || $sort !== 'reinject') {
            return;
        }

        $pointDeVenteId = (int) $achatOrigine->point_de_vente_id;
        $disponible = StockService::disponible($produit, $pointDeVenteId);

        if ($disponible < $quantite) {
            throw new \InvalidArgumentException(
                "Retour fournisseur impossible pour « {$produit->nom} » : stock actuel "
                . "({$disponible}) inférieur à la quantité à retourner ({$quantite}). "
                . 'Une partie a probablement déjà été revendue.'
            );
        }

        StockService::sortie($produit, $pointDeVenteId, $quantite, MouvementStock::RETOUR_FOURNISSEUR, [
            'piece'          => $avoir,
            'reference'      => $numAvoir,
            'fournisseur_id' => $achatOrigine->fournisseur_id,
        ]);
    }

    /**
     * Le coût auquel une marchandise entre en stock.
     *
     * Le prix réellement payé, remise de ligne déduite — et non
     * `produits.prix_achat`, qui est un prix de catalogue figé. C'est lui qui
     * nourrit le **CUMP** (Coût Unitaire Moyen Pondéré) et, de là, la marge et
     * la valeur du stock au bilan.
     */
    private static function coutDEntree($prixUnitaire, $remiseTaux): float
    {
        return round((float) $prixUnitaire * (1 - self::tauxBorne($remiseTaux) / 100), 4);
    }

    private function autoriserAcces(Achat $achat): void
    {
        $entrepriseId = Auth::user()->entreprise_id;
        abort_unless(
            $achat->pointDeVente->entreprise_id === $entrepriseId,
            404,
            'Accès non autorisé.'
        );
    }

    public function confirmerCommande(Achat $achat): RedirectResponse
    {
        $this->autoriserAcces($achat);
        if ($achat->etape !== 'Demande de prix') {
            return back()->with('info', 'Le document n\'est pas à l\'étape Demande de prix.');
        }

        $achat->update(['etape' => 'Bon de commande']);

        return back()->with('succes', 'Commande fournisseur confirmée.');
    }

    public function facturer(Achat $achat): RedirectResponse
    {
        $this->autoriserAcces($achat);
        if ($achat->etape === 'Facture') {
            return back()->with('info', 'Cette facture est déjà validée.');
        }

        DB::transaction(function () use ($achat) {
            $nouveauStatut = ($achat->mode_paiement === 'Crédit' || str_contains($achat->mode_paiement, 'Crédit')) ? 'Crédit' : 'Payé';
            $achat->update(['etape' => 'Facture', 'statut' => $nouveauStatut]);

            // 1. Incrémenter le stock uniquement pour les articles stockables
            foreach ($achat->details as $detail) {
                $produit = $detail->produit;
                if ($produit && $produit->estStockable()) {
                    // Meme raison qu'a la facturation directe : sans cela, la
                    // file des receptions proposerait de receptionner une
                    // seconde fois ce qui vient d'entrer.
                    $detail->update(['quantite_receptionnee' => $detail->quantite]);

                    StockService::entree($produit, (int) $achat->point_de_vente_id, (float) $detail->quantite,
                        MouvementStock::RECEPTION,
                        ['piece' => $achat, 'reference' => $achat->numero_facture,
                         'fournisseur_id' => $achat->fournisseur_id,
                         'cout_unitaire' => self::coutDEntree($detail->prix_unitaire, $detail->remise_taux ?? 0)]);
                }
            }

            // 2. Trésorerie : ne décaisser que si l'achat n'est pas à crédit
            //    (correctif : l'ancien code décaissait systématiquement le TTC
            //    total même pour un achat "Crédit", payant à tort une dette
            //    fournisseur censée rester impayée).
            $montantPaye = $nouveauStatut === 'Crédit' ? 0 : $achat->montant_ttc;

            if ($montantPaye > 0) {
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $achat->point_de_vente_id)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id'  => $achat->point_de_vente_id,
                    'date_operation'     => $achat->date_achat->toDateString(),
                    'type_operation'     => 'Décaissement',
                    'libelle'            => \App\Modules\Admin\Services\ComptabiliteService::libelleTresorerieAchat($achat),
                    'mode_paiement'      => $achat->mode_paiement,
                    'moyen_bancaire'     => $achat->moyen_bancaire,
                    'reference_paiement' => $achat->reference_paiement,
                    'montant_entree'     => 0,
                    'montant_sortie'     => $montantPaye,
                    'solde_resultat'     => $soldeActuel - $montantPaye,
                    'reference_document' => $achat->numero_facture,
                ]);
            }

            // 3. Écritures comptables : décide seule si achat comptant (aucune
            //    ligne 401) ou achat à crédit (401 pour le solde non payé).
            \App\Modules\Admin\Services\ComptabiliteService::genererEcrituresAchat(
                $achat,
                $montantPaye,
                $achat->mode_paiement,
                $achat->date_achat->toDateString(),
                $achat->moyen_bancaire,
                $achat->reference_paiement
            );
        });

        // Si le fournisseur n'a pas de NCC, normalisation BAPA asynchrone
        if (empty($achat->fournisseur?->ncc)) {
            NormaliserAchatBapaJob::dispatch($achat);
        }


        return back()->with('succes', 'Facture d\'achat validée, stock mis à jour et écritures générées.');
    }

    /**
     * Générer un avoir sur une facture d'achat (Retour fournisseur)
     */
    public function creerAvoir(Request $request, Achat $achat): RedirectResponse
    {
        $this->autoriserAcces($achat);
        abort_if($achat->type_facture === 'avoir', 400, "Impossible de générer un avoir sur un avoir.");

        // La DGI ne normalise pas l'avoir d'un bordereau d'achat aux
        // producteurs agricoles. Interdit au dernier verrou, et pas
        // seulement masque a l'ecran : la route reste atteignable a la main.
        abort_if($achat->estBapa(), 400, self::AVOIR_BAPA_INTERDIT);

        $request->validate([
            'raison' => ['required', 'string', 'max:255'],
        ]);

        $avoirId = null;

        DB::transaction(function () use ($achat, $request, &$avoirId) {
            $numAvoir = \App\Modules\Admin\Services\NumerotationService::genererNumeroAchat(
                $achat->pointDeVente->entreprise_id, 'Facture', 'avoir'
            );

            // 1. Création de la facture d'avoir d'achat
            $avoir = Achat::create([
                'point_de_vente_id'          => $achat->point_de_vente_id,
                'fournisseur_id'             => $achat->fournisseur_id,
                'utilisateur_id'             => Auth::id(),
                'numero_facture'             => $numAvoir,
                'numero_facture_fournisseur' => $request->raison, // Raison/Ref de l'avoir fournisseur
                'date_achat'                 => now()->toDateString(),
                'mode_paiement'              => $achat->mode_paiement,
                'moyen_bancaire'             => $achat->moyen_bancaire,
                'reference_paiement'         => $request->raison,
                'montant_ht'                 => $achat->montant_ht,
                'montant_tva'                => $achat->montant_tva,
                'montant_ttc'                => $achat->montant_ttc,
                'statut'                     => 'Payé',
                'type_facture'               => 'avoir',
                'etape'                      => 'Facture',
            ]);

            // 2. Copie des détails et retour fournisseur (décrémentation stock)
            foreach ($achat->details as $detail) {
                \App\Modules\Admin\Modeles\AchatDetail::create([
                    'achat_id'      => $avoir->id,
                    'produit_id'    => $detail->produit_id,
                    'quantite'      => $detail->quantite,
                    'prix_unitaire' => $detail->prix_unitaire,
                    'montant_tva'   => $detail->montant_tva,
                    'montant_ttc'   => $detail->montant_ttc,
                ]);

                // Décrémenter le stock si le produit est stockable
                if ($detail->produit && $detail->produit->estStockable()) {
                    $disponible = StockService::disponible($detail->produit, (int) $achat->point_de_vente_id);

                    if ($disponible < $detail->quantite) {
                        throw new \InvalidArgumentException(
                            "Retour fournisseur impossible pour « {$detail->produit->nom} » : stock actuel ({$disponible}) inférieur à la quantité à retourner ({$detail->quantite}). Une partie a probablement déjà été revendue."
                        );
                    }

                    StockService::sortie($detail->produit, (int) $achat->point_de_vente_id,
                        (float) $detail->quantite, MouvementStock::RETOUR_FOURNISSEUR,
                        ['piece' => $avoir, 'reference' => $numAvoir,
                         'fournisseur_id' => $achat->fournisseur_id]);
                }
            }

            // 3. Écritures comptables
            \App\Modules\Admin\Services\ComptabiliteService::genererEcritureAvoirAchat($avoir);

            // 4. Si la facture d'origine était payée en espèces, on simule l'entrée en caisse du remboursement fournisseur
            if (str_contains(strtolower($achat->mode_paiement), 'espèces') || str_contains(strtolower($achat->mode_paiement), 'caisse')) {
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $achat->point_de_vente_id)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id'  => $achat->point_de_vente_id,
                    'date_operation'     => now()->toDateString(),
                    'type_operation'     => 'Encaissement', // Remboursement du fournisseur
                    'libelle'            => 'Remboursement Avoir fournisseur ' . $numAvoir,
                    'mode_paiement'      => $achat->mode_paiement,
                    'montant_entree'     => $achat->montant_ttc,
                    'montant_sortie'     => 0,
                    'solde_resultat'     => $soldeActuel + $achat->montant_ttc,
                    'reference_document' => $numAvoir,
                ]);
            }

            $avoirId = $avoir->id;
        });

        $this->journaliser('creation_avoir_achat', 'Achat', $avoirId);

        $routeRedirect = request()->routeIs('caissier.*') ? 'caissier.achats.factures' : 'admin.achats.factures';
        return redirect()->route($routeRedirect, ['type' => 'avoir'])
            ->with('succes', "Facture d'avoir fournisseur enregistrée ! Les stocks et écritures comptables d'annulation ont été validés.");
    }

    /**
     * Lot H : Normalisation manuelle DGI/BAPA.
     * Dispatch le job de normalisation pour un achat non encore normalisé.
     */
    public function normaliser(Achat $achat): RedirectResponse
    {
        $this->autoriserAcces($achat);

        if ($achat->normalise) {
            return back()->with('info', 'Cet achat est déjà normalisé.');
        }

        if ($achat->etape !== 'Facture') {
            return back()->with('erreur', 'Seules les factures finalisées peuvent être normalisées.');
        }

        NormaliserAchatBapaJob::dispatchSync($achat);

        $this->journaliser('normalisation_manuelle_achat', 'Achat', $achat->id);

        return back()->with('succes', 'La normalisation BAPA/DGI a été effectuée avec succès. Le document est maintenant normalisé.');
    }

    /** Le motif du refus, le meme partout : une seule phrase a corriger le jour ou la DGI ouvrira. */
    private const AVOIR_BAPA_INTERDIT = "La DGI ne normalise pas encore l'avoir d'un bordereau d'achat "
        . "aux producteurs agricoles (BAPA) : l'operation est indisponible sur cette piece.";

    /**
     * Écarte les bordereaux d'achat aux producteurs agricoles d'une liste de
     * pièces avoirables.
     *
     * **La DGI ne normalise pas l'avoir d'un BAPA.** Tant qu'elle ne le fait
     * pas, un avoir établi ici resterait sans contrepartie fiscale : la pièce
     * existerait dans Selflow, pas chez la plateforme, et les deux états
     * divergeraient sans que rien ne le signale. Mieux vaut ne pas proposer
     * l'opération que d'en produire une que personne ne pourra certifier.
     *
     * Les deux chemins sont fermés ensemble, comme dans `Achat::estBapa()` :
     * le type déclaré, et le fournisseur sans NCC — qui part en BAPA sans que
     * `type_facture` le dise. Le préfixe `BA-` tombe avec eux : c'est celui que
     * `NumerotationService` donne aux bordereaux.
     */
    private static function ecarterLesBapa($query)
    {
        return $query
            ->where(function ($queryType) {
                $queryType->whereNull('type_facture')
                          ->orWhere('type_facture', '!=', 'bapa');
            })
            ->whereHas('fournisseur', fn($queryFourn) => $queryFourn
                ->whereNotNull('ncc')->where('ncc', '!=', ''));
    }

    public function rechercherFacturesPourAvoir(Request $request): \Illuminate\Http\JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $q = $request->query('q');

        $query = Achat::with('fournisseur')
            ->whereHas('pointDeVente', fn($queryPdv) => $queryPdv->where('entreprise_id', $entreprise->id))
            ->where('etape', 'Facture')
            ->where(function($queryNum) {
                // `BA-` retiré : les bordereaux d'achat aux producteurs
                // agricoles ne sont plus avoirables.
                $queryNum->where('numero_facture', 'LIKE', 'AC-%')
                         ->orWhere('numero_facture', 'LIKE', 'ACH-%');
            })
            ->where(function($queryType) {
                $queryType->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
            })
            ->where('archived', false);

        self::ecarterLesBapa($query);

        if ($q) {
            $query->where(function($querySearch) use ($q) {
                $querySearch->where('numero_facture', 'like', "%{$q}%")
                    ->orWhere('numero_facture_fournisseur', 'like', "%{$q}%")
                    ->orWhereHas('fournisseur', fn($queryFourn) => $queryFourn->where('nom', 'like', "%{$q}%"));
            });
        }

        $factures = $query->latest()->limit(10)->get()->map(function($f) {
            $fournNom = $f->fournisseur ? $f->fournisseur->nom : 'Fournisseur inconnu';
            return [
                // L'identifiant public, celui que les adresses portent. Le
                // numérique partait ici et revenait dans une adresse qui
                // attend un uuid : 404 (Not Found — introuvable), puis une
                // page HTML lue comme du JSON.
                'id' => $f->uuid,
                'text' => "{$f->numero_facture} - {$fournNom} (" . number_format($f->montant_ttc, 0, ',', ' ') . " XOF)"
            ];
        });

        return response()->json($factures);
    }

    public function detailsFacturePourAvoir(Achat $achat): \Illuminate\Http\JsonResponse
    {
        $this->autoriserAcces($achat);

        // La DGI ne normalise pas l'avoir d'un bordereau d'achat aux
        // producteurs agricoles. Interdit au dernier verrou, et pas
        // seulement masque a l'ecran : la route reste atteignable a la main.
        abort_if($achat->estBapa(), 400, self::AVOIR_BAPA_INTERDIT);
        $achat->load(['details.produit', 'fournisseur']);

        return response()->json([
            'id' => $achat->uuid,
            'numero_facture' => $achat->numero_facture,
            'fournisseur_nom' => $achat->fournisseur ? $achat->fournisseur->nom : 'Fournisseur inconnu',
            'montant_ttc' => $achat->montant_ttc,
            'details' => $achat->details->map(function($d) {
                return [
                    'id' => $d->id,
                    'produit_id' => $d->produit_id,
                    'libelle' => $d->produit ? $d->produit->nom : 'Produit inconnu',
                    'quantite' => $d->quantite,
                    'prix_unitaire' => $d->prix_unitaire,
                    'montant_tva' => $d->montant_tva,
                    'montant_ttc' => $d->montant_ttc,
                    'unite' => $d->produit ? ($d->produit->unite ?? 'pcs') : 'pcs',
                    'est_stockable' => $d->produit ? $d->produit->estStockable() : false,
                ];
            })
        ]);
    }

    public function creerAvoirNouveau(Request $request): RedirectResponse
    {
        $request->validate([
            // La pièce d'origine est désignée par son identifiant public,
            // celui que l'écran a reçu. Le numérique n'est plus publié.
            'parent_id' => ['required', 'uuid', Appartenance::a('achats', 'uuid')],
            'raison'    => ['required', 'string', 'max:255'],
            'items'     => ['required', 'array'],
        ]);

        $parent = Achat::where('uuid', $request->parent_id)->firstOrFail();
        $this->autoriserAcces($parent);
        abort_if($parent->type_facture === 'avoir', 400, "Impossible de générer un avoir sur un avoir.");

        // La DGI ne normalise pas l'avoir d'un bordereau d'achat aux
        // producteurs agricoles. Interdit au dernier verrou, et pas
        // seulement masque a l'ecran : la route reste atteignable a la main.
        abort_if($parent->estBapa(), 400, self::AVOIR_BAPA_INTERDIT);

        $avoirId = null;

        DB::transaction(function () use ($parent, $request, &$avoirId) {
            $numAvoir = \App\Modules\Admin\Services\NumerotationService::genererNumeroAchat(
                $parent->pointDeVente->entreprise_id, 'Facture', 'avoir'
            );

            // 1. Création de la facture d'avoir d'achat
            $avoir = Achat::create([
                'point_de_vente_id'          => $parent->point_de_vente_id,
                'fournisseur_id'             => $parent->fournisseur_id,
                'utilisateur_id'             => Auth::id(),
                'numero_facture'             => $numAvoir,
                'numero_facture_fournisseur' => $request->raison,
                'date_achat'                 => now()->toDateString(),
                'mode_paiement'              => $parent->mode_paiement,
                'moyen_bancaire'             => $parent->moyen_bancaire,
                'reference_paiement'         => $request->raison,
                'statut'                     => 'Payé',
                'type_facture'               => 'avoir',
                'etape'                      => 'Facture',
                'parent_id'                  => $parent->id,
                'raison_avoir'               => $request->raison,
                'montant_ht'                 => 0,
                'montant_tva'                => 0,
                'montant_ttc'                => 0,
            ]);

            $totalHt = 0;
            $totalTva = 0;
            $totalTtc = 0;

            // 2. Traitement des lignes
            foreach ($request->items as $itemId => $itemData) {
                $isNouveau = isset($itemData['est_nouveau']) && $itemData['est_nouveau'] == 1;
                $qteAvoir = floatval($itemData['quantite']);
                $prixUnit = floatval($itemData['prix_unitaire']);

                if ($qteAvoir <= 0) continue;

                if ($isNouveau) {
                    $produitId = $itemData['produit_id'] ?? null;
                    $libelle = $itemData['libelle_virtuel'] ?? 'Article';
                    
                    $produit = null;
                    if ($produitId) {
                        $produit = \App\Modules\Admin\Modeles\Produit::find($produitId);
                    }

                    $tvaRate = (floatval($itemData['taux_tva'] ?? 18.0)) / 100;
                    $unite = $produit ? $produit->unite : 'pcs';
                    
                    $itemHt = $qteAvoir * $prixUnit;
                    $itemTva = $itemHt * $tvaRate;
                    $itemTtc = $itemHt + $itemTva;

                    \App\Modules\Admin\Modeles\AchatDetail::create([
                        'achat_id'        => $avoir->id,
                        'produit_id'      => $produitId,
                        'libelle_virtuel' => $libelle,
                        'quantite'        => $qteAvoir,
                        'unite'           => $unite,
                        'prix_unitaire'   => $prixUnit,
                        'montant_tva'     => $itemTva,
                        'montant_ttc'     => $itemTtc,
                    ]);

                    $totalHt += $itemHt;
                    $totalTva += $itemTva;
                    $totalTtc += $itemTtc;

                    // Action sur stock (décrémentation stock si retour physique marchandise)
                    if ($produit && $produit->estStockable()) {
                        self::rendreAuFournisseur($produit, $parent, $avoir, $numAvoir,
                            (float) $qteAvoir, $itemData['stock_action'] ?? 'none');
                    }
                } else {
                    $detail = \App\Modules\Admin\Modeles\AchatDetail::where('achat_id', $parent->id)->where('id', $itemId)->first();
                    if (!$detail) continue;

                    $tvaRate = ($detail->montant_ttc - $detail->montant_ht) > 0 ? 0.18 : 0;

                    $itemHt = $qteAvoir * $prixUnit;
                    $itemTva = $itemHt * $tvaRate;
                    $itemTtc = $itemHt + $itemTva;

                    \App\Modules\Admin\Modeles\AchatDetail::create([
                        'achat_id'      => $avoir->id,
                        'produit_id'    => $detail->produit_id,
                        'quantite'      => $qteAvoir,
                        'prix_unitaire' => $prixUnit,
                        'montant_tva'   => $itemTva,
                        'montant_ttc'   => $itemTtc,
                    ]);

                    $totalHt += $itemHt;
                    $totalTva += $itemTva;
                    $totalTtc += $itemTtc;

                    // Action sur stock (décrémentation stock si retour physique marchandise)
                    if ($detail->produit && $detail->produit->estStockable()) {
                        self::rendreAuFournisseur($detail->produit, $parent, $avoir, $numAvoir,
                            (float) $qteAvoir, $itemData['stock_action'] ?? 'none');
                    }
                }
            }

            $avoir->update([
                'montant_ht'  => $totalHt,
                'montant_tva' => $totalTva,
                'montant_ttc' => $totalTtc,
            ]);

            // 3. Écritures comptables
            \App\Modules\Admin\Services\ComptabiliteService::genererEcritureAvoirAchat($avoir);

            // 4. Encaissement trésorerie si remboursement fournisseur
            if (str_contains(strtolower($parent->mode_paiement), 'espèces') || str_contains(strtolower($parent->mode_paiement), 'caisse')) {
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $parent->point_de_vente_id)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id'  => $parent->point_de_vente_id,
                    'date_operation'     => now()->toDateString(),
                    'type_operation'     => 'Encaissement',
                    'libelle'            => 'Remboursement Avoir fournisseur ' . $numAvoir,
                    'mode_paiement'      => $parent->mode_paiement,
                    'montant_entree'     => $totalTtc,
                    'montant_sortie'     => 0,
                    'solde_resultat'     => $soldeActuel + $totalTtc,
                    'reference_document' => $numAvoir,
                ]);
            }

            $avoirId = $avoir->id;
        });

        $this->journaliser('creation_avoir_achat', 'Achat', $avoirId);

        $routeRedirect = request()->routeIs('caissier.*') ? 'caissier.achats.factures' : 'admin.achats.factures';
        return redirect()->route($routeRedirect, ['type' => 'avoir'])
            ->with('succes', "Facture d'avoir fournisseur enregistrée !");
    }

    public function produitsParCategorie(): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();
        $entrepriseId = $user->entreprise_id;

        $produits = \App\Modules\Admin\Modeles\Produit::with('category')
            ->where('entreprise_id', $entrepriseId)
            ->where('statut', 'actif')
            ->get();

        $grouped = [];
        foreach ($produits as $p) {
            $catNom = $p->category ? $p->category->nom : 'Non Catégorisé';
            $grouped[$catNom][] = [
                'id' => $p->id,
                'nom' => $p->nom,
                'prix_vente' => floatval($p->prix_vente),
                'prix_achat' => floatval($p->prix_achat),
                'unite' => $p->unite ?? 'pcs',
                'est_stockable' => $p->estStockable(),
                'taux_tva' => floatval($p->taux_tva ?? 18.0),
            ];
        }

        return response()->json($grouped);
    }

    public function transmettreB2b(Achat $achat): RedirectResponse
    {
        $this->autoriserAcces($achat);
        $entreprise = Auth::user()->entreprise;

        $dejaEnvoye = B2bNegotiation::where('reference_commande', $achat->numero_facture)->exists();
        if ($dejaEnvoye) {
            return back()->with('erreur', "Cette demande a déjà été transmise en B2B.");
        }

        $fournisseur = $achat->fournisseur;
        if (!$fournisseur || empty($fournisseur->ncc)) {
            return back()->with('erreur', "Ce fournisseur n'a pas de NCC renseigné. La liaison B2B n'est pas possible.");
        }

        $fournisseurEntreprise = Entreprise::where('ncc', $fournisseur->ncc)->first();
        if (!$fournisseurEntreprise) {
            return back()->with('erreur', "Aucune entreprise sur Selflow ne correspond au NCC {$fournisseur->ncc} de ce fournisseur.");
        }

        if ($fournisseurEntreprise->id === $entreprise->id) {
            return back()->with('erreur', "Vous ne pouvez pas initier une relation commerciale B2B avec votre propre entreprise.");
        }

        $produitsDemandes = [];
        foreach ($achat->details as $d) {
            $produitsDemandes[] = [
                'produit_id_client' => $d->produit_id,
                'reference'         => $d->produit?->reference ?? 'REF-' . $d->produit_id,
                'nom'               => $d->produit?->nom ?? $d->libelle_virtuel ?? 'Produit #' . $d->produit_id,
                'quantite'          => (float)$d->quantite,
                'prix_propose'      => (float)$d->prix_unitaire,
                'unite'             => $d->unite ?? $d->produit?->unite ?? 'pcs'
            ];
        }

        $historique = [[
            'date'    => now()->toDateTimeString(),
            'auteur'  => Auth::user()->nom . ' ' . Auth::user()->prenom,
            'role'    => 'Client',
            'message' => $achat->etape === 'Bon de commande'
                ? 'Bon de commande direct envoyé via B2B (Transmission différée).'
                : 'Demande de prix initiale (RFQ) envoyée via B2B (Transmission différée).'
        ]];

        B2bNegotiation::create([
            'entreprise_client_id'      => $entreprise->id,
            'entreprise_fournisseur_id' => $fournisseurEntreprise->id,
            'statut'                    => 'RFQ',
            'type_demande'              => $achat->etape === 'Bon de commande' ? 'commande' : 'rfq',
            'reference_commande'        => $achat->numero_facture,
            'produits_demandes'         => $produitsDemandes,
            'historique_discussions'    => $historique,
        ]);

        return back()->with('succes', "Demande transmise avec succès en B2B !");
    }
}
