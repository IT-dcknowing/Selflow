<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportAvoirsService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Le relevé des factures d'avoir (notes de crédit) émises et reçues.
 */
class ReleveDesAvoirsTest extends TestCase
{
    use RefreshDatabase;

    private const NCC = '1864699A';
    private const JETON = 'jeton-du-hub-pour-les-epreuves';

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('framework/testing/avoirs-' . uniqid());
        mkdir($this->dossier, 0777, true);

        config([
            'selflow.portail_fne.scraper.actif'          => true,
            'selflow.portail_fne.scraper.avoirs_actif'   => true,
            'selflow.portail_fne.scraper.avoirs_minutes' => 5,
            // `php` plutôt que Node : le binaire existe partout et s'arrête
            // dès que le script est absent — pas de fenêtre d'erreur Windows.
            'selflow.portail_fne.scraper.node'           => 'php',
            'selflow.portail_fne.scraper.script_avoirs'  => $this->dossier . '/avoirs.js',
            'services.hub.token'                         => self::JETON,
        ]);

        file_put_contents($this->dossier . '/avoirs.js', '<?php exit(0);');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        @rmdir($this->dossier);

        parent::tearDown();
    }

    public function test_lancer_avoirs_respecte_le_verrou_de_cache(): void
    {
        Cache::forget(ScraperPortailFneService::verrouAvoirs(self::NCC));

        $this->assertTrue(ScraperPortailFneService::lancerAvoirs(self::NCC));
        $this->assertTrue(Cache::has(ScraperPortailFneService::verrouAvoirs(self::NCC)));

        // Un second appel immédiat est repoussé par le verrou :
        // deux navigateurs sur le même portail n'ont aucun sens.
        $this->assertFalse(ScraperPortailFneService::lancerAvoirs(self::NCC));
    }

    public function test_lancer_avoirs_refuse_si_eteint(): void
    {
        config(['selflow.portail_fne.scraper.avoirs_actif' => false]);
        $this->assertFalse(ScraperPortailFneService::lancerAvoirs(self::NCC));

        config([
            'selflow.portail_fne.scraper.actif'        => false,
            'selflow.portail_fne.scraper.avoirs_actif' => true,
        ]);
        $this->assertFalse(ScraperPortailFneService::lancerAvoirs(self::NCC));
    }

    public function test_importer_dossier_avoirs_cree_les_enregistrements(): void
    {
        $this->creerEntreprise(self::NCC);

        $payload = [
            'login'    => self::NCC,
            'source'   => '/ws/invoices?subtype=refund',
            'periode'  => ['du' => '2026-01-01', 'au' => '2026-10-08'],
            'factures' => [
                [
                    'reference'        => 'AV-2026-0001',
                    'id'               => 'fne-ref-1',
                    'token'            => 'tok-1',
                    'type'             => 'invoice',
                    'subtype'          => 'refund',
                    'date'             => '2026-10-01T10:00:00Z',
                    'totalBeforeTaxes' => 10000,
                    'totalTaxes'       => 1800,
                    'totalAfterTaxes'  => 11800,
                    'totalDue'         => 11800,
                    'company'          => [
                        'ncc'  => '9999999X',
                        'name' => 'CLIENT TEST',
                    ],
                    'items'            => [
                        [
                            'description'      => 'Article retourné',
                            'quantity'         => 1,
                            'amount'           => 10000,
                            'totalBeforeTaxes' => 10000,
                            'totalTaxes'       => 1800,
                            'totalAfterTaxes'  => 11800,
                        ],
                    ],
                ],
            ],
        ];

        $chemin = $this->dossier . '/' . self::NCC . '_20261008.json';
        file_put_contents($chemin, json_encode($payload));

        $service = app(ImportAvoirsService::class);
        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertDatabaseHas('portail_fne_imports', [
            'login' => self::NCC,
            'type'  => ImportAvoirsService::TYPE,
        ]);
        $this->assertDatabaseHas('portail_fne_factures_recues', [
            'login'     => self::NCC,
            'reference' => 'AV-2026-0001',
            'subtype'   => 'refund',
        ]);

        // Un second import du même fichier est reconnu inchangé
        $rapport2 = $service->importerDossier($this->dossier);
        $this->assertSame(1, $rapport2['ignores']);
    }

    public function test_api_relever_et_statut_avoirs(): void
    {
        $this->creerEntreprise(self::NCC);

        Cache::forget(ScraperPortailFneService::verrouAvoirs(self::NCC));

        // Sans token
        $this->postJson('/api/portail-fne/avoirs/relever')->assertStatus(401);

        // Avec token
        $reponse = $this->postJson(
            '/api/portail-fne/avoirs/relever',
            ['login' => self::NCC],
            ['X-Hub-Token' => self::JETON]
        );

        $reponse->assertStatus(202);
        $reponse->assertJsonFragment(['lance' => true]);

        // Second appel immédiat : 429, un seul navigateur
        $reponse2 = $this->postJson(
            '/api/portail-fne/avoirs/relever',
            ['login' => self::NCC],
            ['X-Hub-Token' => self::JETON]
        );
        $reponse2->assertStatus(429);

        // Statut
        $statut = $this->getJson(
            '/api/portail-fne/avoirs?login=' . self::NCC,
            ['X-Hub-Token' => self::JETON]
        );

        $statut->assertOk();
        $statut->assertJsonStructure([
            'status',
            'data' => [
                'scraper' => ['actif', 'avoirs_actif', 'pas_minutes', 'releve_en_cours'],
                'login',
                'releves',
                'avoirs',
            ],
        ]);
    }

    public function test_commande_artisan_importer_avoirs(): void
    {
        $this->artisan('portail-fne:importer-avoirs', ['--dossier' => $this->dossier])
            ->assertSuccessful();
    }

    // ------------------------------------------------------------------ helpers

    public function test_un_avoir_emis_a_un_client_ne_passe_pas_au_journal_des_achats(): void
    {
        // Le relevé range aussi les avoirs que l'entreprise émet à ses clients,
        // et y nomme le CLIENT comme émetteur. Sans la liste d'origine, cet
        // avoir partait au journal des achats, le client pris pour fournisseur.
        $entreprise = $this->creerEntreprise(self::NCC);

        $avoir = fn (string $ref, string $liste) => [
            'reference' => $ref, 'subtype' => 'refund', 'type' => 'invoice', 'listing_source' => $liste,
            'date' => '2026-10-01T10:00:00Z', 'totalBeforeTaxes' => 10000, 'totalTaxes' => 1800,
            'totalAfterTaxes' => 11800, 'totalDue' => 11800,
            'company' => ['ncc' => '9999999X', 'name' => 'TIERS TEST'],
        ];
        file_put_contents($this->dossier . '/' . self::NCC . '_20261008.json', json_encode([
            'login' => self::NCC, 'source' => 'test', 'factures' => [$avoir('AV-EMIS-1', 'issued'), $avoir('AV-RECU-1', 'received')],
        ]));
        app(ImportAvoirsService::class)->importerDossier($this->dossier);

        $emis = \App\Modules\Admin\Modeles\PortailFneFactureRecue::where('reference', 'AV-EMIS-1')->sole();
        $recu = \App\Modules\Admin\Modeles\PortailFneFactureRecue::where('reference', 'AV-RECU-1')->sole();
        $this->assertSame('issued', $emis->liste_portail);
        $this->assertSame('received', $recu->liste_portail);

        \App\Modules\Admin\Services\EcritureFactureRecueService::pourEntreprise($entreprise->id);

        $this->assertNull($emis->fresh()->operation_id, 'L\'avoir émis n\'est pas un avoir fournisseur.');
        $this->assertNotNull(\App\Modules\Admin\Services\EcritureFactureRecueService::motifDeNePasPorter($emis->fresh()));
        $this->assertSame(1, \App\Modules\Admin\Modeles\PortailFneFactureRecue::recues()->where('entreprise_id', $entreprise->id)->count(),
            'La liste des factures achat DGI ne montre que l\'avoir reçu.');
    }

    private function creerEntreprise(string $ncc): Entreprise
    {
        return Entreprise::create([
            'nom'               => 'ENTREPRISE AVOIR TEST',
            'regime_imposition'  => 'RNI',
            'adresse'           => 'Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-0' . random_int(1000, 9999),
            'ncc'               => $ncc,
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats'],
        ]);
    }
}
