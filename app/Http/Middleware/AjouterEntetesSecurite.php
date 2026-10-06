<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware AjouterEntetesSecurite
 *
 * Injecte les en-têtes de sécurité HTTP sur toutes les réponses.
 * Référence : Section 17.11 de la feuille de route Selflow.
 */
class AjouterEntetesSecurite
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Empêche le navigateur de deviner le type MIME (évite les uploads malveillants)
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Protège contre le clickjacking (important pour un écran de caisse/paiement)
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Limite les informations de référent envoyées à des tiers
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Désactive la sniffer de navigateur obsolète (IE)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Content-Security-Policy : tout vient du domaine.
        //
        // Les polices et les icônes venaient de trois CDN — Google Fonts,
        // cdnjs, jsdelivr — chargés en feuilles bloquantes : la page restait
        // blanche tant qu'ils n'avaient pas répondu, et une liaison lente
        // suffisait à faire attendre chaque écran des dizaines de secondes.
        // Pire, `font-src` n'autorisait pas jsdelivr : la police d'icônes des
        // pages d'entrée était refusée par ce même en-tête, d'où les carrés
        // vides de l'inscription (chantier 9.1). Tout est désormais servi
        // depuis `public/vendor` — lot 41.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
            "style-src 'self' 'unsafe-inline'; " .
            "font-src 'self' data:; " .
            "img-src 'self' data: blob:; " .
            "connect-src 'self';"
        );

        return $response;
    }
}
