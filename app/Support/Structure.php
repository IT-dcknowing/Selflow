<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * `Schema::hasTable` / `hasColumn`, gardés en cache (23/11/2026).
 *
 * Sur MySQL, chaque appel interroge `information_schema` : sur l'hébergement
 * mutualisé, plusieurs centaines de millisecondes, parfois plus, et le menu en
 * faisait plusieurs à chaque page. Une réponse positive se garde : une table
 * ne disparaît pas. Une négative se revérifie au bout de 5 minutes, le temps
 * qu'une migration soit jouée. `php artisan optimize:clear` vide tout.
 */
class Structure
{
    private static array $memo = [];

    public static function aTable(string $table): bool
    {
        return self::demander('table:' . $table, fn () => Schema::hasTable($table));
    }

    public static function aColonne(string $table, string $colonne): bool
    {
        return self::demander('colonne:' . $table . '.' . $colonne, fn () => Schema::hasColumn($table, $colonne));
    }

    private static function demander(string $cle, \Closure $verifier): bool
    {
        // En test, la base change d'un test à l'autre : on ne garde rien.
        if (app()->runningUnitTests()) {
            return (bool) $verifier();
        }
        if (array_key_exists($cle, self::$memo)) {
            return self::$memo[$cle];
        }

        $cache = 'structure:' . config('database.default') . ':' . $cle;
        $valeur = Cache::get($cache);
        if ($valeur === null) {
            $valeur = (bool) $verifier();
            $valeur ? Cache::forever($cache, true) : Cache::put($cache, false, now()->addMinutes(5));
        }

        return self::$memo[$cle] = (bool) $valeur;
    }
}
