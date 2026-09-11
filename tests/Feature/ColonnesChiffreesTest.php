<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Une colonne chiffrée n'a pas de longueur connue d'avance.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui s'est passé
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Première liaison Comptaflow lancée pour de vrai, les deux applications côte
 * à côte :
 *
 *     SQLSTATE[22001] 1406 Data too long for column 'comptaflow_sync_key'
 *
 * Comptaflow avait créé le dossier et rendu la clé ; Selflow n'a pas pu la
 * ranger. La colonne était un `varchar(255)`, posée en juin quand la clé s'y
 * écrivait **en clair**. Le lot 15 a posé le chiffrement sans toucher à la
 * colonne — et une clé chiffrée pèse près de 300 caractères.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Pourquoi quarante-deux épreuves vertes ne disaient rien
 * ─────────────────────────────────────────────────────────────────────────
 *
 * **SQLite ignore la longueur déclarée d'un `varchar`** : il range la chaîne
 * entière sans un mot. MySQL refuse. Aucune épreuve d'écriture ne pouvait donc
 * voir le défaut, si longue que fût la valeur essayée.
 *
 * Ces épreuves ne tentent pas d'écrire. Elles lisent **le type déclaré** du
 * schéma, que SQLite conserve fidèlement même s'il ne l'applique pas.
 */
class ColonnesChiffreesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les types qui n'imposent aucun plafond utile à un chiffré.
     *
     * @var array<int, string>
     */
    private const SANS_PLAFOND = ['text', 'mediumtext', 'longtext', 'blob', 'json'];

    /**
     * Les colonnes déclarées `encrypted` par les modèles, découvertes à la
     * lecture plutôt que recopiées : une colonne chiffrée ajoutée demain est
     * couverte sans que personne ait à y penser.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function colonnesChiffrees(): array
    {
        $trouvees = [];

        foreach (glob(app_path('Modules/*/Modeles/*.php')) ?: [] as $chemin) {
            $source = file_get_contents($chemin);

            if (!preg_match_all("/'([a-z0-9_]+)'\s*=>\s*'encrypted(?::[a-z]+)?'/i", $source, $m)) {
                continue;
            }

            // La barre oblique inverse est construite plutot qu'ecrite : elle ne
            // survit pas au passage par certains shells, et une classe mal formee
            // ferait taire l'epreuve au lieu de la faire tomber.
            $sep = chr(92);
            $classe = 'App' . $sep . 'Modules' . $sep
                . basename(dirname($chemin, 2)) . $sep . 'Modeles' . $sep
                . basename($chemin, '.php');

            if (!class_exists($classe)) {
                continue;
            }

            $table = (new $classe)->getTable();

            foreach ($m[1] as $colonne) {
                $trouvees[] = [$table, $colonne];
            }
        }

        return $trouvees;
    }

    public function test_le_modele_declare_bien_des_colonnes_chiffrees(): void
    {
        // Si cette épreuve tombe, c'est que la découverte ne trouve plus rien —
        // et les deux suivantes ne vérifieraient alors plus rien du tout.
        $this->assertNotEmpty(
            $this->colonnesChiffrees(),
            'Aucune colonne `encrypted` trouvée : la lecture des modèles a cessé de fonctionner.'
        );
    }

    public function test_aucune_colonne_chiffree_nest_bornee_en_longueur(): void
    {
        $fautives = [];

        foreach ($this->colonnesChiffrees() as [$table, $colonne]) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $colonne)) {
                continue;
            }

            foreach (Schema::getColumns($table) as $c) {
                if ($c['name'] !== $colonne) {
                    continue;
                }

                if (!in_array(strtolower($c['type_name']), self::SANS_PLAFOND, true)) {
                    $fautives[] = "{$table}.{$colonne} est déclarée « {$c['type']} »";
                }
            }
        }

        $this->assertSame([], $fautives, implode("\n", [
            'Une colonne chiffrée est déclarée avec une longueur bornée.',
            'La taille d\'un chiffré dépend de la clé, du vecteur d\'initialisation',
            'et de l\'empreinte : elle changera le jour où le chiffrement changera.',
            'MySQL refuse la valeur en 1406 ; SQLite la range sans un mot, si bien',
            'que la suite reste verte sur un chemin que la production ne peut pas',
            'emprunter. Poser `text`, jamais un plafond qu\'il faudra relever.',
        ]));
    }

    public function test_une_cle_de_liaison_chiffree_depasse_deux_cent_cinquante_cinq_caracteres(): void
    {
        // Le constat qui a coûté la panne, écrit noir sur blanc : ce n'est pas
        // « une clé un peu longue », c'est une clé qui ne pouvait jamais entrer.
        $chiffree = Crypt::encryptString('cptf_live_' . str_repeat('a', 40));

        $this->assertGreaterThan(255, strlen($chiffree),
            'Une clé de liaison chiffrée tient désormais dans 255 caractères. '
            . 'Si c\'est vrai, le chiffrement a changé — vérifier que les colonnes '
            . 'suivent, plutôt que de relâcher cette épreuve.');
    }
}
