<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Le relevé des factures reçues part tout seul, et se commande par l'API.
 *
 * Demandé par le propriétaire du projet le 08/09/2026 : *« pour le scrapping
 * achat mettre une logique que le scrapping se lance CHAQUE 5min et ajoute la
 * route dans route /api »*.
 *
 * ## Ce que ces épreuves fixent
 *
 * | Situation | Ce qui doit se produire |
 * |---|---|
 * | planificateur, scraper armé | `achats.js --tous` toutes les cinq minutes |
 * | le ramassage | même pas, **décalé de deux minutes** sur le relevé |
 * | `achats_actif` éteint | la ligne disparaît, celle de `fne.js` reste |
 * | `POST relever` sans jeton | 401 — la route ouvre une session chez la DGI |
 * | `POST relever`, scraper éteint | 503, et non 500 : ce n'est pas une panne |
 * | `POST relever` | 202, verrou posé |
 * | deux appels de suite | 429 au second, **un seul** navigateur |
 * | `company_id` inconnu / sans NCC | 404 / 422, et surtout **pas** un passage complet |
 * | `GET achats` | l'état de la chaîne et le compte des pièces |
 *
 * Le verrou de cache est ce qui s'observe : `lancerAchats()` détache un vrai
 * processus, qu'une épreuve n'a pas à ouvrir. Ce qui se vérifie ici est la
 * **décision** de lancer.
 */
class ReleveDesAchatsChaqueCinqMinutesTest extends TestCase
{
    use RefreshDatabase;

    private const NCC = '1864699A';
    private const JETON = 'jeton-du-hub-pour-les-epreuves';

    protected function setUp(): void
    {
        parent::setUp();

        // Le scraper est éteint dans la suite (`phpunit.xml`) : on l'allume ici,
        // et on pointe `node` sur un chemin qui n'existe pas. Le verrou se pose
        // avant le lancement — c'est lui qu'on observe —, et le processus
        // détaché échoue sans conséquence pour l'épreuve.
        config([
            'selflow.portail_fne.scraper.actif'          => true,
            'selflow.portail_fne.scraper.achats_actif'   => true,
            'selflow.portail_fne.scraper.achats_minutes' => 5,
            // `php`, et non un Node introuvable : le lanceur refuse désormais un
            // programme qui n'existe pas (sous Windows, `start` ouvrait une
            // fenêtre d'erreur qui figeait la suite). `php` existe partout où la
            // suite tourne, et s'arrête aussitôt sur un script absent.
            'selflow.portail_fne.scraper.node'           => 'php',
            'selflow.portail_fne.scraper.script'         => 'fne-qui-n-existe-pas.js',
            'selflow.portail_fne.scraper.script_achats'  => 'achats-qui-n-existe-pas.js',
            'services.hub.token'                         => self::JETON,
        ]);

        // Un journal à cette classe seule, et un repère de position.
        //
        // **Un fichier à part**, parce que celui de la suite est ouvert par
        // d'autres épreuves et par les processus qu'elles détachent : sous
        // Windows, un enfant hérite du descripteur du parent et tient le fichier
        // tant qu'il vit. Les assertions de journal devenaient alors vides sans
        // que rien ne soit cassé.
        //
        // **Un repère plutôt qu'une suppression** : effacer le fichier ici
        // faisait tomber neuf épreuves en 500. Sous Windows, `unlink` sur un
        // fichier encore tenu le met en suppression différée, et tout `fopen`
        // suivant rend « Permission denied ».
        $chemin = storage_path('framework/testing/journal-releve-achats.log');

        if (!is_dir(dirname($chemin))) {
            mkdir(dirname($chemin), 0777, true);
        }

        config(['logging.channels.portail_fne.path' => $chemin]);
        Log::forgetChannel('portail_fne');

        clearstatcache(true, $chemin);
        $this->journalDepuis = is_file($chemin) ? (int) filesize($chemin) : 0;
    }

    /** Où en était le journal quand l'épreuve a commencé. */
    private int $journalDepuis = 0;

    /* ----------------------------- Le planificateur --------------------------- */

    /**
     * Les expressions cron du planificateur, relues avec la configuration
     * courante.
     *
     * `routes/console.php` est lu une fois au démarrage de l'application, quand
     * le scraper est encore éteint : la ligne qu'on cherche n'y serait jamais.
     * On repose donc un ordonnanceur neuf dans le conteneur et on relit le
     * fichier — c'est le seul moyen d'éprouver une planification qui dépend
     * d'un réglage.
     *
     * @return array<int, string>  « expression cron » puis commande
     */
    private function lignesDuPlanificateur(): array
    {
        $this->app->forgetInstance(Schedule::class);
        $this->app->singleton(Schedule::class, fn () => new Schedule());

        // La façade garde l'instance qu'elle a résolue au démarrage : sans cet
        // oubli, `Schedule::command()` continuerait d'écrire dans l'ordonnanceur
        // d'origine et celui qu'on relit resterait vide.
        Facade::clearResolvedInstance(Schedule::class);

        $ordonnanceur = $this->app->make(Schedule::class);

        require base_path('routes/console.php');

        return array_map(
            fn ($evenement) => $evenement->getExpression() . '  ' . $evenement->command,
            $ordonnanceur->events()
        );
    }

    public function test_le_releve_des_achats_passe_toutes_les_cinq_minutes(): void
    {
        $lignes = $this->lignesDuPlanificateur();

        $relevé = array_values(array_filter(
            $lignes,
            fn (string $l) => str_contains($l, 'achats-qui-n-existe-pas.js')
        ));

        $this->assertCount(1, $relevé, "Le relevé des achats doit figurer une fois et une seule.\n"
            . implode("\n", $lignes));
        $this->assertStringStartsWith('*/5 * * * *', $relevé[0]);
        $this->assertStringContainsString('--tous', $relevé[0]);
    }

    public function test_le_ramassage_suit_le_meme_pas_decale_de_deux_minutes(): void
    {
        $lignes = $this->lignesDuPlanificateur();

        $ramassage = array_values(array_filter(
            $lignes,
            fn (string $l) => str_contains($l, 'portail-fne:importer-achats')
        ));

        $this->assertCount(1, $ramassage);

        // Le décalage est ce qui fait que l'écran ne montre pas le fichier du
        // passage précédent : le scraper met des dizaines de secondes.
        $this->assertStringStartsWith('2-59/5 * * * *', $ramassage[0]);
    }

    public function test_le_pas_se_regle_sans_toucher_au_code(): void
    {
        config(['selflow.portail_fne.scraper.achats_minutes' => 15]);

        $lignes = $this->lignesDuPlanificateur();

        $this->assertTrue(
            (bool) array_filter($lignes, fn ($l) => str_starts_with($l, '*/15 * * * *')
                && str_contains($l, 'achats-qui-n-existe-pas.js')),
            'Le pas doit venir de la configuration.'
        );
        $this->assertTrue(
            (bool) array_filter($lignes, fn ($l) => str_starts_with($l, '2-59/15 * * * *')),
            'Le ramassage doit suivre le même pas.'
        );
    }

    public function test_eteindre_le_releve_des_achats_laisse_le_reste_du_scraper(): void
    {
        config(['selflow.portail_fne.scraper.achats_actif' => false]);

        $lignes = $this->lignesDuPlanificateur();

        $this->assertEmpty(
            array_filter($lignes, fn ($l) => str_contains($l, 'achats-qui-n-existe-pas.js')),
            "L'interrupteur doit retirer la ligne du planificateur."
        );

        // Et il ne doit pas emporter le reste : `fne.js` a ses propres rendez-vous.
        $this->assertNotEmpty(
            array_filter($lignes, fn ($l) => str_contains($l, 'fne-qui-n-existe-pas.js')),
            "Éteindre le relevé des achats ne doit pas éteindre le scraper entier."
        );
    }

    /* -------------------------------- Le service ------------------------------ */

    public function test_le_verrou_empeche_deux_navigateurs_sur_le_meme_portail(): void
    {
        $this->assertTrue(ScraperPortailFneService::lancerAchats());
        $this->assertTrue(Cache::has(ScraperPortailFneService::verrouAchats()));

        // Le second appel ne relance rien : vingt appels d'affilée ouvriraient
        // vingt sessions sur le portail de la DGI.
        $this->assertFalse(ScraperPortailFneService::lancerAchats());
    }

    public function test_un_login_precis_et_le_passage_complet_ont_des_verrous_distincts(): void
    {
        ScraperPortailFneService::lancerAchats(self::NCC);

        $this->assertTrue(Cache::has(ScraperPortailFneService::verrouAchats(self::NCC)));
        $this->assertFalse(
            Cache::has(ScraperPortailFneService::verrouAchats()),
            'Relever une entreprise ne doit pas bloquer le passage complet.'
        );
    }

    public function test_le_service_ne_lance_rien_quand_l_interrupteur_est_eteint(): void
    {
        config(['selflow.portail_fne.scraper.achats_actif' => false]);

        $this->assertFalse(ScraperPortailFneService::lancerAchats());
        $this->assertFalse(Cache::has(ScraperPortailFneService::verrouAchats()));
    }

    /* --------------------------------- L'API ---------------------------------- */

    public function test_la_route_de_lancement_refuse_sans_jeton_du_hub(): void
    {
        $this->postJson('/api/portail-fne/achats/relever')->assertStatus(401);
        $this->getJson('/api/portail-fne/achats')->assertStatus(401);

        $this->assertFalse(Cache::has(ScraperPortailFneService::verrouAchats()));
    }

    public function test_un_mauvais_jeton_ne_lance_aucun_navigateur(): void
    {
        $this->postJson('/api/portail-fne/achats/relever', [], ['X-Hub-Token' => 'faux'])
            ->assertStatus(401);

        $this->assertFalse(Cache::has(ScraperPortailFneService::verrouAchats()));
    }

    public function test_le_lancement_rend_202_et_pose_le_verrou(): void
    {
        $this->postJson('/api/portail-fne/achats/relever', [], $this->entetes())
            ->assertStatus(202)
            ->assertJson(['status' => 'success', 'lance' => true, 'login' => 'tous']);

        $this->assertTrue(Cache::has(ScraperPortailFneService::verrouAchats()));
    }

    public function test_un_second_appel_immediat_rend_429_sans_relancer(): void
    {
        $this->postJson('/api/portail-fne/achats/relever', [], $this->entetes())->assertStatus(202);

        $this->postJson('/api/portail-fne/achats/relever', [], $this->entetes())
            ->assertStatus(429)
            ->assertJson(['lance' => false, 'reessayer_dans_minutes' => 5]);
    }

    public function test_le_scraper_eteint_rend_503_et_non_une_panne(): void
    {
        config(['selflow.portail_fne.scraper.actif' => false]);

        $this->postJson('/api/portail-fne/achats/relever', [], $this->entetes())
            ->assertStatus(503)
            ->assertJson(['status' => 'error']);

        $this->assertFalse(Cache::has(ScraperPortailFneService::verrouAchats()));
    }

    public function test_une_entreprise_designee_releve_son_seul_login(): void
    {
        $entreprise = $this->entreprise(self::NCC);

        $this->postJson('/api/portail-fne/achats/relever?company_id=' . $entreprise->id, [], $this->entetes())
            ->assertStatus(202)
            ->assertJson(['login' => self::NCC]);

        $this->assertTrue(Cache::has(ScraperPortailFneService::verrouAchats(self::NCC)));
        $this->assertFalse(Cache::has(ScraperPortailFneService::verrouAchats()));
    }

    public function test_une_entreprise_introuvable_ne_declenche_pas_un_passage_complet(): void
    {
        $this->postJson('/api/portail-fne/achats/relever?company_id=9999', [], $this->entetes())
            ->assertStatus(404);

        $this->assertFalse(
            Cache::has(ScraperPortailFneService::verrouAchats()),
            'Un identifiant faux ne doit pas ouvrir une session pour tout le parc.'
        );
    }

    public function test_une_entreprise_sans_ncc_le_dit_au_lieu_de_relever_tout(): void
    {
        $entreprise = $this->entreprise('');

        $this->postJson('/api/portail-fne/achats/relever?company_id=' . $entreprise->id, [], $this->entetes())
            ->assertStatus(422);

        $this->assertFalse(Cache::has(ScraperPortailFneService::verrouAchats()));
    }

    public function test_l_etat_de_la_chaine_se_lit_de_l_exterieur(): void
    {
        $entreprise = $this->entreprise(self::NCC);

        $import = PortailFneImport::create([
            'entreprise_id'     => $entreprise->id,
            'login'             => self::NCC,
            'date_scraping'     => now()->toDateString(),
            'type'              => ImportFacturesRecuesService::TYPE,
            'fichier_nom'       => self::NCC . '_20260908.json',
            'fichier_empreinte' => str_repeat('a', 64),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
            'lignes_importees'  => 1,
            'dernier_releve_le' => now()->toDateString(),
            'releves'           => 3,
        ]);

        PortailFneFactureRecue::create([
            'import_id'            => $import->id,
            'entreprise_id'        => $entreprise->id,
            'login'                => self::NCC,
            'reference'            => '1431650A26000000588',
            'date_scraping'        => now()->toDateString(),
            'emetteur_ncc'         => '1431650A',
            'emetteur_nom'         => "CENTRE IVOIRIEN D'ARCHIVAGE NUMERIQUE",
            'montant_ht'           => 244000,
            'montant_ttc'          => 244000,
            'statut_rapprochement' => PortailFneFactureRecue::A_RAPPROCHER,
        ]);

        $reponse = $this->getJson('/api/portail-fne/achats?company_id=' . $entreprise->id, $this->entetes())
            ->assertOk()
            ->assertJson(['status' => 'success'])
            ->json('data');

        $this->assertSame(self::NCC, $reponse['login']);
        $this->assertTrue($reponse['scraper']['achats_actif']);
        $this->assertSame(5, $reponse['scraper']['pas_minutes']);
        $this->assertSame(3, $reponse['releves'][0]['releves']);
        $this->assertSame(1, $reponse['releves'][0]['factures']);
        $this->assertSame(1, $reponse['factures'][PortailFneFactureRecue::A_RAPPROCHER]);
    }

    public function test_l_etat_ne_melange_pas_les_entreprises(): void
    {
        $mienne = $this->entreprise(self::NCC);
        $autre  = $this->entreprise('9999999Z', 'AUTRE SOCIETE');

        PortailFneImport::create([
            'entreprise_id'     => $autre->id,
            'login'             => '9999999Z',
            'date_scraping'     => now()->toDateString(),
            'type'              => ImportFacturesRecuesService::TYPE,
            'fichier_nom'       => '9999999Z_20260908.json',
            'fichier_empreinte' => str_repeat('b', 64),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
            'lignes_importees'  => 7,
        ]);

        $data = $this->getJson('/api/portail-fne/achats?company_id=' . $mienne->id, $this->entetes())
            ->assertOk()
            ->json('data');

        // Une pièce fiscale lue par le mauvais client ne se répare pas.
        $this->assertSame([], $data['releves']);
    }

    /* -------------------------------- Le journal ------------------------------ */

    public function test_le_lancement_ecrit_les_chemins_de_node_et_du_script(): void
    {
        ScraperPortailFneService::lancerAchats(self::NCC);

        $journal = $this->journal();

        $this->assertStringContainsString('achats[' . self::NCC . '] : relevé lancé', $journal);

        // Les deux pannes les plus fréquentes du poste sont un Node introuvable
        // et un script déplacé. Le processus étant détaché, son échec n'arrive
        // jamais jusqu'à PHP : sans ces chemins, la ligne suivante du journal est
        // un « fichier introuvable » qui ne dit pas lequel.
        $this->assertStringContainsString('"node":"php"', $journal);
        $this->assertStringContainsString('achats-qui-n-existe-pas.js', $journal);
    }

    public function test_un_renoncement_dit_pourquoi_plutot_que_de_se_taire(): void
    {
        config(['selflow.portail_fne.scraper.achats_actif' => false]);

        ScraperPortailFneService::lancerAchats();

        // « Rien ne s'est passé » est la panne la plus difficile à diagnostiquer
        // quand rien ne l'explique : on cherche un défaut dans le scraper alors
        // qu'un interrupteur est à zéro.
        $this->assertStringContainsString(
            'PORTAIL_FNE_SCRAPER_ACHATS_ACTIF',
            $this->journal(),
            'Le journal doit nommer l\'interrupteur qui a bloqué le lancement.'
        );
    }

    public function test_le_verrou_qui_retient_un_relevé_se_lit_dans_le_journal(): void
    {
        ScraperPortailFneService::lancerAchats();
        ScraperPortailFneService::lancerAchats();

        $this->assertStringContainsString('un relevé est déjà en route', $this->journal());
    }

    public function test_chaque_appel_d_api_laisse_sa_trace(): void
    {
        $this->postJson('/api/portail-fne/achats/relever', [], $this->entetes())->assertStatus(202);
        $this->postJson('/api/portail-fne/achats/relever', [], $this->entetes())->assertStatus(429);

        $journal = $this->journal();

        $this->assertStringContainsString('api[relever] : relevé lancé, rendu 202', $journal);
        $this->assertStringContainsString('api[relever] : refusé 429', $journal);
    }

    public function test_l_appel_refuse_faute_de_chaine_allumee_est_trace(): void
    {
        config(['selflow.portail_fne.scraper.actif' => false]);

        $this->postJson('/api/portail-fne/achats/relever', [], $this->entetes())->assertStatus(503);

        $this->assertStringContainsString('api[relever] : refusé 503', $this->journal());
    }

    public function test_une_entreprise_introuvable_est_tracee_avec_son_identifiant(): void
    {
        $this->postJson('/api/portail-fne/achats/relever?company_id=9999', [], $this->entetes())
            ->assertStatus(404);

        $journal = $this->journal();

        $this->assertStringContainsString('entreprise introuvable, rendu 404', $journal);
        $this->assertStringContainsString('9999', $journal);
    }

    public function test_le_journal_du_scraper_et_celui_de_php_sont_le_meme_fichier(): void
    {
        // C'est tout l'objet du canal : la sortie du processus détaché et les
        // lignes de PHP tombent au même endroit, sans quoi une panne se lit à
        // moitié dans un fichier et à moitié dans l'autre.
        $this->assertSame(
            config('logging.channels.portail_fne.path'),
            ScraperPortailFneService::journal()
        );
    }

    /* -------------------------------- Le décor -------------------------------- */

    /** Ce que le canal du portail a écrit depuis le début de l'épreuve. */
    private function journal(): string
    {
        $chemin = (string) config('logging.channels.portail_fne.path');

        if (!is_file($chemin)) {
            return '';
        }

        clearstatcache(true, $chemin);

        return (string) substr((string) file_get_contents($chemin), $this->journalDepuis);
    }

    /** @return array<string, string> */
    private function entetes(): array
    {
        return ['X-Hub-Token' => self::JETON];
    }

    private function entreprise(string $ncc, string $nom = 'DC-KNOWING CGA'): Entreprise
    {
        return Entreprise::create([
            'nom'              => $nom,
            'regime_imposition' => 'RNI',
            'adresse'          => 'Abidjan',
            'rccm'             => 'CI-ABJ-2026-B-0' . random_int(1000, 9999),
            'ncc'              => $ncc,
            'gerant_fonction'  => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs'   => ['principal', 'ventes', 'achats'],
        ]);
    }
}
