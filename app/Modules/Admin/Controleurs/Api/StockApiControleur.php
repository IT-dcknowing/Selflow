<?php

namespace App\Modules\Admin\Controleurs\Api;

use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StockApiControleur
{
    public function index(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        // Le site vérifié (audit du 23/11/2026) : un membre affecté ne voit que
        // les articles de son point de vente.
        $site = \App\Modules\Admin\Services\SiteAutorise::pourRequete($request);
        // Le stock mobile rendait aussi les prestations, toutes en « Rupture ».
        $produits   = Produit::where('produits.entreprise_id', $entreprise->id)
            ->when($site, fn ($q) => $q->visiblesAuSite($site))
            ->stockables()
            ->with(['stocks'])
            ->leftJoin('categories', 'produits.categorie_id', '=', 'categories.id')
            ->select('produits.*')
            ->orderBy('categories.nom')
            ->orderBy('produits.nom')
            ->get()
            ->map(function ($p) {
                return [
                    'produit_id' => $p->id,
                    'nom' => $p->nom,
                    'categorie' => $p->categorie,
                    'stock_actuel' => $p->stock_actuel,
                    'stock_minimum' => $p->stock_minimum,
                    'etat' => $p->etatStock()
                ];
            });

        return response()->json([
            'statut' => 'succes',
            'stock' => $produits
        ]);
    }

    public function mouvements(Request $request): JsonResponse
    {
        $entreprise  = Auth::user()->entreprise;
        $site        = \App\Modules\Admin\Services\SiteAutorise::pourRequete($request);
        $mouvements  = MouvementStock::with(['produit', 'pointDeVente'])
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
            ->when($site, fn ($q) => $q->where('point_de_vente_id', $site))
            ->latest()
            ->paginate(30);

        return response()->json([
            'statut' => 'succes',
            'mouvements' => $mouvements
        ]);
    }
}
