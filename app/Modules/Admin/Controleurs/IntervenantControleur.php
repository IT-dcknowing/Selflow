<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Intervenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Les prestataires d'une entreprise de service (23/11/2026, salon FOR MEN) :
 * qui a fait quoi, pour quel client, et pour combien.
 */
class IntervenantControleur extends Controller
{
    public function index(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless(Intervenant::moduleActif($entreprise), 404);

        $du = Carbon::parse($request->query('du', now()->startOfMonth()->toDateString()))->startOfDay();
        $au = Carbon::parse($request->query('au', now()->toDateString()))->endOfDay();

        // Ce que chacun a réalisé sur la période : factures seulement, hors annulées.
        $chiffres = DB::table('vente_details as d')
            ->join('ventes as v', 'v.id', '=', 'd.vente_id')
            ->join('points_de_vente as p', 'p.id', '=', 'v.point_de_vente_id')
            ->where('p.entreprise_id', $entreprise->id)
            ->whereNotNull('d.intervenant_id')
            ->where('v.etape', 'Facture')
            ->where('v.statut', '!=', \App\Modules\Admin\Modeles\Vente::STATUT_ANNULEE)
            ->whereDate('v.date_vente', '>=', $du->toDateString())->whereDate('v.date_vente', '<=', $au->toDateString())
            ->groupBy('d.intervenant_id')
            ->selectRaw('d.intervenant_id, COUNT(*) as prestations, COUNT(DISTINCT v.client_id) as clients, SUM(d.montant_ttc) as chiffre')
            ->get()->keyBy('intervenant_id');

        $dernieres = DB::table('vente_details as d')
            ->join('ventes as v', 'v.id', '=', 'd.vente_id')
            ->join('points_de_vente as p', 'p.id', '=', 'v.point_de_vente_id')
            ->leftJoin('clients as c', 'c.id', '=', 'v.client_id')
            ->leftJoin('produits as pr', 'pr.id', '=', 'd.produit_id')
            ->leftJoin('intervenants as i', 'i.id', '=', 'd.intervenant_id')
            ->where('p.entreprise_id', $entreprise->id)
            ->whereNotNull('d.intervenant_id')
            ->where('v.etape', 'Facture')
            ->where('v.statut', '!=', \App\Modules\Admin\Modeles\Vente::STATUT_ANNULEE)
            ->whereDate('v.date_vente', '>=', $du->toDateString())->whereDate('v.date_vente', '<=', $au->toDateString())
            ->orderByDesc('v.date_vente')->orderByDesc('d.id')
            ->limit(50)
            ->get(['v.date_vente', 'v.numero_facture', 'c.nom as client', 'i.nom as intervenant', DB::raw('COALESCE(pr.nom, d.libelle_virtuel) as prestation'), 'd.montant_ttc']);

        return view('admin::intervenants.index', [
            'intervenants' => Intervenant::where('entreprise_id', $entreprise->id)->orderByDesc('actif')->orderBy('nom')->get(),
            'chiffres' => $chiffres,
            'dernieres' => $dernieres,
            'du' => $du,
            'au' => $au,
        ]);
    }

    public function creer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless(Intervenant::moduleActif($entreprise), 404);
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'specialite' => ['nullable', 'string', 'max:120'],
            'telephone' => ['nullable', 'string', 'max:40'],
        ]);
        Intervenant::create($donnees + ['entreprise_id' => $entreprise->id, 'actif' => true]);

        return back()->with('succes', $donnees['nom'] . ' est ajouté(e) aux prestataires.');
    }

    public function basculer(Intervenant $intervenant): RedirectResponse
    {
        abort_unless((int) $intervenant->entreprise_id === (int) Auth::user()->entreprise_id, 404);
        $intervenant->update(['actif' => ! $intervenant->actif]);

        return back()->with('succes', $intervenant->nom . ($intervenant->actif ? ' est de nouveau proposé(e) sur les ventes.' : ' n\'est plus proposé(e) sur les ventes. Son historique reste.'));
    }
}
