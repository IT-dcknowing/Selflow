<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Le support corrige l'identité d'une entreprise (24/11/2026) : son nom, son
 * e-mail, l'adresse de connexion de son responsable. Habilitation
 * `identite_entreprises`, distincte de la gestion complète des entreprises.
 */
class IdentiteEntrepriseControleur extends Controller
{
    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('q', ''));
        $entreprises = Entreprise::horsCorbeille()
            ->when($recherche !== '', fn ($q) => $q->where(fn ($w) => $w->where('nom', 'like', "%{$recherche}%")
                ->orWhere('ncc', 'like', "%{$recherche}%")->orWhere('email', 'like', "%{$recherche}%")))
            ->orderBy('nom')->paginate(20)->withQueryString();
        $responsables = Utilisateur::whereIn('entreprise_id', $entreprises->pluck('id'))->where('role', 'admin')
            ->orderBy('id')->get()->unique('entreprise_id')->keyBy('entreprise_id');

        return view('admin::superadmin.identites.index', compact('entreprises', 'responsables', 'recherche'));
    }

    public function enregistrer(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $responsable = self::responsable($entreprise);
        $nom = preg_replace('/\s+/u', ' ', trim((string) $request->input('nom')));
        $request->merge(['nom' => $nom]);

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150', Rule::unique('entreprises', 'nom')->ignore($entreprise->id)],
            'email' => ['nullable', 'email', 'max:150'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'responsable_email' => $responsable
                ? ['required', 'email', 'max:150', Rule::unique('utilisateurs', 'email')->ignore($responsable->id)]
                : ['nullable'],
        ], ['responsable_email.unique' => 'Cette adresse est déjà utilisée par un autre compte.', 'nom.unique' => 'Une entreprise porte déjà ce nom.']);

        $avant = ['nom' => $entreprise->nom, 'email' => $entreprise->email, 'responsable' => $responsable?->email];
        $entreprise->forceFill([
            'nom' => $donnees['nom'],
            'email' => $donnees['email'] ?? null,
            'telephone' => $donnees['telephone'] ?? $entreprise->telephone,
        ])->save();
        if ($responsable && ! empty($donnees['responsable_email'])) {
            $responsable->forceFill(['email' => strtolower(trim($donnees['responsable_email']))])->save();
        }

        Log::notice('[support] identité d\'entreprise modifiée', ['entreprise' => $entreprise->id, 'par' => Auth::id(), 'avant' => $avant,
            'apres' => ['nom' => $entreprise->nom, 'email' => $entreprise->email, 'responsable' => $responsable?->fresh()->email]]);

        return back()->with('succes', '« ' . $entreprise->nom . ' » : identité enregistrée.'
            . ($responsable && $avant['responsable'] !== $responsable->fresh()->email ? ' Le responsable se connecte désormais avec ' . $responsable->fresh()->email . '.' : ''));
    }

    public static function responsable(Entreprise $entreprise): ?Utilisateur
    {
        return Utilisateur::where('entreprise_id', $entreprise->id)->where('role', 'admin')->orderBy('id')->first();
    }
}
