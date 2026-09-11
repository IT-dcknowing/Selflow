<?php

namespace App\Modules\Admin\Services;

use Illuminate\Support\Facades\DB;

/**
 * Les fragments de SQL qui ne s'écrivent pas pareil selon le moteur.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Pourquoi cette classe existe
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Le tableau de bord général répondait 500 (Internal Server Error — erreur
 * interne du serveur) sur une machine et pas sur une autre : il appelait
 * `CONCAT()`, que **SQLite ne connaît qu'à partir de la 3.44**. La suite
 * d'épreuves tourne sur SQLite ; elle passait là où le SQLite était récent et
 * tombait ailleurs. Une panne qui dépend de la machine, non du code.
 *
 * En cherchant, quatre autres emplois du même genre — `DATE_FORMAT()`,
 * `YEAR()`, `MONTH()` —, tous propres à MySQL. Ceux-là marchent en production,
 * qui est sous MySQL. Leur coût est ailleurs : **aucune épreuve ne peut
 * couvrir les écrans qui les portent**, puisque l'épreuve tombe avant d'avoir
 * rien vérifié. Deux rapports entiers étaient donc hors de portée.
 *
 * Ce qui se compose en PHP se compose en PHP — c'est le cas du nom d'un
 * employé, et il n'a pas sa place ici. Ne restent que les expressions qui
 * doivent être calculées par la base, parce qu'elles servent à regrouper.
 */
class ExpressionSqlPortable
{
    /**
     * L'année et le mois d'une colonne de date, au format « 2026-09 ».
     *
     * Sert de clé de regroupement : c'est pour cela qu'elle ne peut pas se
     * calculer en PHP après coup.
     */
    public static function anneeEtMois(string $colonne): string
    {
        return (self::sqlite()
            ? "strftime('%Y-%m', {$colonne})"
            : "DATE_FORMAT({$colonne}, '%Y-%m')");
    }

    /**
     * L'année d'une colonne de date, en nombre.
     */
    public static function annee(string $colonne): string
    {
        return (self::sqlite()
            ? "CAST(strftime('%Y', {$colonne}) AS INTEGER)"
            : "YEAR({$colonne})");
    }

    /**
     * Le mois d'une colonne de date, en nombre — 1 à 12.
     *
     * `strftime` rend « 01 » là où `MONTH()` rend 1 : la conversion en entier
     * n'est pas une élégance, elle évite qu'un libellé de mois se cherche sous
     * « 01 » d'un côté et sous 1 de l'autre.
     */
    public static function mois(string $colonne): string
    {
        return (self::sqlite()
            ? "CAST(strftime('%m', {$colonne}) AS INTEGER)"
            : "MONTH({$colonne})");
    }

    private static function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
