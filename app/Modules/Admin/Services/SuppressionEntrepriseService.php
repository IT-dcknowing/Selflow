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

    /** @var array<string, array>|null  Le graphe des dépendances, calculé une fois par processus. */
    private static ?array $liensEnCache = null;

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
        if (self::$liensEnCache !== null) {
            return self::$liensEnCache;
        }

        // La mémoire du processus ne suffisait pas : chaque requête web est un
        // processus neuf, et le relevé — quatre requêtes à `information_schema`
        // par table, plusieurs centaines en tout — se refaisait à chaque
        // effacement. Sur le MySQL de l'hébergement, l'effacement dépassait
        // cinq minutes avant d'avoir touché une ligne, et la requête mourait
        // en annulant tout (retour du 20/10/2026). Le graphe se lit désormais
        // en trois requêtes sur MySQL, et se garde en cache tant que la base
        // ne change pas de migration.
        $empreinte = 'selflow.graphe_suppression.' . DB::connection()->getDriverName() . '.'
            . (Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0);

        return self::$liensEnCache = \Illuminate\Support\Facades\Cache::remember($empreinte, now()->addDay(), function () {
            if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                try {
                    return self::liensMysql();
                } catch (\Throwable $e) {
                    // Une version de MySQL qui n'aurait pas ces vues : le
                    // relevé lent, mais juste.
                    \Illuminate\Support\Facades\Log::warning('Graphe de suppression : repli sur le relevé par table — ' . $e->getMessage());
                }
            }

            return self::liensParSchema();
        });
    }

    /** Le graphe MySQL, en trois requêtes. */
    private static function liensMysql(): array
    {
        $tables = array_map(fn ($t) => $t->TABLE_NAME, DB::select(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
        ));

        $colonnes = [];
        foreach (DB::select('SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $c) {
            $colonnes[$c->TABLE_NAME][$c->COLUMN_NAME] = $c->IS_NULLABLE === 'YES';
        }

        $parContrainte = [];
        foreach (DB::select("
            SELECT k.TABLE_NAME AS t, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS ref, r.DELETE_RULE AS regle, k.CONSTRAINT_NAME AS nom
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
        ") as $k) {
            $parContrainte[$k->t . '|' . $k->nom][] = $k;
        }

        $fks = [];
        foreach ($parContrainte as $partiesDeLaCle) {
            $k = $partiesDeLaCle[0];
            $fks[$k->t][] = [
                'columns'       => array_map(fn ($p) => $p->c, $partiesDeLaCle),
                'foreign_table' => $k->ref,
                'on_delete'     => $k->regle,
            ];
        }

        return self::assembler($tables, $colonnes, $fks);
    }

    /** Le graphe par le `Schema` de Laravel (le SQLite des épreuves, et le reste). */
    private static function liensParSchema(): array
    {
        $tables = array_map(fn ($t) => $t['name'], Schema::getTables());
        $colonnes = [];
        $fks = [];
        foreach ($tables as $table) {
            foreach (Schema::getColumns($table) as $c) {
                $colonnes[$table][$c['name']] = (bool) $c['nullable'];
            }
            $fks[$table] = Schema::getForeignKeys($table);
        }

        return self::assembler($tables, $colonnes, $fks);
    }

    /**
     * @param  array<int, string>  $tables
     * @param  array<string, array<string, bool>>  $colonnes  table => colonne => nullable
     * @param  array<string, array<int, array>>  $fks
     */
    private static function assembler(array $tables, array $colonnes, array $fks): array
    {
        $liens = [];

        foreach ($tables as $table) {
            if (in_array($table, self::EPARGNEES, true)) {
                continue;
            }
            $aUnId = isset($colonnes[$table]['id']);

            foreach ($fks[$table] ?? [] as $cle) {
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
                if ($table !== $parent && isset($colonnes[$table][$colonne])) {
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

        // La nullabilité des colonnes, gardée avec le graphe : plus une
        // requête par colonne à vider.
        $liens['_nullables'] = $colonnes;

        return $liens;
    }

    private static function acceptePasDeValeur(string $table, string $colonne): bool
    {
        return (bool) (self::liens()['_nullables'][$table][$colonne] ?? false);
    }
}
