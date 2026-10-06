<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Les polices, les icônes et les graphiques sont servis par l'application.
 *
 * Ils venaient de trois CDN — Google Fonts, cdnjs, jsdelivr — chargés en
 * feuilles bloquantes : la page restait blanche tant qu'ils n'avaient pas
 * répondu. Et `font-src` n'autorisait pas jsdelivr : la police d'icônes des
 * pages d'entrée était refusée par notre propre en-tête — les carrés vides de
 * l'inscription (chantier 9.1).
 */
class RessourcesServiesParLApplicationTest extends TestCase
{
    private const HOTES = ['fonts.googleapis.com', 'fonts.gstatic.com', 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com'];

    public function test_aucune_vue_ne_charge_une_ressource_externe(): void
    {
        $fautifs = [];

        foreach ([app_path('Modules'), resource_path('views')] as $racine) {
            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

            foreach ($fichiers as $fichier) {
                if (!str_ends_with($fichier->getFilename(), '.blade.php')) {
                    continue;
                }

                $contenu = file_get_contents($fichier->getPathname());

                foreach (self::HOTES as $hote) {
                    if (str_contains($contenu, $hote)) {
                        $fautifs[] = str_replace(base_path() . '/', '', $fichier->getPathname()) . " → {$hote}";
                    }
                }
            }
        }

        $this->assertSame([], $fautifs, "Ressources externes encore chargées :\n" . implode("\n", $fautifs));
    }

    public function test_la_police_tabler_a_disparu_au_profit_de_font_awesome(): void
    {
        // Une seule police d'icônes dans l'application : Tabler pesait
        // 820 Ko pour vingt-sept icônes, et ses classes ne s'affichaient pas
        // dans les paramètres, où seule Font Awesome était chargée.
        foreach (glob(app_path('Modules/*/Vues/{,*/}*.blade.php'), GLOB_BRACE) as $vue) {
            $this->assertDoesNotMatchRegularExpression('/\bti ti-/', file_get_contents($vue), $vue);
        }
    }

    public function test_les_fichiers_servis_existent(): void
    {
        foreach ([
            'vendor/fontawesome/css/all.min.css',
            'vendor/fontawesome/webfonts/fa-solid-900.woff2',
            'vendor/fontawesome/webfonts/fa-regular-400.woff2',
            'vendor/fontawesome/webfonts/fa-brands-400.woff2',
            'vendor/inter/inter.css',
            'vendor/inter/inter-latin-400-normal.woff2',
            'vendor/chartjs/chart.umd.min.js',
        ] as $fichier) {
            $this->assertFileExists(public_path($fichier));
        }
    }

    public function test_la_politique_de_securite_n_ouvre_plus_aucun_cdn(): void
    {
        $csp = $this->get(route('connexion'))->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);

        foreach (self::HOTES as $hote) {
            $this->assertStringNotContainsString($hote, $csp);
        }
    }
}
