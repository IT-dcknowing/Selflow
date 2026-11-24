<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Vente;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Les prestataires externes (hôpitaux, cliniques) d'un laboratoire,
 * 24/11/2026 : chiffre d'affaires, patients envoyés et ce qu'ils doivent encore.
 */
class PrestataireExterneControleur extends Controller
{
    public function index(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($entreprise->aAttribution('apporteurs'), 404);

        $du = Carbon::parse($request->query('du', now()->startOfYear()->toDateString()))->toDateString();
        $au = Carbon::parse($request->query('au', now()->toDateString()))->toDateString();

        $base = DB::table('ventes as v')
            ->join('points_de_vente as p', 'p.id', '=', 'v.point_de_vente_id')
            ->join('clients as c', 'c.id', '=', 'v.apporteur_id')
            ->where('p.entreprise_id', $entreprise->id)
            ->whereNotNull('v.apporteur_id')
            ->where('v.etape', 'Facture')
            ->where('v.statut', '!=', Vente::STATUT_ANNULEE)
            ->whereDate('v.date_vente', '>=', $du)->whereDate('v.date_vente', '<=', $au);

        $lignes = (clone $base)
            ->groupBy('v.apporteur_id', 'c.nom', 'c.telephone')
            ->selectRaw('v.apporteur_id as id, c.nom, c.telephone, COUNT(*) as factures,
                SUM(v.montant_ttc + COALESCE(v.montant_autres_taxes, 0)) as chiffre')
            ->orderByDesc('chiffre')
            ->get();

        // Ce qui reste dû : les factures non soldées, une par une (le payé est
        // la somme des encaissements, pas une colonne).
        $ids = $lignes->pluck('id')->all();
        $duParPrestataire = Vente::whereIn('apporteur_id', $ids)
            ->where('etape', 'Facture')->where('statut', '!=', Vente::STATUT_ANNULEE)->where('statut', '!=', 'Payé')
            ->get()
            ->groupBy('apporteur_id')
            ->map(fn ($ventes) => $ventes->sum(fn ($v) => max(0, (float) $v->montant_ttc + (float) ($v->montant_autres_taxes ?? 0) - (float) $v->montant_paye)));
        $lignes->each(fn ($l) => $l->du_reste = (float) ($duParPrestataire[$l->id] ?? 0));

        $patients = \App\Support\Structure::aTable('vente_patients')
            ? (clone $base)->join('vente_patients as vp', 'vp.vente_id', '=', 'v.id')
                ->groupBy('v.apporteur_id')->selectRaw('v.apporteur_id as id, COUNT(DISTINCT vp.client_id) as patients')
                ->pluck('patients', 'id')
            : collect();

        return view('admin::prestataires-externes.index', compact('lignes', 'patients', 'du', 'au'));
    }
}
