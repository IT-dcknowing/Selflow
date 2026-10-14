<?php

namespace App\Modules\Admin\Controleurs;

use App\Http\Controllers\Controller;
use App\Mail\DemandeStickersRecue;
use App\Mail\EnvoiCourriel;
use App\Modules\Admin\Modeles\DemandeSticker;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Regles\Appartenance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Commander des stickers par DC-Knowing — retest d'octobre 2026.
 *
 * L'ancien écran laissait croire que Selflow vendait les vignettes ; il a été
 * retiré le 25/09/2026. Celui-ci dit ce qui se passe réellement :
 * l'entreprise choisit, **DC-Knowing achète pour elle** sur la plateforme
 * FNE, et la demande se suit ici jusqu'à la livraison.
 */
class DemandeStickerControleur extends Controller
{
    // ── Côté entreprise ─────────────────────────────────────────────

    public function index(): View
    {
        $entreprise = Auth::user()->entreprise;

        return view('admin::stickers.index', [
            'entreprise'    => $entreprise,
            'demandes'      => DemandeSticker::where('entreprise_id', $entreprise->id)->with(['pointDeVente', 'utilisateur'])->latest()->paginate(15),
            'pointsDeVente' => PointDeVente::where('entreprise_id', $entreprise->id)->orderBy('nom')->get(),
            'prixUnitaire'  => (float) config('selflow.sticker_prix_unitaire', 20),
            'alerte'        => \App\Modules\Admin\Services\AlerteStickersService::pour($entreprise),
        ]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;

        $donnees = $request->validate([
            'quantite'          => ['required', 'integer', 'min:' . DemandeSticker::QUANTITE_MIN, 'max:' . DemandeSticker::QUANTITE_MAX],
            'point_de_vente_id' => ['nullable', Appartenance::a('points_de_vente', 'id')],
            'urgence'           => ['required', Rule::in(array_keys(DemandeSticker::URGENCES))],
            'contact_telephone' => ['nullable', 'string', 'max:30'],
            'commentaire'       => ['nullable', 'string', 'max:1000'],
        ], [
            'quantite.min' => 'Une demande porte sur ' . DemandeSticker::QUANTITE_MIN . ' stickers au moins.',
        ]);

        $prix = (float) config('selflow.sticker_prix_unitaire', 20);

        $demande = DemandeSticker::create($donnees + [
            'entreprise_id'  => $entreprise->id,
            'utilisateur_id' => Auth::id(),
            'prix_unitaire'  => $prix,
            'montant_estime' => round($prix * (int) $donnees['quantite'], 2),
            'statut'         => 'recue',
        ]);

        // DC-Knowing doit l'apprendre tout de suite : c'est elle qui achète.
        $parti = false;
        foreach (DemandeSticker::adressesDcKnowing() as $adresse) {
            $parti = EnvoiCourriel::envoyer($adresse, new DemandeStickersRecue($demande, true)) || $parti;
        }
        $demande->update(['courriel_envoye' => $parti]);

        // L'accusé de réception, à qui a demandé.
        EnvoiCourriel::envoyer(Auth::user()->email, new DemandeStickersRecue($demande, false));

        return redirect()->route('admin.stickers.index')->with('succes',
            'Demande de ' . number_format($demande->quantite, 0, ',', ' ') . ' stickers transmise à DC-Knowing, qui les achète pour vous auprès de la DGI.'
            . ($parti ? '' : ' Le courriel n\'a pas pu partir : la demande est enregistrée et visible par DC-Knowing dans son espace.'));
    }

    public function annuler(DemandeSticker $demande): RedirectResponse
    {
        abort_unless($demande->entreprise_id === Auth::user()->entreprise_id, 404);

        if ($demande->statut !== 'recue') {
            return back()->with('erreur', 'DC-Knowing a déjà commencé l\'achat : contactez-la pour annuler.');
        }

        $demande->update(['statut' => 'annulee']);

        return back()->with('succes', 'Demande annulée.');
    }

    // ── Côté DC-Knowing (superadministrateur) ───────────────────────

    public function superadminIndex(Request $request): View
    {
        $statut = array_key_exists((string) $request->query('statut'), DemandeSticker::STATUTS) ? $request->query('statut') : null;

        return view('admin::superadmin.stickers.index', [
            'demandes' => DemandeSticker::with(['entreprise', 'pointDeVente', 'utilisateur', 'traiteePar'])
                ->when($statut, fn ($q) => $q->where('statut', $statut))
                ->orderByRaw("CASE WHEN statut IN ('recue','en_cours','achetee') THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN urgence = 'urgente' THEN 0 ELSE 1 END")
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'statut'   => $statut,
            'ouvertes' => DemandeSticker::whereIn('statut', ['recue', 'en_cours', 'achetee'])->count(),
        ]);
    }

    public function superadminMettreAJour(Request $request, DemandeSticker $demande): RedirectResponse
    {
        $donnees = $request->validate([
            'statut'         => ['required', Rule::in(array_keys(DemandeSticker::STATUTS))],
            'note_dcknowing' => ['nullable', 'string', 'max:1000'],
        ]);

        $demande->update($donnees + ['traitee_par' => Auth::id(), 'traitee_le' => now()]);

        return back()->with('succes', 'Demande de ' . ($demande->entreprise?->nom ?? '') . ' : ' . $demande->libelleStatut() . '.');
    }
}
