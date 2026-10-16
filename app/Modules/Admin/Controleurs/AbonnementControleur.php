<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\PaiementAbonnement;
use App\Modules\Admin\Modeles\ParametrePlateforme;
use App\Modules\Admin\Regles\PaiementParReference;
use App\Modules\Admin\Regles\Abonnements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Les offres d'abonnement, en cartes — décision du propriétaire du 15/10/2026.
 *
 * La page s'affiche au visiteur qui va s'inscrire, et à l'entreprise juste
 * après son inscription (complète ou « terminée sans remplir la suite ») : elle
 * y choisit son offre.
 */
class AbonnementControleur
{
    /** Les offres, pour le visiteur. */
    public function vitrine(): View
    {
        return view('admin::abonnements.offres', $this->donnees() + ['entreprise' => null]);
    }

    /** Les offres, pour l'entreprise qui choisit la sienne. */
    public function choisir(): View
    {
        return view('admin::abonnements.offres', $this->donnees() + ['entreprise' => Auth::user()->entreprise]);
    }

    public function enregistrer(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($entreprise, 403);

        $donnees = $request->validate([
            'offre' => ['required', 'string', Rule::in(array_keys(Abonnements::offres()))],
        ], [
            'offre.in' => 'Cette offre n\'existe pas.',
        ]);
        $offre = $donnees['offre'];

        if ($offre === Abonnements::PASS_CADEAU && $entreprise->pass_cadeau_utilise_le !== null) {
            return $this->retour($request)->with('erreur', 'Le pass cadeau a déjà été utilisé par votre entreprise : il ne s\'offre qu\'une fois.');
        }

        // Une offre payante se règle par dépôt sur le numéro du cabinet : la
        // référence de la transaction est exigée, et le superadministrateur
        // l'active (16/10/2026, en attendant l'API de paiement).
        $paiement = null;
        if (self::estPayante($offre)) {
            $reglement = $request->validate(PaiementParReference::regles(), PaiementParReference::messages());
            $paiement = $this->declarerLePaiement($entreprise, $offre, 'souscription', $reglement);
        }

        Abonnements::appliquer($entreprise, $offre);

        $message = match ($offre) {
            Abonnements::PASS_CADEAU => 'Votre pass cadeau est actif : ' . Abonnements::DUREE_PASS_CADEAU_JOURS
                . ' jours gratuits, 5 points de vente et 5 utilisateurs, tout est activé. Il se termine le '
                . $entreprise->fresh()->abonnement_expire_le->format('d/m/Y') . '.',
            Abonnements::SUR_MESURE => 'Votre demande d\'offre sur mesure est notée : le service commercial vous contacte.'
                . $this->contactsEnPhrase(),
            default => 'Offre « ' . Abonnements::libelle($offre) . ' » retenue. Votre paiement de '
                . number_format((float) $paiement->montant, 0, ',', ' ') . ' F (référence ' . $paiement->reference_paiement
                . ') est transmis à DC-Knowing, qui le vérifie et active votre abonnement.',
        };

        return $this->retour($request)->with('succes', $message);
    }

    /**
     * Renouveler son offre avant l'échéance — ou après, pour sortir du
     * blocage. Le paiement en ligne n'est pas encore branché : la demande est
     * notée, DC-Knowing confirme le règlement et repousse l'échéance.
     */
    public function renouveler(Request $request): RedirectResponse
    {
        $entreprise = Auth::user()->entreprise;
        abort_unless($entreprise, 403);

        $offre = $entreprise->plan_abonnement;
        if (! in_array($offre, [Abonnements::SOLO, Abonnements::BUSINESS, Abonnements::PREMIUM, Abonnements::SUR_MESURE], true)) {
            return $this->retour($request)->with('erreur', 'Choisissez d\'abord une offre : le pass cadeau ne se renouvelle pas.');
        }

        // Toute offre renouvelable se règle par référence ; l'offre sur mesure
        // avec le montant de son devis, que le client saisit (16/10/2026).
        $surMesure = $offre === Abonnements::SUR_MESURE;
        $reglement = $request->validate(
            PaiementParReference::regles() + ($surMesure ? ['montant' => ['required', 'integer', 'min:1']] : []),
            PaiementParReference::messages() + ['montant.required' => 'Saisissez le montant payé, celui convenu dans votre devis.']
        );
        $paiement = $this->declarerLePaiement($entreprise, $offre, 'renouvellement', $reglement,
            $surMesure ? (int) $reglement['montant'] : null);

        $entreprise->forceFill([
            'abonnement_demande'    => $offre,
            'abonnement_demande_le' => now(),
            'abonnement_statut'     => Abonnements::STATUT_A_REGLER,
        ])->save();

        return $this->retour($request)->with('succes',
            'Renouvellement de l\'offre « ' . Abonnements::libelle($offre) . ' » demandé. '
            . 'Votre paiement de ' . number_format((float) $paiement->montant, 0, ',', ' ') . ' F (référence '
            . $paiement->reference_paiement . ') est transmis à DC-Knowing, qui le vérifie et prolonge votre abonnement.');
    }

    /** Les offres qui se paient : Solo, Business, Premium. */
    private static function estPayante(string $offre): bool
    {
        return (int) (Abonnements::offres()[$offre]['prix'] ?? 0) > 0;
    }

    /** @param array{operateur_paiement: string, reference_paiement: string} $reglement */
    private function declarerLePaiement(\App\Modules\Admin\Modeles\Entreprise $entreprise, string $offre, string $nature, array $reglement, ?int $montant = null): PaiementAbonnement
    {
        $paiement = PaiementAbonnement::create([
            'entreprise_id'      => $entreprise->id,
            'utilisateur_id'     => Auth::id(),
            'offre'              => $offre,
            'nature'             => $nature,
            'mois'               => 1,
            'montant'            => $montant ?? (int) Abonnements::offres()[$offre]['prix'],
            'operateur_paiement' => $reglement['operateur_paiement'],
            'reference_paiement' => trim($reglement['reference_paiement']),
            'statut'             => 'recu',
        ]);

        // Alerte aux destinataires configurés : un paiement à confirmer
        // (16/10/2026). Le superadmin l'active depuis Paiements d'abonnement.
        try {
            \App\Modules\Admin\Services\AlerteSuperadminService::alerter(
                \App\Modules\Admin\Services\AlerteSuperadminService::PAIEMENT,
                'Paiement à confirmer : ' . $entreprise->nom,
                'Entreprise : ' . $entreprise->nom . "\n"
                . 'Offre : ' . Abonnements::libelle($offre) . ' (' . $nature . ")\n"
                . 'Montant : ' . number_format((float) $paiement->montant, 0, ',', ' ') . " F\n"
                . 'Opérateur : ' . $paiement->operateur_paiement . "\n"
                . 'Référence : ' . $paiement->reference_paiement . "\n"
                . 'À confirmer dans Superadmin › Paiements d\'abonnement.'
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Alerte paiement non envoyée : ' . $e->getMessage());
        }

        return $paiement;
    }

    /** Depuis la section Facturation, on y revient ; sinon, au tableau de bord. */
    private function retour(Request $request): RedirectResponse
    {
        return $request->input('depuis') === 'facturation'
            ? redirect()->to(route('admin.entreprise.parametres') . '#facturation')
            : redirect()->route('admin.tableau_de_bord');
    }

    private function donnees(): array
    {
        return [
            'offres' => Abonnements::offres(),
            'contact' => [
                'email'     => ParametrePlateforme::lire(ParametrePlateforme::COMMERCIAL_EMAIL, ParametrePlateforme::lire(ParametrePlateforme::ASSISTANCE_EMAIL)),
                'telephone' => ParametrePlateforme::lire(ParametrePlateforme::COMMERCIAL_TELEPHONE, ParametrePlateforme::lire(ParametrePlateforme::ASSISTANCE_TELEPHONE)),
            ],
        ];
    }

    private function contactsEnPhrase(): string
    {
        $contact = $this->donnees()['contact'];
        $morceaux = array_filter([$contact['email'], $contact['telephone']]);

        return $morceaux ? ' Contact : ' . implode(' · ', $morceaux) . '.' : '';
    }
}
