<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supprimer une entreprise et tout ce qui lui appartient — 15/10/2026.
 *
 * La suppression effaçait les utilisateurs, puis les points de vente, puis
 * l'entreprise, sans transaction : en production, la base a refusé la
 * deuxième étape (une vente pointe vers une autre vente, `ventes.parent_id`),
 * et l'entreprise est restée **sans ses utilisateurs**.
 *
 * Désormais, on relève tout ce qui dépend de l'entreprise en suivant les clés
 * étrangères du schéma (et les colonnes `entreprise_id` / `point_de_vente_id`
 * qui n'en portent pas), de proche en proche, puis on efface le tout dans une
 * seule transaction. Une table ajoutée demain est prise en compte sans qu'on
 * ait à la nommer ici.
 */
class SuppressionEntrepriseService
{
    /** Tables qui ne se vident jamais par ce chemin. */
    private const EPARGNEES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs', 'job_batches', 'password_reset_tokens'];

    /**
     * @return array<string, int> lignes effacées, par table
     */
    public static function supprimer(Entreprise $entreprise): array
    {
        ['effacer' => $aEffacer, 'vider' => $aVider, 'liaisons' => $liaisons] = self::releverLesDependances($entreprise);

        DB::transaction(function () use ($aEffacer, $aVider, $liaisons) {
            Schema::withoutForeignKeyConstraints(function () use ($aEffacer, $aVider, $liaisons) {
                // Ce qui appartient à d'autres et pointait ici : le lien
                // s'efface (ON DELETE SET NULL), la ligne reste.
                foreach ($aVider as [$table, $colonne, $ids]) {
                    if (! self::acceptePasDeValeur($table, $colonne)) {
                        continue;
                    }
                    foreach (array_chunk($ids, 500) as $lot) {
                        DB::table($table)->whereIn($colonne, $lot)->update([$colonne => null]);
                    }
                }
                // Les tables de liaison sans identifiant.
                foreach ($liaisons as [$table, $colonne, $ids]) {
                    foreach (array_chunk($ids, 500) as $lot) {
                        DB::table($table)->whereIn($colonne, $lot)->delete();
                    }
                }
                foreach ($aEffacer as $table => $ids) {
                    foreach (array_chunk($ids, 500) as $lot) {
                        DB::table($table)->whereIn('id', $lot)->delete();
                    }
                }
            });
        });

        return array_map('count', $aEffacer);
    }

    /**
     * Ce qui disparaîtra (identifiants par table), les liens à remettre à
     * vide, et les tables de liaison à purger.
     *
     * @return array{effacer: array<string, array<int, int>>, vider: array<int, array{0: string, 1: string, 2: array<int, int>}>, liaisons: array<int, array{0: string, 1: string, 2: array<int, int>}>}
     */
    public static function releverLesDependances(Entreprise $entreprise): array
    {
        $liens = self::liens();
        $aEffacer = ['entreprises' => [(int) $entreprise->id]];
        $file = ['entreprises'];
        $vus = [];

        while ($file !== []) {
            $parent = array_shift($file);
            $idsParent = $aEffacer[$parent];

            foreach ($liens[$parent] ?? [] as [$table, $colonne, $mode]) {
                if ($mode !== 'cascade') {
                    continue;
                }
                $trouves = [];
                foreach (array_chunk($idsParent, 500) as $lot) {
                    $trouves = array_merge($trouves, DB::table($table)->whereIn($colonne, $lot)->pluck('id')->map(fn ($id) => (int) $id)->all());
                }

                $nouveaux = array_values(array_diff($trouves, $aEffacer[$table] ?? []));
                if ($nouveaux !== []) {
                    $aEffacer[$table] = array_values(array_merge($aEffacer[$table] ?? [], $nouveaux));
                    $file[] = $table;
                }
            }
        }

        // Une fois l'ensemble connu : les liens « SET NULL » et les tables de
        // liaison, vers tout ce qui disparaît.
        $aVider = [];
        $liaisons = [];
        foreach ($aEffacer as $parent => $ids) {
            foreach ($liens[$parent] ?? [] as [$table, $colonne, $mode]) {
                if ($mode === 'nul') {
                    $aVider[] = [$table, $colonne, $ids];
                } elseif ($mode === 'liaison') {
                    $liaisons[] = [$table, $colonne, $ids];
                }
            }
        }

        return ['effacer' => $aEffacer, 'vider' => $aVider, 'liaisons' => $liaisons];
    }

    /**
     * Table parente → [[table enfant, colonne]], d'après les clés étrangères
     * et les colonnes conventionnelles. Seules les tables qui ont un `id`
     * s'effacent ligne par ligne ; les tables de liaison sans `id` sont
     * vidées par leur clé.
     *
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    private static function liens(): array
    {
        $liens = [];
        $tables = array_map(fn ($t) => $t['name'], Schema::getTables());

        foreach ($tables as $table) {
            if (in_array($table, self::EPARGNEES, true)) {
                continue;
            }
            $aUnId = Schema::hasColumn($table, 'id');

            foreach (Schema::getForeignKeys($table) as $cle) {
                if (count($cle['columns']) !== 1 || ! in_array($cle['foreign_table'], $tables, true)) {
                    continue;
                }
                // Seul un « ON DELETE CASCADE » déclaré dit que la ligne
                // appartient à son parent. Toute autre contrainte peut venir
                // d'une autre entreprise (son achat qui pointe vers une
                // négociation B2B commune) : le lien se vide, la ligne reste.
                $surSuppression = strtolower((string) ($cle['on_delete'] ?? ''));
                $mode = ! $aUnId ? 'liaison' : ($surSuppression === 'cascade' ? 'cascade' : 'nul');
                $liens[$cle['foreign_table']][] = [$table, $cle['columns'][0], $mode];
            }

            // Les colonnes de rattachement sans contrainte déclarée : la ligne
            // appartient à l'entreprise (ou à son site), elle part avec elle.
            foreach (['entreprise_id' => 'entreprises', 'point_de_vente_id' => 'points_de_vente'] as $colonne => $parent) {
                if ($table !== $parent && Schema::hasColumn($table, $colonne)) {
                    $liens[$parent][] = [$table, $colonne, $aUnId ? 'cascade' : 'liaison'];
                }
            }
        }

        foreach ($liens as $parent => $enfants) {
            // Une même colonne déclarée deux fois (contrainte + convention) :
            // « cascade » l'emporte, la ligne appartient à l'entreprise.
            $parCle = [];
            foreach ($enfants as [$table, $colonne, $mode]) {
                $cle = $table . '.' . $colonne;
                if (! isset($parCle[$cle]) || $mode === 'cascade') {
                    $parCle[$cle] = [$table, $colonne, $mode];
                }
            }
            $liens[$parent] = array_values($parCle);
        }

        return $liens;
    }

    private static function acceptePasDeValeur(string $table, string $colonne): bool
    {
        foreach (Schema::getColumns($table) as $definition) {
            if ($definition['name'] === $colonne) {
                return (bool) $definition['nullable'];
            }
        }

        return false;
    }
}
