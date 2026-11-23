<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Le verrou du relevé des ventes se rend quand le scraper a fini.
 *
 * Constaté en production le 23/11/2026, toutes les cinq minutes :
 *
 *     ventes : dossier absent, rien à ramasser — …/portail-fne/ventes
 *     ventes[1864699A] : rien lancé — un relevé est déjà en route.
 *
 * Le verrou n'était rendu que par l'écran qui interroge le statut. Lancé par le
 * planificateur, un relevé gardait son verrou vingt minutes, fini ou non, et
 * son échec ne s'écrivait nulle part ailleurs que dans la sortie brute de Node.
 *
 * | Situation | Ce qui doit se produire |
 * |---|---|
 * | scraper fini avec succès | verrou rendu, relevé suivant lancé |
 * | scraper fini en erreur | échec écrit **une fois**, avec le fichier à lire ; verrou gardé |
 * | scraper mort sans marqueur | lu comme interrompu, même traitement qu'une erreur |
 * | scraper vivant | « déjà en route », avec depuis combien de minutes |
 */
class ReleveDesVentesVerrouTest extends TestCase
{
    use RefreshDatabase;

    private const NCC = '1864699A';

    private string $dossier;
    private string $journal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('framework/testing/ventes-verrou-' . uniqid());
        mkdir($this->dossier, 0777, true);

        $this->journal = $this->dossier . '/portail-fne-vente.log';

        config([
            'selflow.portail_fne.scraper.actif'          => true,
            'selflow.portail_fne.scraper.ventes_actif'   => true,
            'selflow.portail_fne.scraper.ventes_minutes' => 5,
            'selflow.portail_fne.scraper.node'           => 'php',
            'selflow.portail_fne.scraper.script'         => $this->dossier . '/fne.js',
            'selflow.portail_fne.scraper.script_ventes'  => $this->dossier . '/ventes.js',
            'logging.channels.portail_fne_vente.path'    => $this->journal,
        ]);
        Log::forgetChannel('portail_fne_vente');

        file_put_contents($this->dossier . '/ventes.js', '<?php exit(0);');

        $entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-01111', 'ncc' => self::NCC, 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats'],
        ]);

        FneCredential::create([
            'entreprise_id'        => $entreprise->id,
            'statut'               => 'test',
            'ncc_associe'          => self::NCC,
            'acces_mot_de_passe'   => 'mot-de-passe-des-epreuves',
            'telechargement_actif' => true,
        ]);

        Cache::forget(ScraperPortailFneService::verrouVentes(self::NCC));
    }

    protected function tearDown(): void
    {
        Cache::forget(ScraperPortailFneService::verrouVentes(self::NCC));
        Log::forgetChannel('portail_fne_vente');

        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            @unlink($fichier);
        }
        @rmdir($this->dossier);

        parent::tearDown();
    }

    /** Pose un suivi comme l'aurait fait un lancement, il y a sept minutes. */
    private function unSuivi(array $enPlus = []): string
    {
        $fichierEtat = $this->dossier . '/etat-' . uniqid() . '.json';

        Cache::put(ScraperPortailFneService::verrouVentes(self::NCC), array_merge([
            'fichier_etat' => $fichierEtat,
            'lance_a'      => now()->subMinutes(7)->getTimestamp(),
            'expire_a'     => now()->addMinutes(13)->getTimestamp(),
        ], $enPlus), now()->addMinutes(13));

        return $fichierEtat;
    }

    /** Un numéro de processus qui n'existe pas. */
    private function unProcessusMort(): int
    {
        $pid = 4_000_000;
        while (is_dir('/proc/' . $pid)) {
            $pid++;
        }

        return $pid;
    }

    private function journal(): string
    {
        Log::forgetChannel('portail_fne_vente');
        clearstatcache(true, $this->journal);

        return is_file($this->journal) ? (string) file_get_contents($this->journal) : '';
    }

    public function test_un_releve_reussi_rend_le_verrou_et_le_suivant_part(): void
    {
        $fichierEtat = $this->unSuivi();
        file_put_contents($fichierEtat, json_encode(['exit_code' => 0]));

        $this->assertTrue(
            ScraperPortailFneService::lancerVentes(self::NCC),
            'Un relevé terminé ne doit plus retenir le suivant.'
        );

        $this->assertFileDoesNotExist($fichierEtat, 'Le marqueur du relevé fini est rangé.');
        $this->assertStringContainsString('relevé précédent terminé, verrou rendu', $this->journal());

        $suivi = Cache::get(ScraperPortailFneService::verrouVentes(self::NCC));
        $this->assertNotSame($fichierEtat, $suivi['fichier_etat'] ?? null, 'Le nouveau relevé a son propre suivi.');
    }

    public function test_un_releve_en_erreur_est_ecrit_une_fois_et_garde_son_verrou(): void
    {
        $fichierEtat = $this->unSuivi();
        file_put_contents($fichierEtat, json_encode(['exit_code' => 1]));

        $this->assertFalse(ScraperPortailFneService::lancerVentes(self::NCC));
        $this->assertFalse(
            ScraperPortailFneService::lancerVentes(self::NCC),
            'Relancer aussitôt présenterait le mot de passe du client au portail toutes les cinq minutes.'
        );

        $journal = $this->journal();
        $this->assertSame(1, substr_count($journal, 'relevé échoué'), "L'échec s'écrit une fois, pas à chaque passage.");
        $this->assertStringContainsString('code 1', $journal);
        $this->assertStringContainsString('portail-fne-vente.log', $journal, 'Le journal dit où lire la sortie du scraper.');
        $this->assertStringContainsString('pause jusqu', $journal);
        $this->assertTrue(Cache::has(ScraperPortailFneService::verrouVentes(self::NCC)));
    }

    public function test_un_scraper_mort_sans_marqueur_est_lu_comme_interrompu(): void
    {
        $this->unSuivi(['pid' => $this->unProcessusMort()]);

        $this->assertSame(
            ['exit_code' => -1, 'interrompu' => true],
            ScraperPortailFneService::etatProcessus(ScraperPortailFneService::verrouVentes(self::NCC))
        );

        $this->assertFalse(ScraperPortailFneService::lancerVentes(self::NCC));
        $this->assertStringContainsString('sans écrire son marqueur de fin', $this->journal());
    }

    public function test_un_scraper_vivant_retient_le_suivant_et_dit_depuis_quand(): void
    {
        $this->unSuivi(['pid' => getmypid()]);

        $this->assertSame(
            ['en_cours' => true],
            ScraperPortailFneService::etatProcessus(ScraperPortailFneService::verrouVentes(self::NCC))
        );

        $this->assertFalse(ScraperPortailFneService::lancerVentes(self::NCC));
        $this->assertStringContainsString('un relevé est déjà en route (lancé il y a 7 min)', $this->journal());
    }

    public function test_le_lancement_retient_le_numero_du_processus(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Le numéro du processus ne se lit que sous Unix.');
        }

        $this->assertTrue(ScraperPortailFneService::lancerVentes(self::NCC));

        $suivi = Cache::get(ScraperPortailFneService::verrouVentes(self::NCC));
        $this->assertIsInt($suivi['pid'] ?? null, 'Sans numéro de processus, une mort silencieuse reste invisible.');
        $this->assertArrayHasKey('lance_a', $suivi);
    }
}
