<?php

namespace App\Modules\Admin\Controleurs\Api;

use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use App\Modules\Admin\Regles\Appartenance;

class PersonnelApiControleur
{
    public function index(): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        
        $personnels = Utilisateur::where('entreprise_id', $entreprise->id)
            ->with('pointDeVente')
            ->orderBy('nom')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'uuid' => $p->uuid,
                    'nom' => $p->nom,
                    'prenom' => $p->prenom,
                    'email' => $p->email,
                    'role' => $p->role,
                    'fonction' => $p->fonction,
                    'statut' => $p->statut,
                    'point_de_vente' => $p->pointDeVente ? [
                        'id' => $p->pointDeVente->id,
                        'uuid' => $p->pointDeVente->uuid,
                        'nom' => $p->pointDeVente->nom
                    ] : null
                ];
            });

        return response()->json([
            'statut' => 'succes',
            'personnel' => $personnels
        ]);
    }

    public function creer(Request $request): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;

        $request->validate([
            'nom'                => ['required', 'string', 'max:150'],
            'prenom'             => ['required', 'string', 'max:150'],
            'email'              => ['required', 'email', 'max:150', Rule::unique('utilisateurs', 'email')],
            'password'           => ['required', 'string', 'min:6'],
            'role'               => ['required', 'string', Rule::in(['admin', 'caissier'])],
            'point_de_vente_id'  => ['nullable', Appartenance::a('points_de_vente', 'id')],
            'fonction'           => ['nullable', 'string', 'max:150'],
            'date_debut_contrat' => ['nullable', 'date'],
            'date_fin_contrat'   => ['nullable', 'date', 'after_or_equal:date_debut_contrat'],
            'notes'              => ['nullable', 'string'],
            'habilitations'      => ['nullable', 'array'],
        ]);

        $personnel = Utilisateur::create([
            'entreprise_id'      => $entreprise->id,
            'point_de_vente_id'  => $request->point_de_vente_id,
            'nom'                => $request->nom,
            'prenom'             => $request->prenom,
            'email'              => $request->email,
            'password'           => Hash::make($request->password),
            'role'               => $request->role,
            'fonction'           => $request->fonction,
            'date_debut_contrat' => $request->date_debut_contrat,
            'date_fin_contrat'   => $request->date_fin_contrat,
            'statut'             => 'actif',
            'notes'              => $request->notes,
            'habilitations'      => $request->habilitations ?? [],
        ]);

        $avisParti = \App\Mail\EnvoiCourriel::annoncerCompte($personnel, true);

        return response()->json([
            'statut' => 'succes',
            'message' => 'Membre du personnel créé avec succès.' . ($avisParti ? '' : \App\Mail\EnvoiCourriel::AVIS_NON_PARTI),
            'avis_envoye' => $avisParti,
            'personnel' => [
                'id' => $personnel->id,
                'uuid' => $personnel->uuid,
                'nom' => $personnel->nom,
                'prenom' => $personnel->prenom
            ]
        ], 201);
    }

    public function details(Utilisateur $personnel): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        if ($personnel->entreprise_id !== $entreprise->id) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès non autorisé.'
            ], 403);
        }

        return response()->json([
            'statut' => 'succes',
            'personnel' => [
                'id' => $personnel->id,
                'uuid' => $personnel->uuid,
                'nom' => $personnel->nom,
                'prenom' => $personnel->prenom,
                'email' => $personnel->email,
                'role' => $personnel->role,
                'fonction' => $personnel->fonction,
                'date_debut_contrat' => $personnel->date_debut_contrat,
                'date_fin_contrat' => $personnel->date_fin_contrat,
                'statut' => $personnel->statut,
                'notes' => $personnel->notes,
                'habilitations' => $personnel->habilitations ?? [],
                'point_de_vente_id' => $personnel->point_de_vente_id
            ]
        ]);
    }

    public function modifier(Request $request, Utilisateur $personnel): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        if ($personnel->entreprise_id !== $entreprise->id) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès non autorisé.'
            ], 403);
        }

        $request->validate([
            'nom'                => ['required', 'string', 'max:150'],
            'prenom'             => ['required', 'string', 'max:150'],
            'email'              => ['required', 'email', 'max:150', Rule::unique('utilisateurs', 'email')->ignore($personnel->id)],
            'password'           => ['nullable', 'string', 'min:6'],
            'role'               => ['required', 'string', Rule::in(['admin', 'caissier'])],
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

        $personnel->update($data);

        return response()->json([
            'statut' => 'succes',
            'message' => 'Informations du personnel mises à jour.'
        ]);
    }

    public function changerStatut(Utilisateur $personnel): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        if ($personnel->entreprise_id !== $entreprise->id) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès non autorisé.'
            ], 403);
        }

        if ($personnel->id === Auth::id()) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Vous ne pouvez pas modifier votre propre statut.'
            ], 400);
        }

        $personnel->statut = $personnel->statut === 'actif' ? 'inactif' : 'actif';
        $personnel->save();

        $action = $personnel->statut === 'actif' ? 'débloqué' : 'bloqué';
        return response()->json([
            'statut' => 'succes',
            'message' => "Le personnel a été {$action} avec succès.",
            'nouveau_statut' => $personnel->statut
        ]);
    }

    public function supprimer(Utilisateur $personnel): JsonResponse
    {
        $entreprise = Auth::user()->entreprise;
        if ($personnel->entreprise_id !== $entreprise->id) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès non autorisé.'
            ], 403);
        }

        if ($personnel->id === Auth::id()) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.'
            ], 400);
        }

        $personnel->delete();

        return response()->json([
            'statut' => 'succes',
            'message' => 'Membre du personnel supprimé avec succès.'
        ]);
    }
}
