<?php

namespace App\Http\Middleware;

use App\Modules\Admin\Modeles\IpBloquee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Une IP bloquée par le superadministrateur n'accède plus à rien (16/10/2026) :
 * 403 sec, sans un mot sur ce que cache l'URL. Avant toute autre vérification.
 */
class BloquerIpInterdite
{
    public function handle(Request $request, Closure $next): Response
    {
        if (IpBloquee::estBloquee($request->ip())) {
            abort(403);
        }

        return $next($request);
    }
}
