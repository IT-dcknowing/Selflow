<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Modeles\BrouillonVente;
use App\Modules\Admin\Modeles\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Les brouillons de vente (24/11/2026, propriétaire) : on prépare la vente,
 * on l'enregistre sans facturer ; « Passer en facture » la rouvre dans
 * l'écran de vente, où on la modifie et la valide.
 */
class BrouillonVenteControleur extends Controller
{
    public function index(): View
    {
        return view('admin::ventes.brouillons', [
            'brouillons' => BrouillonVente::with(['utilisateur:id,nom,prenom', 'pointDeVente:id,nom'])
                ->where('entreprise_id', Auth::user()->entreprise_id)
                ->when(Auth::user()->estAffecteAUnSite(), fn ($q) => $q->where('point_de_vente_id', Auth::user()->point_de_vente_id))
                ->latest('updated_at')->get(),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $panier = json_decode((string) $request->input('panier_json', '{}'), true);
        if (! is_array($panier) || $panier === []) {
            return back()->with('error', 'Ajoutez au moins un article avant d\'enregistrer le brouillon.');
        }

        $donnees = $request->except(['_token', 'panier_json', 'jeton_envoi', 'articles']);
        $donnees['panier'] = $panier;
        $total = collect($panier)->sum(fn ($l) => (float) ($l['prix'] ?? 0) * (float) ($l['qte'] ?? 0) * (1 - (float) ($l['remise_taux'] ?? 0) / 100));

        $libelle = null;
        if (! empty($donnees['client_id'])) {
            $libelle = Client::where('entreprise_id', Auth::user()->entreprise_id)->whereKey((int) $donnees['client_id'])->value('nom');
        }
        $libelle ??= trim((string) ($donnees['prestataire_nom'] ?? '')) ?: null;
        if (! $libelle && ! empty($donnees['patients'])) {
            $libelle = collect((array) $donnees['patients'])->map(fn ($c) => preg_replace('/^(id|nom):/', '', (string) $c))->implode(', ');
        }

        $attributs = [
            'entreprise_id' => Auth::user()->entreprise_id,
            'point_de_vente_id' => session('point_de_vente_actif_id') && is_numeric(session('point_de_vente_actif_id')) ? (int) session('point_de_vente_actif_id') : Auth::user()->point_de_vente_id,
            'utilisateur_id' => Auth::id(),
            'libelle' => $libelle ? mb_substr($libelle, 0, 250) : 'Client de passage',
            'total' => round($total, 2),
            'lignes' => count($panier),
            'donnees' => $donnees,
        ];

        $existant = $request->filled('brouillon_id')
            ? BrouillonVente::where('entreprise_id', Auth::user()->entreprise_id)->find((int) $request->input('brouillon_id'))
            : null;
        $existant ? $existant->update($attributs) : BrouillonVente::create($attributs);

        $prefixe = $request->routeIs('caissier.*') ? 'caissier' : 'admin';

        return redirect()->route($prefixe . '.ventes.nouvelle')
            ->with('succes', 'Brouillon enregistré. Retrouvez-le dans Factures vente › Brouillons.')
            ->with('brouillon_enregistre', true);
    }

    public function supprimer(BrouillonVente $brouillon): RedirectResponse
    {
        abort_unless((int) $brouillon->entreprise_id === (int) Auth::user()->entreprise_id, 404);
        $brouillon->delete();

        return back()->with('succes', 'Brouillon supprimé.');
    }

    /** Le brouillon d'une vente rouverte, pour l'écran de vente. */
    public static function pourLaVente(?int $id): ?BrouillonVente
    {
        return $id ? BrouillonVente::where('entreprise_id', Auth::user()->entreprise_id)->find($id) : null;
    }
}
