<?php

namespace App\Modules\Admin\Controleurs;

use App\Jobs\NormaliserFactureFne;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Modeles\Banque;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\B2bNegotiation;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Traits\GereLesChampsFne;
use App\Modules\Admin\Traits\JournaliseActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Regles\Quantite;
use App\Modules\Admin\Services\StockService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use App\Modules\Admin\Modeles\FneRejet;
use Termwind\Components\Dd;

class VenteControleur
{
    use JournaliseActions;
    use GereLesChampsFne;

    public function nouvelle(): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = Auth::user()->estCaissier()
            ? Auth::user()->point_de_vente_id
            // La caisse creait un point de vente « Siege » a Abidjan, commune
            // Cocody, responsable « Superviseur », des qu'aucun n'etait actif.
            // Trois informations inventees — et le nom du point de vente est ce
            // que la plateforme de la DGI recoit : elle refuse la piece s'il ne
            // correspond a aucun site declare sur l'espace FNE. On ouvrait donc
            // en silence un magasin qui n'existe nulle part, et la premiere
            // facture partait a son nom.
            : (session('point_de_vente_actif_id')
                ?? Auth::user()->pointDeVenteDOuverture()
                ?? PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->value('id'));
        $clients = Client::obtenirClientsPrioritaires($entreprise->id);
        // Archiver un article, c'est dire qu'on ne le vend plus. La caisse le
        // proposait quand même : le filtre ne vivait que sur l'écran du
        // catalogue.
        $produits = Produit::where('entreprise_id', $entreprise->id)
            ->selectionnables()
            ->with('taxes')
            ->orderBy('nom')
            ->get();

        $categories = $produits->pluck('categorie')->unique()->sort()->values();
        $banques = CodeJournal::where('type', 'Banque')->where('entreprise_id', $entreprise->id)->orderBy('intitule')->get();

        return view('admin::ventes.nouvelle', compact('clients', 'produits', 'categories', 'pointDeVenteId', 'banques'));
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = Auth::user()->estCaissier()
            ? Auth::user()->point_de_vente_id
            // La caisse creait un point de vente « Siege » a Abidjan, commune
            // Cocody, responsable « Superviseur », des qu'aucun n'etait actif.
            // Trois informations inventees — et le nom du point de vente est ce
            // que la plateforme de la DGI recoit : elle refuse la piece s'il ne
            // correspond a aucun site declare sur l'espace FNE. On ouvrait donc
            // en silence un magasin qui n'existe nulle part, et la premiere
            // facture partait a son nom.
            : (session('point_de_vente_actif_id')
                ?? Auth::user()->pointDeVenteDOuverture()
                ?? PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->value('id'));

        $request->validate(array_merge([
            'client_id' => ['nullable', 'integer', Appartenance::a('clients', 'id')],
            'mode_paiement' => ['required', 'string'],
            'articles' => ['required', 'array', 'min:1'],
            'articles.*.produit_id' => ['nullable', 'integer', Appartenance::a('produits', 'id')],
            'articles.*.libelle_virtuel' => ['nullable', 'string', 'max:255'],
            'articles.*.quantite' => Quantite::physique(),
            'articles.*.unite' => ['nullable', 'string', 'max:50'],
            'articles.*.prix_unitaire' => ['nullable', 'numeric', 'min:0'],
            'articles.*.tva' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'type_piece' => ['nullable', 'string', 'in:facture,recu'],
            // Un terme dans le passé ne serait pas une offre, mais une offre
            // déjà caduque au moment où on la remet au client.
            'date_validite' => ['nullable', 'date', 'after_or_equal:today'],
            'articles.*.code_tva' => ['nullable', 'string', 'in:TVA,TVAB,TVAC,TVAD'],
            'articles.*.taxes' => ['nullable', 'array'],
            'articles.*.taxes.*.nom' => ['required_with:articles.*.taxes.*.taux', 'string', 'max:100'],
            'articles.*.taxes.*.taux' => ['required_with:articles.*.taxes.*.nom', 'numeric', 'gt:0', 'max:100'],
        ], self::reglesChampsFne()), array_merge([
                'articles.required' => 'Veuillez ajouter au moins un article au panier.',
            ], self::messagesChampsFne()));

        if ($request->mode_paiement === 'Banque') {
            $request->validate([
                'banque_id' => ['required', 'integer', Appartenance::a('codes_journaux', 'id')],
                'moyen_bancaire' => ['required', 'string', 'in:carte,virement,cheque'],
                'reference_paiement' => ['required', 'string', 'max:255'],
            ], [
                'banque_id.required' => 'Veuillez sélectionner la banque.',
                'moyen_bancaire.required' => 'Veuillez sélectionner le moyen de paiement bancaire.',
                'reference_paiement.required' => 'Veuillez saisir le numéro ou référence de paiement.',
            ]);
        }

        if ($request->mode_paiement !== 'Crédit') {
            $request->validate([
                'montant_paye' => ['nullable', 'numeric', 'min:0'],
            ], [
                'montant_paye.numeric' => 'Le montant payé doit être un nombre.',
                'montant_paye.min' => 'Le montant payé doit être supérieur ou égal à 0.',
            ]);
        }

        $venteId = null;
        $etapePrevue = $request->input('etape', 'Facture');

        // Vérification de disponibilité AVANT toute écriture en base : une
        // facturation (décrémentation immédiate du stock) ne doit jamais
        // pouvoir dépasser le stock réellement disponible. Un Devis ou Bon
        // de commande, eux, ne décrémentent rien et ne sont donc pas bloqués
        // ici (ils peuvent réserver plus que le stock actuel, à charge pour
        // l'utilisateur de réapprovisionner avant facturation).
        if ($etapePrevue === 'Facture') {
            foreach ($request->articles as $article) {
                if (empty($article['produit_id']))
                    continue;

                $produit = Produit::find($article['produit_id']);
                if (!$produit || !$produit->estStockable())
                    continue;

                $stockDisponible = $produit->stockActuel($pointDeVenteId);
                if ($stockDisponible < $article['quantite']) {
                    return back()->withInput()->with(
                        'error',
                        "❌ Stock insuffisant pour « {$produit->nom} » : disponible {$stockDisponible}, demandé {$article['quantite']}."
                    );
                }

                // **Le lot le plus proche de sa date est le premier que le FEFO
                // propose** — c'est donc lui qui bloque, et il faut le dire
                // avant d'écrire quoi que ce soit. `LotService` refusera de
                // toute façon, mais par une exception : elle protège les
                // données, elle n'explique rien à l'utilisateur.
                if ($perime = self::lotPerimeEnTete($produit, $pointDeVenteId)) {
                    return back()->withInput()->with(
                        'error',
                        "❌ Le lot « {$perime->numero_lot} » de « {$produit->nom} » est périmé depuis le "
                        . $perime->date_peremption->format('d/m/Y')
                        . '. Mettez-le au rebut avant de servir ce qui suit.'
                    );
                }
            }
        }

        DB::transaction(function () use ($request, $pointDeVenteId, &$venteId, $entreprise) {
            $montantHt = 0;
            $remiseTaux = self::tauxBorne($request->input('remise_taux', 0));
            $etape = $request->input('etape', 'Facture');

            // 1. Précalcul du montant HT total, remises d'article déduites.
            //    Ordre imposé par la FNE : remise de ligne d'abord, remise
            //    globale ensuite sur le total ainsi obtenu.
            foreach ($request->articles as $article) {
                if (!empty($article['produit_id'])) {
                    $produit = Produit::findOrFail($article['produit_id']);
                    $prix = $produit->prix_vente;
                } else {
                    $prix = floatval($article['prix_unitaire'] ?? 0);
                }

                $remiseLigne = self::tauxBorne($article['remise_taux'] ?? 0);
                $ht = $article['quantite'] * $prix * (1 - $remiseLigne / 100);
                $montantHt += $ht;
            }

            // La remise globale est saisie en pourcentage (format attendu par
            // la DGI) ; son équivalent en francs reste stocké dans `remise`
            // pour la comptabilité et les modèles d'impression.
            $remise = round($montantHt * $remiseTaux / 100, 2);
            $montantHtNet = max(0, $montantHt - $remise);
            $ratio = $montantHt > 0 ? $montantHtNet / $montantHt : 0;

            // 2. Calcul du montant de TVA total (somme de la TVA nette de chaque article)
            $montantTva = 0;
            foreach ($request->articles as $article) {
                if (!empty($article['produit_id'])) {
                    $produit = Produit::findOrFail($article['produit_id']);
                    // Le taux saisi sur la ligne prime sur celui du catalogue
                    $tvaRate = isset($article['tva']) && $article['tva'] !== ''
                        ? self::tauxBorne($article['tva'])
                        : $produit->taux_tva;
                    $prix = $produit->prix_vente;
                } else {
                    $tvaRate = self::tauxBorne($article['tva'] ?? 18);
                    $prix = floatval($article['prix_unitaire'] ?? 0);
                }

                $remiseLigne = self::tauxBorne($article['remise_taux'] ?? 0);
                $itemHt = $article['quantite'] * $prix * (1 - $remiseLigne / 100);
                $itemHtNet = $itemHt * $ratio;
                $itemTva = $itemHtNet * ($tvaRate / 100);
                $montantTva += $itemTva;
            }

            $montantTtc = $montantHtNet + $montantTva;

            // Taxes parafiscales (GRA, AIRSI, DTD…) : elles s'ajoutent a ce que
            // paie le client sans etre du chiffre d'affaires ni de la TVA.
            $montantAutresTaxes = self::calculerAutresTaxes($request, $montantTtc, $ratio);

            // Déterminer la valeur finale du mode de paiement pour l'enregistrement
            $modePaiementFinal = $request->mode_paiement;
            if ($request->mode_paiement === 'Banque' && $request->filled('banque_id')) {
                $codeJournal = CodeJournal::where('entreprise_id', Auth::user()->entreprise_id)->findOrFail($request->banque_id);
                $modePaiementFinal = 'Banque : ' . $codeJournal->intitule;
            }

            // Génération numéro de facture
            $numero = \App\Modules\Admin\Services\NumerotationService::genererNumeroVente($entreprise->id, $etape);

            // Calcul du montant payé et statut de la vente
            $montantPaye = 0;
            if ($request->mode_paiement === 'Crédit') {
                $statutVente = 'Crédit';
            } else {
                $netAPayer = $montantTtc + $montantAutresTaxes;
                $montantPaye = $request->filled('montant_paye') ? floatval($request->montant_paye) : $netAPayer;
                if ($montantPaye <= 0) {
                    $statutVente = 'Crédit';
                    $montantPaye = 0;
                } elseif ($montantPaye >= $netAPayer) {
                    $statutVente = 'Payé';
                } else {
                    $statutVente = 'Avance';
                }
            }

            $vente = Vente::create([
                'point_de_vente_id' => $pointDeVenteId,
                'client_id' => $request->client_id ?: null,
                'numero_facture' => $numero,
                'date_vente' => now()->toDateString(),
                // Un devis sans terme engage indéfiniment celui qui l'a fait :
                // il reste présentable des mois plus tard, aux prix du jour où
                // il a été établi. Une facture, elle, n'expire pas.
                'date_validite' => self::termeDeLOffre($request, $etape),
                'mode_paiement' => $modePaiementFinal,
                'moyen_bancaire' => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                'reference_paiement' => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                'mobile_money_operateur' => $request->mode_paiement === 'Mobile Money' ? $request->mobile_money_operateur : null,
                'devise' => $request->devise ?: 'XOF',
                'taux_change' => ($request->devise && $request->devise !== 'XOF') ? floatval($request->taux_change) : null,
                'montant_ht' => $montantHt,
                'montant_tva' => $montantTva,
                'remise' => $remise,
                'remise_taux' => $remiseTaux,
                'montant_ttc' => $montantTtc,
                'montant_autres_taxes' => $montantAutresTaxes,
                'statut' => $statutVente,
                'etape' => $etape,
                // Nature du document : facture par defaut. Un encaissement en
                // especes reste une facture ; seul le bouton « Recu » produit
                // un recu.
                'type_piece' => $request->input('type_piece') === Vente::TYPE_RECU
                    ? Vente::TYPE_RECU
                    : Vente::TYPE_FACTURE,
            ]);

            // Taxes sur le total TTC (champ `customTaxes` de la FNE)
            self::enregistrerTaxesSurTtc($vente, $request->input('taxes_ttc', []), $montantTtc);

            foreach ($request->articles as $article) {
                if (!empty($article['produit_id'])) {
                    $produit = Produit::lockForUpdate()->findOrFail($article['produit_id']);
                    $prix = $produit->prix_vente;
                    $nomElement = $produit->nom;
                    $tvaRate = isset($article['tva']) && $article['tva'] !== ''
                        ? self::tauxBorne($article['tva'])
                        : $produit->taux_tva;
                } else {
                    $produit = null;
                    $prix = floatval($article['prix_unitaire'] ?? 0);
                    $nomElement = $article['libelle_virtuel'] ?? 'Saisie libre';
                    $tvaRate = self::tauxBorne($article['tva'] ?? 18);
                }

                $remiseLigne = self::tauxBorne($article['remise_taux'] ?? 0);
                $ht = $article['quantite'] * $prix * (1 - $remiseLigne / 100);
                $tva = $ht * ($tvaRate / 100);

                $detail = VenteDetail::create([
                    'vente_id' => $vente->id,
                    'produit_id' => $produit ? $produit->id : null,
                    'libelle_virtuel' => $produit ? null : $nomElement,
                    'quantite' => $article['quantite'],
                    'unite' => $article['unite'] ?? 'Unité',
                    'prix_unitaire' => $prix,
                    'remise_taux' => $remiseLigne,
                    'montant_tva' => $tva,
                    'montant_ttc' => $ht + $tva,
                ]);

                // Taxes de la ligne : celles de la fiche produit, ou celles
                // saisies directement sur une ligne libre.
                self::copierTaxesProduitSurLigne($detail, $produit);
                if (!$produit) {
                    self::enregistrerTaxesDeLigne($detail, $article['taxes'] ?? []);
                }

                if ($produit && $etape === 'Facture' && $produit->estStockable()) {
                    // Controle final sous le verrou de la fiche de stock —
                    // celui-la meme qui servira a la sortie. C'est la seconde
                    // verification, autoritaire cette fois : elle protege des
                    // ventes concurrentes passees entre la pre-verification et
                    // ici.
                    $disponible = StockService::disponible($produit, (int) $pointDeVenteId);

                    if ($disponible < $article['quantite']) {
                        throw new \InvalidArgumentException(
                            "Stock insuffisant pour « {$produit->nom} » (Disponible: {$disponible}, Demandé: {$article['quantite']})."
                        );
                    }

                    // Marquer la ligne comme totalement livrée : la facturation directe
                    // implique une sortie de stock immédiate. Sans cela, cette même
                    // ligne réapparaîtrait dans la file de "Livraisons à valider"
                    // (StockControleur::livraisons()) et son stock serait décrémenté
                    // UNE SECONDE FOIS si un utilisateur validait une "livraison" dessus.
                    $detail->update(['quantite_livree' => $article['quantite']]);

                    StockService::sortie(
                        $produit,
                        (int) $pointDeVenteId,
                        (float) $article['quantite'],
                        MouvementStock::LIVRAISON,
                        ['piece' => $vente, 'reference' => $numero, 'client_id' => $vente->client_id]
                    );
                }
            }

            // Trésorerie et Comptabilité (uniquement si validé en étape Facture)
            // Trésorerie et Comptabilité (uniquement si validé en étape Facture)
            if ($etape === 'Facture') {
                // Le reçu part par la même porte que la facture : la procédure
                // d'interfaçage n'expose qu'un seul point d'émission. Ce qui les
                // sépare est le format d'impression, pas l'envoi.
                //
                // La certification suit désormais le réglage de l'entreprise —
                // dès l'émission, ou à la main après vérification. Une pièce
                // certifiée ne se reprenant pas, le choix lui appartient.
                if ($entreprise->normaliseAutomatiquement($vente)) {
                    // En arrière-plan : n'attendons pas la réponse de la DGI.
                    NormaliserFactureFne::dispatch($vente);
                }

                // Écritures de facturation (+ règlement immédiat le cas échéant).
                // genererEcrituresVente() décide seule si la vente est comptant
                // (aucune ligne 411) ou à crédit total/partiel (411 pour le solde
                // réellement non couvert) — voir ComptabiliteService pour le détail.
                \App\Modules\Admin\Services\ComptabiliteService::genererEcrituresVente(
                    $vente,
                    $statutVente === 'Crédit' ? 0 : $montantPaye,
                    $modePaiementFinal,
                    now()->toDateString(),
                    $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    $request->mode_paiement === 'Banque' ? $request->reference_paiement : null
                );

                if ($statutVente !== 'Crédit' && $montantPaye > 0) {
                    $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pointDeVenteId)
                        ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                    TresorerieJournal::create([
                        'point_de_vente_id' => $pointDeVenteId,
                        'date_operation' => now()->toDateString(),
                        'type_operation' => 'Encaissement',
                        'libelle' => \App\Modules\Admin\Services\ComptabiliteService::libelleTresorerieVente($vente),
                        'mode_paiement' => $modePaiementFinal,
                        'moyen_bancaire' => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                        'reference_paiement' => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                        'montant_entree' => $montantPaye,
                        'montant_sortie' => 0,
                        'solde_resultat' => $soldeActuel + $montantPaye,
                        'reference_document' => $numero,
                    ]);
                }
            } else {
                // Étape Devis : Enregistrer le règlement (acompte) dans la trésorerie si présent
                if ($statutVente !== 'Crédit' && $montantPaye > 0) {
                    $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pointDeVenteId)
                        ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                    TresorerieJournal::create([
                        'point_de_vente_id' => $pointDeVenteId,
                        'date_operation' => now()->toDateString(),
                        'type_operation' => 'Encaissement',
                        'libelle' => 'Acompte Vente — Devis ' . $numero,
                        'mode_paiement' => $modePaiementFinal,
                        'montant_entree' => $montantPaye,
                        'montant_sortie' => 0,
                        'solde_resultat' => $soldeActuel + $montantPaye,
                        'reference_document' => $numero,
                    ]);
                }
            }

            $venteId = $vente->id;
        }); // fin DB::transaction

        // ── Envoi B2B automatique pour "Bon de commande" ───────────────────────
        // Si le client a un NCC qui correspond à une entreprise enregistrée dans
        // Selflow, on crée automatiquement un bon de commande dans son espace B2B.
        if (isset($etape) && $etape === 'Bon de commande' && isset($vente)) {
            $clientNcc = null;
            if ($vente->client_id) {
                $clientNcc = Client::where('id', $vente->client_id)->value('ncc');
            }
            if ($clientNcc) {
                $entrepriseDestinataire = Entreprise::where('ncc', $clientNcc)->first();
                if ($entrepriseDestinataire && $entrepriseDestinataire->id !== Auth::user()->entreprise_id) {
                    // Charger les détails avec les produits
                    $vente->load('details.produit');
                    $produitsDemandes = $vente->details->map(function ($d) {
                        return [
                            'nom' => $d->produit?->nom ?? 'Produit #' . $d->produit_id,
                            'quantite' => $d->quantite,
                            'unite' => $d->unite ?? $d->produit?->unite ?? 'pcs',
                            'prix_propose' => $d->prix_unitaire,
                        ];
                    })->values()->all();

                    B2bNegotiation::create([
                        'entreprise_client_id' => Auth::user()->entreprise_id,
                        'entreprise_fournisseur_id' => $entrepriseDestinataire->id,
                        'statut' => 'RFQ',
                        'type_demande' => 'commande',
                        'reference_commande' => $vente->numero_facture,
                        'produits_demandes' => $produitsDemandes,
                        'prix_final' => $vente->montant_ttc,
                        'historique_discussions' => [],
                    ]);
                }
            }
        }

        // Journaliser la création de la vente
        $this->journaliser('creation_vente', 'Vente', $venteId ?? null);

        $routeRetour = request()->routeIs('caissier.*') ? 'caissier.ventes.factures' : 'admin.ventes.factures';
        $etape = $request->input('etape', 'Facture');
        return redirect()->route($routeRetour, ['etape' => $etape])
            ->with('succes', $etape . ' enregistré(e) avec succès.');
    }

    public function factures(): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = Auth::user()->estCaissier()
            ? Auth::user()->point_de_vente_id
            : session('point_de_vente_actif_id');

        $etapeActive = request('etape', 'Facture');
        $type = request('type');
        $voirArchives = request('archives') === '1';

        // Calcul des totaux par étape (non archivés seulement)
        $compteQuery = Vente::where(function ($q) {
            $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
        })->where('archived', false);
        if ($pointDeVenteId) {
            $compteQuery->where('point_de_vente_id', $pointDeVenteId);
        } else {
            $compteQuery->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));
        }

        $totaux = $compteQuery->select('etape', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
            ->groupBy('etape')
            ->pluck('total', 'etape')
            ->toArray();

        $nbDV = $totaux['Devis'] ?? 0;
        $nbBC = $totaux['Bon de commande'] ?? 0;
        $nbFacture = $totaux['Facture'] ?? 0;

        // Calcul du total des Bons de Livraison actifs
        $blCompteQuery = BonLivraison::whereNotIn('statut', ['facture']);
        if ($pointDeVenteId) {
            $blCompteQuery->where('point_de_vente_id', $pointDeVenteId);
        } else {
            $blCompteQuery->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));
        }
        $nbBL = $blCompteQuery->count();

        if ($etapeActive === 'Bon de livraison') {
            $blQuery = BonLivraison::with(['bonDeCommande', 'client', 'pointDeVente', 'facture'])
                ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));
            if ($pointDeVenteId) {
                $blQuery->where('point_de_vente_id', $pointDeVenteId);
            }
            if (request()->filled('statut')) {
                $blQuery->where('statut', request('statut'));
            }
            $ventes = $blQuery->latest()->paginate(20);
        } else {
            $baseQuery = Vente::with(['client', 'pointDeVente', 'details.produit', 'pieceLiee', 'rejets']);

            if ($type === 'avoir') {
                $baseQuery->where('type_facture', 'avoir');
                $etapeActive = 'Facture';
            } else {
                $baseQuery->where(function ($q) {
                    $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
                });
                $baseQuery->where('etape', $etapeActive);
            }

            // Filtrage archives : par défaut on exclut les archivés
            if ($voirArchives) {
                $baseQuery->where('archived', true);
            } else {
                $baseQuery->where('archived', false);
            }

            if ($pointDeVenteId) {
                $baseQuery->where('point_de_vente_id', $pointDeVenteId);
            } else {
                $baseQuery->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));
            }

            // Filtres additionnels (recherche, statut, période) — appliqués
            // automatiquement dès la saisie côté vue, aucun bouton requis.
            if (request()->filled('recherche')) {
                $recherche = request('recherche');
                $baseQuery->where(function ($q) use ($recherche) {
                    $q->where('numero_facture', 'like', "%{$recherche}%")
                        ->orWhere('numero_fne', 'like', "%{$recherche}%")
                        ->orWhereHas('client', fn($qc) => $qc->where('nom', 'like', "%{$recherche}%"));
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
                $baseQuery->whereDate('date_vente', '>=', request('date_debut'));
            }
            if (request()->filled('date_fin')) {
                $baseQuery->whereDate('date_vente', '<=', request('date_fin'));
            }

            $ventes = $baseQuery->latest()->paginate(20)->appends(request()->query());
        }

        $facturesDispo = collect();
        if ($type === 'avoir') {
            $facturesDispoQuery = Vente::with('client')
                ->whereHas('pointDeVente', fn($queryPdv) => $queryPdv->where('entreprise_id', $entreprise->id))
                ->where('etape', 'Facture')
                ->where(function ($queryNum) {
                    // Accepte l'ancien préfixe (VT-) ET le nouveau (VTE-,
                    // depuis le changement de convention de numérotation).
                    $queryNum->where('numero_facture', 'LIKE', 'VT-%')
                        ->orWhere('numero_facture', 'LIKE', 'VTE-%');
                })
                ->where(function ($queryType) {
                    $queryType->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
                })
                ->where('archived', false);
            if ($pointDeVenteId) {
                $facturesDispoQuery->where('point_de_vente_id', $pointDeVenteId);
            }
            $facturesDispo = $facturesDispoQuery->latest()->get();
        }

        $banques = \App\Modules\Admin\Modeles\CodeJournal::where('type', 'Banque')->where('entreprise_id', $entreprise->id)->orderBy('intitule')->get();

        return view('admin::ventes.factures', compact('ventes', 'etapeActive', 'nbDV', 'nbBC', 'nbBL', 'nbFacture', 'type', 'voirArchives', 'facturesDispo', 'banques'));
    }



    public function imprimer(Vente $vente): View
    {
        abort_unless(
            $vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id,
            404
        );

        $vendeur = $vente->utilisateur;

        // Le document affiche les taxes de chaque ligne et celles de la pièce
        $vente->load(['details.produit', 'details.taxes', 'taxesPersonnalisees', 'client', 'pointDeVente.entreprise']);

        // Calculer ce qui a été effectivement payé
        $dejaPaye = TresorerieJournal::where('reference_document', $vente->numero_facture)->sum('montant_entree');

        return view('admin::factures.vente', compact('vente', 'vendeur', 'dejaPaye'));
    }

    public function imprimerTicket(Vente $vente): View
    {
        abort_unless(
            $vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id,
            404
        );

        $vendeur = $vente->utilisateur;
        $dejaPaye = TresorerieJournal::where('reference_document', $vente->numero_facture)->sum('montant_entree');

        $bl = null;
        if (request()->filled('bl')) {
            $bl = BonLivraison::with('details.produit')->find(request('bl'));
        }

        return view('admin::factures.ticket', compact('vente', 'vendeur', 'dejaPaye', 'bl'));
    }


    /**
     * Formulaire de modification d'une vente.
     */
    public function modifierFormulaire(Vente $vente): View
    {
        abort_unless(
            $vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id,
            404
        );

        if ($vente->normalise) {
            abort(403, 'Cette facture a été normalisée et ne peut plus être modifiée.');
        }

        if ($vente->estFige()) {
            abort(403, self::pourquoiFige($vente));
        }

        $entreprise = Auth::user()->entreprise;
        $clients = Client::obtenirClientsPrioritaires($entreprise->id);
        // Load details with products
        $vente->load(['details.produit', 'client']);

        // Le catalogue n'offre plus que ce qui se vend encore — mais un devis
        // établi avant l'archivage porte peut-être un article rangé depuis. Le
        // taire ici retirerait la ligne du formulaire, donc de la pièce, à la
        // première modification. Les articles déjà portés restent proposés.
        $dejaPortes = $vente->details->pluck('produit_id')->filter()->all();

        $produits = Produit::where('entreprise_id', $entreprise->id)
            ->where(fn($q) => $q->selectionnables()->orWhereIn('id', $dejaPortes))
            ->orderBy('nom')
            ->get();
        $categories = $produits->pluck('categorie')->unique()->sort()->values();
        $banques = CodeJournal::where('type', 'Banque')->where('entreprise_id', $entreprise->id)->orderBy('intitule')->get();

        return view('admin::ventes.modifier', compact('vente', 'clients', 'produits', 'categories', 'banques'));
    }

    /**
     * Enregistrer la modification d'une vente.
     */
    public function enregistrerModification(Vente $vente, Request $request): RedirectResponse
    {
        abort_unless(
            $vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id,
            404
        );

        if ($vente->normalise) {
            abort(403, 'Cette facture a été normalisée et ne peut plus être modifiée.');
        }

        // **Une offre acceptée ou convertie se relit, elle ne se réécrit pas.**
        // C'est ce qui fait la différence entre un document opposable et une
        // note : un devis dont les prix changent après l'accord du client ne
        // prouve plus rien, et la commande qui en découle facturerait autre
        // chose que ce qui a été convenu. La correction passe par un nouveau
        // devis, ce qui laisse les deux versions lisibles.
        if ($vente->estFige()) {
            abort(403, self::pourquoiFige($vente));
        }

        $request->validate(array_merge([
            'client_id' => ['nullable', 'integer', Appartenance::a('clients', 'id')],
            'mode_paiement' => ['required', 'string'],
            'articles' => ['required', 'array', 'min:1'],
            'articles.*.produit_id' => ['nullable', 'integer', Appartenance::a('produits', 'id')],
            'articles.*.libelle_virtuel' => ['nullable', 'string', 'max:255'],
            'articles.*.quantite' => Quantite::physique(),
            'articles.*.unite' => ['nullable', 'string', 'max:50'],
            'articles.*.prix_unitaire' => ['nullable', 'numeric', 'min:0'],
            'articles.*.tva' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'type_piece' => ['nullable', 'string', 'in:facture,recu'],
            // Un terme dans le passé ne serait pas une offre, mais une offre
            // déjà caduque au moment où on la remet au client.
            'date_validite' => ['nullable', 'date', 'after_or_equal:today'],
            'articles.*.code_tva' => ['nullable', 'string', 'in:TVA,TVAB,TVAC,TVAD'],
            'articles.*.taxes' => ['nullable', 'array'],
            'articles.*.taxes.*.nom' => ['required_with:articles.*.taxes.*.taux', 'string', 'max:100'],
            'articles.*.taxes.*.taux' => ['required_with:articles.*.taxes.*.nom', 'numeric', 'gt:0', 'max:100'],
        ], self::reglesChampsFne()), array_merge([
                'articles.required' => 'Veuillez ajouter au moins un article au panier.',
            ], self::messagesChampsFne()));

        if ($request->mode_paiement === 'Banque') {
            $request->validate([
                'banque_id' => ['required', 'integer', Appartenance::a('codes_journaux', 'id')],
                'moyen_bancaire' => ['required', 'string', 'in:carte,virement,cheque'],
                'reference_paiement' => ['required', 'string', 'max:255'],
            ], [
                'banque_id.required' => 'Veuillez sélectionner la banque.',
                'moyen_bancaire.required' => 'Veuillez sélectionner le moyen de paiement bancaire.',
                'reference_paiement.required' => 'Veuillez saisir le numéro ou référence de paiement.',
            ]);
        }

        DB::transaction(function () use ($vente, $request) {
            $pointDeVenteId = $vente->point_de_vente_id;

            $etaitFacturee = $vente->etape === 'Facture';

            // 1. Restituer les stocks anciens — UNIQUEMENT si la vente était déjà
            //    facturée (le stock n'a jamais été décrémenté pour un Devis ou un
            //    Bon de commande ; l'incrémenter ici serait une fuite de stock fictive).
            //
            // La contre-passation restitue le stock ET garde l'histoire. Le code
            // faisait deux choses distinctes : il ré-incrémentait à la main, puis
            // effaçait les mouvements par
            //     MouvementStock::where('reference_document', ...)->delete()
            // Le stock revenait juste, mais la sortie de dix sacs disparaissait
            // du journal — et avec elle toute chance d'expliquer un écart
            // d'inventaire six mois plus tard. Le modèle refuse désormais cette
            // suppression ; c'est une écriture de sens inverse qui corrige.
            $oldDetails = VenteDetail::where('vente_id', $vente->id)->with('produit')->get();
            if ($etaitFacturee) {
                StockService::contrePasserLaPiece($vente, ['reference' => $vente->numero_facture]);
            }

            // 2. Supprimer les anciens détails et écritures de trésorerie
            VenteDetail::where('vente_id', $vente->id)->delete();
            TresorerieJournal::where('reference_document', $vente->numero_facture)->delete();

            // 3. Recalculer avec les nouveaux articles
            $montantHt = 0;
            $remiseTaux = self::tauxBorne($request->input('remise_taux', 0));

            // 3.1 Précalcul du montant HT total, remises d'article déduites
            foreach ($request->articles as $article) {
                if (!empty($article['produit_id'])) {
                    $produit = Produit::findOrFail($article['produit_id']);
                    $prix = $produit->prix_vente;
                } else {
                    $prix = floatval($article['prix_unitaire'] ?? 0);
                }
                $remiseLigne = self::tauxBorne($article['remise_taux'] ?? 0);
                $ht = $article['quantite'] * $prix * (1 - $remiseLigne / 100);
                $montantHt += $ht;
            }

            $remise = round($montantHt * $remiseTaux / 100, 2);
            $montantHtNet = max(0, $montantHt - $remise);
            $ratio = $montantHt > 0 ? $montantHtNet / $montantHt : 0;

            // 3.2 Calcul du montant de TVA total
            $montantTva = 0;
            foreach ($request->articles as $article) {
                if (!empty($article['produit_id'])) {
                    $produit = Produit::findOrFail($article['produit_id']);
                    // Le taux saisi sur la ligne prime sur celui du catalogue
                    $tvaRate = isset($article['tva']) && $article['tva'] !== ''
                        ? self::tauxBorne($article['tva'])
                        : $produit->taux_tva;
                    $prix = $produit->prix_vente;
                } else {
                    $tvaRate = self::tauxBorne($article['tva'] ?? 18);
                    $prix = floatval($article['prix_unitaire'] ?? 0);
                }

                $remiseLigne = self::tauxBorne($article['remise_taux'] ?? 0);
                $itemHt = $article['quantite'] * $prix * (1 - $remiseLigne / 100);
                $itemHtNet = $itemHt * $ratio;
                $itemTva = $itemHtNet * ($tvaRate / 100);
                $montantTva += $itemTva;
            }

            $montantTtc = $montantHtNet + $montantTva;

            // Déterminer le mode de paiement final
            $modePaiementFinal = $request->mode_paiement;
            if ($request->mode_paiement === 'Banque' && $request->filled('banque_id')) {
                $codeJournal = CodeJournal::where('entreprise_id', Auth::user()->entreprise_id)->findOrFail($request->banque_id);
                $modePaiementFinal = 'Banque : ' . $codeJournal->intitule;
            }

            // Statut et montants
            $montantPaye = 0;
            if ($request->mode_paiement === 'Crédit') {
                $statutVente = 'Crédit';
            } else {
                $montantPaye = $request->filled('montant_paye') ? floatval($request->montant_paye) : $montantTtc;
                if ($montantPaye <= 0) {
                    $statutVente = 'Crédit';
                    $montantPaye = 0;
                } elseif ($montantPaye >= $montantTtc) {
                    $statutVente = 'Payé';
                } else {
                    $statutVente = 'Avance';
                }
            }

            // Mettre à jour la vente
            $vente->update([
                'client_id' => $request->client_id ?: null,
                'mode_paiement' => $modePaiementFinal,
                'moyen_bancaire' => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                'reference_paiement' => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                'montant_ht' => $montantHt,
                'montant_tva' => $montantTva,
                'remise' => $remise,
                'remise_taux' => $remiseTaux,
                'montant_ttc' => $montantTtc,
                'statut' => $statutVente,
                'type_piece' => $request->input('type_piece') === Vente::TYPE_RECU
                    ? Vente::TYPE_RECU
                    : $vente->type_piece,
            ]);

            self::enregistrerTaxesSurTtc($vente, $request->input('taxes_ttc', []), $montantTtc);

            // Re-créer les détails et mouvements
            foreach ($request->articles as $article) {
                if (!empty($article['produit_id'])) {
                    $produit = Produit::lockForUpdate()->findOrFail($article['produit_id']);
                    $prix = $produit->prix_vente;
                    $nomElement = $produit->nom;
                    $tvaRate = isset($article['tva']) && $article['tva'] !== ''
                        ? self::tauxBorne($article['tva'])
                        : $produit->taux_tva;
                } else {
                    $produit = null;
                    $prix = floatval($article['prix_unitaire'] ?? 0);
                    $nomElement = $article['libelle_virtuel'] ?? 'Saisie libre';
                    $tvaRate = self::tauxBorne($article['tva'] ?? 18);
                }

                $remiseLigne = self::tauxBorne($article['remise_taux'] ?? 0);
                $ht = $article['quantite'] * $prix * (1 - $remiseLigne / 100);
                $tva = $ht * ($tvaRate / 100);

                $detail = VenteDetail::create([
                    'coupon_id' => null,
                    'vente_id' => $vente->id,
                    'produit_id' => $produit ? $produit->id : null,
                    'libelle_virtuel' => $produit ? null : $nomElement,
                    'quantite' => $article['quantite'],
                    'unite' => $article['unite'] ?? 'Unité',
                    'prix_unitaire' => $prix,
                    'remise_taux' => $remiseLigne,
                    'montant_tva' => $tva,
                    'montant_ttc' => $ht + $tva,
                ]);

                self::copierTaxesProduitSurLigne($detail, $produit);
                if (!$produit) {
                    self::enregistrerTaxesDeLigne($detail, $article['taxes'] ?? []);
                }

                if ($produit && $etaitFacturee && $produit->estStockable()) {
                    $disponible = StockService::disponible($produit, (int) $pointDeVenteId);

                    if ($disponible < $article['quantite']) {
                        throw new \InvalidArgumentException(
                            "Stock insuffisant pour « {$produit->nom} » (Disponible: {$disponible}, Demandé: {$article['quantite']})."
                        );
                    }

                    // Voir explication dans store() : évite le double décrément via la
                    // file "Livraisons à valider" (StockControleur::livraisons()).
                    VenteDetail::where('vente_id', $vente->id)
                        ->where('produit_id', $produit->id)
                        ->latest('id')
                        ->limit(1)
                        ->update(['quantite_livree' => $article['quantite']]);

                    StockService::sortie(
                        $produit,
                        (int) $pointDeVenteId,
                        (float) $article['quantite'],
                        MouvementStock::LIVRAISON,
                        [
                            'piece' => $vente,
                            'reference' => $vente->numero_facture,
                            'client_id' => $vente->client_id
                        ]
                    );
                }
            }

            // Trésorerie
            if ($statutVente !== 'Crédit' && $montantPaye > 0) {
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pointDeVenteId)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id' => $pointDeVenteId,
                    'date_operation' => now()->toDateString(),
                    'type_operation' => 'Encaissement',
                    'libelle' => 'Modification Vente — Facture ' . $vente->numero_facture,
                    'mode_paiement' => $modePaiementFinal,
                    'moyen_bancaire' => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    'reference_paiement' => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                    'montant_entree' => $montantPaye,
                    'montant_sortie' => 0,
                    'solde_resultat' => $soldeActuel + $montantPaye,
                    'reference_document' => $vente->numero_facture,
                ]);
            }
        });

        $routeRetour = request()->routeIs('caissier.*') ? 'caissier.ventes.factures' : 'admin.ventes.factures';

        return redirect()->route($routeRetour)
            ->with('succes', 'Facture ' . $vente->numero_facture . ' modifiée avec succès.');
    }

    /**
     * Le lot périmé qui bloquerait la sortie, s'il y en a un.
     *
     * Le FEFO — *First Expired, First Out* — sert d'abord ce qui périme le plus
     * tôt : c'est donc le premier lot de la file qui décide. Un lot périmé plus
     * loin dans la file ne gêne pas, parce qu'on ne l'atteindra pas.
     *
     * **Vendre un produit périmé engage la responsabilité du commerçant**, et
     * aucun écran ne rattrape cela après coup. La marchandise périmée quitte le
     * stock par le rebut, qui la trace comme une perte.
     */
    private static function lotPerimeEnTete(Produit $produit, int $pointDeVenteId): ?\App\Modules\Admin\Modeles\Lot
    {
        if (!$produit->suivi_par_lot) {
            return null;
        }

        $premier = \App\Modules\Admin\Modeles\Lot::where('produit_id', $produit->id)
            ->where('point_de_vente_id', $pointDeVenteId)
            ->nonVides()
            ->fefo()
            ->first();

        return $premier && $premier->estPerime() ? $premier : null;
    }

    /**
     * Le terme de l'offre, à l'enregistrement.
     *
     * Une facture n'a pas de terme : elle engage dès son émission et
     * n'expire pas. Un devis sans terme, lui, engage indéfiniment celui qui
     * l'a fait — il reste présentable des mois plus tard, aux prix du jour où
     * il a été établi, et le client qui l'accepte a raison de le faire.
     */
    private static function termeDeLOffre(Request $request, string $etape): ?string
    {
        if (!in_array($etape, Vente::ETAPES_OFFRE, true)) {
            return null;
        }

        return $request->filled('date_validite')
            ? $request->date('date_validite')->toDateString()
            : now()->addDays(Vente::VALIDITE_PAR_DEFAUT)->toDateString();
    }

    /**
     * Ce qui fige une offre, dit à l'utilisateur.
     */
    private static function pourquoiFige(Vente $offre): string
    {
        if ($offre->estConverti()) {
            return 'Ce document a été converti en ' . ($offre->convertiEn?->numero_facture ?? 'une autre pièce')
                . ' : il ne se modifie plus. Modifiez la pièce qui en est issue.';
        }

        return 'Ce document a été accepté le ' . $offre->date_acceptation->format('d/m/Y')
            . ' : le modifier ferait dire à un accord ce qu\'il ne disait pas. '
            . 'Établissez un nouveau devis.';
    }

    /**
     * Ce qui interdit de convertir une offre, ou `null` si rien ne s'y oppose.
     *
     * Deux motifs, et le premier a produit de vraies doubles livraisons :
     * `archived` disait qu'une conversion avait eu lieu sans dire en quoi, et
     * rien n'empêchait la seconde. Le second est ce que le terme veut dire :
     * une offre dépassée ne lie plus personne, et la reprendre telle quelle
     * facturerait aux prix d'un mois révolu.
     */
    private static function obstacleALaConversion(Vente $offre): ?string
    {
        if ($offre->estConverti()) {
            $nee = $offre->convertiEn?->numero_facture;

            return 'Ce document a déjà été converti'
                . ($nee ? " en {$nee}" : '')
                . '. Le convertir une seconde fois créerait une commande en double.';
        }

        if ($offre->estExpire()) {
            return 'Ce devis a expiré le ' . $offre->date_validite->format('d/m/Y')
                . ' : ses prix ne vous engagent plus. Prolongez sa validité ou '
                . 'établissez-en un nouveau avant de le convertir.';
        }

        return null;
    }

    /**
     * Enregistrer l'acceptation du client.
     *
     * C'est ce qu'on oppose en cas de contestation : la date, et le nom de qui
     * a accepté du côté du client. Sans cela, un devis « accepté » ne repose
     * que sur la mémoire de deux personnes.
     */
    public function accepterOffre(Request $request, Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        abort_unless($vente->estUneOffre(), 403);

        $request->validate([
            'accepte_par' => ['nullable', 'string', 'max:190'],
            'date_acceptation' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'date_acceptation.before_or_equal' => 'Une acceptation ne se date pas dans le futur.',
        ]);

        if ($vente->estAccepte()) {
            return back()->with('info', 'Ce document est déjà accepté.');
        }

        // Une offre expirée ne lie plus personne : l'accepter après coup
        // ferait croire à un engagement qui n'existe pas.
        if ($vente->estExpire()) {
            return back()->with(
                'erreur',
                'Ce devis a expiré le ' . $vente->date_validite->format('d/m/Y')
                . ' : il ne peut plus être accepté en l\'état.'
            );
        }

        $vente->update([
            'date_acceptation' => $request->filled('date_acceptation')
                ? $request->date('date_acceptation')->toDateString()
                : now()->toDateString(),
            'accepte_par' => $request->input('accepte_par') ?: $vente->client?->nom,
            'statut' => 'Accepté',
        ]);

        return back()->with('succes', 'Acceptation enregistrée. Le document est désormais figé.');
    }

    /**
     * Repousser le terme d'une offre qui n'a pas encore produit son effet.
     *
     * Prolonger, c'est refaire l'offre : c'est un geste explicite, tracé, et
     * qui reste impossible sur un document déjà converti.
     */
    public function prolongerOffre(Request $request, Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        abort_unless($vente->estUneOffre(), 403);

        $request->validate([
            'date_validite' => ['required', 'date', 'after_or_equal:today'],
        ], [
            'date_validite.after_or_equal' => 'Prolonger une offre, c\'est lui donner un terme à venir.',
        ]);

        if ($vente->estConverti()) {
            return back()->with(
                'erreur',
                'Ce document a déjà été converti : son terme n\'a plus d\'objet.'
            );
        }

        $vente->update(['date_validite' => $request->date('date_validite')->toDateString()]);

        return back()->with(
            'succes',
            'Validité prolongée jusqu\'au ' . $vente->fresh()->date_validite->format('d/m/Y') . '.'
        );
    }

    public function confirmerCommande(Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        if ($vente->etape !== 'Devis') {
            return back()->with('info', 'Le document n\'est pas à l\'étape Devis.');
        }

        if ($obstacle = self::obstacleALaConversion($vente)) {
            return back()->with('erreur', $obstacle);
        }

        $vente->update(['etape' => 'Bon de commande']);

        return back()->with('succes', 'Commande client confirmée avec succès.');
    }

    public function facturer(Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        if ($vente->etape === 'Facture') {
            return back()->with('info', 'Cette facture est déjà validée.');
        }

        DB::transaction(function () use ($vente) {
            // Vérification de disponibilité AVANT toute décrémentation.
            foreach ($vente->details as $detail) {
                if ($detail->produit && $detail->produit->estStockable()) {
                    $dispo = $detail->produit->stockActuel($vente->point_de_vente_id);
                    if ($dispo < $detail->quantite) {
                        throw new \InvalidArgumentException(
                            "Stock insuffisant pour « {$detail->produit->nom} » (Disponible: {$dispo}, Demandé: {$detail->quantite})."
                        );
                    }
                }
            }

            $vente->update(['etape' => 'Facture']);

            // 1. Décrémenter le stock uniquement pour les articles stockables
            foreach ($vente->details as $detail) {
                // Verrou de ligne pour éviter toute décrémentation concurrente incohérente
                $produit = $detail->produit ? \App\Modules\Admin\Modeles\Produit::lockForUpdate()->find($detail->produit->id) : null;
                if ($produit && $produit->estStockable()) {
                    // Évite le double décrément via la file "Livraisons à valider"
                    // (StockControleur::livraisons()) — voir explication dans store().
                    $detail->update(['quantite_livree' => $detail->quantite]);

                    StockService::sortie(
                        $produit,
                        (int) $vente->point_de_vente_id,
                        (float) $detail->quantite,
                        MouvementStock::LIVRAISON,
                        [
                            'piece' => $vente,
                            'reference' => $vente->numero_facture,
                            'client_id' => $vente->client_id
                        ]
                    );
                }
            }

            // 2. Normalisation FNE en arrière-plan (Section 18.5)
            $estRne = empty($vente->client_id) || ($vente->client_id == 'divers');
            NormaliserFactureFne::dispatch($vente, $estRne);

            // 3. Trésorerie : enregistrer le solde encaissé aujourd'hui, si la facture
            //    est totalement soldée (un éventuel acompte a pu être versé plus tôt,
            //    au stade Devis — voir le bloc "Étape Devis" dans store()).
            $dejaPayeAvant = TresorerieJournal::where('reference_document', $vente->numero_facture)->sum('montant_entree');
            $resteAPayer = $vente->statut === 'Payé' ? max(0, $vente->montant_ttc - $dejaPayeAvant) : 0;

            if ($resteAPayer > 0) {
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $vente->point_de_vente_id)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id' => $vente->point_de_vente_id,
                    'date_operation' => now()->toDateString(),
                    'type_operation' => 'Encaissement',
                    'libelle' => \App\Modules\Admin\Services\ComptabiliteService::libelleTresorerieVente($vente),
                    'mode_paiement' => $vente->mode_paiement,
                    'moyen_bancaire' => $vente->moyen_bancaire,
                    'reference_paiement' => $vente->reference_paiement,
                    'montant_entree' => $resteAPayer,
                    'montant_sortie' => 0,
                    'solde_resultat' => $soldeActuel + $resteAPayer,
                    'reference_document' => $vente->numero_facture,
                ]);
            }

            // 4. Écritures comptables de facturation, en une seule opération cohérente :
            //    - le montant total déjà encaissé à ce jour (acompte devis + solde ci-dessus)
            //      est transmis à genererEcrituresVente(), qui décide seule s'il s'agit
            //      d'une facture intégralement soldée (aucune ligne 411) ou d'une facture
            //      à crédit total/partiel (411 pour la part réellement non couverte).
            $montantPayeTotal = $dejaPayeAvant + $resteAPayer;

            \App\Modules\Admin\Services\ComptabiliteService::genererEcrituresVente(
                $vente,
                $montantPayeTotal,
                $vente->mode_paiement,
                now()->toDateString(),
                $vente->moyen_bancaire,
                $vente->reference_paiement
            );
        });

        return back()->with('succes', 'Facture validée, stock mis à jour et écritures comptables générées.');
    }

    /**
     * Générer un avoir sur une facture de vente
     */
    public function creerAvoir(Request $request, Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        abort_if($vente->type_facture === 'avoir', 400, "Impossible de générer un avoir sur une facture d'avoir.");

        $request->validate([
            'raison' => ['required', 'string', 'max:255'],
        ]);

        $avoirId = null;

        DB::transaction(function () use ($vente, $request, &$avoirId) {
            $numAvoir = \App\Modules\Admin\Services\NumerotationService::genererNumeroVente(
                $vente->pointDeVente->entreprise_id,
                'Facture',
                'avoir'
            );

            // 1. Création de la facture d'avoir
            $avoir = Vente::create([
                'point_de_vente_id' => $vente->point_de_vente_id,
                'client_id' => $vente->client_id,
                'utilisateur_id' => Auth::id(),
                'numero_facture' => $numAvoir,
                'date_vente' => now()->toDateString(),
                'mode_paiement' => $vente->mode_paiement,
                'moyen_bancaire' => $vente->moyen_bancaire,
                'reference_paiement' => $request->raison, // Raison de l'avoir
                'montant_ht' => $vente->montant_ht,
                'montant_tva' => $vente->montant_tva,
                'remise' => $vente->remise,
                'remise_taux' => $vente->remise_taux,
                'montant_ttc' => $vente->montant_ttc,
                'statut' => 'Payé',
                'type_facture' => 'avoir',
                'etape' => 'Facture',
                'parent_id' => $vente->id,
                'raison_avoir' => $request->raison,
                'autres_mentions' => $vente->autres_mentions,
                'pied_de_page' => $vente->pied_de_page,
            ]);

            // 2. Copie des détails et retour en stock
            foreach ($vente->details as $detail) {
                VenteDetail::create([
                    'vente_id' => $avoir->id,
                    'produit_id' => $detail->produit_id,
                    'libelle_virtuel' => $detail->libelle_virtuel,
                    'quantite' => $detail->quantite,
                    'unite' => $detail->unite,
                    'prix_unitaire' => $detail->prix_unitaire,
                    'remise_taux' => $detail->remise_taux,
                    'montant_tva' => $detail->montant_tva,
                    'montant_ttc' => $detail->montant_ttc,
                    'fne_invoice_item_id' => $detail->fne_invoice_item_id,
                ]);

                // Retour de la marchandise en stock vendable.
                //
                // Un avoir total annule la vente entiere : la marchandise
                // revient telle quelle. L'avoir partiel, lui, laisse le choix
                // ligne par ligne — voir `retournerLaMarchandise()`.
                if ($detail->produit && $detail->produit->estStockable()) {
                    StockService::entree(
                        $detail->produit,
                        (int) $vente->point_de_vente_id,
                        (float) $detail->quantite,
                        MouvementStock::RETOUR_CLIENT,
                        ['piece' => $avoir, 'reference' => $numAvoir, 'client_id' => $vente->client_id]
                    );
                }
            }

            // 3. Écritures comptables
            \App\Modules\Admin\Services\ComptabiliteService::genererEcritureAvoirVente($avoir);

            // 4. Si la facture d'origine était déjà payée en espèces, on simule la sortie de caisse du remboursement
            if (str_contains(strtolower($vente->mode_paiement), 'espèces') || str_contains(strtolower($vente->mode_paiement), 'caisse')) {
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $vente->point_de_vente_id)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id' => $vente->point_de_vente_id,
                    'date_operation' => now()->toDateString(),
                    'type_operation' => 'Décaissement', // Remboursement client
                    'libelle' => 'Remboursement Avoir client ' . $numAvoir,
                    'mode_paiement' => $vente->mode_paiement,
                    'montant_entree' => 0,
                    'montant_sortie' => $vente->montant_ttc,
                    'solde_resultat' => $soldeActuel - $vente->montant_ttc,
                    'reference_document' => $numAvoir,
                ]);
            }

            $avoirId = $avoir->id;
        });

        $this->journaliser('creation_avoir_vente', 'Vente', $avoirId);

        return redirect()->route(request()->routeIs('caissier.*') ? 'caissier.ventes.imprimer' : 'admin.ventes.imprimer', $avoirId)
            ->with('succes', "Facture d'avoir générée avec succès ! Les stocks et écritures comptables inverses ont été validés.");
    }

    /**
     * Lot H : Normalisation manuelle DGI/FNE.
     * Dispatch le job de normalisation pour une facture de vente non encore normalisée.
     */
    public function normaliser(Vente $vente): RedirectResponse
    {

        abort_unless(
            $vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id,
            404
        );

        if ($vente->normalise) {
            return back()->with('info', 'Cette facture est déjà normalisée.');
        }

        if ($vente->etape !== 'Facture') {
            return back()->with('erreur', 'Seules les factures finalisées peuvent être normalisées.');
        }

        NormaliserFactureFne::dispatchSync($vente);

        $this->journaliser('normalisation_manuelle_vente', 'Vente', $vente->id);

        if ($vente->fresh()->normalise) {
            return back()->with('succes', 'La normalisation DGI a été effectuée avec succès. Le document est maintenant normalisé.');
        }

        // Non normalisée : le job a consigné un rejet. On le relit pour dire à
        // l'utilisateur ce qui s'est passé, et — si la DGI a examiné puis
        // refusé la pièce — on lance la relève du portail sans attendre le
        // passage horaire, pour aller chercher le nom réellement déclaré.
        $rejet = FneRejet::where('piece_type', 'vente')
            ->where('piece_id', $vente->id)
            ->where('statut', FneRejet::STATUT_OUVERT)
            ->latest('id')
            ->first();

        if ($rejet && $rejet->cause === FneRejet::CAUSE_DGI) {
            ScraperPortailFneService::relancerApresRejet($rejet->login);

            // Importer les relevés disponibles et créer automatiquement tout point de vente FNE manquant dans Selflow
            \Illuminate\Support\Facades\Artisan::call('portail-fne:importer');
            $entreprise = $rejet->entreprise ?? $vente->pointDeVente?->entreprise ?? Auth::user()->entreprise;
            if ($entreprise) {
                app(\App\Modules\Admin\Services\PointsDeVentePortailService::class)->importer($entreprise);
            }

            // Diagnostiquer immédiatement le rejet et sauvegarder en base
            $correcteur = app(\App\Modules\Admin\Services\CorrectionFneService::class);
            $diagnostic = app(\App\Modules\Admin\Services\DiagnosticFneService::class)->diagnostiquer($rejet);
            $rejet->update([
                'diagnostic' => $diagnostic,
                'statut'     => $rejet->statut === FneRejet::STATUT_RESOLU
                    ? FneRejet::STATUT_RESOLU
                    : FneRejet::STATUT_DIAGNOSTIQUE,
            ]);
            $rejet->refresh();

            // S'il n'y a qu'un seul nom au portail : corriger directement sans étape humaine
            $correctionDirecte = $correcteur->correctionApplicable($rejet);
            if ($correctionDirecte !== null) {
                $fait = $correcteur->corriger($rejet, synchrone: true);
                if ($fait !== null) {
                    $msg = ($fait['mode'] ?? 'bascule') === 'cree'
                        ? sprintf('Le point de vente « %s » a été créé automatiquement dans Selflow d\'après la DGI, et la facture a été normalisée.', $fait['nouveau'])
                        : sprintf('La facture a été rattachée au point de vente « %s » et normalisée avec succès.', $fait['nouveau']);
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
                    ->with('avertissement', 'La DGI a refusé la facture : le point de vente ne correspond pas. Sélectionnez et activez le point de vente correspondant sur votre espace FNE :')
                    ->with('avertissement_action', $boutons);
            }

            $champs = $rejet->nomsDesChamps();
            $precision = $champs ? ' (' . implode(', ', $champs) . ')' : '';

            return back()
                ->with('avertissement', sprintf(
                    "La DGI a refusé la facture%s : le point de vente n'est pas déclaré à "
                    . "l'identique sur votre espace FNE. Récupération des points de vente sur le portail en cours...",
                    $precision
                ))
                ->with('rejet_en_cours_id', $rejet->id)
                ->with('avertissement_action', [
                    [
                        'url' => route('admin.fne.rejets'),
                        'label' => 'Voir les rejets FNE',
                    ],
                ]);
        }

        if ($rejet && $rejet->estReseau()) {
            // Une coupure n'est pas un refus : la DGI n'a rien vu de la pièce.
            // Et rien ne la reprendra tout seul — aucune tâche planifiée ne
            // rejoue les rejets « réseau », et le job lancé par ce bouton n'a
            // pas de file derrière lui pour le rejouer. Le message promettait
            // une reprise automatique qui n'existe pas : on rend la main, avec
            // le bouton qui permet de la reprendre.
            return back()
                ->with(
                    'erreur',
                    "La plateforme FNE est injoignable pour le moment : la facture n'a pas été "
                    . "envoyée, et la DGI n'a rien refusé. Réessayez dans un instant — le rejet "
                    . 'se refermera de lui-même dès que la pièce passera.'
                )
                ->with('erreur_action', [
                    [
                        'url' => request()->routeIs('caissier.*')
                            ? route('caissier.ventes.normaliser', $vente)
                            : route('admin.ventes.normaliser', $vente),
                        'label' => 'Réessayer',
                        'method' => 'post',
                    ]
                ]);
        }

        return back()->with(
            'erreur',
            "La normalisation n'a pas abouti. Consultez l'écran des rejets FNE pour le détail."
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // WORKFLOW DEVIS → COMMANDE → FACTURE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Passer un devis/commande au statut Envoyé.
     */
    public function envoyer(Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        abort_unless(in_array($vente->etape, ['Devis', 'Bon de commande']), 403);

        $vente->update(['statut' => 'Envoyé']);

        return back()->with('succes', ucfirst(strtolower($vente->etape)) . ' marqué comme envoyé.');
    }

    /**
     * Convertir un devis en bon de commande.
     * L'original est archivé, un clone est créé à l'étape Bon de commande.
     */
    public function convertirEnCommande(Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        if ($vente->etape !== 'Devis') {
            return back()->with('erreur', 'Ce document n\'est pas un devis.');
        }

        if ($obstacle = self::obstacleALaConversion($vente)) {
            return back()->with('erreur', $obstacle);
        }

        DB::transaction(function () use ($vente) {
            // 1. Cloner en Bon de commande
            $entrepriseId = $vente->pointDeVente->entreprise_id;
            $nouveauNumero = \App\Modules\Admin\Services\NumerotationService::genererNumeroVente($entrepriseId, 'Bon de commande');

            $clone = $vente->replicate(['archived', 'normalise', 'numero_fne', 'signature_dgi', 'qr_code_data']);
            $clone->numero_facture = $nouveauNumero;
            $clone->etape = 'Bon de commande';
            $clone->statut = 'Brouillon';
            $clone->archived = false;
            $clone->normalise = false;
            $clone->numero_fne = null;
            $clone->signature_dgi = null;
            $clone->qr_code_data = null;

            // **La pièce naît aujourd'hui.** `replicate()` recopiait la date du
            // devis : une commande de juin issue d'un devis de janvier était
            // datée de janvier, se rangeait dans la période de janvier, et la
            // facture qui en découlait entrait dans la déclaration du mauvais
            // mois. L'acceptation du client, elle, appartient au devis : elle
            // ne se recopie pas non plus.
            $clone->date_vente = now()->toDateString();
            $clone->date_validite = now()->addDays(Vente::VALIDITE_PAR_DEFAUT)->toDateString();
            $clone->date_acceptation = null;
            $clone->accepte_par = null;
            $clone->converti_en_id = null;
            $clone->save();

            // 2. Cloner les lignes de détail
            foreach ($vente->details as $detail) {
                $newDetail = $detail->replicate();
                $newDetail->vente_id = $clone->id;
                $newDetail->save();
            }

            // 3. Le devis d'origine dit ce qu'il est devenu. `archived` seul ne
            //    le disait pas, et n'empêchait pas une seconde conversion : le
            //    même devis produisait deux commandes, donc deux livraisons.
            $vente->update(['archived' => true, 'converti_en_id' => $clone->id]);
        });

        return back()->with('succes', 'Le devis a été converti en bon de commande et archivé.');
    }

    /**
     * Convertir un bon de commande en facture à finaliser.
     * L'original est archivé, un clone Facture est créé et l'utilisateur est
     * redirigé vers sa fiche de modification pour saisir le paiement.
     */
    public function convertirEnFacture(Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        if ($vente->etape !== 'Bon de commande') {
            return back()->with('erreur', 'Ce document n\'est pas un bon de commande.');
        }

        if ($obstacle = self::obstacleALaConversion($vente)) {
            return back()->with('erreur', $obstacle);
        }

        $nouvelleFactureId = null;

        DB::transaction(function () use ($vente, &$nouvelleFactureId) {
            // 1. Cloner en Facture (statut Crédit par défaut, en attente de finalisation)
            $entrepriseId = $vente->pointDeVente->entreprise_id;
            $nouveauNumero = \App\Modules\Admin\Services\NumerotationService::genererNumeroVente($entrepriseId, 'Facture');

            $clone = $vente->replicate(['archived', 'normalise', 'numero_fne', 'signature_dgi', 'qr_code_data']);
            $clone->numero_facture = $nouveauNumero;
            $clone->etape = 'Facture';
            $clone->statut = 'Crédit';
            $clone->archived = false;
            $clone->normalise = false;
            $clone->numero_fne = null;
            $clone->signature_dgi = null;
            $clone->qr_code_data = null;

            // **La facture naît aujourd'hui.** `replicate()` recopiait la date
            // du bon de commande : une facture de juin issue d'une commande de
            // janvier était datée de janvier et entrait dans la déclaration de
            // TVA du mauvais mois. Une facture n'a pas de terme — elle engage
            // dès son émission —, et l'acceptation appartient à l'offre.
            $clone->date_vente = now()->toDateString();
            $clone->date_validite = null;
            $clone->date_acceptation = null;
            $clone->accepte_par = null;
            $clone->converti_en_id = null;
            $clone->save();

            // 2. Cloner les lignes
            foreach ($vente->details as $detail) {
                $newDetail = $detail->replicate();
                $newDetail->vente_id = $clone->id;
                $newDetail->save();
            }

            // 3. La commande dit ce qu'elle est devenue, et ne se convertit
            //    plus : deux factures pour une commande, c'est un client
            //    facturé deux fois.
            $vente->update(['archived' => true, 'converti_en_id' => $clone->id]);

            $nouvelleFactureId = $clone->id;
        });

        // Rediriger vers la modification pour finaliser le paiement
        $route = request()->routeIs('caissier.*') ? 'caissier.ventes.modifier' : 'admin.ventes.modifier';
        return redirect()->route($route, $nouvelleFactureId)
            ->with('succes', 'Bon de commande converti en facture. Veuillez renseigner le mode de paiement et valider.');
    }

    /**
     * Établir la facture correspondant à un reçu déjà émis.
     *
     * C'est le seul sens prévu par la DGI : les champs `isRne` et `rne` de
     * l'API désignent « le numéro du reçu pour lequel la facture est émise ».
     * Rien ne permet en revanche de rattacher un reçu à une facture antérieure,
     * aussi ce sens n'est-il pas proposé.
     *
     * Le reçu d'origine est conservé tel quel — il a pu être remis au client :
     * on ne le réécrit pas. La facture reprend ses informations, les deux
     * pièces se pointent mutuellement, et la facture naît non normalisée
     * puisqu'elle doit obtenir sa propre certification.
     */
    public function convertirPiece(Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);

        if ($vente->type_facture === 'avoir') {
            return back()->with('erreur', 'Un avoir ne peut pas être converti.');
        }

        if ($vente->etape !== 'Facture') {
            return back()->with('erreur', 'Seule une pièce validée peut être convertie.');
        }

        if (!$vente->estRecu()) {
            return back()->with('erreur', "Seul un reçu peut donner lieu à une facture. La DGI ne prévoit pas l'opération inverse.");
        }

        if ($vente->pieceLiee) {
            return back()->with('erreur', 'Ce reçu a déjà donné lieu à la facture ' . $vente->pieceLiee->numero_facture . '.');
        }

        $versRecu = false;
        $nouvelleId = null;

        DB::transaction(function () use ($vente, $versRecu, &$nouvelleId) {
            $entrepriseId = $vente->pointDeVente->entreprise_id;

            $clone = $vente->replicate([
                'archived',
                'normalise',
                'numero_fne',
                'signature_dgi',
                'qr_code_data',
                'fichier_fne_pdf_url',
                'fne_invoice_id',
            ]);
            $clone->numero_facture = \App\Modules\Admin\Services\NumerotationService::genererNumeroVente($entrepriseId, 'Facture');
            $clone->type_piece = Vente::TYPE_FACTURE;
            $clone->type_facture = 'proformat';
            $clone->piece_liee_id = $vente->id;
            $clone->archived = false;
            $clone->normalise = false;
            $clone->numero_fne = null;
            $clone->signature_dgi = null;
            $clone->qr_code_data = null;
            $clone->fichier_fne_pdf_url = null;
            $clone->fne_invoice_id = null;
            $clone->save();

            // Les lignes sont recopiées sans les identifiants FNE de la pièce
            // d'origine : ils appartiennent à sa certification, pas à celle-ci.
            foreach ($vente->details as $detail) {
                $nouveauDetail = $detail->replicate(['fne_invoice_item_id']);
                $nouveauDetail->vente_id = $clone->id;
                $nouveauDetail->fne_invoice_item_id = null;
                $nouveauDetail->save();

                foreach ($detail->taxes as $taxe) {
                    $nouveauDetail->taxes()->create(['nom' => $taxe->nom, 'taux' => $taxe->taux]);
                }
            }

            foreach ($vente->taxesPersonnalisees as $taxe) {
                $clone->taxesPersonnalisees()->create([
                    'nom' => $taxe->nom,
                    'taux' => $taxe->taux,
                    'montant' => $taxe->montant,
                ]);
            }

            // Lien symétrique : chaque pièce désigne sa contrepartie
            $vente->update(['piece_liee_id' => $clone->id]);

            $nouvelleId = $clone->id;
        });

        $route = request()->routeIs('caissier.*') ? 'caissier.ventes.imprimer' : 'admin.ventes.imprimer';

        return redirect()->route($route, $nouvelleId)
            ->with('succes', "La facture du reçu « {$vente->numero_facture} » a été établie. Elle reste à normaliser auprès de la DGI.");
    }

    /**
     * Supprimer définitivement une vente archivée.
     */
    public function supprimer(Vente $vente): RedirectResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        abort_if($vente->type_facture === 'avoir', 403, "Impossible de supprimer une facture d'avoir.");
        abort_unless($vente->archived, 403, 'Seuls les documents archivés peuvent être supprimés.');

        // Une offre convertie est le premier maillon d'une chaîne : la
        // supprimer laisse la commande, puis la facture, sans l'accord qui les
        // fonde. C'est précisément ce qu'on oppose en cas de contestation.
        abort_if(
            $vente->estConverti(),
            403,
            'Ce document a été converti en ' . ($vente->convertiEn?->numero_facture ?? 'une autre pièce')
            . " : il fonde la pièce qui en découle et ne peut pas être supprimé."
        );

        $etape = $vente->etape;
        $vente->details()->delete();
        $vente->delete();

        $routeParam = match ($etape) {
            'Devis' => '?etape=Devis&archives=1',
            'Bon de commande' => '?etape=Bon+de+commande&archives=1',
            default => '?archives=1',
        };

        $baseRoute = request()->routeIs('caissier.*') ? 'caissier.ventes.factures' : 'admin.ventes.factures';
        return redirect()->route($baseRoute)->withInput(['etape' => $etape, 'archives' => '1'])
            ->with('succes', 'Document supprimé définitivement.');
    }

    public function rechercherFacturesPourAvoir(Request $request): \Illuminate\Http\JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $q = $request->query('q');

        $query = Vente::with('client')
            ->whereHas('pointDeVente', fn($queryPdv) => $queryPdv->where('entreprise_id', $entreprise->id))
            ->where('etape', 'Facture')
            ->where(function ($queryNum) {
                $queryNum->where('numero_facture', 'LIKE', 'VT-%')
                    ->orWhere('numero_facture', 'LIKE', 'VTE-%');
            })
            ->where(function ($queryType) {
                $queryType->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir');
            })
            ->where('archived', false);

        if ($q) {
            $query->where(function ($querySearch) use ($q) {
                $querySearch->where('numero_facture', 'like', "%{$q}%")
                    ->orWhere('numero_fne', 'like', "%{$q}%")
                    ->orWhereHas('client', fn($queryClient) => $queryClient->where('nom', 'like', "%{$q}%"));
            });
        }

        $factures = $query->latest()->limit(10)->get()->map(function ($f) {
            $clientNom = $f->client ? $f->client->nom : 'Client de passage';
            return [
                // L'identifiant public, celui que les adresses portent depuis
                // que les URL ne montrent plus les identifiants de base. Le
                // numérique était renvoyé ici, et l'écran le remettait ensuite
                // dans une adresse qui attend un uuid : la requête tombait sur
                // un 404 (Not Found — introuvable), et le script, qui lisait la
                // réponse en JSON, recevait la page d'erreur en HTML.
                'id' => $f->uuid,
                'text' => "{$f->numero_facture} - {$clientNom} (" . number_format($f->montant_ttc, 0, ',', ' ') . " XOF)"
            ];
        });

        return response()->json($factures);
    }

    public function detailsFacturePourAvoir(Vente $vente): \Illuminate\Http\JsonResponse
    {
        abort_unless($vente->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        $vente->load(['details.produit', 'client']);

        // Une facture peut recevoir plusieurs avoirs successifs : la modale
        // doit proposer ce qui reste à créditer, pas la quantité vendue.
        $dejaCredite = self::quantitesDejaCreditees($vente);

        return response()->json([
            // L'identifiant public : c'est lui que le formulaire d'avoir
            // renvoie ensuite comme pièce d'origine. Rendre le numérique le
            // publiait dans une page, et le formulaire le refusait.
            'id' => $vente->uuid,
            'numero_facture' => $vente->numero_facture,
            'client_nom' => $vente->client ? $vente->client->nom : 'Client de passage',
            'montant_ttc' => $vente->montant_ttc,
            'autres_mentions' => $vente->autres_mentions,
            'pied_de_page' => $vente->pied_de_page,
            'details' => $vente->details->map(function ($d) use ($dejaCredite) {
                $credite = (float) ($dejaCredite[$d->id] ?? 0);
                $restante = max(0, (float) $d->quantite - $credite);

                return [
                    'id' => $d->id,
                    'produit_id' => $d->produit_id,
                    'libelle' => $d->produit ? $d->produit->nom : $d->libelle_virtuel,
                    'quantite' => $d->quantite,
                    'quantite_creditee' => $credite,
                    'quantite_restante' => $restante,
                    'prix_unitaire' => $d->prix_unitaire,
                    'remise_taux' => (float) ($d->remise_taux ?? 0),
                    'taux_tva' => self::tauxTvaDeLaLigne($d),
                    'montant_tva' => $d->montant_tva,
                    'montant_ttc' => $d->montant_ttc,
                    'unite' => $d->unite ?? 'pcs',
                    'est_stockable' => $d->produit ? $d->produit->estStockable() : false,
                ];
            })
        ]);
    }

    /**
     * Le sort de la marchandise rendue, choisi ligne par ligne sur l'avoir.
     *
     * Trois cas, et trois seulement :
     *
     * - **`reinject`** — elle revient vendable. Une entrée, motif « retour
     *   client ».
     * - **`scrap`** — elle revient inutilisable. Deux écritures : elle est
     *   bien rentrée, puis elle est sortie au rebut. Le stock ne bouge pas au
     *   total, mais l'histoire est vraie, et le rebut apparaît là où on le
     *   cherche — c'est aussi ce qui permettra de l'imputer en perte.
     * - **`none`** — elle n'est pas revenue. Geste commercial, marchandise
     *   restée chez le client : rien à écrire.
     *
     * Le choix était auparavant consigné dans une clé `notes` que
     * `mouvements_stock` n'a pas : Eloquent la laissait tomber sans rien dire.
     * Et le rebut écrivait une entrée fantôme — quantité N, `stock_avant` 0,
     * `stock_apres` 0 — qui ne changeait rien mais se lisait comme une entrée.
     * Un retour défectueux et un retour vendable étaient donc indiscernables à
     * l'écran, et le rebut invisible.
     */
    private static function retournerLaMarchandise(
        ?Produit $produit,
        Vente $venteOrigine,
        Vente $avoir,
        string $numAvoir,
        float $quantite,
        string $sort
    ): void {
        if (!$produit || !$produit->estStockable() || $quantite <= 0 || $sort === 'none') {
            return;
        }

        $contexte = [
            'piece' => $avoir,
            'reference' => $numAvoir,
            'client_id' => $venteOrigine->client_id,
        ];

        $pointDeVenteId = (int) $venteOrigine->point_de_vente_id;

        StockService::entree($produit, $pointDeVenteId, $quantite, MouvementStock::RETOUR_CLIENT, $contexte);

        if ($sort === 'scrap') {
            StockService::sortie($produit, $pointDeVenteId, $quantite, MouvementStock::REBUT, $contexte);
        }
    }

    /**
     * Taux de TVA réellement appliqué à une ligne, reconstitué depuis les
     * montants enregistrés.
     *
     * `vente_details` ne stocke ni le taux ni le HT : seuls la quantité, le
     * prix unitaire, la remise et la TVA en valeur sont conservés. Le taux se
     * retrouve donc en divisant la TVA par le HT net de remise.
     */
    private static function tauxTvaDeLaLigne(VenteDetail $detail): float
    {
        $remise = (float) ($detail->remise_taux ?? 0);
        $htNet = (float) $detail->quantite * (float) $detail->prix_unitaire * (1 - $remise / 100);

        if ($htNet <= 0) {
            return 0.0;
        }

        return round((float) $detail->montant_tva / $htNet * 100, 2);
    }

    /**
     * Quantités déjà créditées par les avoirs existants d'une facture, par
     * ligne d'origine.
     *
     * Une facture peut recevoir plusieurs avoirs — un retour partiel
     * aujourd'hui, un autre demain. Mais la somme des quantités créditées ne
     * peut pas dépasser ce qui a été vendu, sans quoi on rembourserait plus
     * que la facture ne portait.
     *
     * @return array<int,float> quantité déjà créditée, indexée par id de ligne d'origine
     */
    private static function quantitesDejaCreditees(Vente $facture): array
    {
        $lignesOrigine = $facture->details()->get(['id', 'produit_id', 'libelle_virtuel']);

        $avoirs = Vente::where('parent_id', $facture->id)
            ->where('type_facture', 'avoir')
            ->with('details')
            ->get();

        $deja = [];
        foreach ($lignesOrigine as $ligne) {
            $deja[$ligne->id] = 0.0;
        }

        foreach ($avoirs as $avoir) {
            foreach ($avoir->details as $ligneAvoir) {
                // Le rattachement se fait sur le produit, ou sur le libellé
                // pour une ligne de saisie libre. Une ligne ajoutée à l'avoir
                // sans correspondance dans la facture n'entre pas au compteur.
                $origine = $lignesOrigine->first(function ($o) use ($ligneAvoir) {
                    return $ligneAvoir->produit_id
                        ? $o->produit_id === $ligneAvoir->produit_id
                        : $o->libelle_virtuel === $ligneAvoir->libelle_virtuel;
                });

                if ($origine) {
                    $deja[$origine->id] += (float) $ligneAvoir->quantite;
                }
            }
        }

        return $deja;
    }

    public function creerAvoirNouveau(Request $request): RedirectResponse
    {
        $request->validate([
            // La pièce d'origine est désignée par son identifiant public, celui
            // que l'écran a reçu et que les adresses portent. Le numérique était
            // attendu ici : il n'est plus publié nulle part.
            'parent_id' => ['required', 'uuid', Appartenance::a('ventes', 'uuid')],
            'raison' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array'],
        ]);

        $parent = Vente::where('uuid', $request->parent_id)->firstOrFail();
        abort_unless($parent->pointDeVente->entreprise_id === Auth::user()->entreprise_id, 404);
        abort_if($parent->type_facture === 'avoir', 400, "Impossible de générer un avoir sur une facture d'avoir.");

        // Plusieurs avoirs peuvent se succéder sur une même facture, mais leur
        // cumul ne peut pas dépasser les quantités vendues : au-delà, on
        // recréditerait au client davantage que ce qu'il a payé.
        $dejaCredite = self::quantitesDejaCreditees($parent);
        $depassements = [];

        foreach ($request->items as $itemId => $itemData) {
            if (!empty($itemData['est_nouveau'])) {
                continue; // ligne ajoutée à l'avoir, sans origine à plafonner
            }

            $ligne = $parent->details->firstWhere('id', (int) $itemId);
            if (!$ligne) {
                continue;
            }

            $restante = (float) $ligne->quantite - (float) ($dejaCredite[$ligne->id] ?? 0);
            $demandee = floatval($itemData['quantite'] ?? 0);

            if ($demandee > $restante + 0.001) {
                $depassements[] = sprintf(
                    '%s : %s demandé(s), %s restant(s) à créditer',
                    $ligne->produit?->nom ?? $ligne->libelle_virtuel ?? 'Article',
                    rtrim(rtrim(number_format($demandee, 2, ',', ' '), '0'), ','),
                    rtrim(rtrim(number_format(max(0, $restante), 2, ',', ' '), '0'), ',')
                );
            }
        }

        if (!empty($depassements)) {
            return back()->withErrors([
                'items' => 'Cette facture a déjà fait l\'objet d\'un ou plusieurs avoirs. '
                    . 'Les quantités demandées dépassent ce qui reste à créditer — '
                    . implode(' ; ', $depassements) . '.',
            ])->withInput();
        }

        $avoirId = null;

        DB::transaction(function () use ($parent, $request, &$avoirId) {
            $numAvoir = \App\Modules\Admin\Services\NumerotationService::genererNumeroVente(
                $parent->pointDeVente->entreprise_id,
                'Facture',
                'avoir'
            );

            // 1. Création de la facture d'avoir
            $avoir = Vente::create([
                'point_de_vente_id' => $parent->point_de_vente_id,
                'client_id' => $parent->client_id,
                'utilisateur_id' => Auth::id(),
                'numero_facture' => $numAvoir,
                'date_vente' => now()->toDateString(),
                'mode_paiement' => $parent->mode_paiement,
                'moyen_bancaire' => $parent->moyen_bancaire,
                'reference_paiement' => $request->raison,
                'statut' => 'Payé',
                'type_facture' => 'avoir',
                'etape' => 'Facture',
                'parent_id' => $parent->id,
                'raison_avoir' => $request->raison,
                'montant_ht' => 0,
                'montant_tva' => 0,
                'remise' => 0,
                'remise_taux' => 0,
                'montant_ttc' => 0,
            ]);

            $totalHt = 0;
            $totalTva = 0;
            $totalTtc = 0;

            // 2. Traitement des lignes
            foreach ($request->items as $itemId => $itemData) {
                $isNouveau = isset($itemData['est_nouveau']) && $itemData['est_nouveau'] == 1;
                $qteAvoir = floatval($itemData['quantite']);
                $prixUnit = floatval($itemData['prix_unitaire']);

                if ($qteAvoir <= 0)
                    continue;

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

                    VenteDetail::create([
                        'vente_id' => $avoir->id,
                        'produit_id' => $produitId,
                        'libelle_virtuel' => $libelle,
                        'quantite' => $qteAvoir,
                        'unite' => $unite,
                        'prix_unitaire' => $prixUnit,
                        'montant_tva' => $itemTva,
                        'montant_ttc' => $itemTtc,
                    ]);

                    $totalHt += $itemHt;
                    $totalTva += $itemTva;
                    $totalTtc += $itemTtc;

                    self::retournerLaMarchandise(
                        $produit,
                        $parent,
                        $avoir,
                        $numAvoir,
                        (float) $qteAvoir,
                        $itemData['stock_action'] ?? 'none'
                    );
                } else {
                    $detail = VenteDetail::where('vente_id', $parent->id)->where('id', $itemId)->first();
                    if (!$detail)
                        continue;

                    // Un avoir reprend la ligne d'origine telle qu'elle a été
                    // facturée : même taux de TVA, même remise, mêmes taxes.
                    //
                    // Le taux était auparavant calculé ainsi :
                    //     ($detail->montant_ttc - $detail->montant_ht) > 0 ? 0.18 : 0
                    // Or `vente_details` n'a pas de colonne `montant_ht` : la
                    // soustraction portait sur null, la condition était donc
                    // toujours vraie et TOUTES les lignes repartaient à 18 %,
                    // y compris celles exonérées. La remise de ligne, elle,
                    // n'était pas reprise du tout. L'avoir imprimé affichait
                    // ainsi un montant supérieur à celui de la facture qu'il
                    // annulait — et à celui de l'avoir certifié par la DGI.
                    $remiseLigne = (float) ($detail->remise_taux ?? 0);
                    $tauxTva = self::tauxTvaDeLaLigne($detail);

                    $itemHt = $qteAvoir * $prixUnit * (1 - $remiseLigne / 100);
                    $itemTva = $itemHt * $tauxTva / 100;
                    $itemTtc = $itemHt + $itemTva;

                    $ligneAvoir = VenteDetail::create([
                        'vente_id' => $avoir->id,
                        'produit_id' => $detail->produit_id,
                        'libelle_virtuel' => $detail->libelle_virtuel,
                        'quantite' => $qteAvoir,
                        'unite' => $detail->unite,
                        'prix_unitaire' => $prixUnit,
                        'remise_taux' => $remiseLigne,
                        'montant_tva' => $itemTva,
                        'montant_ttc' => $itemTtc,
                        'fne_invoice_item_id' => $detail->fne_invoice_item_id,
                    ]);

                    // Taxes personnalisées de la ligne d'origine : elles ont été
                    // facturées au client, elles doivent lui être recréditées.
                    foreach ($detail->taxes as $taxe) {
                        $ligneAvoir->taxes()->create([
                            'nom' => $taxe->nom,
                            'taux' => $taxe->taux,
                        ]);
                    }

                    $totalHt += $itemHt;
                    $totalTva += $itemTva;
                    $totalTtc += $itemTtc;

                    self::retournerLaMarchandise(
                        $detail->produit,
                        $parent,
                        $avoir,
                        $numAvoir,
                        (float) $qteAvoir,
                        $itemData['stock_action'] ?? 'none'
                    );
                }
            }

            $avoir->update([
                'montant_ht' => $totalHt,
                'montant_tva' => $totalTva,
                'montant_ttc' => $totalTtc,
            ]);

            // 3. Écritures comptables
            \App\Modules\Admin\Services\ComptabiliteService::genererEcritureAvoirVente($avoir);

            // 4. Sortie de trésorerie si remboursement caisse
            if (str_contains(strtolower($parent->mode_paiement), 'espèces') || str_contains(strtolower($parent->mode_paiement), 'caisse')) {
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $parent->point_de_vente_id)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id' => $parent->point_de_vente_id,
                    'date_operation' => now()->toDateString(),
                    'type_operation' => 'Décaissement',
                    'libelle' => 'Remboursement Avoir client ' . $numAvoir,
                    'mode_paiement' => $parent->mode_paiement,
                    'montant_entree' => 0,
                    'montant_sortie' => $totalTtc,
                    'solde_resultat' => $soldeActuel - $totalTtc,
                    'reference_document' => $numAvoir,
                ]);
            }

            $avoirId = $avoir->id;
        });

        $this->journaliser('creation_avoir_vente', 'Vente', $avoirId);

        $route = request()->routeIs('caissier.*') ? 'caissier.ventes.factures' : 'admin.ventes.factures';
        return redirect()->route($route, ['type' => 'avoir'])
            ->with('succes', "Facture d'avoir générée avec succès.");
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
}
