<?php

namespace App\Modules\Admin\Controleurs\Api;

use App\Modules\Admin\Modeles\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Modules\Admin\Regles\Appartenance;

class ProduitApiControleur
{
    public function index(): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $produits   = Produit::where('produits.entreprise_id', $entreprise->id)
            ->leftJoin('categories', 'produits.categorie_id', '=', 'categories.id')
            ->select('produits.*')
            ->orderBy('categories.nom')
            ->orderBy('produits.nom')
            ->paginate(30);

        return response()->json([
            'statut' => 'succes',
            'produits' => $produits
        ]);
    }

    public function creer(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'nom'           => ['required', 'string', 'max:200'],
            'categorie_id'  => ['nullable', 'integer', Appartenance::a('categories')],
            'sous_categorie_id' => ['nullable', 'integer', Appartenance::a('sous_categories')],
            'prix_achat'    => ['required', 'numeric', 'min:0'],
            'prix_vente'    => ['required', 'numeric', 'min:0'],
            'stock_actuel'  => ['required', 'integer', 'min:0'],
            'stock_minimum' => ['required', 'integer', 'min:0'],
            'unite'         => ['nullable', 'string', 'max:20'],
        ]);

        $produit = Produit::create(array_merge(
            $request->only(['nom', 'categorie_id', 'sous_categorie_id', 'prix_achat', 'prix_vente', 'unite']),
            ['entreprise_id' => $entreprise->id]
        ));

        // Le site vérifié (audit du 23/11/2026) : l'article n'est référencé
        // que sur ce site, et sa quantité de départ entre par un mouvement,
        // au lieu d'être écrite sur la fiche sans trace.
        $pdvs = $entreprise->pointsDeVente;
        $defaultPdvId = \App\Modules\Admin\Services\SiteAutorise::pourRequete($request)
            ?? ($pdvs->first()->id ?? null);
        $parReference = \App\Support\Structure::aColonne('stocks', 'reference');

        foreach ($pdvs as $pdv) {
            $isDefault = ($pdv->id == $defaultPdvId);
            \App\Modules\Admin\Modeles\Stock::create(array_filter([
                'produit_id'          => $produit->id,
                'point_de_vente_id'   => $pdv->id,
                'quantite_disponible' => 0,
                'stock_minimum'       => $isDefault ? $request->input('stock_minimum', 5) : 5,
                'stock_maximum'       => 100,
                'reference'           => $parReference ? $isDefault : null,
            ], fn ($v) => $v !== null));
        }

        $initial = (float) $request->input('stock_actuel', 0);
        if ($defaultPdvId && $initial > 0 && $produit->estStockable()) {
            \App\Modules\Admin\Services\StockService::entree($produit, (int) $defaultPdvId, $initial,
                \App\Modules\Admin\Modeles\MouvementStock::ENTREE_DIVERSE, ['cout_unitaire' => (float) $produit->prix_achat]);
        }

        return response()->json([
            'statut' => 'succes',
            'message' => 'Produit ajouté au catalogue avec succès. Référence : ' . $produit->reference,
            'produit' => $produit
        ], 201);
    }

    public function modifier(Request $request, Produit $produit): JsonResponse
    {
        if ($produit->entreprise_id !== Auth::user()->entreprise_id) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès non autorisé.'
            ], 403);
        }

        $request->validate([
            'nom'           => ['required', 'string', 'max:200'],
            'categorie_id'  => ['nullable', 'integer', Appartenance::a('categories')],
            'sous_categorie_id' => ['nullable', 'integer', Appartenance::a('sous_categories')],
            'prix_achat'    => ['required', 'numeric', 'min:0'],
            'prix_vente'    => ['required', 'numeric', 'min:0'],
            'stock_minimum' => ['required', 'integer', 'min:0'],
            'unite'         => ['nullable', 'string', 'max:20'],
        ]);

        $produit->update($request->only(['nom', 'categorie_id', 'sous_categorie_id', 'prix_achat', 'prix_vente', 'unite']));

        $pdvId = session('point_de_vente_actif_id') 
            ?? auth()->user()->point_de_vente_id 
            ?? (Auth::user()->entreprise->pointsDeVente()->first()->id ?? null);

        if ($pdvId) {
            \App\Modules\Admin\Modeles\Stock::updateOrCreate([
                'produit_id'        => $produit->id,
                'point_de_vente_id' => $pdvId,
            ], [
                'stock_minimum' => $request->input('stock_minimum', 5),
            ]);
        }

        return response()->json([
            'statut' => 'succes',
            'message' => 'Produit mis à jour avec succès.',
            'produit' => $produit
        ]);
    }
}
