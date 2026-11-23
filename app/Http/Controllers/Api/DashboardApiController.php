<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\SoldeInitial;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Les indicateurs que le FlowHub affiche pour une entreprise.
 *
 * **Le Hub doit annoncer les mêmes montants que Selflow.** Cette API comptait
 * à sa façon : devis et bons de commande dans le chiffre d'affaires, avoirs
 * additionnés au lieu d'être retranchés, reçus comptés deux fois avec leur
 * facture, et un statut « Payée » qui n'existe pas — toutes les factures
 * s'affichaient impayées. Les montants suivent désormais les règles du tableau
 * de bord général (`AdminControleur::tableauDeBordGeneral`) et de la page
 * « Créances & Règlements » (`ComptabiliteControleur::creances`).
 *
 * Chaque bloc se calcule à part : si l'un échoue — une base pas encore migrée
 * à qui manque une colonne récente, par exemple —, il est signalé dans
 * `avertissements` et le Hub reçoit quand même tous les autres.
 */
class DashboardApiController extends Controller
{
    private const STATUTS_A_REGLER = ['Crédit', 'Avance'];

    /** @var string[] */
    private array $avertissements = [];

    /**
     * Les années qui portent des ventes, de la plus récente à la plus ancienne.
     */
    public function getExercices(Request $request)
    {
        $pdvIds = $this->pointsDeVente($request);
        $anneeCourante = (int) date('Y');

        $premiereVente = $this->bloc('exercices', fn () => Vente::whereIn('point_de_vente_id', $pdvIds)->min('date_vente'));
        $depuis = $premiereVente ? (int) Carbon::parse($premiereVente)->year : $anneeCourante - 1;
        // Une date saisie de travers (an 1900) ne doit pas dérouler un siècle d'exercices.
        $depuis = max(min($depuis, $anneeCourante), $anneeCourante - 10);

        return response()->json([
            'status' => 'success',
            'data'   => array_map(
                fn ($annee) => ['id' => (string) $annee, 'name' => (string) $annee],
                range($anneeCourante, $depuis)
            ),
        ]);
    }

    public function getKpis(Request $request)
    {
        $companyId = $request->query('company_id') ?? $request->header('X-Company-Id');
        $pdvIds    = $this->pointsDeVente($request);
        [$debut, $fin, $libelle] = $this->periode($request);
        // Des dates seules : `date_vente` et `date_achat` sont des DATE, et une
        // borne « 00:00:00 » écarterait le premier jour sous SQLite.
        $bornes = [$debut->toDateString(), $fin->toDateString() . ' 23:59:59'];

        $entreprise = $companyId
            ? $this->bloc('entreprise', fn () => Entreprise::select('id', 'nom', 'regime_imposition')->find($companyId))
            : null;

        // Les factures de la période, comme au tableau de bord général : ni
        // devis ni bon de commande, et un reçu remplacé par sa facture ne
        // compte qu'une fois.
        $ventes = fn () => Vente::sansDoublonRecu()
            ->whereIn('point_de_vente_id', $pdvIds)
            ->where('etape', 'Facture')
            ->whereBetween('date_vente', $bornes);
        $achats = fn () => Achat::whereIn('point_de_vente_id', $pdvIds)
            ->where('etape', 'Facture')
            ->whereBetween('date_achat', $bornes);

        $activite = $this->bloc('activite', function () use ($ventes, $achats) {
            $ventesTtc = self::netDesAvoirs($ventes(), 'montant_ttc');
            $ventesHt  = self::netDesAvoirs($ventes(), 'montant_ht');
            $achatsTtc = self::netDesAvoirs($achats(), 'montant_ttc');
            $achatsHt  = self::netDesAvoirs($achats(), 'montant_ht');
            $nbVentes  = $ventes()->where('type_facture', '!=', 'avoir')->count();
            // La marge se compte hors taxes des deux côtés : la TVA supportée à
            // l'achat est récupérable, ce n'est pas une charge (lot 35.3).
            $marge = $ventesHt - $achatsHt;

            return [
                'ventes_ttc'   => $ventesTtc,
                'ventes_ht'    => $ventesHt,
                'nb_ventes'    => $nbVentes,
                'panier_moyen' => $nbVentes > 0 ? round($ventesTtc / $nbVentes, 2) : 0,
                'achats_ttc'   => $achatsTtc,
                'achats_ht'    => $achatsHt,
                'marge_brute'  => $marge,
                'taux_marge'   => $ventesHt > 0 ? round($marge / $ventesHt * 100, 1) : 0,
            ];
        });

        $fiscalite = $this->bloc('fiscalite', function () use ($ventes, $achats) {
            $collectee  = self::netDesAvoirs($ventes(), 'montant_tva');
            $deductible = self::netDesAvoirs($achats(), 'montant_tva');

            return [
                'tva_collectee'           => $collectee,
                'tva_deductible'          => $deductible,
                'tva_nette'               => $collectee - $deductible,
                'autres_taxes_collectees' => self::netDesAvoirs($ventes(), 'montant_autres_taxes'),
            ];
        });

        $tresorerie = $this->bloc('tresorerie', function () use ($pdvIds, $bornes) {
            $periode = TresorerieJournal::whereIn('point_de_vente_id', $pdvIds)->whereBetween('date_operation', $bornes);
            $encaissements = (float) (clone $periode)->sum('montant_entree');
            $decaissements = (float) (clone $periode)->sum('montant_sortie');

            return [
                'encaissements' => $encaissements,
                'decaissements' => $decaissements,
                'flux_net'      => $encaissements - $decaissements,
                // Ce qu'il y a en caisse et en banque à la fin de la période.
                'solde_a_date'  => (float) TresorerieJournal::whereIn('point_de_vente_id', $pdvIds)
                    ->where('date_operation', '<=', $bornes[1])
                    ->sum(DB::raw('montant_entree - montant_sortie')),
            ];
        });

        $creances = $this->bloc('creances_clients', fn () => $this->restesARegler(
            Vente::with('client')
                ->whereIn('point_de_vente_id', $pdvIds)
                ->where('etape', 'Facture')
                ->where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))
                ->whereIn('statut', self::STATUTS_A_REGLER)
                ->get(),
            'montant_entree',
            // Le dû comprend les taxes parafiscales et le timbre, que le client
            // règle aussi — comme à la page « Créances & Règlements ».
            fn (Vente $v) => (float) $v->montant_ttc + (float) $v->montant_autres_taxes + (float) $v->timbre_quittance,
            fn (Vente $v) => $v->client?->nom ?? 'Client de passage',
            $companyId ? $this->soldesDeDepart((int) $companyId, 'client') : collect()
        ));

        $dettes = $this->bloc('dettes_fournisseurs', fn () => $this->restesARegler(
            Achat::with('fournisseur')
                ->whereIn('point_de_vente_id', $pdvIds)
                ->where('etape', 'Facture')
                ->whereIn('statut', self::STATUTS_A_REGLER)
                ->get(),
            'montant_sortie',
            fn (Achat $a) => (float) $a->montant_ttc,
            fn (Achat $a) => $a->fournisseur?->nom ?? 'Inconnu',
            $companyId ? $this->soldesDeDepart((int) $companyId, 'fournisseur') : collect()
        ));

        $stock = $this->bloc('stock', fn () => $this->stock($companyId, $pdvIds));

        // Les pièces retenues pour les classements : les factures de la période,
        // sans les avoirs — un montant négatif n'a pas de sens dans un palmarès.
        $idsVentes = $this->bloc('classements', fn () => $ventes()->where('type_facture', '!=', 'avoir')->pluck('id'), collect());

        $topProduits = $this->bloc('top_produits', fn () => DB::table('vente_details')
            ->leftJoin('produits', 'produits.id', '=', 'vente_details.produit_id')
            ->whereIn('vente_details.vente_id', $idsVentes)
            ->select(
                DB::raw('COALESCE(produits.nom, vente_details.libelle_virtuel) as nom'),
                DB::raw('SUM(vente_details.quantite) as quantite'),
                DB::raw('SUM(vente_details.montant_ttc) as ca')
            )
            ->groupBy('produits.nom', 'vente_details.libelle_virtuel')
            ->orderByDesc('ca')
            ->limit(5)
            ->get()
            ->map(fn ($l) => ['nom' => $l->nom, 'quantite' => (float) $l->quantite, 'ca' => (float) $l->ca]), collect());

        $caParSite = $this->bloc('ca_par_point_de_vente', fn () => DB::table('ventes')
            ->join('points_de_vente', 'points_de_vente.id', '=', 'ventes.point_de_vente_id')
            ->whereIn('ventes.id', $idsVentes)
            ->select('points_de_vente.nom', DB::raw('SUM(ventes.montant_ttc) as ca'), DB::raw('COUNT(*) as nb'))
            ->groupBy('ventes.point_de_vente_id', 'points_de_vente.nom')
            ->orderByDesc('ca')
            ->get()
            ->map(fn ($l) => ['nom' => $l->nom, 'ca' => (float) $l->ca, 'nb' => (int) $l->nb]), collect());

        $topVendeurs = $this->bloc('top_vendeurs', fn () => DB::table('ventes')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'ventes.utilisateur_id')
            ->whereIn('ventes.id', $idsVentes)
            ->select('utilisateurs.prenom', 'utilisateurs.nom', DB::raw('SUM(ventes.montant_ttc) as total'), DB::raw('COUNT(*) as nb'))
            ->groupBy('ventes.utilisateur_id', 'utilisateurs.prenom', 'utilisateurs.nom')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            // Le nom se compose en PHP : `CONCAT()` n'existe pas dans les
            // SQLite antérieurs à 3.44, où tournent les épreuves.
            ->map(fn ($l) => ['nom' => trim(($l->prenom ?? '') . ' ' . ($l->nom ?? '')), 'total' => (float) $l->total, 'nb_ventes' => (int) $l->nb]), collect());

        $dernieresFactures = $this->bloc('dernieres_factures', fn () => $ventes()
            ->with('client')
            ->orderByDesc('date_vente')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (Vente $v) => [
                'ref'     => $v->numero_facture,
                'date'    => $v->date_vente?->toDateString(),
                'client'  => $v->client?->nom ?? 'Client de passage',
                'montant' => (float) $v->montant_ttc,
                'statut'  => $v->type_facture === 'avoir' ? 'avoir'
                    : (in_array($v->statut, self::STATUTS_A_REGLER, true) ? 'impayee' : 'payee'),
            ]), collect());

        // La courbe couvre toujours l'exercice entier, même quand un mois est
        // choisi : elle sert à situer ce mois dans l'année.
        $graphique = $this->bloc('graphique', fn () => $this->parMois((int) $debut->year, $pdvIds));

        return response()->json([
            'status' => 'success',
            'data'   => [
                'company_name'      => $entreprise->nom ?? null,
                'regime_imposition' => $entreprise->regime_imposition ?? null,
                'periode_label'     => $libelle,
                'periode'           => ['debut' => $debut->toDateString(), 'fin' => $fin->toDateString()],

                // Les clés que lisent les versions du Hub antérieures à celle-ci.
                'total_ventes'            => $activite['ventes_ttc'] ?? 0,
                'total_achats'            => $activite['achats_ttc'] ?? 0,
                'factures_impayees'       => $creances['total'] ?? 0,
                'produits_stock_critique' => $stock['nb_alertes'] ?? 0,
                'top_produits'            => $topProduits,
                'dernieres_factures'      => $dernieresFactures,
                'chart_ventes'            => ['labels' => $graphique['labels'] ?? [], 'data' => $graphique['ventes'] ?? []],

                'activite'              => $activite,
                'fiscalite'             => $fiscalite,
                'tresorerie'            => $tresorerie,
                'creances_clients'      => $creances,
                'dettes_fournisseurs'   => $dettes,
                'stock'                 => $stock,
                'ca_par_point_de_vente' => $caParSite,
                'top_vendeurs'          => $topVendeurs,
                'chart_achats'          => ['labels' => $graphique['labels'] ?? [], 'data' => $graphique['achats'] ?? []],
            ],
            'avertissements' => $this->avertissements,
        ]);
    }

    public function getCompanies(Request $request)
    {
        $entreprises = Entreprise::select('id', 'nom')
            ->orderBy('nom')
            ->get()
            ->map(function ($e) {
                return [
                    'id' => $e->id,
                    'name' => $e->nom, // on renomme "nom" -> "name" pour matcher le format attendu par le Hub
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $entreprises,
        ]);
    }

    /**
     * Calcule un bloc d'indicateurs sans faire échouer les autres.
     */
    private function bloc(string $nom, callable $calcul, $defaut = null)
    {
        try {
            return $calcul();
        } catch (\Throwable $e) {
            Log::warning("[Hub] indicateur SellFlow « {$nom} » indisponible : " . $e->getMessage());
            $this->avertissements[] = "{$nom} : " . Str::limit($e->getMessage(), 160);

            return $defaut;
        }
    }

    /** Les points de vente de l'entreprise demandée ; tous, sans entreprise précisée. */
    private function pointsDeVente(Request $request): Collection
    {
        $companyId = $request->query('company_id') ?? $request->header('X-Company-Id');

        return PointDeVente::query()
            ->when($companyId, fn ($q) => $q->where('entreprise_id', $companyId))
            ->pluck('id');
    }

    /**
     * [début, fin, libellé] de la période demandée : l'exercice (une année
     * civile) entier, ou l'un de ses mois.
     *
     * Le Hub envoie `month=3` avec un exercice et `month=2026-03` sans
     * exercice, comme à RHFlow : les deux formes sont acceptées.
     */
    private function periode(Request $request): array
    {
        $annee = (int) ($request->query('exercice_id') ?: date('Y'));
        $mois  = (string) $request->query('month', '');

        if (str_contains($mois, '-')) {
            [$annee, $mois] = array_map('intval', explode('-', $mois, 2));
        }
        $mois = (int) $mois;

        if ($mois >= 1 && $mois <= 12) {
            $debut = Carbon::create($annee, $mois, 1)->startOfMonth();

            return [$debut, $debut->copy()->endOfMonth(), Str::ucfirst($debut->translatedFormat('F Y'))];
        }

        $debut = Carbon::create($annee, 1, 1)->startOfYear();

        return [$debut, $debut->copy()->endOfYear(), "Exercice {$annee}"];
    }

    /**
     * Somme d'une colonne, avoirs déduits — la règle de `AdminControleur`.
     */
    private static function netDesAvoirs($requete, string $colonne): float
    {
        return (float) (clone $requete)->where('type_facture', '!=', 'avoir')->sum($colonne)
             - (float) (clone $requete)->where('type_facture', 'avoir')->sum($colonne);
    }

    /**
     * Ce qui reste à régler sur des pièces, d'après la trésorerie, soldes de
     * départ compris : total, nombre de pièces et les cinq plus gros tiers.
     */
    private function restesARegler(Collection $pieces, string $colonneReglement, callable $du, callable $tiers, Collection $soldesDeDepart): array
    {
        $regles = TresorerieJournal::whereIn('reference_document', $pieces->pluck('numero_facture')->filter())
            ->groupBy('reference_document')
            ->select('reference_document', DB::raw("SUM({$colonneReglement}) as total"))
            ->pluck('total', 'reference_document');

        $parTiers = [];
        $nb = 0;
        foreach ($pieces as $piece) {
            $reste = round($du($piece) - (float) ($regles[$piece->numero_facture] ?? 0), 2);
            // Moins d'un demi-franc : un arrondi, pas une dette.
            if ($reste <= 0.5) {
                continue;
            }
            $nb++;
            $parTiers[$tiers($piece)] = ($parTiers[$tiers($piece)] ?? 0) + $reste;
        }

        foreach ($soldesDeDepart as $nom => $reste) {
            $nb++;
            $parTiers[$nom] = ($parTiers[$nom] ?? 0) + $reste;
        }

        arsort($parTiers);

        return [
            'total'      => round(array_sum($parTiers), 2),
            'nb_pieces'  => $nb,
            'principaux' => collect($parTiers)->take(5)
                ->map(fn ($montant, $nom) => ['nom' => $nom, 'montant' => round($montant, 2)])
                ->values(),
        ];
    }

    /**
     * Les soldes de départ encore dus, par tiers (`$type` : 'client' ou
     * 'fournisseur', les valeurs de `SoldeInitial::CLIENT` et `::FOURNISSEUR`).
     *
     * Le modèle et sa table sont récents (19/10/2026) : un serveur qui ne les a
     * pas encore n'a simplement pas de solde de départ. Les constantes ne sont
     * pas lues ici pour la même raison — sans la classe, les lire échouerait.
     */
    private function soldesDeDepart(int $entrepriseId, string $type): Collection
    {
        if (!class_exists(SoldeInitial::class) || !Schema::hasTable('soldes_initiaux')) {
            return collect();
        }

        return SoldeInitial::with(['client', 'fournisseur'])
            ->where('entreprise_id', $entrepriseId)
            ->where('type', $type)
            ->get()
            ->map(fn (SoldeInitial $s) => ['nom' => $s->nomDuTiers(), 'reste' => $s->reste()])
            ->filter(fn ($s) => $s['reste'] > 0.5)
            ->groupBy('nom')
            ->map(fn ($lot) => $lot->sum('reste'));
    }

    /**
     * Les articles sous leur seuil, comptés sur la requête entière — le
     * compteur ne plafonne pas à la longueur de la liste (lot 35.2) —, et la
     * valeur du stock au coût moyen.
     */
    private function stock($companyId, Collection $pdvIds): array
    {
        // `stocks.reference` (20/10/2026) : un article non référencé sur un
        // site n'y est pas en rupture.
        $parReference = Schema::hasColumn('stocks', 'reference');

        $alertes = Produit::query()
            ->when($companyId, fn ($q) => $q->where('entreprise_id', $companyId))
            ->stockables()
            ->selectionnables()
            ->whereHas('stocks', function ($q) use ($pdvIds, $parReference) {
                $q->whereIn('point_de_vente_id', $pdvIds)
                  ->whereRaw('quantite_disponible <= stock_minimum');
                if ($parReference) {
                    $q->where('stocks.reference', true);
                }
            });

        $nbAlertes = (clone $alertes)->count();

        $liste = $alertes
            ->with(['stocks' => fn ($q) => $q->whereIn('point_de_vente_id', $pdvIds)])
            ->orderBy('nom')
            ->limit(8)
            ->get()
            ->map(fn (Produit $p) => [
                'nom'      => $p->nom,
                'quantite' => (float) $p->stocks->sum('quantite_disponible'),
                'minimum'  => (float) $p->stocks->sum('stock_minimum'),
            ]);

        $valeur = Schema::hasColumn('stocks', 'cump')
            ? (float) Stock::whereIn('point_de_vente_id', $pdvIds)
                ->whereHas('produit', fn ($q) => $q->stockables())
                ->sum(DB::raw('quantite_disponible * COALESCE(cump, 0)'))
            : null;

        return ['nb_alertes' => $nbAlertes, 'alertes' => $liste, 'valeur' => $valeur];
    }

    /**
     * Ventes et achats de chaque mois de l'exercice, avoirs déduits.
     */
    private function parMois(int $annee, Collection $pdvIds): array
    {
        $bornes = ["{$annee}-01-01", "{$annee}-12-31 23:59:59"];
        // `SUBSTR` existe sous MySQL comme sous SQLite, où tournent les épreuves.
        $totaux = fn ($requete, string $colonneDate) => $requete
            ->whereBetween($colonneDate, $bornes)
            ->selectRaw("SUBSTR({$colonneDate}, 1, 7) as mois")
            ->selectRaw("SUM(CASE WHEN type_facture = 'avoir' THEN -montant_ttc ELSE montant_ttc END) as total")
            ->groupBy('mois')
            ->toBase()
            ->pluck('total', 'mois');

        $ventes = $totaux(Vente::sansDoublonRecu()->whereIn('point_de_vente_id', $pdvIds)->where('etape', 'Facture'), 'date_vente');
        $achats = $totaux(Achat::whereIn('point_de_vente_id', $pdvIds)->where('etape', 'Facture'), 'date_achat');

        $resultat = ['labels' => [], 'ventes' => [], 'achats' => []];
        for ($m = 1; $m <= 12; $m++) {
            $cle = sprintf('%d-%02d', $annee, $m);
            $resultat['labels'][] = Carbon::create($annee, $m, 1)->translatedFormat('M');
            $resultat['ventes'][] = (float) ($ventes[$cle] ?? 0);
            $resultat['achats'][] = (float) ($achats[$cle] ?? 0);
        }

        return $resultat;
    }
}
