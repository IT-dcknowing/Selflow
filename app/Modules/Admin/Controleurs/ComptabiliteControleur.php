<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Services\CacheService;
use App\Modules\Admin\Services\ComptabiliteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Modules\Admin\Regles\Appartenance;
use App\Modules\Admin\Services\BalanceService;
use App\Modules\Admin\Services\FiltrePeriodeService;
use App\Modules\Admin\Services\GrandLivreService;
use App\Modules\Admin\Services\LettrageService;
use App\Modules\Admin\Modeles\PlanComptable;

class ComptabiliteControleur
{
    /**
     * Page « Opération & Écriture Globale »
     */
    public function globale(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        $isAdmin = Auth::user()->role === 'admin';

        // Récupérer les points de vente pour le filtre (via cache Section 18.4)
        $pointsDeVente = CacheService::pointsDeVente($entreprise->id);

        // Récupérer le point de vente sélectionné pour le filtre
        $pdvFilter = $request->input('point_de_vente_id');
        if (!$isAdmin) {
            $pdvFilter = session('point_de_vente_actif_id') ?? Auth::user()->point_de_vente_id;
        }

        $mode          = $request->input('mode', 'operations'); // operations ou ecritures
        $search        = trim($request->input('search', ''));
        $typeOp        = $request->input('type_operation', '');
        $minIds = [];
        $tresoMap = [];
        $venteMap = [];
        $achatMap = [];

        if ($mode === 'ecritures') {
            // Vue écritures (Grand Livre double-entrée)
            $query = EcritureComptable::with(['pointDeVente', 'operation'])
                ->where('entreprise_id', $entreprise->id);

            if (!empty($pdvFilter)) {
                $query->where('point_de_vente_id', $pdvFilter);
            }

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('reference_document', 'like', '%' . $search . '%')
                      ->orWhere('libelle', 'like', '%' . $search . '%')
                      ->orWhere('compte_debit', 'like', '%' . $search . '%')
                      ->orWhere('compte_credit', 'like', '%' . $search . '%');
                });
            }

            $ecritures = $query->orderBy('date_ecriture', 'desc')->orderBy('id', 'desc')->paginate(30);
            $operations = collect();
            // NB : le N° Saisie n'est plus recalculé à la volée (ancien MIN(id) par
            // reference_document, qui mélangeait parfois deux opérations distinctes
            // partageant le même n° de facture). Il est désormais lu directement
            // depuis $ecriture->operation->numero_saisie dans la vue.
        } else {
            // Vue opérations (Trésorerie / Caisse)
            $query = TresorerieJournal::with(['pointDeVente'])
                ->whereHas('pointDeVente', function ($q) use ($entreprise) {
                    $q->where('entreprise_id', $entreprise->id);
                });

            if (!empty($pdvFilter)) {
                $query->where('point_de_vente_id', $pdvFilter);
            }

            if (!empty($typeOp)) {
                $query->where('type_operation', $typeOp);
            }

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('reference_document', 'like', '%' . $search . '%')
                      ->orWhere('libelle', 'like', '%' . $search . '%');
                });
            }

            $operations = $query->orderBy('date_operation', 'desc')->orderBy('id', 'desc')->paginate(30);

            // ── Charger les tiers et statuts liés aux références ─────────────
            $references = $operations->pluck('reference_document')->filter()->unique();
            $ventes     = collect();
            $achats     = collect();

            // Préfixes ventes : VTE-, DEV-, BC-, FAC-, AVO-VTE-, AVO-VT-
            $venteRefs = $references->filter(fn($ref) =>
                str_starts_with($ref, 'VTE-')     ||
                str_starts_with($ref, 'DEV-')     ||
                str_starts_with($ref, 'BC-')      ||
                str_starts_with($ref, 'FAC-')     ||
                str_starts_with($ref, 'AVO-VTE-') ||
                str_starts_with($ref, 'AVO-VT-')
            );
            if ($venteRefs->isNotEmpty()) {
                $ventes = Vente::whereIn('numero_facture', $venteRefs)->with('client')->get()->keyBy('numero_facture');
            }

            // Préfixes achats : ACH-, AVO-ACH-
            $achatRefs = $references->filter(fn($ref) =>
                str_starts_with($ref, 'ACH-')    ||
                str_starts_with($ref, 'AVO-ACH-')
            );
            if ($achatRefs->isNotEmpty()) {
                $achats = Achat::whereIn('numero_facture', $achatRefs)->with('fournisseur')->get()->keyBy('numero_facture');
            }

            // ── Construire la map N° saisie (lier opération de trésorerie ↔ Operation comptable) ──
            // NB : TresorerieJournal n'est pas encore rattaché par clé étrangère à
            // Operation (voir Phase 3/5 de l'audit — fusion des deux tables à
            // terme). En attendant, on résout au mieux par référence de pièce +
            // entreprise, en privilégiant l'opération de règlement/comptant la
            // plus récente pour ce document (celle qui correspond réellement au
            // mouvement de trésorerie affiché).
            $operationsParRef = [];
            if ($references->isNotEmpty()) {
                $operationsParRef = Operation::where('entreprise_id', $entreprise->id)
                    ->whereIn('reference_document', $references)
                    ->orderByDesc('id')
                    ->get()
                    ->groupBy('reference_document');
            }

            foreach ($operations as $op) {
                $op->tier_nom = '—';
                $op->statut   = $op->statut ?? '—';

                // N° saisie = numéro de la vraie Operation liée à ce document (si trouvée), sinon l'id brut
                $opsPourRef = $op->reference_document ? ($operationsParRef[$op->reference_document] ?? null) : null;
                $op->no_saisie = $opsPourRef?->first()?->numero_saisie ?? $op->id;

                if ($op->reference_document) {
                    $ref = $op->reference_document;
                    // Lien ventes (tous préfixes)
                    if (str_starts_with($ref, 'VTE-') || str_starts_with($ref, 'DEV-') ||
                        str_starts_with($ref, 'BC-')  ||
                        str_starts_with($ref, 'FAC-') || str_starts_with($ref, 'AVO-VTE-') ||
                        str_starts_with($ref, 'AVO-VT-')) {
                        $vente = $ventes->get($ref);
                        $op->tier_nom = $vente?->client?->nom ?? 'Client de passage';
                        $op->statut   = $vente?->statut ?? '—';
                    }
                    // Lien achats
                    elseif (str_starts_with($ref, 'ACH-') || str_starts_with($ref, 'AVO-ACH-')) {
                        $achat = $achats->get($ref);
                        $op->tier_nom = $achat?->fournisseur?->nom ?? '—';
                        $op->statut   = $achat?->statut ?? '—';
                    }
                    // OD Production
                    elseif (str_starts_with($ref, 'OD-')) {
                        $op->tier_nom = 'Production interne';
                    }
                }
            }
            $ecritures = collect();
        }

        // Récupérer les codes journaux de l'entreprise avec priorité COMPTAFLOW
        $codesJournaux = \App\Modules\Admin\Modeles\CodeJournal::obtenirJournauxPrioritaires($entreprise->id);

        return view('admin::comptabilite.globale', compact('pointsDeVente', 'pdvFilter', 'mode', 'operations', 'ecritures', 'isAdmin', 'minIds', 'tresoMap', 'venteMap', 'achatMap', 'codesJournaux', 'search', 'typeOp'));
    }

    /**
     * Page « Créances & Règlements »
     */
    public function creances(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        // 1. Calcul des créances clients
        $ventesImpayees = Vente::with(['client', 'pointDeVente'])
            ->whereHas('pointDeVente', function ($q) use ($entreprise) {
                $q->where('entreprise_id', $entreprise->id);
            })
            ->where('etape', 'Facture')
            ->whereIn('statut', ['Crédit', 'Avance'])
            ->get();

        // Optimisation N+1 : Charger la somme des règlements de trésorerie en une seule requête
        $venteRefs = $ventesImpayees->pluck('numero_facture');
        $reglementsVentes = TresorerieJournal::whereIn('reference_document', $venteRefs)
            ->groupBy('reference_document')
            ->select('reference_document', DB::raw('SUM(montant_entree) as total_regle'))
            ->pluck('total_regle', 'reference_document');

        $creancesClients = [];
        foreach ($ventesImpayees as $vente) {
            $clientId = $vente->client_id ?? 0;
            $clientNom = $vente->client?->nom ?? 'Client de passage';

            if (!isset($creancesClients[$clientId])) {
                $creancesClients[$clientId] = [
                    'id' => $clientId,
                    'nom' => $clientNom,
                    'invoices' => [],
                    'total_ttc' => 0,
                    'total_regle' => 0,
                    'solde' => 0,
                ];
            }

            $regle = floatval($reglementsVentes->get($vente->numero_facture) ?? 0);

            $creancesClients[$clientId]['invoices'][] = [
                'id' => $vente->id,
                'numero' => $vente->numero_facture,
                'date' => $vente->date_vente->format('d/m/Y'),
                'ttc' => $vente->montant_ttc,
                'regle' => $regle,
                'reste' => $vente->montant_ttc - $regle
            ];

            $creancesClients[$clientId]['total_ttc'] += $vente->montant_ttc;
            $creancesClients[$clientId]['total_regle'] += $regle;
            $creancesClients[$clientId]['solde'] += ($vente->montant_ttc - $regle);
        }

        $creancesClients = array_filter($creancesClients, fn($c) => $c['solde'] > 0);

        // 2. Calcul des dettes fournisseurs
        $achatsImpayes = Achat::with(['fournisseur', 'pointDeVente'])
            ->whereHas('pointDeVente', function ($q) use ($entreprise) {
                $q->where('entreprise_id', $entreprise->id);
            })
            ->where('etape', 'Facture')
            ->whereIn('statut', ['Crédit', 'Avance'])
            ->get();

        // Optimisation N+1 : Charger la somme des règlements d'achats en une seule requête
        $achatRefs = $achatsImpayes->pluck('numero_facture');
        $reglementsAchats = TresorerieJournal::whereIn('reference_document', $achatRefs)
            ->groupBy('reference_document')
            ->select('reference_document', DB::raw('SUM(montant_sortie) as total_regle'))
            ->pluck('total_regle', 'reference_document');

        $dettesFournisseurs = [];
        foreach ($achatsImpayes as $achat) {
            $fournisseurId = $achat->fournisseur_id;
            $fournisseurNom = $achat->fournisseur?->nom ?? 'Inconnu';

            if (!isset($dettesFournisseurs[$fournisseurId])) {
                $dettesFournisseurs[$fournisseurId] = [
                    'id' => $fournisseurId,
                    'nom' => $fournisseurNom,
                    'invoices' => [],
                    'total_ttc' => 0,
                    'total_regle' => 0,
                    'solde' => 0,
                ];
            }

            $regle = floatval($reglementsAchats->get($achat->numero_facture) ?? 0);

            $dettesFournisseurs[$fournisseurId]['invoices'][] = [
                'id' => $achat->id,
                'numero' => $achat->numero_facture,
                'date' => $achat->date_achat->format('d/m/Y'),
                'ttc' => $achat->montant_ttc,
                'regle' => $regle,
                'reste' => $achat->montant_ttc - $regle
            ];

            $dettesFournisseurs[$fournisseurId]['total_ttc'] += $achat->montant_ttc;
            $dettesFournisseurs[$fournisseurId]['total_regle'] += $regle;
            $dettesFournisseurs[$fournisseurId]['solde'] += ($achat->montant_ttc - $regle);
        }

        $dettesFournisseurs = array_filter($dettesFournisseurs, fn($f) => $f['solde'] > 0);

        $banques = CodeJournal::where('type', 'Banque')->where('entreprise_id', $entreprise->id)->orderBy('intitule')->get();

        return view('admin::comptabilite.creances', compact('creancesClients', 'dettesFournisseurs', 'banques'));
    }

    /**
     * Afficher le relevé détaillé (Fiche Tiers)
     */
    public function releveTiers(string $type, int $id): View
    {
        $entreprise = Auth::user()->entreprise;

        /*
         * L'identifiant 0 designe « personne ».
         *
         * Les creances regroupent les ventes au comptoir sous `client_id`
         * nul, qui devient 0 : le bouton « Releve » menait donc a
         * `/tiers/client/0` et repondait 404 (Not Found - introuvable), sans
         * qu'on comprenne pourquoi ce seul tiers n'avait pas de releve.
         *
         * L'ecran ne propose plus le bouton dans ce cas ; l'adresse, elle,
         * reste atteignable a la main et doit dire ce qu'il en est.
         */
        abort_if($id <= 0, 404, "Ces pieces n'ont pas de fiche : elles ont ete etablies sans tiers, et il n'y a pas de releve a en tirer.");

        if ($type === 'client') {
            $tier = Client::where('entreprise_id', $entreprise->id)->findOrFail($id);

            // Récupérer toutes les factures validées du client
            $invoices = Vente::where('client_id', $id)
                ->where('etape', 'Facture')
                ->orderBy('date_vente')
                ->get();

            // Optimisation N+1 : Charger tous les règlements liés en une seule requête
            $invoiceRefs = $invoices->pluck('numero_facture');
            $reglementsGrouped = TresorerieJournal::whereIn('reference_document', $invoiceRefs)
                ->orderBy('date_operation')
                ->get()
                ->groupBy('reference_document');

            $operations = [];
            foreach ($invoices as $inv) {
                // Facturation (Débit du compte tiers)
                $operations[] = [
                    'date' => $inv->date_vente,
                    'piece' => $inv->numero_facture,
                    'libelle' => 'Facture ' . $inv->numero_facture,
                    'debit' => $inv->montant_ttc,
                    'credit' => 0,
                ];

                // Règlements associés (Crédit du compte tiers)
                $payments = $reglementsGrouped->get($inv->numero_facture) ?? collect();

                foreach ($payments as $pay) {
                    $operations[] = [
                        'date' => $pay->date_operation,
                        'piece' => $pay->reference_document,
                        'libelle' => 'Règlement ' . $pay->mode_paiement,
                        'debit' => 0,
                        'credit' => $pay->montant_entree,
                    ];
                }
            }
        } else {
            $tier = Fournisseur::where('entreprise_id', $entreprise->id)->findOrFail($id);

            // Récupérer tous les achats validés auprès de ce fournisseur
            $invoices = Achat::where('fournisseur_id', $id)
                ->where('etape', 'Facture')
                ->orderBy('date_achat')
                ->get();

            // Optimisation N+1 : Charger tous les règlements liés en une seule requête
            $invoiceRefs = $invoices->pluck('numero_facture');
            $reglementsGrouped = TresorerieJournal::whereIn('reference_document', $invoiceRefs)
                ->orderBy('date_operation')
                ->get()
                ->groupBy('reference_document');

            $operations = [];
            foreach ($invoices as $inv) {
                // Facturation d'achat (Crédit du compte tiers)
                $operations[] = [
                    'date' => $inv->date_achat,
                    'piece' => $inv->numero_facture,
                    'libelle' => 'Achat ' . $inv->numero_facture,
                    'debit' => 0,
                    'credit' => $inv->montant_ttc,
                ];

                // Règlements associés (Débit du compte tiers)
                $payments = $reglementsGrouped->get($inv->numero_facture) ?? collect();

                foreach ($payments as $pay) {
                    $operations[] = [
                        'date' => $pay->date_operation,
                        'piece' => $pay->reference_document,
                        'libelle' => 'Règlement ' . $pay->mode_paiement,
                        'debit' => $pay->montant_sortie,
                        'credit' => 0,
                    ];
                }
            }
        }

        // Trier les opérations par date chronologique
        usort($operations, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        // Calculer le solde progressif
        $solde = 0;
        foreach ($operations as &$op) {
            if ($type === 'client') {
                $solde += ($op['debit'] - $op['credit']);
            } else {
                $solde += ($op['credit'] - $op['debit']);
            }
            $op['solde'] = $solde;
        }

        return view('admin::comptabilite.releve_tiers', compact('tier', 'type', 'operations', 'solde'));
    }

    /**
     * Enregistrer un règlement
     */
    public function enregistrerReglement(Request $request): RedirectResponse
    {
        $request->validate([
            'type'            => ['required', 'string', 'in:client,fournisseur'],
            'tier_id'         => ['required', 'integer'],
            'numero_facture'  => ['required', 'string'],
            'montant'         => ['required', 'numeric', 'min:1'],
            'mode_paiement'   => ['required', 'string'],
            'date_operation'  => ['required', 'date'],
        ]);

        if ($request->mode_paiement === 'Banque') {
            $request->validate([
                'banque_id'          => ['required', 'integer', Appartenance::a('codes_journaux', 'id')],
                'moyen_bancaire'     => ['required', 'string', 'in:carte,virement,cheque'],
                'reference_paiement' => ['required', 'string', 'max:255'],
            ], [
                'banque_id.required'          => 'Veuillez sélectionner la banque.',
                'moyen_bancaire.required'     => 'Veuillez sélectionner le moyen de paiement bancaire.',
                'reference_paiement.required' => 'Veuillez saisir le numéro ou référence de paiement.',
            ]);
        }

        $entreprise = Auth::user()->entreprise;

        DB::transaction(function () use ($request, $entreprise) {
            $numFacture = $request->numero_facture;
            $montant = floatval($request->montant);
            $mode = $request->mode_paiement;
            if ($request->mode_paiement === 'Banque' && $request->filled('banque_id')) {
                $codeJournal = CodeJournal::where('entreprise_id', $entreprise->id)->findOrFail($request->banque_id);
                $mode = 'Banque : ' . $codeJournal->intitule;
            }
            $date = $request->date_operation;

            if ($request->type === 'client') {
                // Filtre par entreprise indispensable : numero_facture n'est unique
                // que PAR entreprise (chaque entreprise a sa propre séquence),
                // jamais globalement. Sans ce filtre, un utilisateur pourrait
                // enregistrer un règlement sur la facture d'une autre entreprise
                // en devinant/saisissant son numéro.
                $vente = Vente::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
                    ->where('numero_facture', $numFacture)
                    ->firstOrFail();
                if (!$vente->point_de_vente_id) {
                    $vente->point_de_vente_id = session('point_de_vente_actif_id')
                        ?? Auth::user()->point_de_vente_id
                        ?? PointDeVente::where('entreprise_id', $entreprise->id)->value('id');
                    $vente->save();
                    $vente->load('pointDeVente');
                }
                $pdvId = $vente->point_de_vente_id;
                
                // Calcul du reste à payer
                $dejaPaye = TresorerieJournal::where('reference_document', $numFacture)->sum('montant_entree');
                $reste = $vente->montant_ttc - $dejaPaye;

                if ($montant > $reste) {
                    abort(422, "Le montant du règlement ne peut pas dépasser le reste à payer (" . $reste . " F).");
                }

                // 1. Enregistrer en trésorerie
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pdvId)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id'  => $pdvId,
                    'date_operation'     => $date,
                    'type_operation'     => 'Encaissement',
                    'libelle'            => 'Règlement Client — Facture ' . $numFacture,
                    'mode_paiement'      => $mode,
                    'moyen_bancaire'     => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    'reference_paiement' => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                    'montant_entree'     => $montant,
                    'montant_sortie'     => 0,
                    'solde_resultat'     => $soldeActuel + $montant,
                    'reference_document' => $numFacture,
                ]);

                // 2. Générer l'écriture comptable
                ComptabiliteService::genererEcritureReglementVente(
                    $vente,
                    $montant,
                    $mode,
                    $date,
                    $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    $request->mode_paiement === 'Banque' ? $request->reference_paiement : null
                );

                // 3. Mettre à jour le statut de la facture
                $nouveauPaye = $dejaPaye + $montant;
                if ($nouveauPaye >= $vente->montant_ttc) {
                    $vente->update(['statut' => 'Payé']);
                } else {
                    $vente->update(['statut' => 'Avance']);
                }
            } else {
                $achat = Achat::whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id))
                    ->where('numero_facture', $numFacture)
                    ->firstOrFail();
                if (!$achat->point_de_vente_id) {
                    $achat->point_de_vente_id = session('point_de_vente_actif_id')
                        ?? Auth::user()->point_de_vente_id
                        ?? PointDeVente::where('entreprise_id', $entreprise->id)->value('id');
                    $achat->save();
                    $achat->load('pointDeVente');
                }
                $pdvId = $achat->point_de_vente_id;
                
                // Calcul du reste à payer
                $dejaPaye = TresorerieJournal::where('reference_document', $numFacture)->sum('montant_sortie');
                $reste = $achat->montant_ttc - $dejaPaye;

                if ($montant > $reste) {
                    abort(422, "Le montant du règlement ne peut pas dépasser le reste à payer (" . $reste . " F).");
                }

                // 1. Enregistrer en trésorerie
                $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pdvId)
                    ->orderByDesc('created_at')->value('solde_resultat') ?? 0;

                TresorerieJournal::create([
                    'point_de_vente_id'  => $pdvId,
                    'date_operation'     => $date,
                    'type_operation'     => 'Décaissement',
                    'libelle'            => 'Règlement Fournisseur — Facture ' . $numFacture,
                    'mode_paiement'      => $mode,
                    'moyen_bancaire'     => $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    'reference_paiement' => $request->mode_paiement === 'Banque' ? $request->reference_paiement : null,
                    'montant_entree'     => 0,
                    'montant_sortie'     => $montant,
                    'solde_resultat'     => $soldeActuel - $montant,
                    'reference_document' => $numFacture,
                ]);

                // 2. Générer l'écriture comptable
                ComptabiliteService::genererEcritureReglementAchat(
                    $achat,
                    $montant,
                    $mode,
                    $date,
                    $request->mode_paiement === 'Banque' ? $request->moyen_bancaire : null,
                    $request->mode_paiement === 'Banque' ? $request->reference_paiement : null
                );

                // 3. Mettre à jour le statut de la facture
                $nouveauPaye = $dejaPaye + $montant;
                if ($nouveauPaye >= $achat->montant_ttc) {
                    $achat->update(['statut' => 'Payé']);
                } else {
                    $achat->update(['statut' => 'Avance']);
                }
            }
        });

        return back()->with('succes', 'Règlement enregistré avec succès.');
    }

    /**
     * La balance de contrôle.
     *
     * Selflow écrit les écritures, Comptaflow les exploite. Mais un client sans
     * abonnement Comptaflow n'avait **aucun moyen de vérifier ce que Selflow
     * avait écrit** : les écritures existaient en base, et nulle part un écran
     * ne les totalisait. Une erreur d'imputation ne se voyait donc jamais.
     *
     * La période reprend le filtre commun de l'application ; le site aussi,
     * pour qu'un gérant puisse contrôler un magasin sans les autres.
     */
    public function balance(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        // La comptabilité ouverte ne suffit pas : la balance se tient dans
        // Comptaflow, et seul le superadministrateur l'accorde (08/10/2026).
        abort_unless(Auth::user()->estSuperAdmin() || $entreprise?->balanceAccordee(), 404);

        [$debut, $fin] = FiltrePeriodeService::intervalle($request);

        // Un identifiant de site venu de l'URL est confronté à l'entreprise
        // avant d'être retenu : sans cela, `?pdv_id=` afficherait la balance
        // du voisin.
        $pointDeVenteId = $request->query('pdv_id');
        $pointDeVenteId = ($pointDeVenteId && $pointDeVenteId !== 'tous')
            ? \App\Modules\Admin\Modeles\PointDeVente::where('id', $pointDeVenteId)
                ->where('entreprise_id', $entreprise->id)
                ->value('id')
            : null;

        $balance = BalanceService::etablir($entreprise->id, $debut, $fin, $pointDeVenteId);

        return view('admin::comptabilite.balance', [
            'balance'        => $balance,
            'generiques'     => BalanceService::comptesGeneriquesUtilises($balance['lignes']),
            'libellePeriode' => FiltrePeriodeService::libelle($request),
            'pointsDeVente'  => $entreprise->pointsDeVente()->orderBy('nom')->get(),
            'pointDeVenteId' => $pointDeVenteId,
        ]);
    }

    /**
     * Le grand livre.
     *
     * La balance dit combien un compte a bougé ; le grand livre dit **pourquoi**.
     * La logique — plage de comptes, soldes initiaux, solde progressif, colonne
     * de lettrage — est reprise de Comptaflow, pour que les deux applications
     * disent la même chose du même exercice.
     */
    public function grandLivre(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        [$debut, $fin] = FiltrePeriodeService::intervalle($request);

        $comptes = PlanComptable::where('entreprise_id', $entreprise->id)
            ->orderBy('numero')
            ->get();

        // Les bornes viennent de l'URL : on ne retient que des numéros qui
        // figurent au plan de **cette** entreprise. Un numéro libre laisserait
        // lire les écritures d'un compte que l'utilisateur n'a pas.
        $numeros = $comptes->pluck('numero')->all();
        $borne = fn (?string $valeur) => in_array($valeur, $numeros, true) ? $valeur : null;

        $compteDebut = $borne($request->query('compte_debut'));
        $compteFin   = $borne($request->query('compte_fin'));

        $pointDeVenteId = $this->siteDemande($request, $entreprise->id);

        $livre = GrandLivreService::etablir(
            $entreprise->id, $compteDebut, $compteFin,
            $debut?->toDateString(), $fin?->toDateString(), $pointDeVenteId
        );

        return view('admin::comptabilite.grand_livre', [
            'livre'          => $livre,
            'comptes'        => $comptes,
            'compteDebut'    => $compteDebut,
            'compteFin'      => $compteFin,
            'libellePeriode' => FiltrePeriodeService::libelle($request),
            'pointsDeVente'  => $entreprise->pointsDeVente()->orderBy('nom')->get(),
            'pointDeVenteId' => $pointDeVenteId,
        ]);
    }

    /**
     * Le résultat, site par site.
     *
     * Chaque écriture porte son `point_de_vente_id` depuis toujours — la
     * vente, l'achat, le règlement, le mouvement de stock, la dotation, la
     * consignation, l'écriture manuelle. L'information partait vers Comptaflow,
     * qui l'ignore, et **aucun écran de Selflow ne s'en servait** : la balance
     * et le grand livre savent se restreindre à un site, mais rien ne les
     * mettait côte à côte. Or c'est la seule question qui compte quand on en
     * tient plusieurs — lequel gagne de l'argent, lequel en perd.
     */
    public function analytique(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        [$debut, $fin] = FiltrePeriodeService::intervalle($request);

        $ventilation = \App\Modules\Admin\Services\AnalytiqueService::parSite(
            $entreprise->id,
            $debut?->toDateString(),
            $fin?->toDateString()
        );

        return view('admin::comptabilite.analytique', [
            'ventilation'    => $ventilation,
            'libellePeriode' => FiltrePeriodeService::libelle($request),
        ]);
    }

    /**
     * L'écran de lettrage d'un compte.
     */
    public function lettrage(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;

        $comptes = PlanComptable::where('entreprise_id', $entreprise->id)
            ->orderBy('numero')
            ->get();

        $compte = in_array($request->query('compte'), $comptes->pluck('numero')->all(), true)
            ? $request->query('compte')
            : null;

        return view('admin::comptabilite.lettrage', [
            'comptes'   => $comptes,
            'compte'    => $compte,
            'ouvertes'  => $compte ? LettrageService::ouvertes($entreprise->id, $compte) : collect(),
            'resteDu'   => $compte ? LettrageService::resteDu($entreprise->id, $compte) : 0.0,
            'lettrages' => \App\Modules\Admin\Modeles\Lettrage::where('entreprise_id', $entreprise->id)
                ->withCount('ecritures')->latest('id')->limit(20)->get(),
        ]);
    }

    /**
     * Poser un lettrage.
     */
    public function lettrer(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'compte'      => ['required', 'string', 'max:20'],
            'ecritures'   => ['required', 'array', 'min:2'],
            'ecritures.*' => ['required', 'integer'],
        ]);

        try {
            // Le service confronte lui-même les identifiants à l'entreprise :
            // une écriture du voisin n'entre pas dans un lettrage.
            $lettrage = LettrageService::lettrer(
                Auth::user()->entreprise_id,
                $donnees['compte'],
                $donnees['ecritures']
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('erreur', $e->getMessage());
        }

        return back()->with('succes', "Lettrage « {$lettrage->code} » posé.");
    }

    /**
     * Défaire un lettrage.
     */
    public function delettrer(\App\Modules\Admin\Modeles\Lettrage $lettrage): RedirectResponse
    {
        abort_unless($lettrage->entreprise_id === Auth::user()->entreprise_id, 404);

        $nombre = LettrageService::delettrer($lettrage);

        return back()->with('succes', "Lettrage défait : {$nombre} écriture(s) redeviennent ouvertes.");
    }

    /**
     * Le site demandé, confronté à l'entreprise.
     *
     * Sans ce contrôle, `?pdv_id=` afficherait les écritures du concurrent.
     */
    private function siteDemande(Request $request, int $entrepriseId): ?int
    {
        $demande = $request->query('pdv_id');

        if (!$demande || $demande === 'tous') {
            return null;
        }

        return \App\Modules\Admin\Modeles\PointDeVente::where('id', $demande)
            ->where('entreprise_id', $entrepriseId)
            ->value('id');
    }

    /**
     * Page « Plan Comptable »
     */
    public function planComptable(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        
        // 1. Comptes Locaux
        $queryLocal = \App\Modules\Admin\Modeles\PlanComptable::where(function ($q) use ($entreprise) {
            $q->whereNull('entreprise_id')
              ->orWhere('entreprise_id', $entreprise->id);
        })->where(function ($q) {
            $q->where('source', '!=', 'comptaflow')
              ->orWhereNull('source');
        });

        if ($request->filled('numero')) {
            $queryLocal->where('numero', 'like', $request->input('numero') . '%');
        }

        if ($request->filled('classe')) {
            $queryLocal->where('numero', 'like', $request->input('classe') . '%');
        }

        if ($request->filled('libelle')) {
            $queryLocal->where('libelle', 'like', '%' . $request->input('libelle') . '%');
        }

        $comptes = $queryLocal->orderBy('numero')->paginate(15, ['*'], 'page_local');

        // 2. Comptes COMPTAFLOW
        $queryComptaflow = \App\Modules\Admin\Modeles\PlanComptable::where('entreprise_id', $entreprise->id)
            ->where('source', 'comptaflow');

        if ($request->filled('numero')) {
            $queryComptaflow->where('numero', 'like', $request->input('numero') . '%');
        }

        if ($request->filled('classe')) {
            $queryComptaflow->where('numero', 'like', $request->input('classe') . '%');
        }

        if ($request->filled('libelle')) {
            $queryComptaflow->where('libelle', 'like', '%' . $request->input('libelle') . '%');
        }

        $comptesComptaflow = $queryComptaflow->orderBy('numero')->paginate(15, ['*'], 'page_comptaflow');

        return view('admin::comptabilite.plan_comptable', compact('comptes', 'comptesComptaflow', 'entreprise'));
    }

    /**
     * Créer un compte dans le Plan Comptable
     */
    /**
     * Poser le plan comptable par défaut, en entier.
     *
     * Le trousseau ne se posait qu'à la création de l'entreprise : une
     * entreprise créée avant qu'un compte entre au référentiel ne l'obtenait
     * plus par aucun chemin, et devait le saisir à la main en devinant son
     * numéro. Le bouton rejoue la dotation.
     *
     * Rien n'est écrasé : les comptes déjà là gardent leur libellé, y compris
     * ceux que l'entreprise a renommés. Seul ce qui manque est ajouté.
     */
    public function poserLePlanParDefaut(): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $bilan = \App\Modules\Admin\Services\TrousseauEntrepriseService::doter($entreprise);

        return back()->with('succes', $bilan['comptes'] > 0
            ? "{$bilan['comptes']} compte(s) ajouté(s) au plan. Ce qui existait n'a pas bougé."
            : 'Votre plan comptable porte déjà tous les comptes du référentiel.');
    }

    public function creerCompteComptable(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'numero'  => [
                'required',
                'string',
                'max:20',
                \Illuminate\Validation\Rule::unique('plan_comptable', 'numero')->where('entreprise_id', $entreprise->id)
            ],
            'libelle' => ['required', 'string', 'max:255'],
        ], [
            'numero.required' => 'Le numéro de compte est obligatoire.',
            'numero.unique'   => 'Ce numéro de compte existe déjà pour votre entreprise.',
            'libelle.required' => 'Le libellé est obligatoire.',
        ]);

        \App\Modules\Admin\Modeles\PlanComptable::create(array_merge(
            $request->only(['numero', 'libelle']),
            ['entreprise_id' => $entreprise->id]
        ));

        return back()->with('succes', 'Compte comptable créé avec succès.');
    }

    /**
     * Enregistrer une écriture comptable manuelle (OD), avec un nombre de
     * lignes illimité (N débits / N crédits). Remplace l'ancienne saisie
     * limitée à un seul compte au débit et un seul au crédit.
     *
     * Format attendu (voir vue globale.blade.php) :
     *   lignes[0][compte] = '601000', lignes[0][sens] = 'debit', lignes[0][montant] = 50000, lignes[0][compte_tiers] = null
     *   lignes[1][compte] = '571000', lignes[1][sens] = 'credit', lignes[1][montant] = 50000
     *   ...
     */
    public function creerEcritureManuelle(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'date_ecriture'          => ['required', 'date'],
            'libelle'                => ['required', 'string', 'max:255'],
            'description'            => ['nullable', 'string', 'max:2000'],
            'code_journal'           => ['required', 'string', 'max:10'],
            'point_de_vente_id'      => ['nullable', 'integer', Appartenance::a('points_de_vente', 'id')],
            'lignes'                 => ['required', 'array', 'min:2'],
            'lignes.*.compte'        => ['required', 'string', 'max:50'],
            'lignes.*.compte_tiers'  => ['nullable', 'string', 'max:50'],
            'lignes.*.sens'          => ['required', 'in:debit,credit'],
            'lignes.*.montant'       => ['required', 'numeric', 'min:0.01'],
        ]);

        $pdvId = $request->point_de_vente_id;
        if (!$pdvId) {
            $pdvId = session('point_de_vente_actif_id')
                ?? Auth::user()->point_de_vente_id
                ?? PointDeVente::where('entreprise_id', $entreprise->id)->value('id');
        }

        // Contrôle d'équilibre strict AVANT toute écriture en base — une
        // écriture déséquilibrée ne doit jamais pouvoir être enregistrée,
        // même en cas d'erreur de saisie ou de manipulation du formulaire.
        $totalDebit = 0;
        $totalCredit = 0;
        foreach ($request->lignes as $ligne) {
            if ($ligne['sens'] === 'debit') {
                $totalDebit += (float) $ligne['montant'];
            } else {
                $totalCredit += (float) $ligne['montant'];
            }
        }
        if (abs($totalDebit - $totalCredit) >= 0.01) {
            return back()->withInput()->with('error',
                "❌ Écriture déséquilibrée : Total Débit (" . number_format($totalDebit, 0, ',', ' ') .
                " F) ≠ Total Crédit (" . number_format($totalCredit, 0, ',', ' ') . " F). Corrigez avant d'enregistrer."
            );
        }

        $numPiece = \App\Modules\Admin\Services\NumerotationService::genererNumeroOD($entreprise->id);
        $date = $request->date_ecriture;

        DB::transaction(function () use ($entreprise, $pdvId, $request, $numPiece, $date) {
            $operation = Operation::creer(
                $entreprise->id, $pdvId, $date, 'OD',
                $request->code_journal, $numPiece, $request->libelle
            );

            foreach ($request->lignes as $ligne) {
                EcritureComptable::create([
                    'operation_id'       => $operation->id,
                    'entreprise_id'      => $entreprise->id,
                    'point_de_vente_id'  => $pdvId,
                    'date_ecriture'      => $date,
                    'libelle'            => $request->libelle,
                    'description'        => $request->description,
                    'reference_document' => $numPiece,
                    'code_journal'       => $request->code_journal,
                    'compte_debit'       => $ligne['sens'] === 'debit' ? $ligne['compte'] : null,
                    'compte_credit'      => $ligne['sens'] === 'credit' ? $ligne['compte'] : null,
                    'compte_tiers'       => $ligne['compte_tiers'] ?? null,
                    'debit'              => $ligne['sens'] === 'debit' ? (float) $ligne['montant'] : 0,
                    'credit'             => $ligne['sens'] === 'credit' ? (float) $ligne['montant'] : 0,
                ]);
            }

            $operation->cloturerEquilibre();
        });

        return back()->with('succes', 'Écriture manuelle enregistrée avec succès.');
    }
}
