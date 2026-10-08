<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\DemandeExercicesAnterieurs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * La comptabilité des exercices antérieurs, côté plateforme (propriétaire,
 * 08/10/2026) : le superadministrateur valide une demande en choisissant le ou
 * les exercices qu'il accorde, ou la refuse.
 */
class SuperadminExercicesAnterieursControleur
{
    public function index(): View
    {
        $demandes = DemandeExercicesAnterieurs::with(['entreprise', 'demandeur'])
            ->orderByRaw("CASE WHEN statut = 'en_attente' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin::superadmin.exercices_anterieurs.index', compact('demandes'));
    }

    public function valider(Request $request, DemandeExercicesAnterieurs $demande): RedirectResponse
    {
        if ($demande->statut !== DemandeExercicesAnterieurs::EN_ATTENTE) {
            return back()->with('error', 'Cette demande a déjà été traitée.');
        }

        // Le superadministrateur choisit parmi les années DEMANDÉES : il ne
        // peut pas accorder un exercice que l'entreprise n'a pas réclamé.
        $donnees = $request->validate([
            'annees'   => ['required', 'array', 'min:1'],
            'annees.*' => ['integer', Rule::in($demande->annees_demandees)],
        ], [
            'annees.required' => 'Cochez au moins un exercice à accorder, ou refusez la demande.',
        ]);

        $accordees = collect($donnees['annees'])->map(fn ($a) => (int) $a)->unique()->sort()->values()->all();

        $demande->update([
            'statut'           => DemandeExercicesAnterieurs::VALIDEE,
            'annees_accordees' => $accordees,
            'traitee_par'      => Auth::id(),
            'traitee_at'       => now(),
        ]);

        Log::info('[Exercices antérieurs] Demande validée', [
            'demande' => $demande->id, 'entreprise' => $demande->entreprise_id,
            'accordees' => $accordees, 'par' => Auth::id(),
        ]);

        return back()->with('success', sprintf(
            '« %s » : exercice(s) %s accordé(s). L\'entreprise peut lancer leur déversement.',
            $demande->entreprise?->nom, implode(', ', $accordees)
        ));
    }

    public function refuser(Request $request, DemandeExercicesAnterieurs $demande): RedirectResponse
    {
        if ($demande->statut !== DemandeExercicesAnterieurs::EN_ATTENTE) {
            return back()->with('error', 'Cette demande a déjà été traitée.');
        }

        $donnees = $request->validate(['motif_refus' => ['nullable', 'string', 'max:500']]);

        $demande->update([
            'statut'      => DemandeExercicesAnterieurs::REFUSEE,
            'motif_refus' => $donnees['motif_refus'] ?? null,
            'traitee_par' => Auth::id(),
            'traitee_at'  => now(),
        ]);

        return back()->with('success', "La demande de « {$demande->entreprise?->nom} » est refusée.");
    }
}
