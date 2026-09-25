<?php

namespace App\Modules\Admin\Controleurs\Api;

use App\Jobs\NormaliserFactureFne;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Modeles\PointDeVente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Regles\Quantite;
use App\Modules\Admin\Services\StockService;

class VenteApiControleur
{
    /**
     * Charger les données pour initialiser le panier de vente.
     */
    public function donneesFormulaire(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = $this->obtenirPointDeVenteId($request);

        $clients = Client::where('entreprise_id', $entreprise->id)->orderBy('nom')->get();
        $produits = Produit::where('entreprise_id', $entreprise->id)->orderBy('nom')->get();
        $categories = $produits->pluck('categorie')->unique()->sort()->values();
        // Banques réellement configurées côté web (CodeJournal type='Banque') —
        // le modèle Banque, séparé, n'est plus utilisé (il n'était jamais
        // alimenté par la configuration faite depuis l'interface web).
        $banques = \App\Modules\Admin\Modeles\CodeJournal::where('entreprise_id', $entreprise->id)
            ->where('type', 'Banque')->orderBy('intitule')->get();

        return response()->json([
            'statut' => 'succes',
            'donnees' => [
                'clients' => $clients,
                'produits' => $produits,
                'categories' => $categories,
                'point_de_vente_actif_id' => intval($pointDeVenteId),
                'banques' => $banques
            ]
        ]);
    }

    /**
     * Enregistrer une nouvelle vente.
     *
     * Réécrit le 22/07/2026 pour aligner ce point d'entrée API sur le
     * comportement du contrôleur web (VenteControleur) : même numérotation
     * (NumerotationService), même architecture de stock (table Stock par
     * point de vente, plus de colonne stock_actuel directe sur Produit),
     * mêmes écritures comptables (ComptabiliteService), et mêmes contrôles
     * de sécurité multi-entreprise (Produit/Banque/document parent scopés).
     */
    public function enregistrer(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = $this->obtenirPointDeVenteId($request);

        $request->validate([
            'client_id'      => ['nullable', 'integer', Appartenance::a('clients', 'id')],
            'mode_paiement'  => ['required', 'string'],
            'remise'         => ['nullable', 'numeric', 'min:0'],
            'articles'       => ['required', 'array', 'min:1'],
            'articles.*.produit_id'      => ['nullable', 'integer', Appartenance::a('produits', 'id')],
            'articles.*.libelle_virtuel' => ['nullable', 'string', 'max:255'],
            'articles.*.quantite'        => Quantite::physique(),
            'articles.*.unite'           => ['nullable', 'string', 'max:50'],
            'articles.*.prix_unitaire'   => ['nullable', 'numeric', 'min:0'],
        ], [
            'articles.required' => 'Veuillez ajouter au moins un article au panier.',
        ]);

        if ($request->mode_paiement === 'Banque') {
            $request->validate([
                'banque_id'          => ['required', 'integer'],
                'moyen_bancaire'     => ['required', 'string', 'in:carte,virement,cheque'],
                'reference_paiement' => ['required', 'string', 'max:255'],
            ]);
        }

        if ($request->mode_paiement !== 'Crédit') {
            $request->validate([
                'montant_paye' => ['required', 'numeric', 'min:0.01'],
            ], [
                'montant_paye.required' => 'Le montant payé est obligatoire pour ce mode de paiement.',
                'montant_paye.min' => 'Le montant payé doit être supérieur à 0.',
            ]);
        }

        $typeDocument = $request->input('type_document', 'Facture');

        // Correspondance avec les étapes du contrôleur web, pour une
        // numérotation IDENTIQUE quelle que soit l'origine (web ou API) :
        //   Devis -> DV-dd-mm-yyyy-xxxx | Commande -> BC-dd-mm-yyyy-xxxx
        //   Facture -> VTE-jjmmaa-xxx (nouvelle convention datée)
        $etape = match ($typeDocument) {
            'Devis'    => 'Devis',
            'Commande' => 'Bon de commande',
            default    => 'Facture',
        };

        // Vérification de disponibilité AVANT toute écriture en base — voir
        // VenteControleur::store() pour la même règle : uniquement pour une
        // Facture (décrémentation immédiate), pas pour un Devis/BC.
        if ($etape === 'Facture') {
            foreach ($request->articles as $article) {
                if (empty($article['produit_id'])) continue;
                $produit = Produit::where('entreprise_id', $entreprise->id)->find($article['produit_id']);
                if (!$produit || !$produit->estStockable()) continue;

                $stockDisponible = $produit->stockActuel($pointDeVenteId);
                if ($stockDisponible < $article['quantite']) {
                    return response()->json([
                        'statut'  => 'erreur',
                        'message' => "Stock insuffisant pour « {$produit->nom} » : disponible {$stockDisponible}, demandé {$article['quantite']}.",
                    ], 422);
                }
            }
        }

        $venteData = [];

        DB::transaction(function () use ($request, $pointDeVenteId, $entreprise, $etape, $typeDocument, &$venteData) {
            $montantHt  = 0;
            $tvaActive = $request->boolean('tva_active', false);
            $remise = floatval($request->input('remise', 0));

            // Précalcul des montants — produit scopé à l'entreprise (sécurité)
            foreach ($request->articles as $article) {
                if (!empty($article['produit_id'])) {
                    $produit = Produit::where('entreprise_id', $entreprise->id)->findOrFail($article['produit_id']);
                    $prix = $produit->prix_vente;
                } else {
                    $prix = floatval($article['prix_unitaire'] ?? 0);
                }

                $ht = $article['quantite'] * $prix;
                $montantHt += $ht;
            }

            $montantHtNet = max(0, $montantHt - $remise);
            $montantTva = $tvaActive ? ($montantHtNet * 0.18) : 0;
            $montantTtc = $montantHtNet + $montantTva;

            // Mode de paiement — code journal banque scopé à l'entreprise
            // (CodeJournal, comme sur le web ; le modèle Banque, séparé et
            // non alimenté par la configuration web, n'est plus utilisé ici).
            $modePaiementFinal = $request->mode_paiement;
            if ($request->mode_paiement === 'Banque' && $request->filled('banque_id')) {
                $codeJournal = \App\Modules\Admin\Modeles\CodeJournal::where('entreprise_id', $entreprise->id)
                    ->findOrFail($request->banque_id);
                $modePaiementFinal = 'Banque : ' . $codeJournal->intitule;
            }

            // Numéro de document — identique à la convention web (NumerotationService)
            $numero = \App\Modules\Admin\Services\NumerotationService::genererNumeroVente($entreprise->id, $etape);

            $montantPaye = 0;
            if ($etape === 'Devis') {
                $statutVente = 'Brouillon';
            } elseif ($etape === 'Bon de commande') {
                $statutVente = 'Confirmée';
            } else {
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
            }

            $vente = Vente::create([
                'point_de_vente_id' => $pointDeVenteId,
                'client_id'         => $request->client_id ?: null,
                'numero_facture'    => $numero,
                'date_vente'        => now()->toDateString(),
                'mode_paiement'     => $modePaiementFinal,
                'moyen_bancaire'    => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                'reference_paiement'=> $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                'montant_ht'        => $montantHt,
                'montant_tva'       => $montantTva,
                'remise'            => $remise,
                'montant_ttc'       => $montantTtc,
                'statut'            => $statutVente,
                'type_facture'      => $typeDocument,
                'etape'             => $etape,
            ]);

            // Mettre à jour le document parent si existant (ex: Devis -> Facture)
            // — scopé à l'entreprise (sécurité : évite de modifier le
            // document d'une autre entreprise via un identifiant deviné).
            if ($request->filled('reference_parent_id')) {
                Vente::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
                    ->where('numero_facture', $request->reference_parent_id)
                    ->update(['statut' => $typeDocument === 'Facture' ? 'Facturé' : 'Converti']);
            }

            foreach ($request->articles as $article) {
                if (!empty($article['produit_id'])) {
                    $produit = Produit::where('entreprise_id', $entreprise->id)
                        ->lockForUpdate()->findOrFail($article['produit_id']);
                    $prix = $produit->prix_vente;
                    $nomElement = $produit->nom;
                } else {
                    $produit = null;
                    $prix = floatval($article['prix_unitaire'] ?? 0);
                    $nomElement = $article['libelle_virtuel'] ?? 'Saisie libre';
                }

                $ht = $article['quantite'] * $prix;
                $tva = $tvaActive ? ($ht * 0.18) : 0;

                $detail = VenteDetail::create([
                    'vente_id'        => $vente->id,
                    'produit_id'      => $produit ? $produit->id : null,
                    'libelle_virtuel' => $produit ? null : $nomElement,
                    'quantite'        => $article['quantite'],
                    'unite'           => $article['unite'] ?? 'Unité',
                    'prix_unitaire'   => $prix,
                    'montant_tva'     => $tva,
                    'montant_ttc'     => $ht + $tva,
                ]);

                if ($etape === 'Facture' && $produit && $produit->estStockable()) {
                    // La disponibilite se lit sous le verrou qui servira a la
                    // sortie : c'est ce que l'API n'avait pas non plus.
                    $disponible = StockService::disponible($produit, (int) $pointDeVenteId);

                    if ($disponible < $article['quantite']) {
                        throw new \InvalidArgumentException(
                            "Stock insuffisant pour « {$produit->nom} » (Disponible: {$disponible}, Demandé: {$article['quantite']})."
                        );
                    }

                    $detail->update(['quantite_livree' => $article['quantite']]);

                    StockService::sortie($produit, (int) $pointDeVenteId, (float) $article['quantite'],
                        MouvementStock::LIVRAISON,
                        ['piece' => $vente, 'reference' => $numero, 'client_id' => $vente->client_id]);
                }
            }

            // Écritures comptables — absentes de l'ancienne implémentation API
            // (une vente créée via l'API n'était jusqu'ici JAMAIS comptabilisée).
            if ($etape === 'Facture') {
                \App\Modules\Admin\Services\ComptabiliteService::genererEcrituresVente(
                    $vente,
                    $statutVente === 'Crédit' ? 0 : $montantPaye,
                    $modePaiementFinal,
                    now()->toDateString(),
                    $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    $request->mode_paiement === 'Banque' ? $request->reference_paiement : null
                );
            }

            // Normalisation FNE DGI-CI en arrière-plan
            if ($etape === 'Facture') {
                $estRne = empty($request->client_id) || ($request->client_id == 'divers');
                NormaliserFactureFne::dispatch($vente, $estRne);
            }

            // Trésorerie
            if ($etape === 'Facture' && $statutVente !== 'Crédit' && $montantPaye > 0) {
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pointDeVenteId)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id'  => $pointDeVenteId,
                    'date_operation'     => now()->toDateString(),
                    'type_operation'     => 'Encaissement',
                    'libelle'            => \App\Modules\Admin\Services\ComptabiliteService::libelleTresorerieVente($vente),
                    'mode_paiement'      => $modePaiementFinal,
                    'moyen_bancaire'     => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    'reference_paiement' => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                    'montant_entree'     => $montantPaye,
                    'montant_sortie'     => 0,
                    'solde_resultat'     => $soldeActuel + $montantPaye,
                    'reference_document' => $numero,
                ]);
            }

            $venteData = [
                'vente_id' => $vente->id,
                'numero_facture' => $vente->numero_facture,
                'details' => [
                    'montant_ht' => floatval($montantHt),
                    'remise' => floatval($remise),
                    'montant_ht_net' => floatval($montantHtNet),
                    'montant_tva' => floatval($montantTva),
                    'montant_ttc' => floatval($montantTtc),
                    'statut_paiement' => $statutVente
                ]
            ];
        });

        return response()->json([
            'statut' => 'succes',
            'message' => 'Vente enregistrée avec succès !',
            'donnees' => $venteData
        ], 201);
    }

    /**
     * Liste des factures de vente.
     */
    public function factures(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $ventes = Vente::with(['client', 'pointDeVente.entreprise', 'details.produit'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->where(function($q) {
                $q->where('numero_facture', 'LIKE', 'VT-%')
                  ->orWhere('numero_facture', 'LIKE', 'VTE-%');
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'statut' => 'succes',
            'ventes' => $ventes
        ]);
    }

    /**
     * Liste des devis.
     */
    public function devis(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $ventes = Vente::with(['client', 'pointDeVente.entreprise', 'details.produit'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->where(function($q) {
                // 'DEV-' : ancien préfixe (avant alignement du 22/07/2026)
                // 'DV-'  : préfixe réel généré par NumerotationService (web et API)
                $q->where('numero_facture', 'LIKE', 'DEV-%')
                  ->orWhere('numero_facture', 'LIKE', 'DV-%');
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'statut' => 'succes',
            'ventes' => $ventes
        ]);
    }

    /**
     * Liste des commandes.
     */
    public function commandes(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $ventes = Vente::with(['client', 'pointDeVente.entreprise', 'details.produit'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->where(function($q) {
                // 'CMD-' : ancien préfixe (avant alignement du 22/07/2026)
                // 'BC-'  : préfixe réel généré par NumerotationService (Bon de commande)
                $q->where('numero_facture', 'LIKE', 'CMD-%')
                  ->orWhere('numero_facture', 'LIKE', 'BC-%');
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'statut' => 'succes',
            'ventes' => $ventes
        ]);
    }

    /*
     * `historique()` vivait ici. Retiree le 25/09/2026 : elle doublait
     * `factures()` sans rien apporter, aucun ecran ne l'appelait, et son
     * droit `historique_ventes` n'etait propose nulle part -- si bien que
     * l'adresse restait fermee a tous sauf a l'administrateur.
     */


    /**
     * Détails d'une facture.
     */
    public function details(Vente $vente): JsonResponse
    {
        if ($vente->pointDeVente->entreprise_id !== Auth::user()->entreprise_id) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès non autorisé.'
            ], 403);
        }

        $vente->load(['client', 'pointDeVente.entreprise', 'details.produit']);

        return response()->json([
            'statut' => 'succes',
            'donnees' => [
                'vente' => [
                    'id' => $vente->id,
                    'uuid' => $vente->uuid,
                    'numero_facture' => $vente->numero_facture,
                    'date_vente' => $vente->date_vente->toDateString(),
                    'mode_paiement' => $vente->mode_paiement,
                    'montant_ht' => floatval($vente->montant_ht),
                    'remise' => floatval($vente->remise),
                    'montant_tva' => floatval($vente->montant_tva),
                    'montant_ttc' => floatval($vente->montant_ttc),
                    'statut' => $vente->statut,
                    'normalise' => $vente->normalise,
                    'qr_code_data' => $vente->qr_code_data,
                    'client' => $vente->client ? [
                        'nom' => $vente->client->nom,
                        'telephone' => $vente->client->telephone,
                        'ncc' => $vente->client->ncc
                    ] : null,
                    'point_de_vente' => [
                        'nom' => $vente->pointDeVente->nom,
                        'ville' => $vente->pointDeVente->ville,
                        'commune' => $vente->pointDeVente->commune,
                        'entreprise' => [
                            'nom' => $vente->pointDeVente->entreprise->nom,
                            'telephone' => $vente->pointDeVente->entreprise->telephone,
                            'ncc' => $vente->pointDeVente->entreprise->ncc
                        ]
                    ],
                    'details' => $vente->details->map(function ($d) {
                        return [
                            'produit_id' => $d->produit_id,
                            'nom_produit' => $d->produit ? $d->produit->nom : $d->libelle_virtuel,
                            'quantite' => $d->quantite,
                            'unite' => $d->unite,
                            'prix_unitaire' => floatval($d->prix_unitaire),
                            'montant_ttc' => floatval($d->montant_ttc)
                        ];
                    })
                ]
            ]
        ]);
    }


    /**
     * Modifier rapidement le statut de règlement (paiement partiel ou total).
     */
    public function modifierStatut(Vente $vente, Request $request): JsonResponse
    {
        if ($vente->pointDeVente->entreprise_id !== Auth::user()->entreprise_id) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès non autorisé.'
            ], 403);
        }

        $request->validate([
            'statut'        => ['required', 'string', 'in:Payé,Crédit,Avance'],
            'mode_paiement' => ['nullable', 'string', 'max:100'],
            'montant_paye'  => ['nullable', 'numeric', 'min:0'],
        ]);

        $updateData = $request->only(['statut', 'mode_paiement']);
        // montant_paye is not stored in ventes table, it is tracked via TresorerieJournal
        $vente->update($updateData);

        // Enregistrer l'encaissement si paiement effectif
        if (in_array($request->statut, ['Payé', 'Avance']) && $request->filled('montant_paye')) {
            $soldeActuel = TresorerieJournal::where('point_de_vente_id', $vente->point_de_vente_id)
                ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

            TresorerieJournal::create([
                'entreprise_id'      => Auth::user()->entreprise_id,
                'point_de_vente_id'  => $vente->point_de_vente_id,
                'type_operation'     => 'Encaissement',
                'libelle'            => 'Règlement facture vente ' . $vente->numero_facture,
                'montant_entree'     => floatval($request->input('montant_paye')),
                'montant_sortie'     => 0,
                'solde_resultat'     => $soldeActuel + floatval($request->input('montant_paye')),
                'mode_paiement'      => $request->input('mode_paiement', 'Espèces'),
                'reference_document' => $vente->numero_facture,
                'date_operation'     => now()->toDateString(),
            ]);
        }

        return response()->json([
            'statut'  => 'succes',
            'message' => 'Facture mise à jour avec succès.',
            'nouveau_statut' => $vente->fresh()->statut,
        ]);
    }

    /**
     * Récupère la liste des factures impayées (statut Crédit ou Avance).
     */
    public function impayes(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        
        $impayes = Vente::with(['client'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->whereIn('statut', ['Crédit', 'Avance'])
            ->get()
            ->map(function ($vente) {
                // Si pas de date_echeance, on calcule depuis date_vente
                $dateRef = $vente->date_echeance ?? $vente->date_vente;
                $delay = $dateRef ? \Carbon\Carbon::parse($dateRef)->diffInDays(now(), false) : 0;
                
                return [
                    'id_facture' => $vente->id,
                    'nom_client' => $vente->client ? $vente->client->nom : 'Client Divers',
                    'numero_facture' => $vente->numero_facture,
                    // Si on ne stocke pas le reste à payer en base, on affiche le TTC.
                    'montant_restant' => $vente->montant_ttc, 
                    'jours_retard' => max(0, intval($delay)),
                    'telephone' => $vente->client ? $vente->client->telephone : '',
                    'email' => $vente->client ? $vente->client->email : '',
                ];
            });

        return response()->json([
            'statut' => 'succes',
            'donnees' => $impayes
        ]);
    }

    /**
     * Déclenche une relance client.
     */
    public function relancer($id): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        
        $vente = Vente::with(['client', 'pointDeVente'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->findOrFail($id);

        // Ici, logiquement vous envoyez un email ou SMS.
        // Exemple : Mail::to($vente->client->email)->send(new RelanceFactureMail($vente));
        
        return response()->json([
            'statut' => 'succes',
            'message' => 'Relance traitée avec succès pour la facture ' . $vente->numero_facture
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
