<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
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
            // La somme tendue : elle n'est pas le décaissement, qui reste borné
            // au dû. Voir `Achat::monnaieRendue()`.
            'montant_paye'               => ['nullable', 'numeric', 'min:0'],
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

            // La somme tendue au fournisseur. Elle ne vaut pas décaissement :
            // ce qui sort de la caisse est borné au dû, et la différence est
            // la monnaie rendue, imprimée sur la pièce.
            $montantTendu = $request->filled('montant_paye') ? (float) $request->input('montant_paye') : 0.0;

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
                'montant_recu'               => $montantTendu > 0 ? $montantTendu : null,
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
                // Ce qui a été tendu au fournisseur, et ce qui est
                // réellement décaissé : jamais plus que le dû, timbre du
                // bordereau compris.
                $montantPaye = $statutInitial === 'Crédit' ? 0 : min(
                    $montantTendu > 0 ? $montantTendu : $achat->netAPayer(),
                    $achat->netAPayer()
                );

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
        // On revient sur la section qui porte la pièce qu'on vient d'établir.
        // Le paramètre `type` envoyé jusqu'ici ne désignait plus rien depuis le
        // retrait de l'avoir : on retombait sur la première section, où un
        // bordereau tout juste saisi ne figurait pas.
        $retour = $achat->etape === 'Facture'
            ? ['etape' => 'Facture', 'section' => $achat->estBapa() ? 'bapa' : 'enregistrees']
            : ['etape' => $achat->etape];

        return redirect()->route($routeRedirect, $retour)
            ->with('succes', $successLabel);
    }

    public function factures(): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = session('point_de_vente_actif_id') ?? Auth::user()->point_de_vente_id;

        $etapeActive = request('etape', 'Facture');

        /*
         * Trois natures d'achat, et une seule d'entre elles se normalise.
         *
         * Le propriétaire les a énoncées le 25/09/2026 :
         *
         *  - la **facture enregistrée** : celle qu'on saisit pour suivre une
         *    dépense. Elle ne part nulle part — c'est le fournisseur qui a
         *    certifié la sienne, si tant est qu'il l'ait fait ;
         *  - le **bordereau (BAPA)** : la seule pièce d'achat qu'un client de
         *    Selflow ait le droit de normaliser, parce que c'est lui qui
         *    l'établit, auprès d'un producteur qui n'émet rien ;
         *  - la **facture d'achat DGI** : celle que le fournisseur a certifiée
         *    et que le relevé du portail rapporte. Elle est déjà normalisée :
         *    il n'y a rien à lui faire, seulement à la rapprocher.
         *
         * Les mélanger dans un seul tableau obligeait chaque ligne à expliquer
         * ce qu'elle était, et la colonne « Normalisé (DGI) » à mentir pour les
         * deux tiers d'entre elles.
         */
        $section = request('section', 'enregistrees');
        if (!in_array($section, ['enregistrees', 'bapa', 'dgi'], true)) {
            $section = 'enregistrees';
        }

        $baseQuery = Achat::with(['fournisseur', 'pointDeVente', 'details.produit', 'rejets'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));

        if ($pointDeVenteId) {
            $baseQuery->where('point_de_vente_id', $pointDeVenteId);
        }

        // L'avoir fournisseur a été retiré le 25/09/2026 : la DGI ne prévoit
        // pas qu'un acheteur établisse l'avoir de son fournisseur. Les pièces
        // déjà enregistrées restent en base et sortent simplement des listes.
        $baseQuery->where(function($q) {
            $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
        });
        $baseQuery->where('etape', $etapeActive);

        if ($etapeActive === 'Facture') {
            if ($section === 'bapa') {
                // Deux chemins mènent au bordereau, et un seul se lit dans
                // `type_facture` — voir `Achat::scopeBordereaux()`.
                $baseQuery->bordereaux();
            } elseif ($section === 'dgi') {
                // Aucune pièce de Selflow ici : cette section ne montre que ce
                // que le portail a rapporté.
                $baseQuery->whereRaw('1 = 0');
            } else {
                $baseQuery->horsBordereaux();
            }
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

        // Ce que chaque section porte, pour que l'onglet le dise avant qu'on
        // l'ouvre.
        $compteFactures = (clone $compteQuery)->where('etape', 'Facture');
        $nbBapa = (clone $compteFactures)->bordereaux()->count();
        $nbEnregistrees = (clone $compteFactures)->horsBordereaux()->count();
        // Le compteur de l'onglet dit ce qu'il y a à regarder, donc sans les
        // écartées : les compter ferait un nombre qui ne baisse jamais.
        $nbDgi = PortailFneFactureRecue::where('entreprise_id', $entreprise->id)
            ->where('statut_rapprochement', '!=', PortailFneFactureRecue::ECARTEE)
            ->count();
        $nbEcartees = PortailFneFactureRecue::where('entreprise_id', $entreprise->id)
            ->where('statut_rapprochement', PortailFneFactureRecue::ECARTEE)
            ->count();

        $achats = $baseQuery->latest()->paginate(20)->appends(request()->query());

        // `$facturesDispo` servait au modal « Créer une facture d'avoir »,
        // retiré le 25/09/2026. La vue ne la lit plus : elle sort aussi d'ici.

        // Les factures que la DGI détient pour l'entreprise et que Selflow n'a
        // pas encore rattachées à un achat.
        //
        // Elles n'apparaissaient que sur `/admin/achats/factures-recues`, un
        // écran séparé que rien n'obligeait à ouvrir : une facture certifiée par
        // un fournisseur pouvait rester des semaines sans être rapprochée, alors
        // que l'écran des factures d'achat était consulté tous les jours.
        //
        // **Le filtre par point de vente ne s'y applique pas**, et ce n'est pas
        // un oubli : le portail ne dit pas à quel site de l'entreprise une
        // facture reçue se rattache — ses champs `clientEstablishment` et
        // `clientPointOfSale` décrivent l'émetteur, comme `FneService` envoie
        // les nôtres quand c'est nous qui émettons. Les masquer sous prétexte
        // qu'elles n'ont pas de site les rendrait invisibles partout, et
        // personne ne les rapprocherait jamais. Le site leur vient de l'achat
        // auquel on les rattache.
        //
        // Cet écran ne totalise aucun montant : les y faire figurer ne fausse
        // donc aucun cumul, contrairement à l'écran FNE qui, lui, les écarte
        // dès qu'un site précis est demandé.
        $facturesPortail = collect();
        if ($etapeActive === 'Facture' && $section === 'dgi') {
            // **Toutes**, et non les seules non rattachées. L'écran séparé
            // `/admin/achats/factures-recues` était jusqu'ici le seul endroit
            // où revoir une facture déjà rapprochée ou écartée, et donc le seul
            // où l'on pouvait revenir sur l'une ou sur l'autre. En les montrant
            // toutes ici, cet écran n'a plus de raison d'être.
            /*
             * Ce qu'on a mis de côté ne revient pas chaque jour.
             *
             * L'écran séparé avait un filtre par statut ; il portait seul la
             * possibilité de revenir sur un écartement. La section le reprend :
             * par défaut elle montre ce qui vit — à rapprocher et rattachées —,
             * et `?statut=ecartees` rend ce qu'on a mis de côté, avec le geste
             * qui l'en sort. Sans ce filtre, écarter n'aurait plus aucun effet.
             */
            $ecarteesSeules = request('statut') === 'ecartees';

            $facturesPortail = PortailFneFactureRecue::where('entreprise_id', $entreprise->id)
                ->when(
                    $ecarteesSeules,
                    fn ($q) => $q->where('statut_rapprochement', PortailFneFactureRecue::ECARTEE),
                    fn ($q) => $q->where('statut_rapprochement', '!=', PortailFneFactureRecue::ECARTEE)
                )
                // Le site retenu, plus celles que personne n'a encore rangées.
                // Les secondes n'appartiennent à aucun site : les masquer les
                // rendrait invisibles sous tous, et personne ne les affecterait
                // jamais. Les premières, elles, encombreraient la vue d'un site
                // qui ne les a pas supportées.
                ->when($pointDeVenteId, fn ($q) => $q->where(function ($qs) use ($pointDeVenteId) {
                    $qs->where('point_de_vente_id', $pointDeVenteId)
                       ->orWhereNull('point_de_vente_id');
                }))
                ->when(request()->filled('recherche'), function ($q) {
                    $recherche = request('recherche');
                    $q->where(function ($qr) use ($recherche) {
                        $qr->where('reference', 'like', "%{$recherche}%")
                           ->orWhere('emetteur_nom', 'like', "%{$recherche}%")
                           ->orWhere('emetteur_ncc', 'like', "%{$recherche}%");
                    });
                })
                // « Non normalisée » n'a pas de sens pour une pièce que la DGI
                // détient : le filtre ne la retient que du côté « normalisée ».
                ->when(request('dgi_filtre') === 'non', fn ($q) => $q->whereRaw('1 = 0'))
                ->when(request()->filled('date_debut'), fn ($q) => $q->whereDate('date_facture', '>=', request('date_debut')))
                ->when(request()->filled('date_fin'), fn ($q) => $q->whereDate('date_facture', '<=', request('date_fin')))
                ->with(['lignes', 'pointDeVente'])
                ->orderByDesc('date_facture')
                ->get();
        }

        // Pour le sélecteur de site des factures du portail. Chargés une fois
        // plutôt qu'à chaque ligne.
        $sitesDisponibles = $facturesPortail->isEmpty()
            ? collect()
            : \App\Modules\Admin\Modeles\PointDeVente::where('entreprise_id', $entreprise->id)
                ->orderBy('nom')->get();

        return view('admin::achats.factures', compact(
            'achats', 'etapeActive', 'section', 'nbDP', 'nbBC', 'nbFacture',
            'nbBapa', 'nbEnregistrees', 'nbDgi', 'nbEcartees',
            'facturesPortail', 'sitesDisponibles'
        ));
    }



    /**
     * La pièce d'achat, en PDF véritable — facture enregistrée ou bordereau.
     */
    public function pdf(Achat $achat, \App\Modules\Admin\Services\DocumentPdfService $pdf): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(
            $achat->pointDeVente->entreprise_id === Auth::user()->entreprise_id,
            404
        );

        $achat->load(['details.produit', 'fournisseur', 'pointDeVente.entreprise']);
        $dejaPaye = \App\Modules\Admin\Modeles\TresorerieJournal::where('reference_document', $achat->numero_facture)
            ->sum('montant_sortie');

        return response($pdf->achat($achat, (float) $dejaPaye), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'
                . \App\Modules\Admin\Services\DocumentPdfService::nomDuFichier($achat) . '"',
        ]);
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

        // Le succès se lit sur la pièce, il ne se suppose pas. Ce message
        // partait en vert quoi qu'il arrive — même quand la DGI refusait le
        // bordereau, même quand la plateforme n'avait pas répondu : un vert
        // rassurant sur un achat resté non normalisé. C'est le défaut corrigé
        // sur les ventes au lot 20 ; il vivait ici aussi.
        if ($achat->fresh()->normalise) {
            return back()->with('succes', 'La normalisation BAPA/DGI a été effectuée avec succès. Le document est maintenant normalisé.');
        }

        $rejet = FneRejet::where('piece_type', 'achat')
            ->where('piece_id', $achat->id)
            ->where('statut', FneRejet::STATUT_OUVERT)
            ->latest('id')
            ->first();

        // La plateforme n'a pas répondu : rien n'a été refusé, rien n'a été
        // examiné, et personne ne rejouera à notre place.
        if ($rejet && $rejet->estReseau()) {
            return back()
                ->with('erreur',
                    "La plateforme FNE est injoignable pour le moment : le bordereau n'a pas été "
                    . "envoyé, et la DGI n'a rien refusé. Réessayez dans un instant — le rejet "
                    . 'se refermera de lui-même dès que la pièce passera.'
                )
                ->with('erreur_action', [[
                    'url'    => route('admin.achats.normaliser', $achat),
                    'label'  => 'Réessayer',
                    'method' => 'post',
                ]]);
        }

        if ($rejet && $rejet->cause === FneRejet::CAUSE_DGI) {
            \Illuminate\Support\Facades\Artisan::call('portail-fne:importer');
            $entreprise = $rejet->entreprise ?? $achat->pointDeVente?->entreprise ?? Auth::user()->entreprise;
            if ($entreprise) {
                app(\App\Modules\Admin\Services\PointsDeVentePortailService::class)->importer($entreprise);
            }

            $correcteur = app(\App\Modules\Admin\Services\CorrectionFneService::class);
            $diagnostic = app(\App\Modules\Admin\Services\DiagnosticFneService::class)->diagnostiquer($rejet);
            $rejet->update([
                'diagnostic' => $diagnostic,
                'statut'     => $rejet->statut === FneRejet::STATUT_RESOLU
                    ? FneRejet::STATUT_RESOLU
                    : FneRejet::STATUT_DIAGNOSTIQUE,
            ]);
            $rejet->refresh();

            $correctionDirecte = $correcteur->correctionApplicable($rejet);
            if ($correctionDirecte !== null) {
                $fait = $correcteur->corriger($rejet, synchrone: true);
                if ($fait !== null) {
                    $msg = ($fait['mode'] ?? 'bascule') === 'cree'
                        ? sprintf('Le point de vente « %s » a été créé automatiquement dans Selflow d\'après la DGI, et le document a été normalisé.', $fait['nouveau'])
                        : sprintf('Le document a été rattaché au point de vente « %s » et normalisé avec succès.', $fait['nouveau']);
                    return back()->with('succes', $msg);
                }
            }

            // Si plusieurs points sont déclarés au portail : afficher DIRECTEMENT la liste sélective des points de vente
            $auChoix = $correcteur->nomsAuChoix($rejet);
            if ($auChoix !== []) {
                $boutons = [];
                foreach ($auChoix as $rang => $nom) {
                    $boutons[] = [
                        'url'          => route('admin.fne.rejets.corriger_avec', ['rejet' => $rejet, 'rang' => $rang]),
                        'label'        => $nom,
                        'nom'          => $nom,
                        'action_label' => 'Activer',
                        'method'       => 'post',
                    ];
                }

                return back()
                    ->with('avertissement', 'La DGI a refusé le document : le point de vente ne correspond pas. Sélectionnez et activez le point de vente correspondant sur votre espace FNE :')
                    ->with('avertissement_action', $boutons);
            }
        }

        return back()
            ->with('avertissement', "La DGI n'a pas certifié le bordereau. Le détail du refus, et "
                . "la correction s'il y en a une à faire, sont sur l'écran des rejets FNE.")
            ->with('avertissement_action', [[
                'url'   => route('admin.fne.rejets'),
                'label' => 'Voir les rejets FNE',
            ]]);
    }

    /** Le motif du refus, le meme partout : une seule phrase a corriger le jour ou la DGI ouvrira. */
    private const AVOIR_BAPA_INTERDIT = "La DGI ne normalise pas encore l'avoir d'un bordereau d'achat "
        . "aux producteurs agricoles (BAPA) : l'operation est indisponible sur cette piece.";





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
    }    /*
     * L'avoir fournisseur a été retiré le 25/09/2026.
     *
     * `creerAvoir()`, `creerAvoirNouveau()`, `rechercherFacturesPourAvoir()` et
     * `detailsFacturePourAvoir()` vivaient ici, avec `ecarterLesBapa()` qui ne
     * servait qu'à elles.
     *
     * **Un acheteur n'établit pas l'avoir de son fournisseur.** La DGI ne le
     * prévoit pas : la plateforme ne certifie l'avoir que du côté de celui qui
     * a émis la facture. Selflow offrait donc un document que rien ne rendait
     * opposable, et qui décrémentait pourtant les stocks.
     *
     * Les avoirs déjà enregistrés restent en base et restent lisibles : ils
     * sortent des listes, ils ne sont pas détruits. Ce qui disparaît, c'est la
     * possibilité d'en établir de nouveaux.
     */


}
