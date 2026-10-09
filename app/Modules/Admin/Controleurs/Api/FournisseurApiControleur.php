<?php

namespace App\Modules\Admin\Controleurs\Api;

use App\Modules\Admin\Modeles\Fournisseur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FournisseurApiControleur
{
    public function index(): JsonResponse
    {
        $entreprise   = Auth::user()->entreprise;
        $fournisseurs = Fournisseur::where('entreprise_id', $entreprise->id)
            ->withCount('achats')
            ->orderBy('nom')
            ->paginate(30);

        return response()->json([
            'statut' => 'succes',
            'fournisseurs' => $fournisseurs
        ]);
    }

    public function creer(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        // Normaliser le NCC : suppression des espaces et mise en majuscule
        $request->merge(['ncc' => $request->has('ncc') ? strtoupper(preg_replace('/\s+/', '', $request->input('ncc'))) : null]);
        $request->merge(['regime_imposition' => \App\Modules\Admin\Modeles\Entreprise::normaliserRegime($request->input('regime_imposition'))]);

        $request->validate([
            'nom'               => ['required', 'string', 'max:150'],
            'type_facturation'  => ['nullable', 'in:B2B,B2C,B2G,B2F'],
            'telephone'         => ['nullable', 'string', 'max:30'],
            'email'             => ['nullable', 'email', 'max:150'],
            'adresse'           => ['nullable', 'string', 'max:255'],
            'secteur'           => ['nullable', 'string', 'max:100'],
            'ncc'               => ['required_if:type_facturation,B2B', 'nullable', 'string', 'size:8', 'regex:/^[A-Z0-9]{7}[A-Z]$/'],
            'regime_imposition' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Modules\Admin\Modeles\Entreprise::regimesAcceptesPourTiers())],
        ]);

        $fournisseur = Fournisseur::create(array_merge(
            $request->only(['nom', 'type_facturation', 'telephone', 'email', 'adresse', 'secteur', 'ncc', 'regime_imposition']),
            ['entreprise_id' => $entreprise->id]
        ));

        return response()->json([
            'statut' => 'succes',
            'message' => 'Fournisseur ajouté avec succès.',
            'fournisseur' => $fournisseur
        ], 201);
    }
}
