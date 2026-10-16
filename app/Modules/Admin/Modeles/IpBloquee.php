<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Une adresse IP interdite d'accès (16/10/2026). Bloquée, elle ne voit plus
 * rien, pas même l'URL : le middleware répond 403 avant toute route.
 *
 * La liste est gardée en cache une minute : la lire en base à chaque requête
 * de chaque visiteur coûterait plus que le blocage lui-même.
 */
class IpBloquee extends Model
{
    protected $table = 'ip_bloquees';

    protected $fillable = ['ip', 'motif', 'bloquee_par'];

    protected static function booted(): void
    {
        $oublier = fn () => Cache::forget('ip_bloquees');
        static::saved($oublier);
        static::deleted($oublier);
    }

    /** @return array<string, bool> les IP bloquées, pour un test O(1). */
    public static function liste(): array
    {
        // Jamais bloquant : une base indisponible, ou la table pas encore
        // migrée, ne doit pas couper l'accès à toute la plateforme.
        try {
            return Cache::remember('ip_bloquees', 60, fn () => self::pluck('ip')->flip()->map(fn () => true)->all());
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function estBloquee(?string $ip): bool
    {
        return $ip !== null && isset(self::liste()[$ip]);
    }
}
