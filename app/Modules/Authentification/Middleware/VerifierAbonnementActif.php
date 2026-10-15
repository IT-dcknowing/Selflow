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
 */
class VerifierAbonnementActif
{
    /** Ce qui reste possible : choisir ou renouveler son offre. */
    private const TOUJOURS_PERMIS = [
        'admin.abonnement.enregistrer',
        'admin.abonnement.renouveler',
        'deconnexion',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = Auth::user();

        if (! $utilisateur || $utilisateur->estSuperAdmin() || $request->isMethodSafe()) {
            return $next($request);
        }

        if (! Abonnements::estATerme($utilisateur->entreprise)) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::TOUJOURS_PERMIS, true)) {
            return $next($request);
        }

        $message = 'Abonnement à terme : renouvelez-le ou passez à une offre supérieure pour continuer.';

        if ($request->expectsJson()) {
            return response()->json(['statut' => 'abonnement_a_terme', 'message' => $message], 423);
        }

        return redirect()->to(route('admin.entreprise.parametres') . '#facturation')->with('erreur', $message);
    }
}
