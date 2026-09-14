<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\OrdreProduction;
use App\Modules\Admin\Modeles\TransfertStock;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\TaxConfiguration;
use App\Modules\Admin\Services\FiltrePeriodeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Carbon\Carbon;

/**
 * Module <GESTION FNE> — 3 pages informatives (aucun traitement, uniquement
 * de la consultation/analyse) :
 *   1. Gestion FNE       : KPI restreints aux documents NORMALISÉS DGI
 *      (miroir de la plateforme FNE elle-même).
 *   2. Situation Générale : KPI sur TOUTE l'activité réelle de l'entreprise
 *      (normalisée + non normalisée), + mouvements de stock.
 *   3. Factures & Reçus   : registre complet, consultable/téléchargeable,
 *      TOUTES les factures (normalisées ou non — voir correctif demandé).
 *
 * Conventions retenues faute de concept "Reçu" natif dans Selflow (voir
 * commentaires inline) :
 *   - Une vente sans client identifié (client_id null, "client de passage")
 *     est traitée comme un "Reçu" plutôt qu'une "Facture".
 *   - Côté achat, un BAPA (type_facture='bapa') est traité comme un document
 *     "émis" par Selflow (l'entreprise établit elle-même le document faute
 *     de facture fournisseur), les factures normales reçues comme "reçues".
 *
 * Indicateurs 100% propres à la plateforme DGI (solde stickers, timbres —
 * données qu'on ne peut obtenir que via un futur appel API dédié, non
 * disponible actuellement) : affichés à 0, comme dans la maquette validée.
 */
class FneDashboardControleur
{
    // ─────────────────────────────────────────────────────────────────
    // PAGE 1 — GESTION FNE (documents normalisés uniquement)
    // ─────────────────────────────────────────────────────────────────

    public function gestion(Request $request): View
    {
        $entreprise    = Auth::user()->entreprise;
        $pointsDeVente = PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get();
        $taxConfig     = TaxConfiguration::where('entreprise_id', $entreprise->id)->first();

        return view('admin::fne.gestion', [
            'entreprise'    => $entreprise,
            'pointsDeVente' => $pointsDeVente,
            'taxConfig'     => $taxConfig,
            'kpis'          => $this->calculerKpisGestionFne($request, $entreprise->id),
        ]);
    }

    public function gestionJson(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        return response()->json($this->calculerKpisGestionFne($request, $entreprise->id));
    }

    private function calculerKpisGestionFne(Request $request, int $entrepriseId): array
    {
        $ent = \App\Modules\Admin\Modeles\Entreprise::find($entrepriseId);
        $taxConfig = TaxConfiguration::where('entreprise_id', $entrepriseId)->first();

        // 1. Calculer les stickers consommés (quantité) à vie (toutes périodes confondues)
        $totalVentesNormalisees = Vente::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId))
            ->where('etape', 'Facture')
            ->where('normalise', true)
            ->count();

        $totalAchatsNormalises = Achat::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId))
            ->where('type_facture', 'bapa')
            ->where('etape', 'Facture')
            ->where('normalise', true)
            ->count();

        $stickers_consommes = $totalVentesNormalisees + $totalAchatsNormalises;

        // 2. Mode de facturation constaté chez la DGI et solde correspondant.
        //    Le mode (stickers ou provision en francs) se coche dans les
        //    paramètres de la plateforme et l'API n'expose aucun champ pour le
        //    lire. Il se déduit du solde renvoyé à la dernière certification :
        //    `balance_sticker` d'un côté, `balance_funds` de l'autre. Tant
        //    qu'aucune pièce n'a été normalisée, le mode reste inconnu — et on
        //    le dit, plutôt que d'afficher un zéro qui ressemble à une panne.
        $mode_facturation = $ent?->fne_mode_facturation;
        $stickers_solde   = intval($ent?->fne_sticker_balance ?? 0);
        $solde_provision  = (float) ($ent?->fne_solde_provision ?? 0);
        $solde_maj_at     = $ent?->fne_solde_maj_at;

        // 3. Solde exprime dans les deux unites.
        //
        //    La plateforme renvoie soit un nombre de vignettes, soit une
        //    provision en francs — jamais les deux. Le prix unitaire n'etant
        //    transmis nulle part, la conversion s'appuie sur le tarif inscrit
        //    en configuration (20 F). C'est donc une equivalence, pas un relevé,
        //    et l'affichage le presente comme telle.
        $prixSticker = max(1, (float) config('selflow.sticker_prix_unitaire', 20));

        if ($mode_facturation === 'provision') {
            $solde_valeur   = $solde_provision;
            $stickers_solde = (int) floor($solde_provision / $prixSticker);
        } else {
            $solde_valeur = $stickers_solde * $prixSticker;
        }

        // Stickers achetes = solde + consommes, dans les deux modes.
        $stickers_achats = $stickers_solde + $stickers_consommes;

        [$debut, $fin] = $this->resoudrePeriode($request);
        $pdvId = $request->input('pdv_id');

        // Un recu deja remplace par sa facture ne doit pas compter deux fois
        $ventesQuery = Vente::sansDoublonRecu()
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId))
            ->where('etape', 'Facture')
            ->where('normalise', true);
        $this->filtrerPeriode($ventesQuery, 'date_vente', $request);
        if ($pdvId && $pdvId !== 'tous') $ventesQuery->where('point_de_vente_id', $pdvId);

        $achatsQuery = Achat::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId))
            ->where('etape', 'Facture')
            ->where('normalise', true);
        $this->filtrerPeriode($achatsQuery, 'date_achat', $request);
        if ($pdvId && $pdvId !== 'tous') $achatsQuery->where('point_de_vente_id', $pdvId);

        // 4. Timbre de quittance de la période.
        //
        //    Seule la DGI le décide. Le payload de certification ne comporte
        //    aucun champ de timbre — `fiscalStamp` n'apparaît que dans la
        //    RÉPONSE — et le déclenchement dépend d'un réglage coché sur la
        //    plateforme, que l'API n'expose pas.
        //
        //    L'estimation locale (montant TTC × taux) a donc été retirée :
        //    elle produisait des chiffres démentis par les pièces certifiées.
        //    Sur les factures de contrôle du 4 août 2026, la DGI a appliqué
        //    100 F sur un TTC de 16 700 comme sur un TTC de 29 382 — un
        //    montant forfaitaire, là où le calcul local annonçait 250 F et
        //    441 F. Une facture Cash de 270 900 F n'a, elle, reçu aucun
        //    timbre : la règle « espèces au-delà du seuil » ne suffit pas
        //    à prédire le déclenchement.
        //
        //    On ne rapporte donc que ce que la plateforme a réellement retenu.
        $timbreDgi = (clone $ventesQuery)->whereNotNull('fne_timbre_fiscal');
        $timbre_montant  = (float) (clone $timbreDgi)->sum('fne_timbre_fiscal');
        $timbre_quantite = (clone $timbreDgi)->where('fne_timbre_fiscal', '>', 0)->count();

        // Pièces certifiées dont la réponse ne portait pas encore ce retour :
        // leur timbre éventuel est inconnu, et le compteur le signale.
        $timbre_inconnu = (clone $ventesQuery)->whereNull('fne_timbre_fiscal')->count();

        // Montants compares : ceux de Selflow et ceux retenus par la DGI, sur
        // les seules pieces dont la reponse de certification les a rapportes.
        $comparables = (clone $ventesQuery)->whereNotNull('fne_montant_ttc');
        $ttcSelflow  = (float) (clone $comparables)->sum('montant_ttc');
        $ttcDgi      = (float) (clone $comparables)->sum('fne_montant_ttc');

        // Timbre retenu sur ces memes pieces. La carte le montre a cote de
        // l'ecart : le droit de timbre est du par le debiteur en sus du prix
        // (art. 875 du CGI) et Selflow ne le porte pas dans `montant_ttc`. Sans
        // ce reperage, un ecart parfaitement legitime etait presente comme
        // « une divergence de calcul a corriger ».
        $timbreComparables = (float) (clone $comparables)->sum('fne_timbre_fiscal');

        $comparablesTva = (clone $ventesQuery)->whereNotNull('fne_montant_tva');
        $tvaSelflow     = (float) (clone $comparablesTva)->sum('montant_tva');
        $tvaDgi         = (float) (clone $comparablesTva)->sum('fne_montant_tva');

        // La distinction facture / reçu vient de `type_piece`. Elle était
        // auparavant deduite de la presence d'un client, si bien qu'une vente
        // au comptant gonflait le compteur des reçus.
        $facturesVente = (clone $ventesQuery)->where(function($q) { $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'); })
            ->where('type_piece', '!=', Vente::TYPE_RECU);
        $recusVente = (clone $ventesQuery)->where(function($q) { $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'); })
            ->where('type_piece', Vente::TYPE_RECU);
        $avoirsVente = (clone $ventesQuery)->where('type_facture', 'avoir');

        // Proforma : devis et bons de commande de la periode. Ils ne sont pas
        // normalises, donc hors de $ventesQuery ; le compteur restait a zero.
        $proformaVente = Vente::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId))
            ->whereIn('etape', ['Devis', 'Bon de commande']);
        $this->filtrerPeriode($proformaVente, 'date_vente', $request);
        if ($pdvId && $pdvId !== 'tous') $proformaVente->where('point_de_vente_id', $pdvId);

        $facturesAchat = (clone $achatsQuery)->where(function($q) { $q->whereNull('type_facture')->orWhereNotIn('type_facture', ['avoir', 'bapa']); });
        $avoirsAchat = (clone $achatsQuery)->where('type_facture', 'avoir');

        $totalHtVente = (clone $ventesQuery)->where('type_facture', '!=', 'avoir')->sum('montant_ht');
        $totalTvaVente = (clone $ventesQuery)->where('type_facture', '!=', 'avoir')->sum('montant_tva');
        $totalTtcVente = (clone $ventesQuery)->where('type_facture', '!=', 'avoir')->sum('montant_ttc');
        $totalRemiseVente = (clone $ventesQuery)->where('type_facture', '!=', 'avoir')->sum('remise');

        return [
            'periode' => ['debut' => $debut->toDateString(), 'fin' => $fin->toDateString(), 'libelle' => FiltrePeriodeService::libelle($request)],

            // Indicateurs propres à la plateforme DGI
            'mode_facturation'  => $mode_facturation,
            'solde_provision'   => $solde_provision,
            'solde_valeur'      => round($solde_valeur, 2),
            'sticker_prix'      => $prixSticker,
            'stickers_consommes_valeur' => round($stickers_consommes * $prixSticker, 2),
            'stickers_achats_valeur'    => round($stickers_achats * $prixSticker, 2),
            'solde_maj_at'      => $solde_maj_at?->toDateTimeString(),
            'stickers_solde'    => $stickers_solde,
            'stickers_achats'   => $stickers_achats,
            'stickers_consommes'=> $stickers_consommes,
            'timbre_quittance'  => $timbre_montant,
            'timbre_quantite'   => $timbre_quantite,
            'timbre_inconnu'    => $timbre_inconnu,

            // Indicateurs tires des reponses de certification
            'alertes_stickers'  => (clone $ventesQuery)->where('fne_alerte_stickers', true)->count()
                                   + (clone $achatsQuery)->where('fne_alerte_stickers', true)->count(),
            // Ecarts avec la plateforme. Les deux termes de la comparaison sont
            // exposes, pas seulement leur difference : un ecart de 2 637 F ne
            // dit rien tant qu'on ignore s'il porte sur 10 000 F ou sur deux
            // millions, ni de quel cote penche la divergence.
            'tva_selflow'       => round($tvaSelflow, 2),
            'tva_dgi'           => round($tvaDgi, 2),
            'ecart_tva_dgi'     => round($tvaDgi - $tvaSelflow, 2),
            'ttc_selflow'       => round($ttcSelflow, 2),
            'ttc_dgi'           => round($ttcDgi, 2),
            'ecart_ttc_dgi'     => round($ttcDgi - $ttcSelflow, 2),
            'timbre_comparables' => round($timbreComparables, 2),
            'pieces_controlees' => (clone $ventesQuery)->whereNotNull('fne_montant_ttc')->count(),

            'ventes' => [
                'factures' => ['nombre' => (clone $facturesVente)->count(), 'montant' => (clone $facturesVente)->sum('montant_ttc')],
                'avoirs'   => ['nombre' => (clone $avoirsVente)->count(),   'montant' => (clone $avoirsVente)->sum('montant_ttc')],
                'recus'    => ['nombre' => (clone $recusVente)->count(),     'montant' => (clone $recusVente)->sum('montant_ttc')],
                'proforma' => ['nombre' => (clone $proformaVente)->count(), 'montant' => (clone $proformaVente)->sum('montant_ttc')],
                'total_ht'      => $totalHtVente,
                'total_tva'     => $totalTvaVente,
                'total_ttc'     => $totalTtcVente,
                'total_remises' => $totalRemiseVente,
            ],

            'achats' => [
                'factures'           => ['nombre' => (clone $facturesAchat)->count(), 'montant' => (clone $facturesAchat)->sum('montant_ttc')],
                'avoirs'             => ['nombre' => (clone $avoirsAchat)->count(),   'montant' => (clone $avoirsAchat)->sum('montant_ttc')],
                'total_ht'           => (clone $achatsQuery)->where('type_facture', '!=', 'avoir')->sum('montant_ht'),
                'total_tva_deductible'=> (clone $achatsQuery)->where('type_facture', '!=', 'avoir')->sum('montant_tva'),
                'total_ttc'          => (clone $achatsQuery)->where('type_facture', '!=', 'avoir')->sum('montant_ttc'),
                'ca_ht'              => (clone $achatsQuery)->where('type_facture', '!=', 'avoir')->sum('montant_ht'),
                'ca_ttc'             => (clone $achatsQuery)->where('type_facture', '!=', 'avoir')->sum('montant_ttc'),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // CONFIGURATION FISCALE (TSE / TDT / TVA)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Retourner la configuration fiscale courante en JSON.
     */
    public function obtenirConfig(): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $config     = TaxConfiguration::where('entreprise_id', $entreprise->id)->first();

        $regime    = $config?->regime     ?? $entreprise->regime_imposition;
        $categorie = TaxConfiguration::categorieDepuisRegime($regime);

        return response()->json([
            'regime'     => $regime,
            'categorie'  => $categorie,
            'label_categorie' => $categorie === 'CAS_A' ? 'CAS A — Assujetti TVA (RNI / RSI)' : 'CAS B — Non-assujetti (TEE, TCE, RME…)',
            'tva_active' => $config?->tva_active ?? false,
            'tva_taux'   => $config?->tva_taux   ?? 18.00,
            'tse_active' => $config?->tse_active ?? false,
            'tse_taux'   => $config?->tse_taux   ?? 0.10,
            'tdt_active' => $config?->tdt_active ?? false,
            'tdt_taux'   => $config?->tdt_taux   ?? 1.50,
            'tdt_seuil'  => $config?->tdt_seuil  ?? 5000.00,
        ]);
    }

    /**
     * Sauvegarder la configuration fiscale (POST AJAX).
     */
    public function sauvegarderConfig(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;

        $validated = $request->validate([
            'tva_active' => ['boolean'],
            'tva_taux'   => ['numeric', 'min:0', 'max:100'],
            'tse_active' => ['boolean'],
            'tse_taux'   => ['numeric', 'min:0', 'max:100'],
            'tdt_active' => ['boolean'],
            'tdt_taux'   => ['numeric', 'min:0', 'max:100'],
            'tdt_seuil'  => ['numeric', 'min:0'],
        ]);

        $regime    = $entreprise->regime_imposition;
        $categorie = TaxConfiguration::categorieDepuisRegime($regime);

        // Règle métier : TSE interdite en CAS B (non-assujettis)
        if ($categorie === 'CAS_B') {
            $validated['tse_active'] = false;
        }

        // Règle métier : TVA masquée en CAS B
        if ($categorie === 'CAS_B') {
            $validated['tva_active'] = false;
        }

        $config = TaxConfiguration::updateOrCreate(
            ['entreprise_id' => $entreprise->id],
            array_merge($validated, [
                'regime'    => $regime,
                'categorie' => $categorie,
            ])
        );

        return response()->json([
            'success'   => true,
            'message'   => 'Configuration fiscale enregistrée avec succès.',
            'categorie' => $categorie,
            'config'    => [
                'tva_active' => $config->tva_active,
                'tse_active' => $config->tse_active,
                'tdt_active' => $config->tdt_active,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // PAGE 2 — SITUATION GÉNÉRALE (toute l'activité réelle)
    // ─────────────────────────────────────────────────────────────────

    public function situation(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointsDeVente = PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get();

        return view('admin::fne.situation', [
            'entreprise' => $entreprise,
            'pointsDeVente' => $pointsDeVente,
            'kpis' => $this->calculerKpisSituation($request, $entreprise->id),
        ]);
    }

    public function situationJson(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        return response()->json($this->calculerKpisSituation($request, $entreprise->id));
    }

    private function calculerKpisSituation(Request $request, int $entrepriseId): array
    {
        [$debut, $fin] = $this->resoudrePeriode($request);
        $pdvId = $request->input('pdv_id');

        // `sansDoublonRecu` manquait ici, alors qu'il est applique partout
        // ailleurs : un recu deja remplace par sa facture etait compte deux fois.
        $ventesQuery = Vente::sansDoublonRecu()
            ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId))
            ->where('etape', 'Facture');
        $this->filtrerPeriode($ventesQuery, 'date_vente', $request);
        if ($pdvId && $pdvId !== 'tous') $ventesQuery->where('point_de_vente_id', $pdvId);

        $achatsQuery = Achat::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId))
            ->where('etape', 'Facture');
        $this->filtrerPeriode($achatsQuery, 'date_achat', $request);
        if ($pdvId && $pdvId !== 'tous') $achatsQuery->where('point_de_vente_id', $pdvId);

        $caReel = (clone $ventesQuery)->where('type_facture', '!=', 'avoir')->sum('montant_ttc')
                - (clone $ventesQuery)->where('type_facture', 'avoir')->sum('montant_ttc');
        $achatsReel = (clone $achatsQuery)->where('type_facture', '!=', 'avoir')->sum('montant_ttc')
                    - (clone $achatsQuery)->where('type_facture', 'avoir')->sum('montant_ttc');

        // Trésorerie nette encaissée sur la période (entrées - sorties réellement mouvementées)
        $tresoQuery = TresorerieJournal::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId));
        $this->filtrerPeriode($tresoQuery, 'date_operation', $request);
        if ($pdvId && $pdvId !== 'tous') $tresoQuery->where('point_de_vente_id', $pdvId);
        $entrees = (clone $tresoQuery)->sum('montant_entree');
        $sorties = (clone $tresoQuery)->sum('montant_sortie');

        // Taux de conformite : part des ventes certifiees dans les ventes emises.
        //
        // Il etait rapporte au chiffre d'affaires reel, dont les avoirs sont
        // deduits, alors que son numerateur les ignore. Avec 502 382 F d'avoirs
        // sur la periode, le rapport montait a 145,9 % — un taux de conformite
        // superieur a 100 % n'ayant aucun sens. Les deux termes portent
        // desormais sur le meme perimetre : les ventes hors avoirs.
        $ventesNormalisees = (clone $ventesQuery)->where('normalise', true)->where('type_facture', '!=', 'avoir')->sum('montant_ttc');
        $ventesEmises      = (clone $ventesQuery)->where('type_facture', '!=', 'avoir')->sum('montant_ttc');
        $tauxConformite    = $ventesEmises > 0 ? round(($ventesNormalisees / $ventesEmises) * 100, 1) : 0;

        // Déclaré vs non déclaré (vente)
        $venteNormaliseesCount = (clone $ventesQuery)->where('normalise', true)->where('type_facture', '!=', 'avoir')->count();
        $venteNonNormaliseesCount = (clone $ventesQuery)->where('normalise', false)->where('type_facture', '!=', 'avoir')->count();
        $venteNonNormaliseesMontant = (clone $ventesQuery)->where('normalise', false)->where('type_facture', '!=', 'avoir')->sum('montant_ttc');
        // Total de la ligne : la somme de ses deux colonnes. Le chiffre
        // d'affaires reel y figurait, avoirs deduits, si bien que le total
        // etait inferieur a la somme affichee juste a sa gauche.
        $venteTotalMontant = $ventesNormalisees + $venteNonNormaliseesMontant;

        // Déclaré vs non déclaré (achat)
        $achatNormaliseesCount = (clone $achatsQuery)->where('normalise', true)->where('type_facture', '!=', 'avoir')->count();
        $achatNonNormaliseesCount = (clone $achatsQuery)->where('normalise', false)->where('type_facture', '!=', 'avoir')->count();
        $achatNonNormaliseesMontant = (clone $achatsQuery)->where('normalise', false)->where('type_facture', '!=', 'avoir')->sum('montant_ttc');
        $achatNormaliseesMontant = (clone $achatsQuery)->where('normalise', true)->where('type_facture', '!=', 'avoir')->sum('montant_ttc');
        $achatTotalMontant = $achatNormaliseesMontant + $achatNonNormaliseesMontant;

        // Stock : ordres de production (comptés + valorisés via les écritures de production)
        $ordresProduction = OrdreProduction::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entrepriseId));
        $this->filtrerPeriode($ordresProduction, 'date_production', $request);
        if ($pdvId && $pdvId !== 'tous') $ordresProduction->where('point_de_vente_id', $pdvId);
        $nbOrdresProduction = (clone $ordresProduction)->count();

        $valeurProduite = EcritureComptable::whereHas('operation', function ($q) use ($entrepriseId, $request) {
                $q->where('entreprise_id', $entrepriseId)
                  ->where('type_operation', 'Production');
                $this->filtrerPeriode($q, 'date_operation', $request);
            })
            ->where('compte_debit', '351100')
            ->sum('debit');

        $transfertsQuery = TransfertStock::whereHas('produit', fn($q) => $q->where('entreprise_id', $entrepriseId))
            ->where('statut', 'Validé');
        $this->filtrerPeriode($transfertsQuery, 'approuve_le', $request);
        $nbTransferts = (clone $transfertsQuery)->count();

        return [
            'periode' => ['debut' => $debut->toDateString(), 'fin' => $fin->toDateString(), 'libelle' => FiltrePeriodeService::libelle($request)],

            'ca_reel' => $caReel,
            'achats_reel' => $achatsReel,
            'tresorerie_nette' => $entrees - $sorties,
            'tresorerie_entrees' => $entrees,
            'tresorerie_sorties' => $sorties,
            'taux_conformite_fne' => $tauxConformite,

            'declaration' => [
                'ventes' => [
                    'normalisees' => ['nombre' => $venteNormaliseesCount, 'montant' => $ventesNormalisees],
                    'non_normalisees' => ['nombre' => $venteNonNormaliseesCount, 'montant' => $venteNonNormaliseesMontant],
                    'total' => ['nombre' => $venteNormaliseesCount + $venteNonNormaliseesCount, 'montant' => $venteTotalMontant],
                ],
                'achats' => [
                    'normalises' => ['nombre' => $achatNormaliseesCount, 'montant' => $achatNormaliseesMontant],
                    'non_normalises' => ['nombre' => $achatNonNormaliseesCount, 'montant' => $achatNonNormaliseesMontant],
                    'total' => ['nombre' => $achatNormaliseesCount + $achatNonNormaliseesCount, 'montant' => $achatTotalMontant],
                ],
            ],

            'stock' => [
                'nb_ordres_production' => $nbOrdresProduction,
                'valeur_produite' => (float) $valeurProduite,
                'nb_transferts' => $nbTransferts,
                'pertes' => 0, // Non tracké dans Selflow actuellement (aucun type de mouvement "Perte" dédié)
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    // PAGE 3 — FACTURES & REÇUS (registre complet, normalisé ou non)
    // ─────────────────────────────────────────────────────────────────

    public function factures(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointsDeVente = PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get();

        return view('admin::fne.factures', [
            'entreprise' => $entreprise,
            'pointsDeVente' => $pointsDeVente,
        ]);
    }

    /**
     * Endpoint JSON paginé/filtré consommé par la page Factures & Reçus
     * (rendu instantané côté client, sans recharger la page).
     *
     * Paramètres attendus : flux (ventes|achats), categorie, filtre_mois,
     * filtre_semaine, filtre_jour, pdv_id, recherche, page.
     */
    public function facturesJson(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $flux = $request->input('flux', 'ventes');
        $categorie = $request->input('categorie', 'emis');
        $pdvId = $request->input('pdv_id');
        $recherche = trim((string) $request->input('recherche', ''));
        $parPage = 20;

        if ($flux === 'ventes') {
            $query = Vente::with(['client', 'pointDeVente', 'parent', 'pieceLiee'])
                ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
                ->where('etape', 'Facture');
            $this->filtrerPeriode($query, 'date_vente', $request);

            // La nature du document vient de `type_piece`, choisie a la saisie.
            // Auparavant elle etait deduite de l'absence de client, si bien
            // qu'une vente au comptant apparaissait a tort comme un recu.
            $query = match ($categorie) {
                'avoir_client' => $query->where('type_facture', 'avoir'),
                'recu_recu'    => $query->where(function ($q) { $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'); })
                                        ->where('type_piece', Vente::TYPE_RECU),
                default        => $query->where(function ($q) { $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'); })
                                        ->where('type_piece', '!=', Vente::TYPE_RECU),
            };

            if ($pdvId && $pdvId !== 'tous') $query->where('point_de_vente_id', $pdvId);
            if ($recherche !== '') {
                $query->where(function ($q) use ($recherche) {
                    $q->where('numero_facture', 'like', "%{$recherche}%")
                      ->orWhere('numero_fne', 'like', "%{$recherche}%")
                      ->orWhereHas('client', fn($qc) => $qc->where('nom', 'like', "%{$recherche}%"));
                });
            }

            $documents = $query->orderByDesc('date_vente')->orderByDesc('id')->paginate($parPage);

            $lignes = $documents->getCollection()->map(function (Vente $v) {
                $dgiUrl = $v->fichier_fne_pdf_url;

                return [
                    'id' => $v->id,
                    'type_doc' => $v->libelleTypeDocument(),
                    'is_recu' => $v->estRecu(),
                    // Reçu dont la facture est issue, ou facture issue du reçu
                    'recu_lie' => $v->pieceLiee?->estRecu() ? $v->pieceLiee->numero_facture : null,
                    'recu_lie_url' => $v->pieceLiee?->estRecu()
                        ? route('admin.ventes.ticket', $v->pieceLiee->id)
                        : null,
                    'fichier_recu_url' => $v->estRecu()
                        ? route('admin.ventes.ticket', $v->id)
                        : null,
                    'num_piece' => $v->numero_facture,
                    'num_fne' => $v->numero_fne,
                    'tiers' => $v->client?->nom ?? 'Client de passage',
                    'ht' => (float) $v->montant_ht,
                    'tva' => (float) $v->montant_tva,
                    'ttc' => (float) $v->montant_ttc,
                    'normalise' => (bool) $v->normalise,
                    'date' => $v->date_vente?->toDateString(),
                    'pdv' => $v->pointDeVente?->nom,
                    'facture_origine' => $v->type_facture === 'avoir' ? $v->parent?->numero_facture : null,
                    'voir_url' => $dgiUrl,
                    'telechargement_url' => $dgiUrl,
                    'local_url' => route('admin.ventes.imprimer', $v->id),
                    'normaliser_url' => route('admin.ventes.normaliser', $v->id),
                ];
            });
        } else {
            $query = Achat::with(['fournisseur', 'pointDeVente', 'parent'])
                ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
                ->where('etape', 'Facture');
            $this->filtrerPeriode($query, 'date_achat', $request);

            $query = match ($categorie) {
                'avoir_fournisseur' => $query->where('type_facture', 'avoir'),
                'emis'              => $query->where('type_facture', 'bapa'),
                default             => $query->where(function($q) { $q->whereNull('type_facture')->orWhereNotIn('type_facture', ['avoir', 'bapa']); }),
            };

            if ($pdvId && $pdvId !== 'tous') $query->where('point_de_vente_id', $pdvId);
            if ($recherche !== '') {
                $query->where(function ($q) use ($recherche) {
                    $q->where('numero_facture', 'like', "%{$recherche}%")
                      ->orWhere('numero_fne', 'like', "%{$recherche}%")
                      ->orWhereHas('fournisseur', fn($qf) => $qf->where('nom', 'like', "%{$recherche}%"));
                });
            }

            $documents = $query->orderByDesc('date_achat')->orderByDesc('id')->paginate($parPage);

            $lignes = $documents->getCollection()->map(function (Achat $a) {
                $dgiUrl = $a->fichier_fne_pdf_url;

                return [
                    'id' => $a->id,
                    'type_doc' => $a->type_facture === 'avoir' ? 'Facture Avoir' : ($a->type_facture === 'bapa' ? 'BAPA' : 'Facture'),
                    'is_recu' => false,
                    'num_piece' => $a->numero_facture,
                    'num_fne' => $a->numero_fne,
                    'tiers' => $a->fournisseur?->nom ?? '—',
                    'ht' => (float) $a->montant_ht,
                    'tva' => (float) $a->montant_tva,
                    'ttc' => (float) $a->montant_ttc,
                    'normalise' => (bool) $a->normalise,
                    'date' => $a->date_achat?->toDateString(),
                    'pdv' => $a->pointDeVente?->nom,
                    'facture_origine' => $a->type_facture === 'avoir' ? $a->parent?->numero_facture : null,
                    'voir_url' => $dgiUrl,
                    'telechargement_url' => $dgiUrl,
                    'local_url' => route('admin.achats.imprimer', $a->id),
                    'normaliser_url' => route('admin.achats.normaliser', $a->id),
                ];
            });
        }

        return response()->json([
            'documents' => $lignes,
            'pagination' => [
                'page_courante' => $documents->currentPage(),
                'derniere_page' => $documents->lastPage(),
                'total' => $documents->total(),
                'de' => $documents->firstItem() ?? 0,
                'a' => $documents->lastItem() ?? 0,
            ],
            'totaux' => [
                'ht' => $lignes->sum('ht'),
                'ttc' => $lignes->sum('ttc'),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // HELPER PARTAGÉ — résolution de la période sélectionnée par les filtres
    // ─────────────────────────────────────────────────────────────────

    /**
     * Restreindre une requete a la periode retenue.
     *
     * Les ecrans FNE prenaient auparavant un type de periode et une date de
     * reference, sans considerer l'exercice comptable ouvert : deux pages
     * ouvertes cote a cote annoncaient des chiffres differents pour ce que
     * l'utilisateur croyait etre le meme perimetre. Ils passent desormais par
     * le meme resolveur que le tableau de bord general.
     *
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     */
    private function filtrerPeriode($query, string $colonneDate, Request $request)
    {
        return FiltrePeriodeService::appliquer($query, $colonneDate, $request);
    }

    /**
     * Bornes englobantes de la periode, pour l'afficher — jamais pour filtrer.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resoudrePeriode(Request $request): array
    {
        return FiltrePeriodeService::intervalle($request);
    }

    // ─────────────────────────────────────────────────────────────────
    // TRAITEMENTS PAR LOT & PLANIFICATION
    // ─────────────────────────────────────────────────────────────────

    public function batchNormaliser(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $ids = $request->input('ids', []);
        $flux = $request->input('flux', 'ventes');

        if (empty($ids) || !is_array($ids)) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune facture sélectionnée.'
            ], 400);
        }

        if (count($ids) > 15) {
            return response()->json([
                'success' => false,
                'message' => 'La sélection ne peut pas dépasser 15 factures.'
            ], 400);
        }

        $successCount = 0;
        $errors = [];

        // Une pièce en échec ne fait plus tomber le lot entier : l'erreur est
        // relevée et le traitement passe à la suivante. Auparavant, une seule
        // facture non conforme suffisait à interrompre toute la série, sans
        // que rien n'indique que les pièces restantes n'avaient pas été vues.
        foreach ($ids as $id) {
            try {
                if ($flux === 'ventes') {
                    // Sans `withoutGlobalScopes`, une piece hors de la periode
                    // active en session est introuvable et le lot s'arrete.
                    $vente = Vente::withoutGlobalScopes()
                        ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
                        ->find($id);

                    if (!$vente) {
                        $errors[] = "Vente #{$id} introuvable.";
                        continue;
                    }

                    if ($vente->normalise) {
                        $successCount++;
                        continue;
                    }

                    $fneResult = \App\Modules\Admin\Services\FneService::normaliserFacture($vente);

                    if ($fneResult['success']) {
                        $updateData = [
                            'normalise'     => true,
                            'numero_fne'    => $fneResult['numero_recu'],
                            'signature_dgi' => $fneResult['signature'] ?? null,
                            'qr_code_data'  => $fneResult['qr_code_data'],
                            'fichier_fne_pdf_url' => $fneResult['pdf_url'] ?? null,
                        ];

                        if ($vente->type_facture !== 'avoir') {
                            $updateData['type_facture'] = 'normale';
                        }

                        if (!empty($fneResult['invoice_id'])) {
                            $updateData['fne_invoice_id'] = $fneResult['invoice_id'];
                        }

                        $updateData += \App\Modules\Admin\Services\FneService::colonnesRetoursFne($fneResult);

                        $vente->update($updateData);

                        if (!empty($fneResult['fne_item_ids']) && is_array($fneResult['fne_item_ids'])) {
                            foreach ($fneResult['fne_item_ids'] as $detailId => $itemId) {
                                \App\Modules\Admin\Modeles\VenteDetail::where('id', $detailId)->update(['fne_invoice_item_id' => $itemId]);
                            }
                        }

                        \App\Modules\Admin\Modeles\FneRejet::resoudre($vente);
                        $successCount++;
                    } else {
                        \App\Modules\Admin\Modeles\FneRejet::consigner($vente, $fneResult);
                        $errors[] = "Erreur sur la facture {$vente->numero_facture} : " . ($fneResult['message'] ?? 'Erreur inconnue');
                        continue;
                    }
                } else {
                    $achat = Achat::withoutGlobalScopes()
                        ->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
                        ->find($id);

                    if (!$achat) {
                        $errors[] = "Achat #{$id} introuvable.";
                        continue;
                    }

                    if ($achat->normalise) {
                        $successCount++;
                        continue;
                    }

                    $fneResult = \App\Modules\Admin\Services\FneService::normaliserAchatBapa($achat);

                    if ($fneResult['success']) {
                        // Les retours de la plateforme — timbre applique, montants
                        // retenus, alerte de stickers, horodatage — etaient conserves
                        // pour les ventes mais perdus pour les bordereaux. Or la DGI
                        // applique aussi un timbre aux bordereaux : sans cela, le
                        // document continuait d'afficher l'estimation au bareme au
                        // lieu du montant reellement retenu.
                        $achat->update([
                            'normalise'     => true,
                            'numero_fne'    => $fneResult['numero_recu'],
                            'signature_dgi' => $fneResult['signature'] ?? null,
                            'qr_code_data'  => $fneResult['qr_code_data'],
                            'fichier_fne_pdf_url' => $fneResult['pdf_url'] ?? null,
                        ] + \App\Modules\Admin\Services\FneService::colonnesRetoursFne($fneResult));
                        \App\Modules\Admin\Modeles\FneRejet::resoudre($achat);
                        $successCount++;
                    } else {
                        \App\Modules\Admin\Modeles\FneRejet::consigner($achat, $fneResult);
                        $errors[] = "Erreur sur l'achat {$achat->numero_facture} : " . ($fneResult['message'] ?? 'Erreur inconnue');
                        continue;
                    }
                }
            } catch (\Exception $e) {
                $errors[] = "Exception : " . $e->getMessage();
                continue;
            }
        }

        return response()->json([
            'success' => empty($errors),
            'success_count' => $successCount,
            'total' => count($ids),
            'errors' => $errors
        ]);
    }

    /**
     * Préparer une normalisation par période.
     *
     * Le traitement passait par une file d'attente : sans worker
     * (`php artisan queue:work`), le job n'était jamais consommé et l'écran
     * restait indéfiniment sur « En attente de traitement ». On renvoie
     * désormais la liste des pièces concernées, que l'interface fait traiter
     * par tranches successives via `batchNormaliser` — le même chemin,
     * synchrone et éprouvé, que la sélection manuelle.
     */
    public function scheduleBatch(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $flux = $request->input('flux', 'ventes');
        $dateDebut = $request->input('date_debut');
        $dateFin = $request->input('date_fin');
        $batchSize = intval($request->input('batch_size', 15));

        if (empty($dateDebut) || empty($dateFin)) {
            return response()->json([
                'success' => false,
                'message' => 'Les dates de début et de fin sont obligatoires.'
            ], 400);
        }

        // Un lancement porte au plus 15 documents : c'est la limite deja
        // appliquee a la selection manuelle.
        if ($batchSize < 1 || $batchSize > 15) {
            return response()->json([
                'success' => false,
                'message' => 'Le nombre de documents à normaliser doit être compris entre 1 et 15.',
            ], 422);
        }

        // `withoutGlobalScopes` neutralise le filtre de période stocké en
        // session, qui restreindrait la plage de dates saisie au formulaire.
        $ids = $flux === 'ventes'
            ? Vente::withoutGlobalScopes()
                ->where('normalise', false)
                ->where('etape', 'Facture')
                ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
                ->whereBetween('date_vente', [$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59'])
                ->orderBy('date_vente')->orderBy('id')
                ->limit($batchSize)
                ->pluck('id')
            : Achat::withoutGlobalScopes()
                ->where('normalise', false)
                ->where('type_facture', 'bapa')
                ->where('etape', 'Facture')
                ->whereHas('pointDeVente', fn ($q) => $q->where('entreprise_id', $entreprise->id))
                ->whereBetween('date_achat', [$dateDebut . ' 00:00:00', $dateFin . ' 23:59:59'])
                ->orderBy('date_achat')->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

        if ($ids->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun document non normalisé sur cette période.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'flux'    => $flux,
            'ids'     => $ids->values(),
            'total'   => $ids->count(),
        ]);
    }

    public function batchStatus(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $statusPath = storage_path("app/fne_batch_{$entreprise->id}.json");

        if ($request->input('cancel') === '1') {
            if (file_exists($statusPath)) {
                $data = json_decode(file_get_contents($statusPath), true);
                $data['status'] = 'cancelled';
                $data['last_updated'] = now()->toDateTimeString();
                file_put_contents($statusPath, json_encode($data));
                return response()->json(['success' => true, 'message' => 'Demande d\'annulation prise en compte.']);
            }

            return response()->json(['success' => true, 'message' => 'Aucun traitement en cours.']);
        }

        // Purge du suivi : permet a l'interface de repartir d'un etat neutre
        // apres un traitement termine, annule ou en echec.
        if ($request->input('reset') === '1') {
            if (file_exists($statusPath)) {
                unlink($statusPath);
            }

            return response()->json(['status' => 'idle', 'processed_count' => 0, 'total_to_process' => 0]);
        }

        if (!file_exists($statusPath)) {
            return response()->json([
                'status' => 'idle',
                'processed_count' => 0,
                'total_to_process' => 0
            ]);
        }

        $data = json_decode(file_get_contents($statusPath), true) ?: [];

        // Un lot qui reste « queued » signifie qu'aucun worker de file
        // d'attente ne tourne : le job attendra indefiniment. On le dit
        // clairement plutot que de laisser la barre de progression tourner.
        if (($data['status'] ?? null) === 'queued' && !empty($data['queued_at'])) {
            $attenteSecondes = now()->diffInSeconds(\Carbon\Carbon::parse($data['queued_at']));
            $data['waiting_seconds'] = $attenteSecondes;

            if ($attenteSecondes > 30 && config('queue.default') !== 'sync') {
                $data['worker_missing'] = true;
                $data['error'] = 'Le traitement est en file d\'attente mais aucun worker ne le consomme. '
                    . 'Lancez « php artisan queue:work » sur le serveur, ou passez QUEUE_CONNECTION=sync.';
            }
        }

        return response()->json($data);
    }
}
