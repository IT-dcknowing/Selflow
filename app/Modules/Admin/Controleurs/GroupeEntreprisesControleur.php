<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\GroupeEntreprises;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Les groupes d'entreprises (23/11/2026) : le superadministrateur lie des
 * entreprises, désigne les administrateurs qui peuvent passer de l'une à
 * l'autre, et active le groupe. L'administrateur autorisé change ensuite
 * d'entreprise depuis son menu de profil.
 */
class GroupeEntreprisesControleur extends Controller
{
    // ══════════════ Superadministrateur ══════════════

    public function index(): View
    {
        return view('admin::superadmin.groupes.index', [
            'groupes'     => GroupeEntreprises::with(['entreprises:id,nom', 'utilisateurs:id,nom,prenom,email,entreprise_id'])->orderBy('nom')->get(),
            'entreprises' => Entreprise::orderBy('nom')->get(['id', 'nom', 'ncc']),
        ]);
    }

    public function creer(Request $request): RedirectResponse
    {
        $donnees = $request->validate(['nom' => ['required', 'string', 'max:120']]);
        GroupeEntreprises::create(['nom' => $donnees['nom'], 'actif' => false]);

        return back()->with('succes', 'Groupe créé. Ajoutez ses entreprises et ses administrateurs, puis activez-le.');
    }

    /** Les entreprises du groupe et les administrateurs autorisés, en une fois. */
    public function enregistrer(Request $request, GroupeEntreprises $groupe): RedirectResponse
    {
        $donnees = $request->validate([
            'nom'              => ['required', 'string', 'max:120'],
            'entreprises'      => ['array'],
            'entreprises.*'    => ['integer', 'exists:entreprises,id'],
            'utilisateurs'     => ['array'],
            'utilisateurs.*'   => ['integer', 'exists:utilisateurs,id'],
        ]);

        $entreprises = collect($donnees['entreprises'] ?? [])->map(fn ($id) => (int) $id)->unique();

        // Seuls des administrateurs d'une entreprise du groupe peuvent y être
        // autorisés : on ne donne pas accès à un groupe à un inconnu.
        $utilisateurs = Utilisateur::whereIn('id', $donnees['utilisateurs'] ?? [])
            ->where('role', 'admin')
            ->whereIn('entreprise_id', $entreprises)
            ->pluck('id');

        DB::transaction(function () use ($groupe, $donnees, $entreprises, $utilisateurs) {
            $groupe->update(['nom' => $donnees['nom']]);
            $groupe->entreprises()->sync($entreprises->all());
            $groupe->utilisateurs()->sync($utilisateurs->all());
        });

        return back()->with('succes', 'Groupe « ' . $groupe->nom . ' » enregistré : '
            . $entreprises->count() . ' entreprise(s), ' . $utilisateurs->count() . ' administrateur(s) autorisé(s).');
    }

    public function basculer(GroupeEntreprises $groupe): RedirectResponse
    {
        if (!$groupe->actif && $groupe->entreprises()->count() < 2) {
            return back()->with('erreur', 'Un groupe se lie à partir de deux entreprises.');
        }

        $groupe->update(['actif' => !$groupe->actif]);

        return back()->with('succes', 'Groupe « ' . $groupe->nom . ' » ' . ($groupe->actif ? 'activé' : 'désactivé') . '.');
    }

    public function supprimer(GroupeEntreprises $groupe): RedirectResponse
    {
        $nom = $groupe->nom;
        $groupe->delete();

        return back()->with('succes', "Groupe « {$nom} » supprimé.");
    }

    // ══════════════ Administrateur autorisé ══════════════

    /** Passer à une autre entreprise du groupe. */
    public function changer(Request $request, Entreprise $entreprise): RedirectResponse
    {
        $utilisateur = Auth::user();
        $accessibles = GroupeEntreprises::entreprisesAccessibles($utilisateur);

        abort_unless($accessibles->contains('id', $entreprise->id), 403, 'Accès refusé : cette entreprise n’est pas dans un groupe qui vous est ouvert.');

        if ((int) $utilisateur->entreprise_id === (int) $entreprise->id) {
            return back();
        }

        $depart = $utilisateur->entreprise?->nom;

        // Le compte bascule : l'entreprise et ce qui en dépendait — le point
        // de vente, la période, le site actif — repartent de la nouvelle.
        $utilisateur->forceFill([
            'entreprise_id'     => $entreprise->id,
            'point_de_vente_id' => PointDeVente::where('entreprise_id', $entreprise->id)->count() === 1
                ? PointDeVente::where('entreprise_id', $entreprise->id)->value('id') : null,
        ])->save();

        $request->session()->forget(['point_de_vente_actif_id', 'point_de_vente_actif_nom', 'active_periode_id', 'periode_active']);

        try {
            Log::info('[groupe] ' . $utilisateur->email . " passe de « {$depart} » à « {$entreprise->nom} ».");
        } catch (\Throwable) {
        }

        return redirect()->route('admin.tableau_de_bord')->with('succes', "Vous travaillez maintenant dans « {$entreprise->nom} ».");
    }
}
