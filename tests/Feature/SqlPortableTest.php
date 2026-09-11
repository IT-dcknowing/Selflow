<?php

namespace Tests\Feature;

use App\Modules\Admin\Services\ExpressionSqlPortable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le SQL écrit à la main ne doit pas dépendre du moteur.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui s'est passé
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Le tableau de bord général répondait 500 (Internal Server Error — erreur
 * interne du serveur) sur une machine et pas sur une autre. Il appelait
 * `CONCAT()`, que **SQLite ne connaît qu'à partir de la 3.44**. La suite
 * tourne sur SQLite : elle passait là où le SQLite était récent, tombait
 * ailleurs. Une panne qui dépend de la machine, non du code.
 *
 * En cherchant, quatre autres emplois — `DATE_FORMAT()`, `YEAR()`, `MONTH()` —
 * propres à MySQL. Ceux-là marchent en production. Leur coût est ailleurs :
 * **aucune épreuve ne pouvait couvrir les écrans qui les portent**, l'épreuve
 * tombant avant d'avoir rien vérifié. Deux rapports entiers étaient hors de
 * portée, et personne ne s'en apercevait.
 */
class SqlPortableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les fonctions qu'un seul moteur connaît.
     *
     * `CONCAT` y figure : SQLite ne l'a qu'à partir de la 3.44, et la version
     * installée n'est pas une propriété du code.
     *
     * @var array<int, string>
     */
    private const NON_PORTABLES = [
        'CONCAT', 'DATE_FORMAT', 'GROUP_CONCAT', 'IFNULL', 'STR_TO_DATE',
        'DATEDIFF', 'YEAR', 'MONTH', 'DAYOFWEEK', 'CURDATE', 'RAND',
    ];

    public function test_aucun_controleur_nappelle_une_fonction_propre_a_un_moteur(): void
    {
        $fautifs = [];

        foreach (glob(app_path('Modules/*/Controleurs/*.php')) ?: [] as $chemin) {
            foreach (file($chemin, FILE_IGNORE_NEW_LINES) as $n => $ligne) {
                if (!str_contains($ligne, 'DB::raw')) {
                    continue;
                }

                foreach (self::NON_PORTABLES as $fonction) {
                    if (preg_match('/\b' . $fonction . '\s*\(/i', $ligne)) {
                        $fautifs[] = basename($chemin) . ':' . ($n + 1) . ' → ' . $fonction . '()';
                    }
                }
            }
        }

        $this->assertSame([], $fautifs, implode("\n", [
            'Du SQL écrit à la main appelle une fonction que tous les moteurs',
            'n\'ont pas. En production (MySQL) elle passe ; sous SQLite, où',
            'tournent les épreuves, l\'écran répond 500 et aucune épreuve ne peut',
            'le couvrir. Composer en PHP ce qui se compose en PHP, et passer par',
            '`ExpressionSqlPortable` pour ce que la base doit calculer.',
        ]));
    }

    public function test_les_expressions_portables_rendent_les_memes_valeurs_que_mysql(): void
    {
        DB::table('entreprises')->insert([
            'nom'        => 'Essai de portabilité',
            'created_at' => '2026-03-07 10:00:00',
            'updated_at' => '2026-03-07 10:00:00',
        ]);

        $ligne = DB::table('entreprises')
            ->select(
                DB::raw(ExpressionSqlPortable::anneeEtMois('created_at') . ' as periode'),
                DB::raw(ExpressionSqlPortable::annee('created_at') . ' as annee'),
                DB::raw(ExpressionSqlPortable::mois('created_at') . ' as mois'),
            )
            ->where('nom', 'Essai de portabilité')
            ->first();

        $this->assertSame('2026-03', $ligne->periode);
        $this->assertSame(2026, (int) $ligne->annee);

        // Le mois est un nombre, non « 03 » : `strftime` rend la chaîne remplie
        // de zéros là où `MONTH()` rend un entier. Les confondre ferait chercher
        // un libellé de mois sous deux clés différentes selon le moteur.
        $this->assertSame(3, (int) $ligne->mois);
        $this->assertSame('3', (string) (int) $ligne->mois);
    }
}
