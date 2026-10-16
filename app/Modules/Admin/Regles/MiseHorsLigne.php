<?php

namespace App\Modules\Admin\Regles;

/**
 * La mise hors ligne de la plateforme — retour du 16/10/2026.
 *
 * Le superadministrateur coupe l'accès (attaque en cours, gros travaux) d'un
 * bouton, depuis Pannes & assistance. Les utilisateurs voient une page qui
 * rassure — « maintenance évolutive, ne vous inquiétez pas » — avec les
 * contacts de l'équipe. Le superadministrateur, lui, continue d'entrer : c'est
 * lui qui rallume.
 *
 * L'état tient dans un fichier, et non en base : il est lu à chaque requête,
 * et un `file_exists` ne coûte rien là où une requête SQL s'ajouterait à
 * toutes les pages. Il survit aussi à une base indisponible — le jour même où
 * l'on a le plus besoin de couper.
 */
final class MiseHorsLigne
{
    public static function chemin(): string
    {
        return (string) config('selflow.hors_ligne_fichier', storage_path('framework/selflow-hors-ligne.json'));
    }

    public static function active(): bool
    {
        return is_file(self::chemin());
    }

    /** @return array{message: ?string, depuis: ?string, par: ?string} */
    public static function etat(): array
    {
        $etat = self::active() ? json_decode((string) @file_get_contents(self::chemin()), true) : null;

        return [
            'message' => $etat['message'] ?? null,
            'depuis'  => $etat['depuis'] ?? null,
            'par'     => $etat['par'] ?? null,
        ];
    }

    public static function couper(?string $message, ?string $par): void
    {
        @mkdir(dirname(self::chemin()), 0775, true);
        file_put_contents(self::chemin(), json_encode([
            'message' => $message ?: null,
            'depuis'  => now()->toIso8601String(),
            'par'     => $par,
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function rallumer(): void
    {
        @unlink(self::chemin());
    }
}
