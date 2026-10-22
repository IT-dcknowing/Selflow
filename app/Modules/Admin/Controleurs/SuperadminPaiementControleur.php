<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\PaiementAbonnement;
use App\Modules\Admin\Regles\Abonnements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Les paiements d'abonnement déclarés par référence — 16/10/2026.
 *
 * L'entreprise dépose le montant sur le numéro du cabinet et saisit la
 * référence ; ici, DC-Knowing la vérifie : « En cours », puis « Confirmer et
 * activer » (l'échéance est repoussée d'autant de mois), ou « Échec » avec un
 * motif que l'entreprise lit dans sa section Facturation.
 */
class SuperadminPaiementControleur
{
    public function index(Request $request): View
    {
        $statut = array_key_exists((string) $request->query('statut'), PaiementAbonnement::STATUTS) ? $request->query('statut') : null;

        return view('admin::superadmin.paiements.index', [
            'paiements' => PaiementAbonnement::with(['entreprise', 'utilisateur', 'traitePar'])
                ->when($statut, fn ($q) => $q->where('statut', $statut))
                ->orderByRaw("CASE WHEN statut IN ('recu','en_cours') THEN 0 ELSE 1 END")
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'statut'    => $statut,
            'ouverts'   => PaiementAbonnement::whereIn('statut', PaiementAbonnement::A_TRAITER)->count(),
        ]);
    }

    public function traiter(Request $request, PaiementAbonnement $paiement): RedirectResponse
    {
        $donnees = $request->validate([
            'action'      => ['required', Rule::in(['en_cours', 'activer', 'echec'])],
            'motif_echec' => ['required_if:action,echec', 'nullable', 'string', 'max:255'],
        ], ['motif_echec.required_if' => 'Indiquez le motif de l\'échec : l\'entreprise le verra.']);

        // Un paiement en échec se confirme encore (22/10/2026) : le règlement
        // retrouvé après coup active l'abonnement depuis la même ligne.
        $permis = $donnees['action'] === 'activer' ? $paiement->peutEtreConfirme() : $paiement->estOuvert();
        if (! $permis) {
            return back()->with('erreur', 'Ce paiement est déjà traité (' . $paiement->libelleStatut() . ').');
        }

        $traite = ['traite_par' => Auth::id()];

        match ($donnees['action']) {
            'en_cours' => $paiement->update($traite + ['statut' => 'en_cours', 'en_cours_le' => now()]),
            'echec'    => $paiement->update($traite + ['statut' => 'echec', 'echec_le' => now(), 'motif_echec' => $donnees['motif_echec']]),
            'activer'  => $this->activer($paiement, $traite),
        };

        return back()->with('succes', ($paiement->entreprise?->nom ?? '') . ' : ' . $paiement->fresh()->libelleStatut() . '.');
    }

    /**
     * Le paiement confirmé active l'offre et avance l'échéance d'autant de
     * mois que payés — un mois : 30 ou 31 jours selon le mois. Elle part de
     * maintenant, sauf quand une période déjà payée court encore : le
     * renouvellement anticipé s'y ajoute. Les jours d'un pass cadeau ne
     * s'ajoutent pas (22/10/2026). Une entreprise à terme ou en attente est
     * débloquée.
     */
    private function activer(PaiementAbonnement $paiement, array $traite): void
    {
        DB::transaction(function () use ($paiement, $traite) {
            $entreprise = $paiement->entreprise;
            $depart = ! $paiement->bloquant && $entreprise->abonnement_expire_le?->isFuture()
                ? $entreprise->abonnement_expire_le->copy()
                : now();

            if ($entreprise->plan_abonnement !== $paiement->offre) {
                Abonnements::appliquer($entreprise, $paiement->offre);
                $entreprise->refresh();
            }

            $entreprise->forceFill([
                'abonnement_statut'     => Abonnements::STATUT_ACTIF,
                'abonnement_expire_le'  => $depart->addMonthsNoOverflow(max(1, (int) $paiement->mois))->endOfDay(),
                'abonnement_demande'    => null,
                'abonnement_demande_le' => null,
            ])->save();

            $paiement->update($traite + ['statut' => 'active', 'active_le' => now()]);
        });
    }
}
