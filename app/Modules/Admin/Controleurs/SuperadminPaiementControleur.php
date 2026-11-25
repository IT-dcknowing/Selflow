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

        // Abonnement / réabonnement (23/11/2026) : la page montre aussi les
        // abonnements en cours, bientôt à terme et à terme, et chacun se
        // prolonge ou se met à jour à tout moment, sans attendre l'échéance.
        $vue = $request->query('vue') === 'paiements' || $statut ? 'paiements' : 'abonnements';
        // Toutes les entreprises (24/11/2026, propriétaire : « je dois voir ici
        // tous les abonnements, même les antérieurs ») : celles sans échéance
        // ont leur propre groupe, l'offre Business (25 000 F) proposée.
        $toutes = \App\Modules\Admin\Modeles\Entreprise::query()->horsCorbeille()
            ->orderBy('abonnement_expire_le')->orderBy('nom')
            ->get(array_merge(['id', 'uuid', 'nom', 'ncc', 'plan_abonnement', 'abonnement_statut', 'abonnement_expire_le', 'created_at'],
                \App\Support\Structure::aColonne('entreprises', 'message_abonnement') ? ['message_abonnement', 'message_abonnement_le'] : []));
        $entreprises = $toutes->filter(fn ($e) => $e->abonnement_expire_le !== null)->values();
        $maintenant = now();
        $abonnements = [
            'a_terme' => $entreprises->filter(fn ($e) => $e->abonnement_expire_le->lt($maintenant))->values(),
            'bientot' => $entreprises->filter(fn ($e) => $e->abonnement_expire_le->gte($maintenant) && $e->abonnement_expire_le->lte($maintenant->copy()->addDays(10)))->values(),
            'en_cours' => $entreprises->filter(fn ($e) => $e->abonnement_expire_le->gt($maintenant->copy()->addDays(10)))->values(),
            'sans_echeance' => $toutes->filter(fn ($e) => $e->abonnement_expire_le === null)->sortBy('nom')->values(),
        ];

        return view('admin::superadmin.paiements.index', [
            'vue'         => $vue,
            'aConfirmer'  => PaiementAbonnement::with(['entreprise', 'utilisateur', 'traitePar'])
                ->where(fn ($q) => $q->whereIn('statut', PaiementAbonnement::A_TRAITER)
                    ->orWhere(fn ($e) => $e->where('statut', 'echec')->where('updated_at', '>=', now()->subDays(30))))
                ->latest()->limit(50)->get(),
            'abonnements' => $abonnements,
            'sansEcheance' => $abonnements['sans_echeance']->count(),
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

    /**
     * Réactivation manuelle d'un abonnement échu par le superadministrateur.
     * Les cases cochées représentent les mois effectivement réglés. On garde
     * une trace dans le registre des paiements, sans référence mobile money
     * inventée : le règlement a été contrôlé hors plateforme par le superadmin.
     */
    public function reactiver(Request $request, \App\Modules\Admin\Modeles\Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate([
            'offre' => ['required', Rule::in([Abonnements::SOLO, Abonnements::BUSINESS, Abonnements::PREMIUM, Abonnements::SUR_MESURE])],
            'mois_payes' => ['required', 'array', 'min:1', 'max:12'],
            'mois_payes.*' => ['required', 'integer', 'between:1,12', 'distinct'],
            'montant_total' => ['nullable', 'numeric', 'min:1'],
        ], [
            'mois_payes.required' => 'Cochez au moins un mois payé.',
            'mois_payes.min' => 'Cochez au moins un mois payé.',
            'mois_payes.max' => 'Vous ne pouvez pas sélectionner plus de douze mois.',
        ]);

        $mois = count($donnees['mois_payes']);
        $offre = $donnees['offre'];
        $tarifMensuel = Abonnements::offres()[$offre]['prix'] ?? null;
        $montant = $tarifMensuel !== null
            ? (int) $tarifMensuel * $mois
            : (float) ($donnees['montant_total'] ?? 0);

        if ($montant <= 0) {
            return back()->withErrors(['montant_total' => 'Indiquez le montant total réglé pour cette offre sur mesure.'])->withInput();
        }

        DB::transaction(function () use ($entreprise, $offre, $mois, $montant) {
            $entreprise = \App\Modules\Admin\Modeles\Entreprise::whereKey($entreprise->id)->lockForUpdate()->firstOrFail();
            $depart = $entreprise->abonnement_expire_le?->isFuture()
                ? $entreprise->abonnement_expire_le->copy()
                : now();

            if ($entreprise->plan_abonnement !== $offre) {
                Abonnements::appliquer($entreprise, $offre);
                $entreprise->refresh();
            }

            $entreprise->forceFill([
                'abonnement_statut' => Abonnements::STATUT_ACTIF,
                'abonnement_expire_le' => $depart->addMonthsNoOverflow($mois)->endOfDay(),
                'abonnement_demande' => null,
                'abonnement_demande_le' => null,
            ])->save();

            PaiementAbonnement::create([
                'entreprise_id' => $entreprise->id,
                'utilisateur_id' => null,
                'offre' => $offre,
                'nature' => 'renouvellement',
                'mois' => $mois,
                'montant' => $montant,
                'operateur_paiement' => 'superadmin',
                'reference_paiement' => 'REABONNEMENT-SUPERADMIN-' . $entreprise->id . '-' . now()->format('YmdHis'),
                'statut' => 'active',
                'active_le' => now(),
                'traite_par' => Auth::id(),
            ] + (PaiementAbonnement::colonnesEnPlace() ? [
                'bloquant' => false,
                'confirmation_vue_le' => now(),
            ] : []));
        });

        return back()->with('succes', $entreprise->nom . ' : abonnement réactivé pour ' . $mois . ' mois.');
    }

    /**
     * Prolonger ou mettre à jour un abonnement à tout moment (23/11/2026) :
     * changer d'offre, ajouter des mois (à partir de l'échéance en cours si
     * elle n'est pas passée), ou fixer une échéance précise.
     */
    public function prolonger(Request $request, \App\Modules\Admin\Modeles\Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate([
            'offre' => ['required', Rule::in([Abonnements::SOLO, Abonnements::BUSINESS, Abonnements::PREMIUM, Abonnements::SUR_MESURE])],
            'mois' => ['nullable', 'integer', 'between:0,36'],
            'echeance' => ['nullable', 'date', 'after:today'],
            'montant_total' => ['nullable', 'numeric', 'min:0'],
        ], ['echeance.after' => 'L\'échéance fixée doit être postérieure à aujourd\'hui.']);

        $mois = (int) ($donnees['mois'] ?? 0);
        $offre = $donnees['offre'];
        $echeance = $donnees['echeance'] ?? null;

        if ($mois === 0 && ! $echeance && $offre === $entreprise->plan_abonnement) {
            return back()->with('erreur', $entreprise->nom . ' : rien à modifier. Choisissez une autre offre, un nombre de mois ou une échéance.');
        }

        // Le montant saisi l'emporte ; sinon, le tarif de l'offre fois les mois.
        $tarif = Abonnements::offres()[$offre]['prix'] ?? null;
        $montant = (float) ($donnees['montant_total'] ?? 0) ?: (float) ($tarif ?? 0) * $mois;
        if ($mois > 0 && $montant <= 0) {
            return back()->with('erreur', 'Indiquez le montant réglé pour une offre sur mesure.');
        }

        DB::transaction(function () use ($entreprise, $offre, $mois, $echeance, $montant) {
            $entreprise = \App\Modules\Admin\Modeles\Entreprise::whereKey($entreprise->id)->lockForUpdate()->firstOrFail();

            if ($entreprise->plan_abonnement !== $offre) {
                Abonnements::appliquer($entreprise, $offre);
                $entreprise->refresh();
            }

            $nouvelleEcheance = $entreprise->abonnement_expire_le;
            if ($echeance) {
                $nouvelleEcheance = \Illuminate\Support\Carbon::parse($echeance)->endOfDay();
            } elseif ($mois > 0) {
                $depart = $entreprise->abonnement_expire_le?->isFuture() ? $entreprise->abonnement_expire_le->copy() : now();
                $nouvelleEcheance = $depart->addMonthsNoOverflow($mois)->endOfDay();
            }

            $entreprise->forceFill([
                'abonnement_statut' => Abonnements::STATUT_ACTIF,
                'abonnement_expire_le' => $nouvelleEcheance,
                'abonnement_demande' => null,
                'abonnement_demande_le' => null,
            ])->save();

            if ($mois > 0) {
                PaiementAbonnement::create([
                    'entreprise_id' => $entreprise->id,
                    'utilisateur_id' => null,
                    'offre' => $offre,
                    'nature' => 'renouvellement',
                    'mois' => $mois,
                    'montant' => $montant,
                    'operateur_paiement' => 'superadmin',
                    'reference_paiement' => 'PROLONGATION-SUPERADMIN-' . $entreprise->id . '-' . now()->format('YmdHis'),
                    'statut' => 'active',
                    'active_le' => now(),
                    'traite_par' => Auth::id(),
                ] + (PaiementAbonnement::colonnesEnPlace() ? ['bloquant' => false, 'confirmation_vue_le' => now()] : []));
            }
        });

        $entreprise->refresh();

        return back()->with('succes', $entreprise->nom . ' : ' . Abonnements::libelle($entreprise->plan_abonnement)
            . ', échéance au ' . $entreprise->abonnement_expire_le?->format('d/m/Y') . '.');
    }

    /**
     * Le message du superadministrateur à une entreprise (25/11/2026) : en
     * haut de chacune de ses pages, et dans la fenêtre de blocage. Vide :
     * le message est retiré.
     */
    public function message(Request $request, \App\Modules\Admin\Modeles\Entreprise $entreprise): RedirectResponse
    {
        $donnees = $request->validate(['message' => ['nullable', 'string', 'max:1000']]);
        // Le superadministrateur voit et écrit des dates ; on garde le modèle
        // ({echeance}, {jours}) pour que le nombre de jours avance chaque jour.
        $texte = trim((string) ($donnees['message'] ?? ''));
        $texte = $texte !== '' ? Abonnements::versModele($entreprise, $texte) : '';

        if (! \App\Support\Structure::aColonne('entreprises', 'message_abonnement')) {
            return back()->with('erreur', 'Lancez d\'abord php artisan migrate : la colonne du message n\'existe pas encore.');
        }

        $entreprise->forceFill([
            'message_abonnement'    => $texte !== '' ? $texte : null,
            'message_abonnement_le' => $texte !== '' ? now() : null,
        ])->save();

        return back()->with('succes', $entreprise->nom . ' : ' . ($texte !== '' ? 'message enregistré, il s\'affiche en haut de ses pages.' : 'message retiré.'));
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
