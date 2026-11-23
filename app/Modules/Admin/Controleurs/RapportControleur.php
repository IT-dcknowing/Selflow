<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
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

    /**
     * Comparer les prix réellement pratiqués par article et par vendeur.
     *
     * Le périmètre est toujours celui de l'entreprise connectée ; un membre
     * affecté à un site ne peut jamais élargir le rapport à d'autres sites.
     */
    public function analysePrix(Request $request)
    {
        $utilisateur = Auth::user();
        $entreprise = $utilisateur->entreprise;
        abort_unless($entreprise, 403);

        $request->validate([
            'debut' => ['nullable', 'date'],
            'fin' => ['nullable', 'date'],
            'pdv_id' => ['nullable', 'integer'],
            'vendeur_id' => ['nullable', 'integer'],
            'produit' => ['nullable', 'string', 'max:150'],
        ]);

        if ($request->filled('debut') && $request->filled('fin')
            && $request->input('fin') < $request->input('debut')) {
            $request->validate(['fin' => ['after_or_equal:debut']]);
        }

        $pointsDeVente = CacheService::pointsDeVente($entreprise->id);
        $pdvIds = $pointsDeVente->pluck('id');

        if ($utilisateur->estAffecteAUnSite()) {
            $pdvIds = $pdvIds->intersect([$utilisateur->point_de_vente_id])->values();
        } elseif ($request->filled('pdv_id')) {
            abort_unless($pdvIds->contains((int) $request->input('pdv_id')), 403);
            $pdvIds = collect([(int) $request->input('pdv_id')]);
        }

        $lignes = DB::table('vente_details')
            ->join('ventes', 'ventes.id', '=', 'vente_details.vente_id')
            ->leftJoin('produits', 'produits.id', '=', 'vente_details.produit_id')
            ->leftJoin('utilisateurs', 'utilisateurs.id', '=', 'ventes.utilisateur_id')
            ->whereIn('ventes.point_de_vente_id', $pdvIds)
            ->where('ventes.etape', 'Facture')
            ->where(function ($q) {
                $q->whereNull('ventes.type_facture')->orWhere('ventes.type_facture', '!=', 'avoir');
            })
            ->when($request->filled('debut'), fn ($q) => $q->whereDate('ventes.date_vente', '>=', $request->input('debut')))
            ->when($request->filled('fin'), fn ($q) => $q->whereDate('ventes.date_vente', '<=', $request->input('fin')))
            ->when($request->filled('vendeur_id'), fn ($q) => $q->where('ventes.utilisateur_id', (int) $request->input('vendeur_id')))
            ->when($request->filled('produit'), function ($q) use ($request) {
                $terme = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($request->input('produit'))) . '%';
                $q->where(function ($w) use ($terme) {
                    $w->where('produits.nom', 'like', $terme)
                      ->orWhere('produits.reference', 'like', $terme)
                      ->orWhere('vente_details.libelle_virtuel', 'like', $terme);
                });
            })
            ->select([
                'vente_details.produit_id',
                'vente_details.libelle_virtuel',
                'produits.nom as nom_produit',
                'produits.reference as reference_produit',
                'ventes.utilisateur_id',
                'utilisateurs.nom as vendeur_nom',
                'utilisateurs.prenom as vendeur_prenom',
            ])
            ->selectRaw('SUM(vente_details.quantite) as quantite_vendue')
            ->selectRaw('AVG(vente_details.prix_unitaire) as prix_moyen')
            ->selectRaw('MIN(vente_details.prix_unitaire) as prix_minimum')
            ->selectRaw('MAX(vente_details.prix_unitaire) as prix_maximum')
            ->selectRaw('SUM(vente_details.montant_ttc) as chiffre_affaires')
            ->selectRaw('COUNT(DISTINCT ventes.id) as nombre_factures')
            ->groupBy([
                'vente_details.produit_id',
                'vente_details.libelle_virtuel',
                'produits.nom',
                'produits.reference',
                'ventes.utilisateur_id',
                'utilisateurs.nom',
                'utilisateurs.prenom',
            ])
            ->orderBy('produits.nom')
            ->orderBy('utilisateurs.nom')
            ->paginate(50)
            ->withQueryString();

        $vendeurs = \App\Modules\Authentification\Modeles\Utilisateur::where('entreprise_id', $entreprise->id)
            ->whereIn('id', function ($q) use ($pdvIds) {
                $q->select('utilisateur_id')->from('ventes')->whereIn('point_de_vente_id', $pdvIds)->whereNotNull('utilisateur_id');
            })
            ->orderBy('nom')->orderBy('prenom')->get(['id', 'nom', 'prenom']);

        return view('admin::rapports.analyse_prix', compact('entreprise', 'pointsDeVente', 'lignes', 'vendeurs'));
    }

    public function analyseActivite(Request $request)
    {
        $utilisateur = Auth::user();
        $entreprise  = $utilisateur->entreprise;
        $periodeId   = session('active_periode_id');

        // ─── Liste des points de vente de l'entreprise (via cache Section 18.4) ─────────────────────
        $pointsDeVente = CacheService::pointsDeVente($entreprise->id);
        // Un membre affecté ne voit que l'activité de son site (audit du 23/11/2026).
        if ($site = \App\Modules\Admin\Services\SiteAutorise::siteImpose()) {
            $pointsDeVente = $pointsDeVente->where('id', $site)->values();
        } elseif ($request->filled('site') && $pointsDeVente->contains('id', (int) $request->query('site'))) {
            // Le rapport d'un seul site (23/11/2026), choisi parmi ceux de l'entreprise.
            $pointsDeVente = $pointsDeVente->where('id', (int) $request->query('site'))->values();
        }
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

    /**
     * Le rapport d'activité en PDF (23/11/2026) : les données de l'écran,
     * rendues par la vue de l'écran puis mises en page pour l'impression.
     */
    public function exporterActivite(Request $request, \App\Modules\Admin\Services\DocumentPdfService $pdf): \Symfony\Component\HttpFoundation\Response
    {
        $donnees = $this->analyseActivite($request)->getData();
        $entreprise = $donnees['entreprise'];
        $pdvIds = $donnees['pointsDeVente']->pluck('id');
        $unSeulSite = $request->filled('site') || \App\Modules\Admin\Services\SiteAutorise::siteImpose();

        $periode = session('active_periode_id')
            ? \App\Modules\Admin\Modeles\Periode::find(session('active_periode_id'))
            : null;
        $donnees['libellePeriode'] = $periode ? 'Période : ' . $periode->nom : 'Toutes périodes';
        $donnees['titreSite'] = $unSeulSite ? 'Site : ' . $donnees['pointsDeVente']->pluck('nom')->implode(', ') : null;

        // ── Ce que l'écran ne montre pas, et qu'un rapport complet doit dire ──

        // Le résultat comptable par site (écran « Résultat par site »).
        [$debut, $fin] = \App\Modules\Admin\Services\FiltrePeriodeService::intervalle($request);
        $analytique = \App\Modules\Admin\Services\AnalytiqueService::parSite($entreprise->id, $debut?->toDateString(), $fin?->toDateString());
        $analytique['sites'] = collect($analytique['sites'])->filter(fn ($l) => $l['id'] === null ? !$unSeulSite : $pdvIds->contains($l['id']))->values()->all();
        $donnees['analytique'] = $analytique;

        // Le stock valorisé au CUMP (Coût Unitaire Moyen Pondéré), par site.
        $donnees['stockParSite'] = $donnees['pointsDeVente']->map(function ($site) {
            $fiches = Stock::where('point_de_vente_id', $site->id)->whereHas('produit', fn ($q) => $q->stockables());
            $avecReference = \Illuminate\Support\Facades\Schema::hasColumn('stocks', 'reference');

            return [
                'nom'      => $site->nom,
                'articles' => (clone $fiches)->when($avecReference, fn ($q) => $q->where('reference', true))->count(),
                'valeur'   => (float) (clone $fiches)->sum(DB::raw('quantite_disponible * COALESCE(cump, 0)')),
                'ruptures' => (clone $fiches)->when($avecReference, fn ($q) => $q->where('reference', true))->where('quantite_disponible', '<=', 0)->count(),
                'alertes'  => (clone $fiches)->when($avecReference, fn ($q) => $q->where('reference', true))->whereColumn('quantite_disponible', '<=', 'stock_minimum')->count(),
            ];
        })->values();

        // Ce que les clients doivent, ce que l'entreprise doit (pièces à crédit
        // ou en avance, moins ce que la trésorerie a déjà encaissé ou payé).
        $reste = function ($pieces, string $colonne) {
            $regles = TresorerieJournal::whereIn('reference_document', $pieces->pluck('numero_facture')->filter())
                ->groupBy('reference_document')->select('reference_document', DB::raw("SUM({$colonne}) as total"))
                ->pluck('total', 'reference_document');

            return round($pieces->sum(fn ($p) => max(0, (float) $p->montant_ttc - (float) ($regles[$p->numero_facture] ?? 0))), 2);
        };
        $donnees['creancesClients'] = $reste(Vente::whereIn('point_de_vente_id', $pdvIds)->where('etape', 'Facture')
            ->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))
            ->whereIn('statut', ['Crédit', 'Avance'])->get(['numero_facture', 'montant_ttc']), 'montant_entree');
        $donnees['dettesFournisseurs'] = $reste(Achat::whereIn('point_de_vente_id', $pdvIds)->where('etape', 'Facture')
            ->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))
            ->whereIn('statut', ['Crédit', 'Avance'])->get(['numero_facture', 'montant_ttc']), 'montant_sortie');

        // La TVA, avoirs déduits.
        $net = fn ($requete, string $col) => (float) (clone $requete)->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))->sum($col)
            - (float) (clone $requete)->where('type_facture', 'avoir')->sum($col);
        $donnees['tvaCollectee'] = $net(Vente::whereIn('point_de_vente_id', $pdvIds)->where('etape', 'Facture'), 'montant_tva');
        $donnees['tvaDeductible'] = $net(Achat::whereIn('point_de_vente_id', $pdvIds)->where('etape', 'Facture'), 'montant_tva');

        // La normalisation FNE des factures de vente.
        $factures = Vente::whereIn('point_de_vente_id', $pdvIds)->where('etape', 'Facture')
            ->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))
            ->where(fn ($q) => $q->whereNull('statut')->orWhere('statut', '!=', 'Annulée'));
        $donnees['fne'] = [
            'normalisees'        => (clone $factures)->where('normalise', true)->count(),
            'montantNormalise'   => (float) (clone $factures)->where('normalise', true)->sum('montant_ttc'),
            'nonNormalisees'     => (clone $factures)->where('normalise', false)->count(),
            'montantNonNormalise'=> (float) (clone $factures)->where('normalise', false)->sum('montant_ttc'),
        ];

        $nom = 'rapport-activite-' . \Illuminate\Support\Str::slug($entreprise->nom)
            . ($unSeulSite ? '-' . \Illuminate\Support\Str::slug($donnees['pointsDeVente']->pluck('nom')->implode('-')) : '')
            . '-' . now()->format('Ymd') . '.pdf';

        return response($pdf->rapportActivite($donnees), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $nom . '"',
        ]);
    }
}
