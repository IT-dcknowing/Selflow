<?php

namespace App\Http\Middleware;

use App\Modules\Admin\Modeles\ParametrePlateforme;
use App\Modules\Admin\Modeles\Visite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Journalise chaque requête web pour la traçabilité (16/10/2026) : IP, page,
 * statut, durée, qui. Écrit après la réponse, pour ne rien ajouter au temps
 * perçu, et seulement si la traçabilité est active (réglable, par défaut oui).
 *
 * Volontairement sobre : pas les requêtes du superadministrateur (il n'est pas
 * le trafic à surveiller, et sa page d'analyse se rafraîchit seule), pas les
 * ressources statiques, une ligne par requête, purgée au-delà de 30 jours.
 */
class JournaliserVisite
{
    public function handle(Request $request, Closure $next): Response
    {
        $debut = microtime(true);
        $reponse = $next($request);

        try {
            if (!self::active() || $request->isMethod('OPTIONS')) {
                return $reponse;
            }

            $user = Auth::user();
            if ($user?->estSuperAdmin()) {
                return $reponse;
            }

            Visite::create([
                'ip'             => $request->ip(),
                'methode'        => $request->method(),
                'chemin'         => mb_substr($request->path(), 0, 500),
                'statut'         => $reponse->getStatusCode(),
                'duree_ms'       => (int) round((microtime(true) - $debut) * 1000),
                'utilisateur_id' => $user?->id,
                'entreprise_id'  => $user?->entreprise_id,
                'agent'          => mb_substr((string) $request->userAgent(), 0, 255),
                'session_id'     => $request->hasSession() ? mb_substr($request->session()->getId(), 0, 64) : null,
                'cree_le'        => now(),
            ]);
        } catch (\Throwable $e) {
            // La traçabilité ne doit jamais gêner la requête.
        }

        return $reponse;
    }

    /** La traçabilité est active sauf si explicitement désactivée. */
    public static function active(): bool
    {
        return Cache::remember('tracabilite_active', 60, function () {
            return ParametrePlateforme::lire('tracabilite_trafic', 'oui') !== 'non';
        });
    }
}
