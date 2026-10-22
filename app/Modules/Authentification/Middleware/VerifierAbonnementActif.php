<?php

namespace App\Modules\Authentification\Middleware;

use App\Modules\Admin\Regles\Abonnements;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'abonnement arrivé à échéance — décision du propriétaire du 15/10/2026.
 *
 * L'entreprise accède toujours à son espace, mais ne peut plus rien y faire :
 * toute action (envoi de formulaire, enregistrement, suppression) est refusée
 * et renvoie vers la section Facturation, d'où elle renouvelle ou passe à une
 * offre supérieure. À l'écran, une fenêtre le dit et empêche de cliquer.
 *
 * Même blocage pour l'abonnement en attente (22/10/2026) : une offre payée
 * par référence que DC-Knowing n'a pas encore confirmée, ou dont le paiement
 * a échoué, ne permet pas de travailler.
 */
class VerifierAbonnementActif
{
    /** Ce qui reste possible : choisir ou renouveler son offre. */
    private const TOUJOURS_PERMIS = [
        'admin.abonnement.enregistrer',
        'admin.abonnement.renouveler',
        'admin.abonnement.confirmation_vue',
        'deconnexion',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = Auth::user();

        if (! $utilisateur || $utilisateur->estSuperAdmin() || $request->isMethodSafe()) {
            return $next($request);
        }

        $suspens = Abonnements::paiementEnSuspens($utilisateur->entreprise);

        if (! $suspens && ! Abonnements::estATerme($utilisateur->entreprise)) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::TOUJOURS_PERMIS, true)) {
            return $next($request);
        }

        [$statut, $message] = match (true) {
            $suspens?->statut === 'echec' => ['abonnement_paiement_echec',
                'Le paiement de votre abonnement n\'a pas été confirmé : saisissez une nouvelle référence pour continuer.'],
            $suspens !== null => ['abonnement_en_attente',
                'En attente de confirmation de l\'abonnement : vous pourrez travailler dès que DC-Knowing aura confirmé votre paiement.'],
            default => ['abonnement_a_terme',
                'Abonnement à terme : renouvelez-le ou passez à une offre supérieure pour continuer.'],
        };

        if ($request->expectsJson()) {
            return response()->json(['statut' => $statut, 'message' => $message], 423);
        }

        return redirect()->to(route('admin.entreprise.parametres') . '#facturation')->with('erreur', $message);
    }
}
