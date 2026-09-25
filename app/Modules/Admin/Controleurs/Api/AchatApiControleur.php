<?php

namespace App\Modules\Admin\Controleurs\Api;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Regles\Quantite;
use App\Modules\Admin\Services\StockService;

class AchatApiControleur
{
    /**
     * Charger les données pour initialiser le formulaire d'achat.
     */
    public function donneesFormulaire(Request $request): JsonResponse
    {
        $entreprise  = Auth::user()->entreprise;
        $fournisseurs = Fournisseur::where('entreprise_id', $entreprise->id)->orderBy('nom')->get();
        $produits     = Produit::where('entreprise_id', $entreprise->id)->orderBy('nom')->get();
        $pointDeVenteId = $this->obtenirPointDeVenteId($request);

        return response()->json([
            'statut' => 'succes',
            'donnees' => [
                'fournisseurs' => $fournisseurs,
                'produits' => $produits,
                'point_de_vente_actif_id' => intval($pointDeVenteId)
            ]
        ]);
    }

    /**
     * Enregistrer un nouvel achat.
     *
     * Réécrit le 22/07/2026 pour aligner ce point d'entrée API sur le
     * comportement du contrôleur web (AchatControleur) : numérotation
     * NumerotationService (ACH-jjmmaa-xxx), code journal banque via
     * CodeJournal (au lieu du modèle Banque, déconnecté de la config web),
     * écritures comptables via ComptabiliteService (absentes auparavant :
     * un achat créé par l'API n'était jusqu'ici jamais comptabilisé), et
     * sécurité multi-entreprise (fournisseur/produit/banque scopés).
     */
    public function enregistrer(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = $this->obtenirPointDeVenteId($request);

        $request->validate([
            'fournisseur_id' => ['required', 'integer', Appartenance::a('fournisseurs', 'id')],
            'date_achat'     => ['required', 'date'],
            'mode_paiement'  => ['required', 'string'],
            'articles'       => ['required', 'array', 'min:1'],
            'articles.*.produit_id'    => ['nullable', 'integer', Appartenance::a('produits', 'id')],
            'articles.*.libelle_virtuel' => ['nullable', 'string', 'max:255'],
            'articles.*.quantite'      => Quantite::physique(),
            'articles.*.prix_unitaire' => ['required', 'numeric', 'min:0'],
            'articles.*.unite'         => ['nullable', 'string', 'max:50'],
        ], [
            'fournisseur_id.required' => 'Veuillez sélectionner un fournisseur.',
            'articles.required'       => 'Veuillez ajouter au moins un article.',
        ]);

        if ($request->mode_paiement === 'Banque') {
            $request->validate([
                'banque_id'          => ['required', 'integer'],
                'moyen_bancaire'     => ['required', 'string', 'in:carte,virement,cheque'],
                'reference_paiement' => ['required', 'string', 'max:255'],
            ]);
        }

        // Sécurité : le fournisseur doit appartenir à l'entreprise de l'utilisateur.
        $fournisseur = Fournisseur::where('entreprise_id', $entreprise->id)->findOrFail($request->fournisseur_id);

        $achatData = [];

        DB::transaction(function () use ($request, $pointDeVenteId, $entreprise, $fournisseur, &$achatData) {
            $montantHt  = 0;
            $montantTva = 0;

            foreach ($request->articles as $article) {
                $ht          = $article['quantite'] * $article['prix_unitaire'];
                $tva         = $ht * 0.18;
                $montantHt  += $ht;
                $montantTva += $tva;
            }

            $montantTtc = $montantHt + $montantTva;

            $montantPaye = floatval($request->input('montant_paye', 0));
            $statutAchat = 'Crédit';
            if ($montantPaye >= $montantTtc && $montantTtc > 0) {
                $statutAchat = 'Payé';
            } elseif ($montantPaye > 0) {
                $statutAchat = 'Avance';
            }

            // Numéro de document — identique à la convention web (ACH-jjmmaa-xxx)
            $numero = \App\Modules\Admin\Services\NumerotationService::genererNumeroAchat($entreprise->id, 'Facture');

            $modePaiementFinal = $request->mode_paiement ?? 'Espèces';
            if ($request->mode_paiement === 'Banque' && $request->filled('banque_id')) {
                $codeJournal = \App\Modules\Admin\Modeles\CodeJournal::where('entreprise_id', $entreprise->id)
                    ->find($request->banque_id);
                if ($codeJournal) {
                    $modePaiementFinal = 'Banque : ' . $codeJournal->intitule;
                }
            }

            $achat = Achat::create([
                'point_de_vente_id' => $pointDeVenteId,
                'fournisseur_id'    => $fournisseur->id,
                'numero_facture'    => $numero,
                'date_achat'        => $request->date_achat,
                'mode_paiement'     => $modePaiementFinal,
                'moyen_bancaire'    => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                'reference_paiement'=> $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                'montant_ht'        => $montantHt,
                'montant_tva'       => $montantTva,
                'montant_ttc'       => $montantTtc,
                'statut'            => $statutAchat,
                'etape'             => 'Facture',
            ]);

            foreach ($request->articles as $article) {
                $produit = !empty($article['produit_id'])
                    ? Produit::where('entreprise_id', $entreprise->id)->lockForUpdate()->find($article['produit_id'])
                    : null;
                $ht      = $article['quantite'] * $article['prix_unitaire'];
                $tva     = $ht * 0.18;

                $detail = AchatDetail::create([
                    'achat_id'       => $achat->id,
                    'produit_id'     => $produit ? $produit->id : null,
                    'libelle_virtuel'=> $produit ? null : ($article['libelle_virtuel'] ?? 'Saisie libre'),
                    'quantite'       => $article['quantite'],
                    'unite'          => $article['unite'] ?? 'Unité',
                    'prix_unitaire'  => $article['prix_unitaire'],
                    'montant_tva'    => $tva,
                    'montant_ttc'    => $ht + $tva,
                ]);

                // Augmenter le stock + mouvement si produit existe et stockable
                // (pas de contrôle de disponibilité nécessaire ici : un achat
                // AUGMENTE le stock, aucun risque de valeur négative).
                if ($produit && $produit->estStockable()) {
                    // Reception implicite, comme sur le web : sans cela la file
                    // des receptions proposerait d'entrer une seconde fois ce
                    // qui vient d'entrer.
                    $detail->update(['quantite_receptionnee' => $article['quantite']]);

                    StockService::entree($produit, (int) $pointDeVenteId, (float) $article['quantite'],
                        MouvementStock::RECEPTION,
                        ['piece' => $achat, 'reference' => $numero,
                         'fournisseur_id' => $achat->fournisseur_id,
                         'cout_unitaire' => (float) $article['prix_unitaire']]);
                }
            }

            // Écritures comptables — absentes de l'ancienne implémentation API
            // (un achat créé via l'API n'était jusqu'ici JAMAIS comptabilisé).
            \App\Modules\Admin\Services\ComptabiliteService::genererEcrituresAchat(
                $achat,
                $statutAchat === 'Crédit' ? 0 : $montantPaye,
                $modePaiementFinal,
                $request->date_achat,
                $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                $request->mode_paiement === 'Banque' ? $request->reference_paiement : null
            );

            // Enregistrement automatique en décaissement (trésorerie)
            if ($statutAchat !== 'Crédit' && $montantPaye > 0) {
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

            $achatData = [
                'achat_id' => $achat->id,
                'numero_facture' => $achat->numero_facture
            ];
        });

        return response()->json([
            'statut' => 'succes',
            'message' => 'Achat enregistré et facture générée avec succès.',
            'donnees' => $achatData
        ], 201);
    }

    /**
     * Liste des factures d'achat.
     */
    public function factures(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $achats = Achat::with(['fournisseur', 'pointDeVente', 'details.produit'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->latest()
            ->paginate(20);

        return response()->json([
            'statut' => 'succes',
            'achats' => $achats
        ]);
    }

    /*
     * `historique()` vivait ici. Retiree le 25/09/2026 : elle doublait
     * `factures()` sans rien apporter, aucun ecran ne l'appelait, et son
     * droit `historique_achats` n'etait propose nulle part -- si bien que
     * l'adresse restait fermee a tous sauf a l'administrateur.
     */


    /**
     * Détail d'une facture d'achat.
     */
    public function details(Achat $achat): JsonResponse
    {
        if ($achat->pointDeVente->entreprise_id !== Auth::user()->entreprise_id) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès non autorisé.'
            ], 403);
        }

        $achat->load(['fournisseur', 'pointDeVente.entreprise', 'details.produit']);

        return response()->json([
            'statut' => 'succes',
            'donnees' => [
                'achat' => [
                    'id' => $achat->id,
                    'uuid' => $achat->uuid,
                    'numero_facture' => $achat->numero_facture,
                    'date_achat' => $achat->date_achat->toDateString(),
                    'mode_paiement' => $achat->mode_paiement,
                    'montant_ht' => floatval($achat->montant_ht),
                    'montant_tva' => floatval($achat->montant_tva),
                    'montant_ttc' => floatval($achat->montant_ttc),
                    'montant_paye' => floatval($achat->montant_paye ?? 0),
                    'statut' => $achat->statut,
                    'fournisseur' => [
                        'nom' => $achat->fournisseur->nom,
                        'telephone' => $achat->fournisseur->telephone,
                        'ncc' => $achat->fournisseur->ncc
                    ],
                    'point_de_vente' => [
                        'nom' => $achat->pointDeVente->nom,
                        'ville' => $achat->pointDeVente->ville,
                        'commune' => $achat->pointDeVente->commune
                    ],
                    'details' => $achat->details->map(function ($d) {
                        return [
                            'produit_id' => $d->produit_id,
                            'nom_produit' => $d->produit->nom,
                            'quantite' => $d->quantite,
                            'prix_unitaire' => floatval($d->prix_unitaire),
                            'montant_ttc' => floatval($d->montant_ttc)
                        ];
                    })
                ]
            ]
        ]);
    }

    /**
     * Modifier rapidement le statut de règlement (paiement partiel ou total) pour un achat.
     */
    public function modifierStatut(Achat $achat, Request $request): JsonResponse
    {
        if ($achat->pointDeVente->entreprise_id !== Auth::user()->entreprise_id) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès non autorisé.'
            ], 403);
        }

        $request->validate([
            'statut'        => ['required', 'string', 'in:Payé,Crédit,Avance,en_retard'],
            'mode_paiement' => ['nullable', 'string', 'max:100'],
            'montant_paye'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $updateData = $request->only(['statut', 'mode_paiement']);
        // montant_paye is not stored in achats table, it is tracked via TresorerieJournal
        $achat->update($updateData);

        // Enregistrer le décaissement si paiement effectif
        if (in_array($request->statut, ['Payé', 'Avance']) && $request->filled('montant_paye')) {
            $soldeActuel = TresorerieJournal::where('point_de_vente_id', $achat->point_de_vente_id)
                ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

            TresorerieJournal::create([
                'entreprise_id'      => Auth::user()->entreprise_id,
                'point_de_vente_id'  => $achat->point_de_vente_id,
                'type_operation'     => 'Décaissement',
                'libelle'            => 'Règlement facture achat ' . $achat->numero_facture,
                'montant_entree'     => 0,
                'montant_sortie'     => floatval($request->input('montant_paye')),
                'solde_resultat'     => $soldeActuel - floatval($request->input('montant_paye')),
                'mode_paiement'      => $request->input('mode_paiement', 'Espèces'),
                'reference_document' => $achat->numero_facture,
                'date_operation'     => now()->toDateString(),
            ]);
        }

        return response()->json([
            'statut'  => 'succes',
            'message' => 'Facture mise à jour avec succès.',
            'nouveau_statut' => $achat->fresh()->statut,
        ]);
    }

    /**
     * Récupère le point de vente actif.
     */
    private function obtenirPointDeVenteId(Request $request)
    {
        return $request->header('X-Point-De-Vente-Id') 
            ?? $request->query('point_de_vente_id') 
            ?? session('point_de_vente_actif_id') 
            ?? Auth::user()->point_de_vente_id;
    }
}
