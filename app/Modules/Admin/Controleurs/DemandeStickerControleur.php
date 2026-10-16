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
        ] + \App\Modules\Admin\Regles\PaiementParReference::regles(), [
            'quantite.min' => 'Une demande porte sur ' . DemandeSticker::QUANTITE_MIN . ' stickers au moins.',
        ] + \App\Modules\Admin\Regles\PaiementParReference::messages());

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

        // Alerte aux destinataires configurés (courriel + WhatsApp), 16/10/2026.
        try {
            \App\Modules\Admin\Services\AlerteSuperadminService::alerter(
                \App\Modules\Admin\Services\AlerteSuperadminService::DEMANDE_STICKERS,
                'Demande de stickers : ' . (Auth::user()->entreprise?->nom ?? ''),
                'Entreprise : ' . (Auth::user()->entreprise?->nom ?? '—') . "\n"
                . 'Quantité : ' . number_format($demande->quantite, 0, ',', ' ') . " stickers\n"
                . 'Opérateur : ' . ($demande->operateur_paiement ?? '—') . "\n"
                . 'Référence : ' . ($demande->reference_paiement ?? '—') . "\n"
                . 'Le ' . now()->format('d/m/Y à H:i') . '.'
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Alerte stickers non envoyée : ' . $e->getMessage());
        }

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

        $demande->update(['statut' => 'annulee', 'annulee_le' => now()]);

        return back()->with('succes', 'Demande annulée.');
    }

    // ── Côté DC-Knowing (superadministrateur) ───────────────────────

    public function superadminIndex(Request $request): View
    {
        $statut = array_key_exists((string) $request->query('statut'), DemandeSticker::STATUTS) ? $request->query('statut') : null;

        return view('admin::superadmin.stickers.index', [
            'demandes' => DemandeSticker::with(['entreprise', 'pointDeVente', 'utilisateur', 'traiteePar'])
                ->when($statut, fn ($q) => $q->where('statut', $statut))
                ->orderByRaw("CASE WHEN statut IN ('recue','en_cours') THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN urgence = 'urgente' THEN 0 ELSE 1 END")
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'statut'   => $statut,
            'ouvertes' => DemandeSticker::whereIn('statut', DemandeSticker::A_TRAITER)->count(),
        ]);
    }

    /**
     * Les trois gestes de DC-Knowing (16/10/2026) : « En cours » (paiement
     * reçu, achat lancé), « Terminé » (stickers achetés ; le stock et le solde
     * relevés sur l'espace FNE du client s'y saisissent), « Échec » (avec
     * motif). Chaque geste garde son heure.
     */
    public function superadminMettreAJour(Request $request, DemandeSticker $demande): RedirectResponse
    {
        $donnees = $request->validate([
            'action'          => ['required', Rule::in(['en_cours', 'terminer', 'echec'])],
            'motif_echec'     => ['required_if:action,echec', 'nullable', 'string', 'max:255'],
            'stock_communique' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'solde_communique' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'note_dcknowing'  => ['nullable', 'string', 'max:1000'],
        ], ['motif_echec.required_if' => 'Indiquez le motif de l\'échec : l\'entreprise le verra.']);

        if (! $demande->estOuverte()) {
            return back()->with('erreur', 'Cette demande est déjà ' . mb_strtolower($demande->libelleStatut()) . '.');
        }

        $commun = ['traitee_par' => Auth::id(), 'traitee_le' => now(), 'note_dcknowing' => $donnees['note_dcknowing'] ?? $demande->note_dcknowing];

        match ($donnees['action']) {
            'en_cours' => $demande->update($commun + ['statut' => 'en_cours', 'en_cours_le' => now()]),
            'echec'    => $demande->update($commun + ['statut' => 'echec', 'echec_le' => now(), 'motif_echec' => $donnees['motif_echec']]),
            'terminer' => $this->terminer($demande, $donnees, $commun),
        };

        return back()->with('succes', 'Demande de ' . ($demande->entreprise?->nom ?? '') . ' : ' . $demande->fresh()->libelleStatut() . '.');
    }

    /**
     * Terminer : les stickers sont achetés. Le stock et le solde relevés sur
     * l'espace FNE du client sont portés à sa fiche — à la demande expresse du
     * propriétaire (16/10/2026) ; la prochaine normalisation les remettra de
     * toute façon à la valeur de la plateforme.
     */
    private function terminer(DemandeSticker $demande, array $donnees, array $commun): void
    {
        $stock = $donnees['stock_communique'] ?? null;
        $solde = $donnees['solde_communique'] ?? null;

        $demande->update($commun + [
            'statut' => 'achetee', 'achetee_le' => now(),
            'stock_communique' => $stock, 'solde_communique' => $solde,
            'notification_vue_le' => null,
        ]);

        $entreprise = $demande->entreprise;
        if ($entreprise && ($stock !== null || $solde !== null)) {
            $valeurs = ['fne_solde_maj_at' => now()];
            if ($stock !== null) {
                $valeurs['fne_sticker_balance'] = (int) $stock;
            }
            if ($solde !== null) {
                $valeurs['fne_solde_provision'] = (float) $solde;
            }
            $entreprise->forceFill($valeurs)->save();
        }
    }

    /** L'entreprise a vu l'avis « stock renouvelé ». */
    public function avisVu(DemandeSticker $demande): RedirectResponse
    {
        abort_unless($demande->entreprise_id === Auth::user()->entreprise_id, 404);
        $demande->update(['notification_vue_le' => now()]);

        return back();
    }
}
