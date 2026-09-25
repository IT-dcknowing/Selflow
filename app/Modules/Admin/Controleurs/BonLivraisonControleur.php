<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\BonLivraisonDetail;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Jobs\NormaliserFactureFne;
use App\Modules\Admin\Services\NumerotationService;
use App\Modules\Admin\Traits\JournaliseActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Modules\Admin\Services\StockService;

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
     * Formulaire de création du BL pré-rempli depuis le BC.
     * Vérifie le stock disponible pour chaque article.
     */
    public function creerDepuisBC(Vente $vente): View|RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        // Sécurité : le BC doit appartenir à l'entreprise
        abort_unless($vente->pointDeVente->entreprise_id === $entreprise->id, 404);

        // Seulement si c'est bien un Bon de Commande sans BL existant
        if ($vente->etape !== 'Bon de commande') {
            return back()->with('erreur', 'Ce document n\'est pas un bon de commande.');
        }
        if ($vente->bonLivraison) {
            return redirect()
                ->route(request()->routeIs('caissier.*') ? 'caissier.ventes.livraison.voir' : 'admin.ventes.livraison.voir',
                        $vente->bonLivraison)
                ->with('info', 'Un bon de livraison existe déjà pour cette commande.');
        }

        $pointDeVenteId = $vente->point_de_vente_id;

        // Construire la liste des lignes avec contrôle de stock
        $lignes = $vente->details->map(function ($detail) use ($pointDeVenteId) {
            $stock = \App\Modules\Admin\Modeles\Stock::where('produit_id', $detail->produit_id)
                ->where('point_de_vente_id', $pointDeVenteId)
                ->first();

            // En float : le stock se compte aussi en kilos et en litres, et
            // (int) faisait apparaitre 12,5 kg comme 12 disponibles.
            $stockDispo    = $stock ? max(0, (float) $stock->quantite_disponible) : 0;
            $qteCom        = (float) $detail->quantite;
            $qteSuggestion = min($qteCom, $stockDispo);
            $estInsuffisant = $stockDispo < $qteCom;

            return [
                'detail_id'      => $detail->id,
                'produit_id'     => $detail->produit_id,
                'libelle'        => $detail->libelle_virtuel ?? $detail->produit?->nom ?? '(article supprimé)',
                'unite'          => $detail->unite,
                'qte_commandee'  => $qteCom,
                'stock_dispo'    => $stockDispo,
                'qte_suggere'    => $qteSuggestion,
                'est_insuffisant'=> $estInsuffisant,
            ];
        });

        $stockInsuffisant = $lignes->where('est_insuffisant', true)->count();

        return view('admin::ventes.livraison_creer', compact(
            'vente', 'lignes', 'stockInsuffisant'
        ));
    }

    /**
     * Enregistre le Bon de Livraison.
     * Déduit le stock (qte_livree) et met à jour le statut du BC.
     */
    public function enregistrer(Request $request, Vente $vente): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($vente->pointDeVente->entreprise_id === $entreprise->id, 404);

        if ($vente->etape !== 'Bon de commande') {
            return back()->with('erreur', 'Ce document n\'est pas un bon de commande.');
        }
        if ($vente->bonLivraison) {
            return back()->with('erreur', 'Un bon de livraison existe déjà pour cette commande.');
        }

        // Validation
        $request->validate([
            'date_livraison'  => 'required|date',
            'notes'           => 'nullable|string|max:1000',
            'lignes'          => 'required|array|min:1',
            'lignes.*.produit_id'    => 'required|integer',
            'lignes.*.qte_commandee' => 'required|integer|min:0',
            'lignes.*.qte_livree'    => 'required|integer|min:0',
            'lignes.*.libelle'       => 'required|string',
            'lignes.*.unite'         => 'nullable|string',
        ]);

        $blId = null;
        $blCle = null;

        // Vérification de disponibilité AVANT toute écriture en base.
        foreach ($request->lignes as $ligne) {
            $qteL = max(0, (int) ($ligne['qte_livree'] ?? 0));
            if ($qteL > 0 && !empty($ligne['produit_id'])) {
                $produit = Produit::find($ligne['produit_id']);
                if ($produit && $produit->estStockable()) {
                    $dispo = $produit->stockActuel($vente->point_de_vente_id);
                    if ($dispo < $qteL) {
                        return back()->withInput()->with('erreur',
                            "❌ Stock insuffisant pour livrer « {$produit->nom} » (Disponible: {$dispo}, Demandé: {$qteL})."
                        );
                    }
                }
            }
        }

        DB::transaction(function () use ($request, $vente, $entreprise, &$blId, &$blCle) {
            $numeroBL    = NumerotationService::genererNumeroBL($entreprise->id);
            $totalCom    = 0;
            $totalLivre  = 0;
            $estPartiel  = false;

            // Calculer si livraison partielle
            foreach ($request->lignes as $ligne) {
                $qteC = (int) $ligne['qte_commandee'];
                $qteL = (int) $ligne['qte_livree'];
                $totalCom   += $qteC;
                $totalLivre += $qteL;
                if ($qteL < $qteC) {
                    $estPartiel = true;
                }
            }

            $statut = 'en_preparation';
            if ($totalLivre === 0) {
                $statut = 'en_preparation';
            } elseif ($estPartiel) {
                $statut = 'partiel';
            }

            // Créer le BL
            $bl = BonLivraison::create([
                'numero_bl'           => $numeroBL,
                'vente_id'            => $vente->id,
                'point_de_vente_id'   => $vente->point_de_vente_id,
                'client_id'           => $vente->client_id,
                'created_by'          => Auth::id(),
                'date_livraison'      => $request->date_livraison,
                'statut'              => $statut,
                'livraison_partielle' => $estPartiel,
                'notes'               => $request->notes,
            ]);

            // Créer les lignes et déduire le stock
            foreach ($request->lignes as $ligne) {
                $qteL = max(0, (int) $ligne['qte_livree']);

                BonLivraisonDetail::create([
                    'bon_livraison_id' => $bl->id,
                    'produit_id'       => $ligne['produit_id'],
                    'libelle'          => $ligne['libelle'],
                    'unite'            => $ligne['unite'] ?? null,
                    'qte_commandee'    => (int) $ligne['qte_commandee'],
                    'qte_livree'       => $qteL,
                ]);

                // Déduire du stock (sortie), avec verrou, contrôle final et traçabilité
                if ($qteL > 0 && !empty($ligne['produit_id'])) {
                    $produit = Produit::lockForUpdate()->find($ligne['produit_id']);

                    if ($produit && $produit->estStockable()) {
                        // La disponibilite se lit sous le verrou de la fiche,
                        // celui-la meme qui servira a la sortie.
                        $disponible = StockService::disponible($produit, (int) $vente->point_de_vente_id);

                        if ($disponible < $qteL) {
                            throw new \InvalidArgumentException(
                                "Stock insuffisant pour livrer « {$produit->nom} » (Disponible: {$disponible}, Demandé: {$qteL})."
                            );
                        }

                        // Ce mouvement partait sans sous-type : l'ecran des
                        // mouvements le rangeait dans le defaut, en gris, et la
                        // section « Ventes » ne le montrait pas.
                        StockService::sortie($produit, (int) $vente->point_de_vente_id, (float) $qteL,
                            MouvementStock::LIVRAISON, [
                                'piece'     => $bl,
                                'reference' => $numeroBL,
                                'client_id' => $vente->client_id,
                            ]);

                        // Synchroniser avec le détail de vente d'origine : évite le
                        // double décrément si l'utilisateur passe aussi par la file
                        // "Livraisons à valider" (StockControleur::livraisons()),
                        // qui se base sur VenteDetail.quantite_livree.
                        $venteDetail = VenteDetail::where('vente_id', $vente->id)
                            ->where('produit_id', $produit->id)
                            ->first();
                        if ($venteDetail) {
                            $venteDetail->increment('quantite_livree', $qteL);
                        }
                    }
                }
            }

            // Mettre à jour le statut du BC
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

        $bl->load(['details.produit', 'bonDeCommande.utilisateur', 'facture', 'client', 'pointDeVente']);

        $vente = $bl->bonDeCommande;
        $vendeur = $vente->utilisateur;
        $dejaPaye = TresorerieJournal::where('reference_document', $vente->numero_facture)->sum('montant_entree');

        $banques = CodeJournal::where('type', 'Banque')->where('entreprise_id', $entreprise->id)->orderBy('intitule')->get();

        return view('admin::factures.vente', compact('bl', 'vente', 'vendeur', 'dejaPaye', 'banques'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // MARQUER LIVRÉ
    // ──────────────────────────────────────────────────────────────────────────

    public function marquerLivre(BonLivraison $bl): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($bl->pointDeVente->entreprise_id === $entreprise->id, 404);

        if ($bl->statut === 'facture') {
            return back()->with('info', 'Ce BL est déjà facturé.');
        }

        $statut = $bl->livraison_partielle ? 'partiel' : 'livre';
        $bl->update(['statut' => $statut]);

        $this->journaliser('livraison_confirmee', 'BonLivraison', $bl->id);

        return back()->with('succes', 'Bon de livraison marqué comme ' . ($bl->livraison_partielle ? 'partiel' : 'livré') . '.');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CONVERTIR EN FACTURE
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Génère une Facture depuis ce BL.
     * L'utilisateur choisit si on facture sur les qté commandées (BC) ou livrées (BL).
     * Redirige ensuite vers /modifier pour compléter le paiement.
     */
    public function convertirEnFacture(Request $request, BonLivraison $bl): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($bl->pointDeVente->entreprise_id === $entreprise->id, 404);

        if ($bl->statut === 'facture') {
            return back()->with('erreur', 'Ce BL est déjà facturé.');
        }

        // Valider les nouveaux champs de règlement et livraison immédiate
        $request->validate([
            'base_facturation'   => 'required|in:livree,commandee',
            'mode_paiement'      => 'required|in:Caisse,Banque,Crédit',
            'montant_paye'       => 'nullable|numeric|min:0',
            'banque_id'          => 'required_if:mode_paiement,Banque',
            'moyen_bancaire'     => 'required_if:mode_paiement,Banque|nullable|in:carte,virement,cheque',
            'reference_paiement' => 'required_if:mode_paiement,Banque|nullable|string|max:100',
            'livraison_immediate'=> 'nullable',
        ]);

        $baseQte = $request->input('base_facturation', 'livree');
        $bc = $bl->bonDeCommande->load('details.produit');
        $pointDeVenteId = $bl->point_de_vente_id;

        $nouvelleFactureId = null;

        DB::transaction(function () use ($bl, $bc, $baseQte, $entreprise, $request, $pointDeVenteId, &$nouvelleFactureId) {
            $numeroFacture = NumerotationService::genererNumeroVente($entreprise->id, 'Facture');

            // Recalculer les montants selon la base choisie
            $montantHt  = 0;
            $montantTva = 0;
            $montantTtc = 0;

            foreach ($bc->details as $detail) {
                // Trouver la ligne correspondante dans le BL
                $blDetail = $bl->details->firstWhere('produit_id', $detail->produit_id);
                $qte = ($baseQte === 'livree' && $blDetail)
                    ? $blDetail->qte_livree
                    : $detail->quantite;

                $ratio       = $detail->quantite > 0 ? ($qte / $detail->quantite) : 0;
                $montantHt  += $detail->prix_unitaire * $qte;
                $montantTva += $detail->montant_tva * $ratio;
                $montantTtc += $detail->montant_ttc * $ratio;
            }

            // Déterminer la valeur finale du mode de paiement pour l'enregistrement
            $modePaiementFinal = $request->mode_paiement;
            if ($request->mode_paiement === 'Banque' && $request->filled('banque_id')) {
                $codeJournal = CodeJournal::where('entreprise_id', Auth::user()->entreprise_id)->findOrFail($request->banque_id);
                $modePaiementFinal = 'Banque : ' . $codeJournal->intitule;
            }

            // Calcul du statut de la vente
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

            // Cloner le BC en Facture
            $facture = $bc->replicate(['archived', 'normalise', 'numero_fne', 'signature_dgi', 'qr_code_data', 'statut']);
            $facture->numero_facture   = $numeroFacture;
            $facture->etape            = 'Facture';
            $facture->statut           = $statutVente;
            $facture->mode_paiement    = $modePaiementFinal;
            $facture->moyen_bancaire   = $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null;
            $facture->reference_paiement = $request->mode_paiement === 'Banque' ? $request->reference_paiement : null;
            $facture->archived         = false;
            $facture->normalise        = false;
            $facture->numero_fne       = null;
            $facture->signature_dgi    = null;
            $facture->qr_code_data     = null;
            $facture->bon_livraison_id  = $bl->id;
            $facture->montant_ht       = $montantHt;
            $facture->montant_tva      = $montantTva;
            $facture->montant_ttc      = $montantTtc;
            $facture->save();

            // Cloner les lignes avec la quantité selon la base choisie
            foreach ($bc->details as $detail) {
                $blDetail = $bl->details->firstWhere('produit_id', $detail->produit_id);
                $qte = ($baseQte === 'livree' && $blDetail) ? $blDetail->qte_livree : $detail->quantite;

                $newDetail = $detail->replicate();
                $newDetail->vente_id  = $facture->id;
                $newDetail->quantite  = $qte;
                $newDetail->save();
            }

            // Écritures comptables (+ règlement immédiat le cas échéant) : décide
            // seule si vente comptant (aucune ligne 411) ou à crédit (411 pour
            // le solde réellement non couvert immédiatement).
            \App\Modules\Admin\Services\ComptabiliteService::genererEcrituresVente(
                $facture,
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
                    'point_de_vente_id'  => $pointDeVenteId,
                    'date_operation'     => now()->toDateString(),
                    'type_operation'     => 'Encaissement',
                    'libelle'            => \App\Modules\Admin\Services\ComptabiliteService::libelleTresorerieVente($facture),
                    'mode_paiement'      => $modePaiementFinal,
                    'moyen_bancaire'     => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    'reference_paiement' => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                    'montant_entree'     => $montantPaye,
                    'montant_sortie'     => 0,
                    'solde_resultat'     => $soldeActuel + $montantPaye,
                    'reference_document' => $numeroFacture,
                ]);
            }

            // Normalisation FNE
            $estRne = empty($facture->client_id) || ($facture->client_id == 'divers');
            NormaliserFactureFne::dispatch($facture, $estRne);

            // Gérer le statut du BL (si livraison immédiate cochée, on le marque comme livré si pas déjà fait)
            if ($request->filled('livraison_immediate') && !in_array($bl->statut, ['livre', 'facture'])) {
                $bl->update(['statut' => 'facture', 'livraison_partielle' => false]);
            } else {
                $bl->update(['statut' => 'facture']);
            }

            // Lier le BL à la facture
            $bl->update([
                'facture_vente_id' => $facture->id,
            ]);

            $nouvelleFactureId = $facture->id;
        });

        $this->journaliser('facturation_depuis_bl', 'BonLivraison', $bl->id);

        $route = request()->routeIs('caissier.*') ? 'caissier.ventes.factures' : 'admin.ventes.factures';
        return redirect()->route($route, ['etape' => 'Facture'])
            ->with('succes', 'Facture ' . $bl->bonDeCommande->numero_facture . ' générée avec succès !');
    }
}
