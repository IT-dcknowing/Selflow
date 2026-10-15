<?php

namespace App\Modules\Admin\Controleurs;

use App\Modules\Admin\Modeles\ParametrePlateforme;
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

        Abonnements::appliquer($entreprise, $offre);

        $message = match ($offre) {
            Abonnements::PASS_CADEAU => 'Votre pass cadeau est actif : ' . Abonnements::DUREE_PASS_CADEAU_JOURS
                . ' jours gratuits, 5 points de vente et 5 utilisateurs, tout est activé. Il se termine le '
                . $entreprise->fresh()->abonnement_expire_le->format('d/m/Y') . '.',
            Abonnements::SUR_MESURE => 'Votre demande d\'offre sur mesure est notée : le service commercial vous contacte.'
                . $this->contactsEnPhrase(),
            default => 'Offre « ' . Abonnements::libelle($offre) . ' » retenue. DC-Knowing vous contacte pour le règlement'
                . ' (' . number_format(Abonnements::offres()[$offre]['prix'], 0, ',', ' ') . ' F par mois).'
                . $this->contactsEnPhrase(),
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

        $entreprise->forceFill([
            'abonnement_demande'    => $offre,
            'abonnement_demande_le' => now(),
            'abonnement_statut'     => Abonnements::STATUT_A_REGLER,
        ])->save();

        return $this->retour($request)->with('succes',
            'Demande de renouvellement de l\'offre « ' . Abonnements::libelle($offre) . ' » enregistrée. '
            . 'Le paiement en ligne arrive bientôt ; d\'ici là, DC-Knowing vous contacte pour le règlement.'
            . $this->contactsEnPhrase());
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
