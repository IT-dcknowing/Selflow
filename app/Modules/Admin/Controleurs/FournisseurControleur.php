<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Services\NumerotationTiersService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class FournisseurControleur
{
    public function index(Request $request): View
    {
        $entreprise   = Auth::user()->entreprise;
        $search = $request->input('search', '');

        $query = Fournisseur::where('entreprise_id', $entreprise->id)
            ->where(function ($q) {
                $q->where('source', '!=', 'comptaflow')
                  ->orWhereNull('source');
            })
            ->withCount('achats')
            ->orderBy('nom');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('telephone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('numero_tiers', 'like', "%{$search}%")
                  ->orWhere('ncc', 'like', "%{$search}%")
                  ->orWhere('secteur', 'like', "%{$search}%");
            });
        }

        $fournisseurs = $query->paginate(15, ['*'], 'page_local')->withQueryString();

        $fournisseursComptaflow = Fournisseur::where('entreprise_id', $entreprise->id)
             ->where('source', 'comptaflow')
             ->withCount('achats')
             ->orderBy('nom')
             ->paginate(15, ['*'], 'page_comptaflow');

        $comptes = \App\Modules\Admin\Modeles\PlanComptable::obtenirComptesPrioritaires($entreprise->id);

        return view('admin::fournisseurs.index', compact('fournisseurs', 'fournisseursComptaflow', 'comptes', 'entreprise', 'search') + ['comptabiliteOuverte' => $entreprise->comptabiliteOuverte()]);
    }

    public function creer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        // Normaliser le NCC : suppression des espaces et mise en majuscule
        $request->merge(['ncc' => $request->has('ncc') ? strtoupper(preg_replace('/\s+/', '', $request->input('ncc'))) : null]);

        $request->validate([
            'nom'               => ['required', 'string', 'max:150'],
            'type_facturation'  => ['nullable', 'in:B2B,B2C,B2G,B2F'],
            'telephone'         => ['nullable', 'string', 'max:30'],
            'email'             => ['nullable', 'email', 'max:150'],
            'adresse'           => ['nullable', 'string', 'max:255'],
            'secteur'           => ['nullable', 'string', 'max:100'],
            'ncc'               => ['required_if:type_facturation,B2B', 'nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
            'rccm'              => ['nullable', 'string', 'max:100'],
            'regime_imposition' => ['nullable', 'string', 'max:100'],
            'compte_comptable'  => $this->regleCompteCollectif($entreprise),
        ], [
            'ncc.required_if' => 'Le NCC est obligatoire pour un fournisseur de type B2B (Entreprise à Entreprise).',
            'ncc.size' => 'Le NCC doit contenir exactement 8 caractères.',
            'ncc.regex' => 'Le NCC doit comporter 8 caractères et se terminer par une lettre majuscule.',
        ]);

        // Comptabilité fermée, le compte de rattachement n'est plus demandé —
        // et ce qui n'est plus demandé n'est plus accepté : un compte posté à
        // la main par-dessus le formulaire s'écrivait tel quel. Le serveur
        // pose le collectif de la nature du tiers.
        $compteCollectif = $entreprise->comptabiliteOuverte()
            ? (string) $request->input('compte_comptable')
            : (string) config('selflow.plan_comptable_defaut.fournisseur_collectif');

        // Le numéro ne se saisit plus à la main : le système le fabrique,
        // selon la convention des paramètres, exactement comme Comptaflow.
        $numeroTiers = NumerotationTiersService::pourFournisseur(
            $entreprise,
            $compteCollectif,
            (string) $request->input('nom')
        );

        Fournisseur::create(array_merge(
            $request->only(['nom', 'type_facturation', 'telephone', 'email', 'adresse', 'secteur', 'rccm', 'regime_imposition']),
            [
                'entreprise_id'    => $entreprise->id,
                'compte_comptable' => $compteCollectif,
                'numero_tiers'  => $numeroTiers,
                'ncc'           => ($request->input('type_facturation') === 'B2B') ? $request->input('ncc') : null,
            ]
        ));

        return back()->with('succes', 'Fournisseur ajouté avec succès.');
    }

    public function modifier(Request $request, Fournisseur $fournisseur): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($fournisseur->entreprise_id === $entreprise->id, 404);

        if ($fournisseur->source === 'comptaflow') {
            // Normaliser le NCC en entrée
            $request->merge(['ncc' => $request->has('ncc') ? strtoupper(preg_replace('/\s+/', '', $request->input('ncc'))) : null]);
            $request->validate([
                'type_facturation'  => ['nullable', 'in:B2B,B2C,B2G,B2F'],
                'telephone'         => ['nullable', 'string', 'max:30'],
                'email'             => ['nullable', 'email', 'max:150'],
                'adresse'           => ['nullable', 'string', 'max:255'],
                'secteur'           => ['nullable', 'string', 'max:100'],
                'ncc'               => ['required_if:type_facturation,B2B', 'nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
                'rccm'              => ['nullable', 'string', 'max:100'],
                'regime_imposition' => ['nullable', 'string', 'max:100'],
            ], [
                'ncc.required_if' => 'Le NCC est obligatoire pour un fournisseur de type B2B.',
                'ncc.size' => 'Le NCC doit contenir exactement 8 caractères.',
                'ncc.regex' => 'Le NCC doit comporter 8 caractères et se terminer par une lettre majuscule.',
            ]);

            $fournisseur->update(array_merge(
                $request->only(['type_facturation', 'telephone', 'email', 'adresse', 'secteur', 'rccm', 'regime_imposition']),
                ['ncc' => ($request->input('type_facturation') === 'B2B') ? $request->input('ncc') : null]
            ));
        } else {
            // Normaliser le NCC en entrée
            $request->merge(['ncc' => $request->has('ncc') ? strtoupper(preg_replace('/\s+/', '', $request->input('ncc'))) : null]);

            $request->validate([
                'nom'               => ['required', 'string', 'max:150'],
                'type_facturation'  => ['nullable', 'in:B2B,B2C,B2G,B2F'],
                'telephone'         => ['nullable', 'string', 'max:30'],
                'email'             => ['nullable', 'email', 'max:150'],
                'adresse'           => ['nullable', 'string', 'max:255'],
                'secteur'           => ['nullable', 'string', 'max:100'],
                'ncc'               => ['required_if:type_facturation,B2B', 'nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
                'rccm'              => ['nullable', 'string', 'max:100'],
                'regime_imposition' => ['nullable', 'string', 'max:100'],
                'compte_comptable'  => $this->regleCompteCollectif($entreprise),
            ], [
                'ncc.required_if' => 'Le NCC est obligatoire pour un fournisseur de type B2B.',
                'ncc.size' => 'Le NCC doit contenir exactement 8 caractères.',
                'ncc.regex' => 'Le NCC doit comporter 8 caractères et se terminer par une lettre majuscule.',
            ]);

            $fournisseur->update(array_merge(
                $request->only(['nom', 'type_facturation', 'telephone', 'email', 'adresse', 'secteur', 'rccm', 'regime_imposition'])
                    + ($entreprise->comptabiliteOuverte() ? $request->only(['compte_comptable']) : []),
                ['ncc' => ($request->input('type_facturation') === 'B2B') ? $request->input('ncc') : null]
            ));
        }

        return back()->with('succes', 'Fournisseur modifié avec succès.');
    }

    public function supprimer(Request $request, Fournisseur $fournisseur): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($fournisseur->entreprise_id === $entreprise->id, 404);

        if ($fournisseur->achats()->exists()) {
            return back()->with('erreur', 'Impossible de supprimer ce fournisseur : il est lié à des achats enregistrés.');
        }

        $fournisseur->delete();
        return back()->with('succes', 'Fournisseur supprimé avec succès.');
    }

    /**
     * La règle du compte collectif suit l'état de la comptabilité.
     *
     * Ouverte, le compte est choisi à l'écran et doit exister au plan.
     * Fermée, le champ n'est pas affiché : le rendre obligatoire refuserait
     * chaque fiche, et l'accepter laisserait écrire un compte que personne
     * n'a pu choisir. Il est donc ignoré, et le serveur décide.
     */
    private function regleCompteCollectif(Entreprise $entreprise): array
    {
        if (!$entreprise->comptabiliteOuverte()) {
            return ['exclude'];
        }

        return [
            'required',
            'string',
            \Illuminate\Validation\Rule::exists('plan_comptable', 'numero')->where(function ($q) use ($entreprise) {
                $q->whereNull('entreprise_id')->orWhere('entreprise_id', $entreprise->id);
            }),
        ];
    }
}
