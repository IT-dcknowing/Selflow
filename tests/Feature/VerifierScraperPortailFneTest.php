<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `portail-fne:verifier-scraper` — ce qui empêche le scraper de tourner.
 *
 * Signalé le 15/09/2026 : en ligne, le pop-up du refus `pointOfSale` restait sur
 * « Récupération en cours » alors qu'en local la liste des points de vente
 * arrivait. Le lancement est détaché, son échec ne se voit nulle part ; la
 * commande le rend visible.
 *
 * | Situation | Ce que la commande doit dire |
 * |---|---|
 * | interrupteur éteint | bloquant, et **dans `.env.production`** |
 * | Node introuvable | bloquant, chemin absolu à poser |
 * | `identifiants.json` absent | bloquant, à recopier hors git |
 * | un NCC sans mot de passe | nommé — **jamais le mot de passe d'un autre** |
 *
 * Aucune épreuve n'ouvre de navigateur : Node pointe sur un chemin qui n'existe
 * pas, et les vérifications de Chromium sont donc sautées.
 */
class VerifierScraperPortailFneTest extends TestCase
{
    use RefreshDatabase;

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('framework/testing/scraper-' . uniqid());
        mkdir($this->dossier, 0777, true);

        config([
            'selflow.portail_fne.scraper.actif'  => false,
            'selflow.portail_fne.scraper.node'   => 'node-qui-n-existe-pas',
            'selflow.portail_fne.scraper.script' => $this->dossier . '/fne.js',
            'selflow.portail_fne.dossier_import' => $this->dossier,
            'selflow.portail_fne.sorties'        => $this->dossier . '/sorties.log',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        @rmdir($this->dossier);

        parent::tearDown();
    }

    public function test_un_serveur_sans_rien_dit_chaque_point_bloquant(): void
    {
        $this->artisan('portail-fne:verifier-scraper')
            ->expectsOutputToContain('PORTAIL_FNE_SCRAPER_ACTIF=true dans .env.production')
            ->expectsOutputToContain('node-qui-n-existe-pas')
            ->expectsOutputToContain('node_modules absent')
            ->expectsOutputToContain('absent (non versionné)')
            ->assertExitCode(1);
    }

    public function test_un_ncc_sans_mot_de_passe_est_nomme_sans_reveler_ceux_des_autres(): void
    {
        $this->uneEntreprise('DC-KNOWING CGA', 'CI-ABJ-2026-B-01111', '1864699A');
        $this->uneEntreprise('Quincaillerie', 'CI-ABJ-2026-B-02222', '9999999Z');

        file_put_contents($this->dossier . '/identifiants.json', json_encode([
            '_lisez-moi' => 'une note, pas un login',
            '9999999Z'   => 'secret-a-ne-pas-afficher',
        ]));

        $this->artisan('portail-fne:verifier-scraper')
            ->expectsOutputToContain('sans mot de passe : 1864699A')
            ->doesntExpectOutputToContain('secret-a-ne-pas-afficher')
            ->assertExitCode(1);
    }

    public function test_aucun_mot_de_passe_du_tout_est_bloquant(): void
    {
        $this->uneEntreprise('DC-KNOWING CGA', 'CI-ABJ-2026-B-01111', '1864699A');

        file_put_contents($this->dossier . '/identifiants.json', json_encode(['1864699A' => '']));

        $this->artisan('portail-fne:verifier-scraper')
            ->expectsOutputToContain('aucune entreprise n\'a de mot de passe : 1864699A')
            ->assertExitCode(1);
    }

    public function test_un_identifiants_json_illisible_est_signale(): void
    {
        file_put_contents($this->dossier . '/identifiants.json', '{pas du json');

        $this->artisan('portail-fne:verifier-scraper')
            ->expectsOutputToContain('illisible (JSON invalide)')
            ->assertExitCode(1);
    }

    private function uneEntreprise(string $nom, string $rccm, string $ncc): Entreprise
    {
        return Entreprise::create([
            'nom' => $nom, 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => $rccm, 'ncc' => $ncc, 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats'],
        ]);
    }
}
