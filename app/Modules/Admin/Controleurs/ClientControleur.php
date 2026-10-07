<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Services\NumerotationTiersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class ClientControleur
{

    public function index(Request $request): View
    {
        $entreprise = Auth::user()->entreprise;
        $search = $request->input('search', '');

        $query = Client::where('entreprise_id', $entreprise->id)
            ->where(function ($q) {
                $q->where('source', '!=', 'comptaflow')
                  ->orWhereNull('source');
            })
            ->withCount('ventes')
            ->orderBy('nom');
        
        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                  ->orWhere('telephone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('numero_tiers', 'like', "%{$search}%")
                  ->orWhere('ncc', 'like', "%{$search}%");
            });
        }

        $clients = $query->paginate(15, ['*'], 'page_local')->withQueryString();

        $clientsComptaflow = Client::where('entreprise_id', $entreprise->id)
            ->where('source', 'comptaflow')
            ->withCount('ventes')
            ->orderBy('nom')
            ->paginate(15, ['*'], 'page_comptaflow');

        $comptes = \App\Modules\Admin\Modeles\PlanComptable::obtenirComptesPrioritaires($entreprise->id);

        return view('admin::clients.index', compact('clients', 'clientsComptaflow', 'comptes', 'entreprise', 'search'));
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
            'ncc'               => ['required_if:type_facturation,B2B', 'nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
            'rccm'              => ['nullable', 'string', 'max:100'],
            'regime_imposition' => ['nullable', 'string', 'max:100'],
            // Comptabilité éteinte, le champ n'est plus à l'écran : il n'est
            // plus exigé, et ce qui serait posté est ignoré (chantier 3.1).
            'compte_comptable'  => $entreprise->comptabiliteOuverte() ? [
                'required',
                'string',
                \Illuminate\Validation\Rule::exists('plan_comptable', 'numero')->where(function ($q) use ($entreprise) {
                    $q->whereNull('entreprise_id')->orWhere('entreprise_id', $entreprise->id);
                })
            ] : ['nullable'],
        ], [
            'ncc.required_if' => 'Le NCC est obligatoire pour un client de type B2B (Entreprise à Entreprise).',
            'ncc.size' => 'Le NCC doit contenir exactement 8 caractères.',
            'ncc.regex' => 'Le NCC doit comporter 8 caractères et se terminer par une lettre majuscule.',
        ]);

        $request->merge(['compte_comptable' => \App\Modules\Admin\Services\ImputationService::compteDeTiers(
            $entreprise, 'client', $request->input('compte_comptable')
        )]);

        // **Le numéro de tiers n'est pas le compte général**, et il ne se
        // saisit plus à la main. Le système le fabrique, selon la convention
        // choisie dans les paramètres, exactement comme Comptaflow — qui
        // retrouve un tiers par égalité de chaîne. Une convention différente
        // d'un côté et de l'autre, et plus aucun tiers n'est reconnu.
        $numeroTiers = NumerotationTiersService::pourClient(
            $entreprise,
            (string) $request->input('compte_comptable'),
            (string) $request->input('nom')
        );

        Client::create(array_merge(
            $request->only(['nom', 'type_facturation', 'telephone', 'email', 'adresse', 'ncc', 'rccm', 'regime_imposition', 'compte_comptable']),
            [
                'entreprise_id' => $entreprise->id,
                'numero_tiers'  => $numeroTiers,
                // Si pas B2B, vider le NCC pour cohérence
                'ncc'           => ($request->input('type_facturation') === 'B2B') ? $request->input('ncc') : null,
            ]
        ));

        return back()->with('succes', 'Client ajouté avec succès.');
    }

    /**
     * Un client créé depuis la caisse, sans la quitter.
     *
     * Vendre à un nouveau client obligeait à passer par Tiers → Clients, à
     * remplir la fiche, puis à revenir à la caisse. On ne demande ici que ce
     * qui part sur la facture : le nom, le type, le NCC d'une entreprise. Le
     * compte général n'est pas demandé : `ImputationService::compteDeTiers()`
     * pose le collectif, comme sur la fiche quand la comptabilité est éteinte
     * (chantier 3.1). Le numéro de tiers, le modèle le fabrique à la création,
     * par quelque porte que la fiche arrive (lot 55).
     */
    public function creerDepuisLaCaisse(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        $request->merge(['ncc' => $request->filled('ncc') ? strtoupper(preg_replace('/\s+/', '', $request->input('ncc'))) : null]);

        // Le refus se rend ici, en JSON, et non par `$request->validate()` :
        // l'application ne rend ses exceptions en JSON que sous `api/*`
        // (`bootstrap/app.php`). Sur cette route web, un NCC manquant devenait
        // une redirection, la fenêtre recevait une page HTML, et le caissier
        // lisait « le serveur n'a pas répondu » au lieu de la vraie raison.
        $validation = Validator::make($request->all(), [
            'nom'              => ['required', 'string', 'max:150'],
            'type_facturation' => ['required', 'in:B2B,B2C,B2G,B2F'],
            'telephone'        => ['nullable', 'string', 'max:30'],
            'ncc'              => ['required_if:type_facturation,B2B', 'nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
        ], [
            'nom.required'     => 'Le nom du client est obligatoire.',
            'ncc.required_if'  => 'Le NCC est obligatoire pour un client de type B2B (Entreprise à Entreprise).',
            'ncc.size'         => 'Le NCC doit contenir exactement 8 caractères.',
            'ncc.regex'        => 'Le NCC doit comporter 8 caractères et se terminer par une lettre majuscule.',
        ]);

        if ($validation->fails()) {
            return response()->json(['errors' => $validation->errors()], 422);
        }

        $client = Client::create([
            'entreprise_id'    => $entreprise->id,
            'nom'              => $request->input('nom'),
            'type_facturation' => $request->input('type_facturation'),
            'telephone'        => $request->input('telephone'),
            'ncc'              => $request->input('type_facturation') === 'B2B' ? $request->input('ncc') : null,
            'compte_comptable' => \App\Modules\Admin\Services\ImputationService::compteDeTiers($entreprise, 'client'),
        ]);

        return response()->json([
            'client' => [
                'id'               => $client->id,
                'nom'              => $client->nom,
                'type_facturation' => $client->type_facturation,
            ],
        ], 201);
    }

    public function modifier(Request $request, Client $client): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($client->entreprise_id === $entreprise->id, 404);

        if ($client->source === 'comptaflow') {
            // Uniquement les champs spécifiques à Selflow
            // Normaliser le NCC en entrée
            $request->merge(['ncc' => $request->has('ncc') ? strtoupper(preg_replace('/\s+/', '', $request->input('ncc'))) : null]);
            $request->validate([
                'type_facturation'  => ['nullable', 'in:B2B,B2C,B2G,B2F'],
                'telephone'         => ['nullable', 'string', 'max:30'],
                'email'             => ['nullable', 'email', 'max:150'],
                'adresse'           => ['nullable', 'string', 'max:255'],
'ncc'               => ['required_if:type_facturation,B2B', 'nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
            'rccm'              => ['nullable', 'string', 'max:100'],
            'regime_imposition' => ['nullable', 'string', 'max:100'],
                ], [
                'ncc.required_if' => 'Le NCC est obligatoire pour un client de type B2B.',
                'ncc.size' => 'Le NCC doit contenir exactement 8 caractères.',
                'ncc.regex' => 'Le NCC doit comporter 8 caractères et se terminer par une lettre majuscule.',
            ]);

            $client->update(array_merge(
                $request->only(['type_facturation', 'telephone', 'email', 'adresse', 'rccm', 'regime_imposition']),
                ['ncc' => ($request->input('type_facturation') === 'B2B') ? $request->input('ncc') : null]
            ));
        } else {
            // Tous les champs
            // Normaliser le NCC en entrée
            $request->merge(['ncc' => $request->has('ncc') ? strtoupper(preg_replace('/\s+/', '', $request->input('ncc'))) : null]);
            $request->validate([
                'nom'               => ['required', 'string', 'max:150'],
                'type_facturation'  => ['nullable', 'in:B2B,B2C,B2G,B2F'],
                'telephone'         => ['nullable', 'string', 'max:30'],
                'email'             => ['nullable', 'email', 'max:150'],
                'adresse'           => ['nullable', 'string', 'max:255'],
                'ncc'               => ['required_if:type_facturation,B2B', 'nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
                'rccm'              => ['nullable', 'string', 'max:100'],
                'regime_imposition' => ['nullable', 'string', 'max:100'],
                'compte_comptable'  => $entreprise->comptabiliteOuverte() ? [
                    'required',
                    'string',
                    \Illuminate\Validation\Rule::exists('plan_comptable', 'numero')->where(function ($q) use ($entreprise) {
                        $q->whereNull('entreprise_id')->orWhere('entreprise_id', $entreprise->id);
                    })
                ] : ['nullable'],
            ], [
                'ncc.required_if' => 'Le NCC est obligatoire pour un client de type B2B.',
                'ncc.size' => 'Le NCC doit contenir exactement 8 caractères.',
                'ncc.regex' => 'Le NCC doit comporter 8 caractères et se terminer par une lettre majuscule.',
            ]);

            $client->update(array_merge(
                $request->only(array_merge(['nom', 'type_facturation', 'telephone', 'email', 'adresse', 'rccm', 'regime_imposition'], $entreprise->comptabiliteOuverte() ? ['compte_comptable'] : [])),
                ['ncc' => ($request->input('type_facturation') === 'B2B') ? $request->input('ncc') : null]
            ));
        }

        return back()->with('succes', 'Client modifié avec succès.');
    }

    public function supprimer(Request $request, Client $client): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($client->entreprise_id === $entreprise->id, 404);

        if ($client->ventes_count > 0 || $client->ventes()->exists()) {
            return back()->with('erreur', 'Impossible de supprimer ce client : il est lié à des ventes enregistrées.');
        }

        $client->delete();
        return back()->with('succes', 'Client supprimé avec succès.');
    }
}
