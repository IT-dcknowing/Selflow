<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Modules\Admin\Services\ExpressionSqlPortable;

class RapportControleur
{
    /**
     * Page principale d'analyse d'activité.
     */
    public function analyseActivite(Request $request)
    {
        $utilisateur = Auth::user();
        $entreprise  = $utilisateur->entreprise;
        $periodeId   = session('active_periode_id');

        // ─── Liste des points de vente de l'entreprise (via cache Section 18.4) ─────────────────────
        $pointsDeVente = CacheService::pointsDeVente($entreprise->id);
        $pdvIds        = $pointsDeVente->pluck('id');

        // ─── VENTES (période active via PeriodeScope) ─────────────────────────
        $ventesBase = Vente::whereIn('point_de_vente_id', $pdvIds)
            ->where('etape', 'Facture');

        $totalVentes    = (clone $ventesBase)->sum('montant_ttc');
        $nbVentes       = (clone $ventesBase)->count();
        $totalVentesHT  = (clone $ventesBase)->sum('montant_ht');

        // ─── ACHATS (période active via PeriodeScope) ─────────────────────────
        $achatsBase = Achat::whereIn('point_de_vente_id', $pdvIds)
            ->where('etape', 'Facture');

        $totalAchats = (clone $achatsBase)->sum('montant_ttc');
        $nbAchats    = (clone $achatsBase)->count();

        // ─── KPIs ─────────────────────────────────────────────────────────────
        $margeBrute       = $totalVentesHT - $totalAchats;
        $tauxMarge        = $totalVentesHT > 0
            ? round(($margeBrute / $totalVentesHT) * 100, 1)
            : 0;
        $panierMoyen      = $nbVentes > 0 ? $totalVentes / $nbVentes : 0;

        // ─── Rentabilité par produit (top vendus vs coût achat) ───────────────
        // montant_ht = montant_ttc - montant_tva (pas de colonne montant_ht dans vente_details)
        $topProduits = VenteDetail::select(
                'produit_id',
                DB::raw('SUM(quantite) as qte_vendue'),
                DB::raw('SUM(montant_ttc) as ca_ttc'),
                DB::raw('SUM(montant_ttc - montant_tva) as ca_ht'),
                'libelle_virtuel'
            )
            ->whereHas('vente', function ($q) use ($pdvIds) {
                $q->whereIn('point_de_vente_id', $pdvIds)
                  ->where('etape', 'Facture');
            })
            ->groupBy('produit_id', 'libelle_virtuel')
            ->orderByDesc('ca_ttc')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $produit = Produit::find($row->produit_id);
                $coutRevient = $produit ? ($produit->prix_achat * $row->qte_vendue) : 0;
                $row->cout_achat    = $coutRevient;
                $row->marge_produit = $row->ca_ht - $coutRevient;
                $row->taux_marge    = $row->ca_ht > 0
                    ? round(($row->marge_produit / $row->ca_ht) * 100, 1)
                    : 0;
                $row->produit_nom = $produit?->nom ?? $row->libelle_virtuel ?? 'Article supprimé';
                return $row;
            });

        // ─── Produits les moins vendus (bottom 5) ────────────────────────────
        $basVendus = VenteDetail::select(
                'produit_id',
                DB::raw('SUM(quantite) as qte_vendue'),
                DB::raw('SUM(montant_ttc) as ca_ttc'),
                'libelle_virtuel'
            )
            ->whereHas('vente', function ($q) use ($pdvIds) {
                $q->whereIn('point_de_vente_id', $pdvIds)
                  ->where('etape', 'Facture');
            })
            ->groupBy('produit_id', 'libelle_virtuel')
            ->having('qte_vendue', '>', 0)
            ->orderBy('ca_ttc')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $produit = Produit::find($row->produit_id);
                $row->produit_nom = $produit?->nom ?? $row->libelle_virtuel ?? 'Article supprimé';
                return $row;
            });

        // ─── Top Dépenses : catégories d'achats ──────────────────────────────
        // Vérification : achat_details a les mêmes colonnes (pas de montant_ht)
        $topDepenses = AchatDetail::select(
                'libelle_virtuel',
                DB::raw('SUM(montant_ttc) as total_depense'),
                DB::raw('SUM(quantite) as qte_achetee')
            )
            ->whereHas('achat', function ($q) use ($pdvIds) {
                $q->whereIn('point_de_vente_id', $pdvIds)
                  ->where('etape', 'Facture');
            })
            ->groupBy('libelle_virtuel')
            ->orderByDesc('total_depense')
            ->limit(10)
            ->get();

        // ─── Comparaison Points de Vente ─────────────────────────────────────
        $performancesPdv = $pointsDeVente->map(function ($pdv) use ($pdvIds) {
            $ventesPdv  = Vente::where('point_de_vente_id', $pdv->id)
                ->where('etape', 'Facture');
            $achatsPdv  = Achat::where('point_de_vente_id', $pdv->id)
                ->where('etape', 'Facture');
            $tresPdv    = TresorerieJournal::where('point_de_vente_id', $pdv->id);

            $ca_ttc     = (clone $ventesPdv)->sum('montant_ttc');
            // montant_ht existe dans la table ventes (pas vente_details)
            $ca_ht      = (clone $ventesPdv)->sum('montant_ht');
            $nb_ventes  = (clone $ventesPdv)->count();
            $depenses   = (clone $achatsPdv)->sum('montant_ttc');
            $entrees    = (clone $tresPdv)->sum('montant_entree');
            $sorties    = (clone $tresPdv)->sum('montant_sortie');
            $marge      = $ca_ht - $depenses;

            return [
                'id'         => $pdv->id,
                'nom'        => $pdv->nom,
                'ville'      => $pdv->ville,
                'ca_ttc'     => $ca_ttc,
                'ca_ht'      => $ca_ht,
                'nb_ventes'  => $nb_ventes,
                'depenses'   => $depenses,
                'entrees'    => $entrees,
                'sorties'    => $sorties,
                'solde_tres' => $entrees - $sorties,
                'marge'      => $marge,
                'rentable'   => $marge > 0,
                'panier_moy' => $nb_ventes > 0 ? round($ca_ttc / $nb_ventes, 0) : 0,
            ];
        })->sortByDesc('ca_ttc')->values();

        // ─── Performance des employés (vendeurs) ─────────────────────────────
        $performancesEmployes = Vente::select(
                'utilisateur_id',
                DB::raw('SUM(montant_ttc) as total_ventes'),
                DB::raw('COUNT(*) as nb_ventes'),
                DB::raw('AVG(montant_ttc) as panier_moyen')
            )
            ->whereIn('point_de_vente_id', $pdvIds)
            ->where('etape', 'Facture')
            ->whereNotNull('utilisateur_id')
            ->groupBy('utilisateur_id')
            ->orderByDesc('total_ventes')
            ->get()
            ->map(function ($row) {
                $user = \App\Modules\Authentification\Modeles\Utilisateur::find($row->utilisateur_id);
                $row->nom_employe = $user ? ($user->prenom . ' ' . $user->nom) : 'Utilisateur supprimé';
                $row->pdv_nom     = $user?->pointDeVente?->nom ?? '—';
                return $row;
            });

        // ─── Évolution mensuelle des ventes (12 derniers mois sans PeriodeScope) ──
        $evolutionMensuelle = DB::table('ventes')
            ->select(
                DB::raw(ExpressionSqlPortable::anneeEtMois('date_vente') . ' as mois'),
                DB::raw('SUM(montant_ttc) as ca'),
                DB::raw('COUNT(*) as nb')
            )
            ->whereIn('point_de_vente_id', $pdvIds)
            ->where('etape', 'Facture')
            ->where('date_vente', '>=', now()->subMonths(11)->startOfMonth())
            ->groupBy('mois')
            ->orderBy('mois')
            ->get();

        $evolutionAchatsMensuelle = DB::table('achats')
            ->select(
                DB::raw(ExpressionSqlPortable::anneeEtMois('date_achat') . ' as mois'),
                DB::raw('SUM(montant_ttc) as depenses')
            )
            ->whereIn('point_de_vente_id', $pdvIds)
            ->where('etape', 'Facture')
            ->where('date_achat', '>=', now()->subMonths(11)->startOfMonth())
            ->groupBy('mois')
            ->orderBy('mois')
            ->get();

        // ─── Répartition modes de paiement ───────────────────────────────────
        $repartitionPaiements = Vente::select('mode_paiement', DB::raw('COUNT(*) as nb'), DB::raw('SUM(montant_ttc) as total'))
            ->whereIn('point_de_vente_id', $pdvIds)
            ->where('etape', 'Facture')
            ->groupBy('mode_paiement')
            ->get();

        // ─── Trésorerie globale ───────────────────────────────────────────────
        $totalEncaissements  = TresorerieJournal::whereIn('point_de_vente_id', $pdvIds)->sum('montant_entree');
        $totalDecaissements  = TresorerieJournal::whereIn('point_de_vente_id', $pdvIds)->sum('montant_sortie');
        $soldeTresorerie     = $totalEncaissements - $totalDecaissements;

        return view('admin::rapports.analyse_activite', compact(
            'entreprise',
            'pointsDeVente',
            'totalVentes',
            'nbVentes',
            'totalAchats',
            'nbAchats',
            'margeBrute',
            'tauxMarge',
            'panierMoyen',
            'topProduits',
            'basVendus',
            'topDepenses',
            'performancesPdv',
            'performancesEmployes',
            'evolutionMensuelle',
            'evolutionAchatsMensuelle',
            'repartitionPaiements',
            'totalEncaissements',
            'totalDecaissements',
            'soldeTresorerie'
        ));
    }
}
