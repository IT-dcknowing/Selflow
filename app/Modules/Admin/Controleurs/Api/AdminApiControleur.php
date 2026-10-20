<?php

namespace App\Modules\Admin\Controleurs\Api;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\TresorerieJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminApiControleur
{
    /**
     * Obtenir le tableau de bord de l'administrateur.
     */
    public function tableauDeBord(Request $request): JsonResponse
    {
        $utilisateur    = Auth::user();
        $entreprise     = $utilisateur->entreprise;
        $pointDeVenteId = $this->obtenirPointDeVenteId($request);

        $aujourd_hui = now()->toDateString();

        // Ventes du jour (filtré par PDV actif si spécifié)
        $queryVentes = Vente::whereHas('pointDeVente', function ($q) use ($entreprise) {
            $q->where('entreprise_id', $entreprise->id);
        })->whereDate('date_vente', $aujourd_hui);

        if ($pointDeVenteId) {
            $queryVentes->where('point_de_vente_id', $pointDeVenteId);
        }

        $ventesAujourdhui  = $queryVentes->get();
        $montantVentesJour = $ventesAujourdhui->sum('montant_ttc');

        // Achats du jour
        $montantAchatsJour = Achat::whereHas('pointDeVente', function ($q) use ($entreprise) {
            $q->where('entreprise_id', $entreprise->id);
        })->whereDate('date_achat', $aujourd_hui)->sum('montant_ttc');

        // Alertes stock
        $produitsEnAlerte = Produit::where('entreprise_id', $entreprise->id)
            ->whereHas('stocks', function($q) {
                $q->whereRaw('quantite_disponible <= stock_minimum')
                  ->where('stocks.reference', true);
            })
            ->with(['stocks'])
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'uuid' => $p->uuid,
                    'reference' => $p->reference,
                    'nom' => $p->nom,
                    'stock_actuel' => $p->stock_actuel,
                    'stock_minimum' => $p->stock_minimum,
                    'etat' => $p->etatStock()
                ];
            });

        // Solde trésorerie
        $solde = TresorerieJournal::whereHas('pointDeVente', function ($q) use ($entreprise) {
            $q->where('entreprise_id', $entreprise->id);
        })->sum(DB::raw('montant_entree - montant_sortie'));

        // Dernières ventes
        $dernieresVentes = Vente::with(['client', 'pointDeVente'])
            ->whereHas('pointDeVente', function ($q) use ($entreprise) {
                $q->where('entreprise_id', $entreprise->id);
            })
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($v) {
                return [
                    'id' => $v->id,
                    'uuid' => $v->uuid,
                    'numero_facture' => $v->numero_facture,
                    'date_vente' => $v->date_vente->toDateString(),
                    'client' => $v->client ? $v->client->nom : 'Client de passage',
                    'montant_ttc' => $v->montant_ttc,
                    'statut' => $v->statut
                ];
            });

        // Points de vente actifs
        $pointsDeVente = $entreprise->pointsDeVente()
            ->withCount(['ventes as ventes_jour' => function ($q) use ($aujourd_hui) {
                $q->whereDate('date_vente', $aujourd_hui);
            }])
            ->withSum(['ventes as montant_ventes_jour' => function ($q) use ($aujourd_hui) {
                $q->whereDate('date_vente', $aujourd_hui);
            }], 'montant_ttc')
            ->get()
            ->map(function ($pdv) {
                return [
                    'id' => $pdv->id,
                    'uuid' => $pdv->uuid,
                    'nom' => $pdv->nom,
                    'statut' => $pdv->statut,
                    'ventes_jour' => $pdv->ventes_jour ?? 0,
                    'montant_ventes_jour' => floatval($pdv->montant_ventes_jour ?? 0)
                ];
            });

        return response()->json([
            'statut' => 'succes',
            'donnees' => [
                'entreprise' => [
                    'id' => $entreprise->id,
                    'uuid' => $entreprise->uuid,
                    'nom' => $entreprise->nom,
                ],
                'point_de_vente_actif_id' => $pointDeVenteId ? intval($pointDeVenteId) : null,
                'metriques_du_jour' => [
                    'montant_ventes_jour' => floatval($montantVentesJour),
                    'montant_achats_jour' => floatval($montantAchatsJour),
                    'solde_tresorerie' => floatval($solde)
                ],
                'produits_en_alerte_stock' => $produitsEnAlerte,
                'dernieres_ventes' => $dernieresVentes,
                'points_de_vente' => $pointsDeVente
            ]
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
