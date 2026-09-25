<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Banque;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TresorerieControleur
{
    public function encaissements(): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = Auth::user()->estCaissier()
            ? Auth::user()->point_de_vente_id
            : session('point_de_vente_actif_id');

        $query = TresorerieJournal::with('pointDeVente')
            ->where(function($q) {
                $q->whereIn('type_operation', ['recette', 'Encaissement', 'encaissement'])
                  ->orWhere('montant_entree', '>', 0);
            });

        if ($pointDeVenteId) {
            $query->where('point_de_vente_id', $pointDeVenteId);
        } else {
            $query->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));
        }

        $operations = $query->latest('date_operation')->latest('id')->paginate(30);

        return view('admin::tresorerie.encaissements', compact('operations'));
    }

    public function decaissements(): View
    {
        $entreprise = Auth::user()->entreprise;
        $pointDeVenteId = Auth::user()->estCaissier()
            ? Auth::user()->point_de_vente_id
            : session('point_de_vente_actif_id');

        $query = TresorerieJournal::with('pointDeVente')
            ->where(function($q) {
                $q->whereIn('type_operation', ['depense', 'Décaissement', 'dépense', 'decaissement'])
                  ->orWhere('montant_sortie', '>', 0);
            });

        if ($pointDeVenteId) {
            $query->where('point_de_vente_id', $pointDeVenteId);
        } else {
            $query->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));
        }

        $operations = $query->latest('date_operation')->latest('id')->paginate(30);

        return view('admin::tresorerie.decaissements', compact('operations'));
    }

    public function journal(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        $role = Auth::user()->role;
        $pointsDeVente = $entreprise->pointsDeVente()->orderBy('nom')->get();

        // Récupérer le point de vente à filtrer
        $pointDeVenteId = $request->input('point_de_vente_id');
        if ($pointDeVenteId === null) {
            $pointDeVenteId = Auth::user()->estCaissier()
                ? Auth::user()->point_de_vente_id
                : session('point_de_vente_actif_id') ?? 'tous';
        }

        $query = TresorerieJournal::with('pointDeVente');

        // Filtrage Point de Vente
        if ($pointDeVenteId !== 'tous' && !empty($pointDeVenteId)) {
            $query->where('point_de_vente_id', $pointDeVenteId);
        } else {
            $query->whereHas('pointDeVente', fn($q) => $q->where('entreprise_id', $entreprise->id));
        }

        // Filtrage Mode de paiement
        if ($request->filled('mode_paiement')) {
            $query->where('mode_paiement', $request->mode_paiement);
        }

        // Filtrage Banque (Moyen Bancaire)
        if ($request->filled('moyen_bancaire')) {
            $query->where('moyen_bancaire', $request->moyen_bancaire);
        }

        // Récupérer la liste des modes de paiement et moyens bancaires uniques existants en base pour cette entreprise
        $pdvIds = $pointsDeVente->pluck('id');
        $modesDisponibles = TresorerieJournal::whereIn('point_de_vente_id', $pdvIds)
            ->whereNotNull('mode_paiement')
            ->distinct()
            ->pluck('mode_paiement');

        $moyensBancairesDisponibles = TresorerieJournal::whereIn('point_de_vente_id', $pdvIds)
            ->whereNotNull('moyen_bancaire')
            ->where('moyen_bancaire', '!=', '')
            ->distinct()
            ->pluck('moyen_bancaire');

        // Calculer les totaux de trésorerie sur l'ensemble filtré (avant pagination)
        $totalEntrees = (clone $query)->sum('montant_entree');
        $totalSorties = (clone $query)->sum('montant_sortie');
        $soldeFinal   = $totalEntrees - $totalSorties;

        $operations = $query->latest()->paginate(30)->withQueryString();

        return view('admin::tresorerie.journal', compact(
            'operations',
            'totalEntrees',
            'totalSorties',
            'soldeFinal',
            'pointsDeVente',
            'pointDeVenteId',
            'modesDisponibles',
            'moyensBancairesDisponibles'
        ));
    }

    public function codesJournaux(): View
    {
        $entreprise = Auth::user()->entreprise;
        
        $codes = CodeJournal::where('entreprise_id', $entreprise->id)
            ->where(function ($q) {
                $q->where('source', '!=', 'comptaflow')
                  ->orWhereNull('source');
            })
            ->latest()
            ->get();

        $codesComptaflow = CodeJournal::where('entreprise_id', $entreprise->id)
            ->where('source', 'comptaflow')
            ->latest()
            ->get();

        return view('admin::tresorerie.codes_journaux', compact('codes', 'codesComptaflow', 'entreprise'));
    }

    /**
     * Poser les journaux par défaut.
     *
     * Même motif que pour le plan comptable : le trousseau ne se posait qu'à
     * la création de l'entreprise. Une entreprise créée avant qu'un journal
     * entre au référentiel — le mobile money, par exemple — ne l'obtenait plus
     * par aucun chemin.
     *
     * La dotation pose aussi le compte de trésorerie de chaque journal créé :
     * un journal de banque dont le 521 n'existe pas au plan laisserait
     * l'écriture de règlement sans imputation.
     */
    public function poserLesJournauxParDefaut(): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $bilan = \App\Modules\Admin\Services\TrousseauEntrepriseService::doter($entreprise);

        $dit = [];
        if ($bilan['journaux'] > 0) {
            $dit[] = "{$bilan['journaux']} journal/journaux ajouté(s)";
        }
        if ($bilan['comptes'] > 0) {
            $dit[] = "{$bilan['comptes']} compte(s) ajouté(s) au plan";
        }

        return back()->with('succes', $dit
            ? implode(' et ', $dit) . ". Ce qui existait n'a pas bougé."
            : 'Vos journaux portent déjà tout ce que le référentiel propose.');
    }

    public function creerCodeJournal(Request $request): RedirectResponse
    {
        // Le compte de contrepartie n'a de sens que sur un journal de
        // trésorerie : c'est le 521 de la banque ou le 571 de la caisse, celui
        // que toute écriture du journal met en jeu. Un journal de ventes ou
        // d'achats n'en a pas — sa contrepartie est le tiers de la pièce. Le
        // champ était pourtant exigé pour les cinq types, et il fallait
        // inventer une valeur pour créer un journal de ventes.
        $request->validate([
            'type'     => ['required', 'string', Rule::in(CodeJournal::TYPES)],
            'code'     => 'required|string|max:50',
            'intitule' => 'required|string|max:255',
            'compte'   => [
                CodeJournal::porteUnCompteDeTresorerie($request->input('type')) ? 'required' : 'nullable',
                'string', 'max:50',
            ],
        ], [
            'compte.required' => 'Un journal de trésorerie porte le compte qu\'il mouvemente : 521… pour une banque, 571… pour une caisse.',
        ]);

        CodeJournal::create([
            'entreprise_id' => Auth::user()->entreprise_id,
            'type'          => $request->type,
            'code'          => $request->code,
            'intitule'      => $request->intitule,
            // Un compte saisi puis le type changé pour « Vente » resterait
            // sinon collé au journal, invisible à l'écran.
            'compte'        => CodeJournal::porteUnCompteDeTresorerie($request->type)
                ? $request->compte
                : null,
        ]);

        return redirect()->back()->with('succes', 'Code journal créé avec succès !');
    }

    /**
     * Renommer un journal, et — s'il est de trésorerie — changer son compte.
     *
     * Le trousseau pose des intitulés génériques, et une entreprise doit
     * pouvoir les faire siens sans passer par une suppression suivie d'une
     * recréation — qui lui ferait perdre le rattachement de ses écritures.
     *
     * **Le code ne se change pas.** Il est la clé sous laquelle les écritures
     * déjà passées sont rangées : le renommer les détacherait de leur journal,
     * et un grand livre ne se réécrit pas.
     *
     * **Le type non plus.** Passer un journal de banque en journal de ventes
     * lui retirerait son compte de contrepartie, et les écritures qui le
     * mouvementent resteraient sans lui.
     */
    public function modifierCodeJournal(Request $request, CodeJournal $code): RedirectResponse
    {
        abort_unless($code->entreprise_id === Auth::user()->entreprise_id, 404);

        $request->validate([
            'intitule' => ['required', 'string', 'max:255'],
            'compte'   => [
                CodeJournal::porteUnCompteDeTresorerie($code->type) ? 'required' : 'nullable',
                'string', 'max:50',
            ],
        ], [
            'compte.required' => 'Un journal de trésorerie porte le compte qu\'il mouvemente : 521… pour une banque, 571… pour une caisse.',
        ]);

        $code->update([
            'intitule' => $request->input('intitule'),
            // Hors trésorerie, le compte reste nul : la contrepartie d'une
            // vente est le tiers de la pièce, et il change à chaque écriture.
            'compte'   => CodeJournal::porteUnCompteDeTresorerie($code->type)
                ? $request->input('compte')
                : null,
        ]);

        return redirect()->back()->with('succes', 'Journal « ' . $code->code . ' » mis à jour.');
    }

    public function supprimerCodeJournal(CodeJournal $code): RedirectResponse
    {
        abort_unless($code->entreprise_id === Auth::user()->entreprise_id, 404);
        $code->delete();
        return redirect()->back()->with('succes', 'Code journal supprimé avec succès !');
    }

    public function creerBanqueAjax(Request $request): JsonResponse
    {
        $request->validate([
            'code'     => 'required|string|max:50',
            'intitule' => 'required|string|max:255',
            'compte'   => 'required|string|max:50',
        ]);

        $journal = CodeJournal::create([
            'entreprise_id' => Auth::user()->entreprise_id,
            'type'          => 'Banque',
            'code'          => strtoupper($request->code),
            'intitule'      => $request->intitule,
            'compte'        => $request->compte,
        ]);

        return response()->json([
            'succes' => true,
            'banque' => [
                'id'            => $journal->id,
                'nom'           => $journal->intitule,
                'numero_compte' => $journal->code . ' - ' . $journal->compte,
                'code'          => $journal->code,
                'intitule'      => $journal->intitule,
                'compte'        => $journal->compte,
            ]
        ]);
    }
}
