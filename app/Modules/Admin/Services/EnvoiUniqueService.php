<?php

namespace App\Modules\Admin\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Un formulaire de pièce ne s'enregistre qu'une fois (urgence du 26/10/2026).
 *
 * Un double clic sur « Valider », ou un réseau lent qui fait renvoyer la page,
 * enregistrait deux ventes identiques sous deux numéros. Chaque formulaire
 * porte désormais un jeton tiré au hasard à son affichage (`jeton_envoi`) ;
 * le premier envoi le réserve, le second le trouve pris et s'arrête.
 *
 * Un envoi sans jeton (API, ancien onglet resté ouvert) passe comme avant.
 */
class EnvoiUniqueService
{
    private const DUREE_SECONDES = 1800;

    /** Réserver le jeton du formulaire ; faux s'il a déjà servi. */
    public static function reserver(Request $request, string $nature): bool
    {
        $cle = self::cle($request, $nature);

        return $cle === null || Cache::add($cle, now()->timestamp, self::DUREE_SECONDES);
    }

    /** Rendre le jeton : l'enregistrement a échoué, l'utilisateur doit pouvoir réessayer. */
    public static function liberer(Request $request, string $nature): void
    {
        if ($cle = self::cle($request, $nature)) {
            Cache::forget($cle);
        }
    }

    private static function cle(Request $request, string $nature): ?string
    {
        $jeton = (string) $request->input('jeton_envoi', '');
        if ($jeton === '' || strlen($jeton) > 64) {
            return null;
        }

        return 'envoi-unique:' . $nature . ':' . Auth::id() . ':' . sha1($jeton);
    }
}
