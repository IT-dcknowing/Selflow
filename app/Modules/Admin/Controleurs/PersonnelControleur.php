<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Services\CacheService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Modules\Admin\Traits\JournaliseActions;
use Illuminate\View\View;
use App\Modules\Admin\Regles\Appartenance;

class PersonnelControleur
{
    use JournaliseActions;
    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;
        
        $personnels = Utilisateur::where('entreprise_id', $entreprise->id)
            ->with('pointDeVente')
            ->orderBy('nom')
            ->get();

        $pointsDeVente = CacheService::pointsDeVente($entreprise->id);

        return view('admin::personnel.index', compact('personnels', 'pointsDeVente'));
    }

    public function creer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'nom'                => ['required', 'string', 'max:150'],
            'prenom'             => ['required', 'string', 'max:150'],
            'email'              => ['required', 'email', 'max:150', Rule::unique('utilisateurs', 'email')],
            'password'           => ['required', 'string', 'min:6'],
            'role'               => ['required', 'string', Rule::in(['admin', 'admin_secondaire', 'responsable_pdv', 'caissier'])],
            'point_de_vente_id'  => ['nullable', Appartenance::a('points_de_vente', 'id')],
            'fonction'           => ['nullable', 'string', 'max:150'],
            'date_debut_contrat' => ['nullable', 'date'],
            'date_fin_contrat'   => ['nullable', 'date', 'after_or_equal:date_debut_contrat'],
            'notes'              => ['nullable', 'string'],
            'habilitations'      => ['nullable', 'array'],
        ]);

        $personnel = Utilisateur::create([
            'entreprise_id'         => $entreprise->id,
            'point_de_vente_id'     => $request->point_de_vente_id,
            'nom'                   => $request->nom,
            'prenom'                => $request->prenom,
            'email'                 => $request->email,
            'password'              => Hash::make($request->password),
            'role'                  => $request->role,
            'fonction'              => $request->fonction,
            'date_debut_contrat'    => $request->date_debut_contrat,
            'date_fin_contrat'      => $request->date_fin_contrat,
            'statut'                => 'actif',
            'notes'                 => $request->notes,
            'habilitations'         => $request->habilitations ?? [],
            'doit_changer_password' => true, // Forcer le changement à la 1ère connexion (Section 13)
        ]);

        $this->journaliser('creation_personnel', 'Utilisateur', $personnel->id, null, $personnel->toArray());

        $avis = \App\Mail\EnvoiCourriel::annoncerCompte($personnel, true) ? '' : \App\Mail\EnvoiCourriel::AVIS_NON_PARTI;

        return redirect()->route('admin.personnel.index')->with('succes', 'Membre du personnel créé avec succès.' . $avis);
    }

    public function details(Utilisateur $personnel): View
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($personnel->entreprise_id === $entreprise->id, 404);

        $pointsDeVente = PointDeVente::where('entreprise_id', $entreprise->id)
            ->orderBy('nom')
            ->get();

        return view('admin::personnel.details', compact('personnel', 'pointsDeVente'));
    }

    public function modifier(Request $request, Utilisateur $personnel): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($personnel->entreprise_id === $entreprise->id, 404);

        $request->validate([
            'nom'                => ['required', 'string', 'max:150'],
            'prenom'             => ['required', 'string', 'max:150'],
            'email'              => ['required', 'email', 'max:150', Rule::unique('utilisateurs', 'email')->ignore($personnel->id)],
            'password'           => ['nullable', 'string', 'min:6'],
            'role'               => ['required', 'string', Rule::in(['admin', 'admin_secondaire', 'responsable_pdv', 'caissier'])],
            'point_de_vente_id'  => ['nullable', Appartenance::a('points_de_vente', 'id')],
            'fonction'           => ['nullable', 'string', 'max:150'],
            'date_debut_contrat' => ['nullable', 'date'],
            'date_fin_contrat'   => ['nullable', 'date', 'after_or_equal:date_debut_contrat'],
            'notes'              => ['nullable', 'string'],
            'habilitations'      => ['nullable', 'array'],
        ]);

        $data = [
            'nom'                => $request->nom,
            'prenom'             => $request->prenom,
            'email'              => $request->email,
            'role'               => $request->role,
            'point_de_vente_id'  => $request->point_de_vente_id,
            'fonction'           => $request->fonction,
            'date_debut_contrat' => $request->date_debut_contrat,
            'date_fin_contrat'   => $request->date_fin_contrat,
            'notes'              => $request->notes,
            'habilitations'      => $request->habilitations ?? [],
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $ancien = $personnel->only(array_keys($data));
        $personnel->update($data);
        $this->journaliser('modification_personnel', 'Utilisateur', $personnel->id, $ancien, $data);
 
        return redirect()->route('admin.personnel.index')->with('succes', 'Informations du personnel mises à jour.');
    }

    public function changerStatut(Utilisateur $personnel): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($personnel->entreprise_id === $entreprise->id, 404);

        if ($personnel->id === Auth::id()) {
            return back()->withErrors(['general' => 'Vous ne pouvez pas modifier votre propre statut.']);
        }

        $ancienStatut = $personnel->statut;
        $personnel->statut = $personnel->statut === 'actif' ? 'inactif' : 'actif';
        $personnel->save();

        $this->journaliser('statut_personnel', 'Utilisateur', $personnel->id, ['statut' => $ancienStatut], ['statut' => $personnel->statut]);

        $action = $personnel->statut === 'actif' ? 'débloqué' : 'bloqué';
        return back()->with('succes', "Le personnel a été {$action} avec succès.");
    }

    public function supprimer(Utilisateur $personnel): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($personnel->entreprise_id === $entreprise->id, 404);

        if ($personnel->id === Auth::id()) {
            return back()->withErrors(['general' => 'Vous ne pouvez pas supprimer votre propre compte.']);
        }

        $ancien = $personnel->toArray();
        $personnel->delete();

        $this->journaliser('suppression_personnel', 'Utilisateur', $personnel->id, $ancien, null);

        return redirect()->route('admin.personnel.index')->with('succes', 'Membre du personnel supprimé avec succès.');
    }
}
