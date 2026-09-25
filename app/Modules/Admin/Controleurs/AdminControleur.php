<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\FiltrePeriodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;


class AdminControleur
{
    /**
     * Somme d'une colonne de montant, avoirs deduits.
     *
     * Un avoir annule tout ou partie d'une piece : son montant se retranche du
     * total. Les tableaux de bord l'additionnaient, si bien qu'annuler une
     * vente faisait monter le chiffre d'affaires. Les ecrans FNE, eux, le
     * deduisaient deja — d'ou des montants differents d'une page a l'autre.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    private static function montantNetDesAvoirs($query, string $colonne = 'montant_ttc'): float
    {
        return (float) (clone $query)->where('type_facture', '!=', 'avoir')->sum($colonne)
             - (float) (clone $query)->where('type_facture', 'avoir')->sum($colonne);
    }

    /**
     * Afficher le tableau de bord de l'administrateur (activité personnelle).
     */
    public function tableauDeBord(Request $request): View
    {
        $utilisateur    = Auth::user();
        $entreprise     = $utilisateur->entreprise;
        $pointDeVenteId = session('point_de_vente_actif_id');
        $aujourd_hui    = now()->toDateString();

        // ── Ventes personnelles ──────────────────────────────────────
        // `sansDoublonRecu` ecarte les recus deja remplaces par leur facture :
        // sans cela, le meme chiffre d'affaires serait compte deux fois.
        $qVentes = Vente::sansDoublonRecu()->where('utilisateur_id', $utilisateur->id)->where('etape', 'Facture');
        // ── Achats personnels ─────────────────────────────────────────
        $qAchats = Achat::where('utilisateur_id', $utilisateur->id)->where('etape', 'Facture');

        // Un seul resolveur pour tous les tableaux de bord, FNE compris : voir
        // FiltrePeriodeService. Chaque ecran resolvait sa periode a sa maniere,
        // et deux pages ouvertes cote a cote annonçaient des chiffres
        // differents pour ce que l'utilisateur croyait etre le meme perimetre.
        FiltrePeriodeService::appliquer($qVentes, 'date_vente', $request);
        FiltrePeriodeService::appliquer($qAchats, 'date_achat', $request);
        $periodeLabel = FiltrePeriodeService::libelle($request);

        if ($pointDeVenteId) {
            $qVentes->where('point_de_vente_id', $pointDeVenteId);
            $qAchats->where('point_de_vente_id', $pointDeVenteId);
        }

        $montantVentesJour = self::montantNetDesAvoirs($qVentes);
        $nbVentesJour      = (clone $qVentes)->where('type_facture', '!=', 'avoir')->count();
        $montantAchatsJour = self::montantNetDesAvoirs($qAchats);

        // ── Ventes de la période (via PeriodeScope) ───────────────────────────
        $qVentesPeriode = Vente::sansDoublonRecu()
            ->where('utilisateur_id', $utilisateur->id)
            ->where('etape', 'Facture');
        if ($pointDeVenteId) $qVentesPeriode->where('point_de_vente_id', $pointDeVenteId);
        $totalVentesPeriode = self::montantNetDesAvoirs($qVentesPeriode);
        $nbVentesPeriode    = (clone $qVentesPeriode)->where('type_facture', '!=', 'avoir')->count();

        // ── Solde trésorerie personnel ────────────────────────────────────────
        $qTreso = TresorerieJournal::where('utilisateur_id', $utilisateur->id);
        if ($pointDeVenteId) $qTreso->where('point_de_vente_id', $pointDeVenteId);
        $solde = $qTreso->sum(DB::raw('montant_entree - montant_sortie'));

        // ── Alertes stock ─────────────────────────────────────────────────────
        // `stockables()` : une fiche de stock peut exister pour un article qui
        // n'en gere pas — l'ecran de creation en pose une pour tout le monde.
        // Sans ce filtre, un service traine dans les alertes de rupture.
        // Un article archivé ne se réapprovisionne pas : le porter en rupture
        // pousserait à commander ce qu'on a décidé de ne plus vendre.
        /*
         * Les articles sous leur seuil.
         *
         * **Le compte et la liste sont deux choses.** Le tableau n'en montre
         * que huit — c'est un tableau de bord, pas un inventaire — mais le
         * compteur affichait `count()` sur cette liste tronquée : trente
         * articles en rupture s'annonçaient « 8 alertes stock », et le chiffre
         * ne montait jamais au-delà. Un indicateur qui plafonne est pire que
         * pas d'indicateur : il rassure.
         */
        $requeteAlertes = Produit::where('entreprise_id', $entreprise->id)
            ->stockables()
            ->selectionnables()
            ->whereHas('stocks', function($q) use ($pointDeVenteId) {
                if ($pointDeVenteId) {
                    $q->where('point_de_vente_id', $pointDeVenteId);
                }
                $q->whereRaw('quantite_disponible <= stock_minimum');
            });

        $nbProduitsEnAlerte = (clone $requeteAlertes)->count();

        $produitsEnAlerte = $requeteAlertes->with(['stocks'])->limit(8)->get();

        // ── Dernières ventes personnelles ─────────────────────────────────────
        $qLast = Vente::with(['client', 'pointDeVente'])
            ->where('utilisateur_id', $utilisateur->id);
        if ($pointDeVenteId) $qLast->where('point_de_vente_id', $pointDeVenteId);
        $dernieresVentes = $qLast->latest()->limit(8)->get();

        // ── Évolution 7 derniers jours (ventes personnelles - sans PeriodeScope) ──
        $evolution7j = DB::table('ventes')
            /*
             * Un avoir se retranche de la courbe, comme il se retranche du
             * chiffre d'affaires.
             *
             * `SUM(montant_ttc)` l'additionnait, et `COUNT(*)` le comptait pour
             * une vente : annuler une facture faisait **monter** la courbe du
             * jour. Les cartes du haut avaient ete corrigees ; la courbe juste
             * en dessous disait encore le contraire, et les deux se
             * contredisaient sur le meme ecran.
             */
            ->select(
                DB::raw("DATE(date_vente) as jour"),
                DB::raw("SUM(CASE WHEN type_facture = 'avoir' THEN -montant_ttc ELSE montant_ttc END) as total"),
                DB::raw("SUM(CASE WHEN type_facture = 'avoir' THEN 0 ELSE 1 END) as nb")
            )
            ->where('utilisateur_id', $utilisateur->id)
            ->where('etape', 'Facture')
            ->when($pointDeVenteId, fn($q) => $q->where('point_de_vente_id', $pointDeVenteId))
            ->whereBetween('date_vente', [now()->subDays(6)->toDateString(), $aujourd_hui])
            ->groupBy('jour')
            ->orderBy('jour')
            ->get();

        // Remplir les jours manquants
        $jours7 = collect();
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $found = $evolution7j->firstWhere('jour', $date);
            $jours7->push(['jour' => $date, 'total' => $found ? $found->total : 0, 'nb' => $found ? $found->nb : 0]);
        }

        // ── Meilleur produit vendu sur la période ─────────────────────────────
        $meilleurProduit = DB::table('vente_details')
            ->join('ventes', 'ventes.id', '=', 'vente_details.vente_id')
            ->select('vente_details.libelle_virtuel', 'vente_details.produit_id',
                DB::raw('SUM(vente_details.montant_ttc) as ca'),
                DB::raw('SUM(vente_details.quantite) as qte'))
            ->where('ventes.utilisateur_id', $utilisateur->id)
            ->where('ventes.etape', 'Facture')
            ->when($pointDeVenteId, fn($q) => $q->where('ventes.point_de_vente_id', $pointDeVenteId))
            ->groupBy('vente_details.libelle_virtuel', 'vente_details.produit_id')
            ->orderByDesc('ca')
            ->first();

        return view('admin::tableau_de_bord', compact(
            'entreprise', 'montantVentesJour', 'montantAchatsJour',
            'nbVentesJour', 'totalVentesPeriode', 'nbVentesPeriode',
            'produitsEnAlerte', 'nbProduitsEnAlerte', 'solde', 'dernieresVentes',
            'pointDeVenteId', 'jours7', 'meilleurProduit', 'periodeLabel'
        ));
    }

    /**
     * Afficher le tableau de bord général de l'entreprise.
     */
    public function tableauDeBordGeneral(Request $request): View
    {
        $utilisateur = Auth::user();
        $entreprise  = $utilisateur->entreprise;
        $aujourd_hui = now()->toDateString();

        $pdvIds = $entreprise->pointsDeVente()->pluck('id');

        // Cet ecran couvre l'entreprise entiere : le site vient du filtre, et
        // non du point de vente actif de la session. Il en heritait sans le
        // dire, si bien qu'un « tableau de bord general » annoncant trois
        // points de vente n'en totalisait qu'un — et contredisait la Situation
        // Generale, qui part de « Tous les sites ».
        $pdvFiltre      = $request->query('pdv_id', 'tous');
        $pointDeVenteId = ($pdvFiltre && $pdvFiltre !== 'tous') ? (int) $pdvFiltre : null;

        // ── CA global ─────────────────────────────────────────────────
        // `sansDoublonRecu` ecarte les recus deja remplaces par leur facture, et
        // `etape = Facture` ecarte devis et bons de commande : sans ces deux
        // conditions, un devis en attente et un recu deja converti gonflaient le
        // chiffre d'affaires. Les ecrans FNE les appliquent depuis toujours ;
        // les deux pages annoncaient donc des montants differents.
        $qVentes = Vente::sansDoublonRecu()->whereIn('point_de_vente_id', $pdvIds)->where('etape', 'Facture');
        // ── Achats globaux ────────────────────────────────────────────
        $qAchats = Achat::whereIn('point_de_vente_id', $pdvIds)->where('etape', 'Facture');

        // Meme resolveur que le tableau de bord personnel et que les ecrans FNE.
        FiltrePeriodeService::appliquer($qVentes, 'date_vente', $request);
        FiltrePeriodeService::appliquer($qAchats, 'date_achat', $request);
        $periodeLabel = FiltrePeriodeService::libelle($request);

        if ($pointDeVenteId) {
            $qVentes->where('point_de_vente_id', $pointDeVenteId);
            $qAchats->where('point_de_vente_id', $pointDeVenteId);
        }

        // Un avoir annule tout ou partie d'une facture : il se retranche du
        // chiffre d'affaires. Il etait additionne, si bien qu'annuler une vente
        // faisait monter le CA au lieu de le faire baisser.
        $montantVentesJour = self::montantNetDesAvoirs($qVentes);
        $nbVentesJour      = (clone $qVentes)->where('type_facture', '!=', 'avoir')->count();
        $montantAchatsJour = self::montantNetDesAvoirs($qAchats);

        // ── Marge brute et panier moyen, sur la periode retenue ───────────────
        //
        // Ces indicateurs ignoraient le filtre : ils portaient toujours sur
        // l'exercice entier, si bien que choisir un mois laissait le second
        // bandeau immobile a cote d'un premier qui, lui, bougeait. Ils suivent
        // desormais le meme perimetre que les cartes de chiffre d'affaires,
        // dont ils reprennent directement les totaux.
        $totalVentesPeriode = $montantVentesJour;
        $nbVentesPeriode    = $nbVentesJour;
        $totalAchatsPeriode = $montantAchatsJour;

        $totalVentesHTPeriode = self::montantNetDesAvoirs($qVentes, 'montant_ht');

        /*
         * La marge se compte hors taxes des deux côtés.
         *
         * Elle retranchait des ventes **HT** des achats **TTC** : la TVA
         * supportée sur les achats venait donc en diminution de la marge, alors
         * qu'elle est récupérable et n'est pas une charge. Sur des achats
         * à 18 %, la marge était sous-estimée de 18 % du montant acheté, et le
         * taux avec elle — un commerce qui gagne de l'argent pouvait s'afficher
         * à perte.
         */
        $totalAchatsHTPeriode = self::montantNetDesAvoirs($qAchats, 'montant_ht');
        $margeBrutePeriode    = $totalVentesHTPeriode - $totalAchatsHTPeriode;
        $tauxMargePeriode     = $totalVentesHTPeriode > 0
            ? round(($margeBrutePeriode / $totalVentesHTPeriode) * 100, 1)
            : 0;

        // ── Alertes stock ─────────────────────────────────────────────────────
        // `stockables()` : une fiche de stock peut exister pour un article qui
        // n'en gere pas — l'ecran de creation en pose une pour tout le monde.
        // Sans ce filtre, un service traine dans les alertes de rupture.
        // Un article archivé ne se réapprovisionne pas : le porter en rupture
        // pousserait à commander ce qu'on a décidé de ne plus vendre.
        /*
         * Les articles sous leur seuil.
         *
         * **Le compte et la liste sont deux choses.** Le tableau n'en montre
         * que huit — c'est un tableau de bord, pas un inventaire — mais le
         * compteur affichait `count()` sur cette liste tronquée : trente
         * articles en rupture s'annonçaient « 8 alertes stock », et le chiffre
         * ne montait jamais au-delà. Un indicateur qui plafonne est pire que
         * pas d'indicateur : il rassure.
         */
        $requeteAlertes = Produit::where('entreprise_id', $entreprise->id)
            ->stockables()
            ->selectionnables()
            ->whereHas('stocks', function($q) use ($pointDeVenteId) {
                if ($pointDeVenteId) {
                    $q->where('point_de_vente_id', $pointDeVenteId);
                }
                $q->whereRaw('quantite_disponible <= stock_minimum');
            });

        $nbProduitsEnAlerte = (clone $requeteAlertes)->count();

        $produitsEnAlerte = $requeteAlertes->with(['stocks'])->limit(8)->get();

        // ── Solde trésorerie global ───────────────────────────────────────────
        // Il ignorait le filtre, la ou la Situation Generale l'applique : les
        // deux ecrans annoncaient des decaissements differents.
        $qTreso = TresorerieJournal::whereIn('point_de_vente_id', $pdvIds);
        FiltrePeriodeService::appliquer($qTreso, 'date_operation', $request);
        if ($pointDeVenteId) $qTreso->where('point_de_vente_id', $pointDeVenteId);
        $totalEncaissements = $qTreso->sum('montant_entree');
        $totalDecaissements = $qTreso->sum('montant_sortie');
        $solde              = $totalEncaissements - $totalDecaissements;

        // ── Dernières ventes globales ─────────────────────────────────────────
        $qLast = Vente::with(['client', 'pointDeVente'])->whereIn('point_de_vente_id', $pdvIds);
        if ($pointDeVenteId) $qLast->where('point_de_vente_id', $pointDeVenteId);
        $dernieresVentes = $qLast->latest()->limit(8)->get();

        // ── Points de vente avec métriques du jour ────────────────────────────
        /*
         * Ce que chaque site a fait aujourd'hui.
         *
         * Le compte et la somme ignoraient les avoirs : annuler une facture
         * faisait **monter** le site de son montant, et le comptait pour une
         * vente de plus. Cinquieme endroit ou la meme faute vivait -- les
         * cartes, la courbe, le classement des vendeurs, le chiffre par site,
         * et celui-ci.
         */
        $pointsDeVente = $entreprise->pointsDeVente()
            ->withCount(['ventes as ventes_jour' => fn($q) => $q
                ->whereDate('date_vente', $aujourd_hui)
                ->where(fn($qa) => $qa->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))])
            ->withSum(['ventes as ventes_hors_avoir' => fn($q) => $q
                ->whereDate('date_vente', $aujourd_hui)
                ->where(fn($qa) => $qa->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))], 'montant_ttc')
            ->withSum(['ventes as avoirs_du_jour' => fn($q) => $q
                ->whereDate('date_vente', $aujourd_hui)
                ->where('type_facture', 'avoir')], 'montant_ttc')
            ->get()
            ->each(function ($site) {
                $site->montant_ventes_jour = (float) ($site->ventes_hors_avoir ?? 0)
                    - (float) ($site->avoirs_du_jour ?? 0);
            });

        // ── Évolution 7 derniers jours (globale - sans PeriodeScope) ──────────
        $evolution7j = DB::table('ventes')
            /*
             * Un avoir se retranche de la courbe, comme il se retranche du
             * chiffre d'affaires.
             *
             * `SUM(montant_ttc)` l'additionnait, et `COUNT(*)` le comptait pour
             * une vente : annuler une facture faisait **monter** la courbe du
             * jour. Les cartes du haut avaient ete corrigees ; la courbe juste
             * en dessous disait encore le contraire, et les deux se
             * contredisaient sur le meme ecran.
             */
            ->select(
                DB::raw("DATE(date_vente) as jour"),
                DB::raw("SUM(CASE WHEN type_facture = 'avoir' THEN -montant_ttc ELSE montant_ttc END) as total"),
                DB::raw("SUM(CASE WHEN type_facture = 'avoir' THEN 0 ELSE 1 END) as nb")
            )
            ->whereIn('point_de_vente_id', $pdvIds)
            ->where('etape', 'Facture')
            ->when($pointDeVenteId, fn($q) => $q->where('point_de_vente_id', $pointDeVenteId))
            ->whereBetween('date_vente', [now()->subDays(6)->toDateString(), $aujourd_hui])
            ->groupBy('jour')
            ->orderBy('jour')
            ->get();

        $jours7 = collect();
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $found = $evolution7j->firstWhere('jour', $date);
            $jours7->push(['jour' => $date, 'total' => $found ? $found->total : 0, 'nb' => $found ? $found->nb : 0]);
        }

        // ── Meilleurs vendeurs sur la période affichée ────────────────────────
        //    Le classement portait sur la seule journée, quel que soit le
        //    filtre choisi : il suit désormais le même périmètre que les KPI.
        //    Les avoirs sont ecartes du classement comme du camembert : un
        //    montant negatif attribue a un vendeur ou a un site n'a pas de sens
        //    dans un palmares ni dans une part de camembert.
        $idsVentesFiltrees = (clone $qVentes)->where('type_facture', '!=', 'avoir')->pluck('id');

        //    Le nom se compose en PHP, et non par `CONCAT()` en SQL : cette
        //    fonction n'existe pas dans SQLite avant la 3.44, ou tourne la suite
        //    d'epreuves. Le tableau de bord repondait donc 500 (Internal Server
        //    Error — erreur interne du serveur) sur toute machine dont le SQLite
        //    est plus ancien, et la suite passait ailleurs : une panne qui
        //    dependait de la machine, non du code. `RapportControleur` composait
        //    deja le nom de cette facon.
        $topVendeurs = DB::table('ventes')
            ->join('utilisateurs', 'utilisateurs.id', '=', 'ventes.utilisateur_id')
            ->select('ventes.utilisateur_id',
                'utilisateurs.prenom as employe_prenom',
                'utilisateurs.nom as employe_nom',
                // Un avoir se retranche du total du vendeur et ne compte pas
                // pour une vente : sans cela, annuler une facture faisait
                // monter celui qui l'avait annulee dans le classement.
                DB::raw("SUM(CASE WHEN ventes.type_facture = 'avoir' THEN -ventes.montant_ttc ELSE ventes.montant_ttc END) as total"),
                DB::raw("SUM(CASE WHEN ventes.type_facture = 'avoir' THEN 0 ELSE 1 END) as nb_ventes"))
            ->whereIn('ventes.id', $idsVentesFiltrees)
            ->groupBy('ventes.utilisateur_id', 'utilisateurs.prenom', 'utilisateurs.nom')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(function ($ligne) {
                $ligne->nom_employe = trim(($ligne->employe_prenom ?? '') . ' ' . ($ligne->employe_nom ?? ''));

                return $ligne;
            });

        // ── CA par PDV sur la période ─────────────────────────────────────────
        //    Le camembert totalisait toutes les ventes de la base : hors
        //    periode comptable, hors filtre, avoirs compris et recus comptes
        //    deux fois. Il porte desormais sur les memes pieces que les KPI.
        $caPdvPeriode = DB::table('ventes')
            ->join('points_de_vente', 'points_de_vente.id', '=', 'ventes.point_de_vente_id')
            ->select('points_de_vente.nom as pdv_nom',
                // Meme regle : l'avoir se retranche du site qui l'a emis.
                DB::raw("SUM(CASE WHEN ventes.type_facture = 'avoir' THEN -ventes.montant_ttc ELSE ventes.montant_ttc END) as ca"),
                DB::raw("SUM(CASE WHEN ventes.type_facture = 'avoir' THEN 0 ELSE 1 END) as nb"))
            ->whereIn('ventes.id', $idsVentesFiltrees)
            ->groupBy('ventes.point_de_vente_id', 'points_de_vente.nom')
            ->orderByDesc('ca')
            ->get();

        return view('admin::tableau_de_bord_general', compact(
            'entreprise', 'montantVentesJour', 'montantAchatsJour',
            'nbVentesJour', 'totalVentesPeriode', 'totalVentesHTPeriode', 'nbVentesPeriode',
            'totalAchatsPeriode', 'margeBrutePeriode', 'tauxMargePeriode',
            'produitsEnAlerte', 'nbProduitsEnAlerte', 'solde', 'totalEncaissements', 'totalDecaissements',
            'dernieresVentes', 'pointsDeVente', 'pointDeVenteId',
            'jours7', 'topVendeurs', 'caPdvPeriode', 'periodeLabel'
        ));
    }

    /**
     * Afficher le profil de l'utilisateur connecté.
     */
    public function monProfil(): View
    {
        $utilisateur = Auth::user();
        return view('admin::personnel.profil', compact('utilisateur'));
    }

    /**
     * Enregistrer les modifications du profil.
     */
    public function enregistrerProfil(Request $request): RedirectResponse
    {
        $utilisateur = Auth::user();

        $request->validate([
            'nom'      => ['required', 'string', 'max:150'],
            'prenom'   => ['required', 'string', 'max:150'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
            'avatar'   => ['nullable', 'image', 'mimes:png,jpg,jpeg,svg,webp', 'max:2048'],
        ]);

        $data = [
            'nom'    => $request->nom,
            'prenom' => $request->prenom,
        ];

        if ($request->filled('password')) {
            $data['password'] = \Illuminate\Support\Facades\Hash::make($request->password);
        }

        if ($request->hasFile('avatar')) {
            if ($utilisateur->avatar_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($utilisateur->avatar_path)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($utilisateur->avatar_path);
            }
            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }

        $utilisateur->update($data);

        return back()->with('succes', 'Votre profil a été mis à jour avec succès.');
    }
}
