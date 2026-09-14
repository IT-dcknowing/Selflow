<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Services\PointsDeVentePortailService;
use App\Modules\Admin\Services\ScraperPortailFneService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les points de facturation du portail FNE, repris dans Selflow.
 *
 * Le sens est celui-là et pas l'autre : le portail déclare, Selflow s'aligne.
 * Rien n'est jamais créé au portail depuis Selflow — déclarer un point de
 * facturation est un acte du contribuable, et le scraper ne fait que lire.
 *
 * Ce qui se joue ici est le nom : c'est lui que `pointOfSale` porte, et une
 * facture émise sous un nom que le portail ne déclare pas est refusée. Le
 * reprendre du portail, plutôt que le ressaisir, retire l'accent et la casse
 * du chemin d'une certification.
 */
class PointsDeVenteDuPortailTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private string $dossier;

    private const LOGIN = '1864699A';
    private const ETABLISSEMENT = '42200613-f402-40a8-bd4d-a778bb5b96f0';

    protected function setUp(): void
    {
        parent::setUp();

        // « Reprendre » range d'abord ce que le scraper a pu déposer : sans
        // dossier à soi, l'épreuve lirait les relevés réels du poste.
        $this->dossier = storage_path('framework/testing/pdv-portail-' . uniqid());
        mkdir($this->dossier, 0777, true);
        config(['selflow.portail_fne.dossier_import' => $this->dossier]);

        $this->entreprise = $this->uneEntreprise(self::LOGIN);

        $this->actingAs(Utilisateur::create([
            'nom'           => 'Yao',
            'prenom'        => 'Adjoua',
            'email'         => 'adjoua@dcknowing.ci',
            'password'      => bcrypt('secret-de-test'),
            'role'          => 'admin',
            'entreprise_id' => $this->entreprise->id,
        ]));
    }

    public function test_les_points_declares_au_portail_et_absents_de_selflow_sont_crees(): void
    {
        $this->relever(['FACTURATION SIEGE', 'AGENCE YOPOUGON']);

        $this->post(route('admin.pdv.importer_du_portail'))->assertRedirect();

        $points = PointDeVente::where('entreprise_id', $this->entreprise->id)->orderBy('nom')->get();

        $this->assertSame(['AGENCE YOPOUGON', 'FACTURATION SIEGE'], $points->pluck('nom')->all());

        // L'identifiant du portail est retenu : c'est par lui que le relevé
        // suivant reconnaîtra le même point, même renommé de part ou d'autre.
        $siege = $points->firstWhere('nom', 'FACTURATION SIEGE');
        $this->assertSame(self::ETABLISSEMENT, $siege->etablissement_fne_id);
        $this->assertSame('Ouvert', $siege->statut);

        // Le portail ne publie ni ville ni commune : les inventer écrirait une
        // adresse que personne n'a déclarée.
        $this->assertSame('', $siege->ville);
        $this->assertSame('', $siege->commune);
    }

    public function test_un_point_deja_saisi_a_la_main_est_adopte_au_lieu_d_etre_duplique(): void
    {
        // Le doublon est le vrai risque : le lot 20 a dû fusionner à la main
        // trois « FACTURATION SIEGE » nés d'un appariement par nom.
        $ancien = $this->unPointDeVente('facturation siege ');

        $this->relever(['FACTURATION SIEGE']);

        $this->post(route('admin.pdv.importer_du_portail'))->assertRedirect();

        $this->assertSame(1, PointDeVente::where('entreprise_id', $this->entreprise->id)->count());
        $this->assertSame(self::ETABLISSEMENT, $ancien->refresh()->etablissement_fne_id);

        // Le nom saisi n'est pas récrit ici : aligner le nom est le geste du
        // rapprochement des rejets, qui sait de quelle pièce il parle.
        $this->assertSame('facturation siege ', $ancien->nom);
    }

    public function test_un_second_passage_cree_le_point_si_le_nom_n_existe_plus_dans_selflow(): void
    {
        $this->relever(['FACTURATION SIEGE']);
        $this->post(route('admin.pdv.importer_du_portail'));

        $pdv = PointDeVente::sole();
        $pdv->update(['nom' => 'Siège']);

        $this->post(route('admin.pdv.importer_du_portail'))->assertRedirect();

        // Le point renommé est préservé, et le nom déclaré au portail est créé
        $this->assertSame(2, PointDeVente::count());
        $this->assertSame('Siège', $pdv->refresh()->nom);
        $this->assertTrue(PointDeVente::where('nom', 'FACTURATION SIEGE')->exists());
    }

    public function test_sans_releve_du_portail_rien_n_est_cree_et_le_dit(): void
    {
        $this->post(route('admin.pdv.importer_du_portail'))->assertRedirect();

        $this->assertSame(0, PointDeVente::count());
        $this->assertStringContainsString('Aucun relevé', session('avertissement'));
    }

    public function test_le_releve_d_une_autre_entreprise_n_entre_pas_ici(): void
    {
        $autre = $this->uneEntreprise('9999999Z');
        $this->relever(['SIEGE DU CONCURRENT'], $autre);

        $this->post(route('admin.pdv.importer_du_portail'))->assertRedirect();

        $this->assertSame(0, PointDeVente::where('entreprise_id', $this->entreprise->id)->count());
    }

    public function test_le_quota_d_abonnement_borne_la_reprise(): void
    {
        // Le quota borne la reprise comme il borne la création à la main : un
        // relevé du portail n'ouvre pas l'abonnement.
        $this->entreprise->update(['quota_points_de_vente' => 1]);
        $this->unPointDeVente('BOUTIQUE PLATEAU');

        $this->relever(['FACTURATION SIEGE', 'AGENCE YOPOUGON']);

        $this->post(route('admin.pdv.importer_du_portail'))->assertRedirect();

        $this->assertSame(1, PointDeVente::where('entreprise_id', $this->entreprise->id)->count());
        $this->assertStringContainsString('Quota', session('avertissement'));
    }

    public function test_le_point_repris_ouvre_ses_fiches_de_stock(): void
    {
        // Un site né d'un relevé doit se comporter comme un site créé à la
        // main : sans fiches, son stock resterait invisible.
        $article = Produit::create([
            'entreprise_id' => $this->entreprise->id,
            'reference'     => 'CIM-001',
            'nom'           => 'Ciment CPJ 45',
            'type'          => 'marchandise',
            'unite'         => 'sac',
            'prix_achat'    => 5000,
            'prix_vente'    => 6500,
            'taux_tva'      => 18,
        ]);

        $this->relever(['FACTURATION SIEGE']);
        $this->post(route('admin.pdv.importer_du_portail'));

        $this->assertSame(1, Stock::where('produit_id', $article->id)
            ->where('point_de_vente_id', PointDeVente::sole()->id)
            ->count());
    }

    public function test_l_ecran_montre_ce_que_le_portail_declare_et_ce_qui_manque(): void
    {
        $this->relever(['FACTURATION SIEGE']);
        $this->unPointDeVente('hgf');

        $reponse = $this->get(route('admin.pdv.index'));

        $reponse->assertOk();
        $reponse->assertSee('FACTURATION SIEGE');

        // Un point que Selflow porte et que le portail ne déclare pas : c'est
        // exactement de là que viennent les refus sur `pointOfSale`.
        $reponse->assertSee('inconnu(s) du portail', false);
        $reponse->assertSee('hgf');
    }

    public function test_deux_releves_du_meme_jour_ne_montrent_pas_les_points_en_double(): void
    {
        // Le passage nocturne, puis un clic sur « Relever maintenant » : deux
        // jeux, le même jour. Retenus par leur date, ils s'empilaient et
        // l'écran montrait chaque point deux fois.
        $this->relever(['FACTURATION SIEGE', 'FACTURATION TEST 2']);
        $this->relever(['FACTURATION SIEGE', 'FACTURATION TEST 2', 'teste']);

        $comparaison = app(PointsDeVentePortailService::class)->comparer($this->entreprise);

        $this->assertSame(
            ['FACTURATION SIEGE', 'FACTURATION TEST 2', 'teste'],
            array_column($comparaison['points'], 'nom')
        );
        $this->assertSame(3, $comparaison['a_creer']);
    }

    public function test_l_ecran_range_le_depot_et_cree_automatiquement_le_point(): void
    {
        $this->poserTableur('1864699A_20260831.xlsx', [
            ['Nom', 'Outil', 'Statut', "ID de l'établissement", 'Créé à', 'Mise à jour à'],
            ['teste', 'Application FNE', '1', self::ETABLISSEMENT, '2026-08-31T13:00:37.674Z', '2026-08-31T13:00:37.674Z'],
        ]);

        $reponse = $this->get(route('admin.pdv.index'));

        $reponse->assertOk();
        $reponse->assertSee('teste');
        $this->assertTrue(PointDeVente::where('nom', 'teste')->where('entreprise_id', $this->entreprise->id)->exists());
        $reponse->assertSee('Dans Selflow', false);

        // Le pilote du bouton doit être dans la page rendue
        $reponse->assertSee('formReleverPortail', false);
    }

    /* ═══════════════ Aller voir le portail sans attendre ═══════════════ */

    public function test_le_bouton_de_releve_le_dit_quand_le_scraper_est_eteint(): void
    {
        // `phpunit.xml` éteint le scraper : aucune épreuve ne lance Node. Le
        // bouton doit le dire plutôt que de laisser croire qu'un relevé part.
        $this->post(route('admin.pdv.relever_le_portail'))->assertRedirect();

        $this->assertStringContainsString('éteint', session('avertissement'));
    }

    public function test_un_releve_frais_ne_renvoie_personne_au_portail(): void
    {
        // Une session sur le portail de la DGI se paie d'une connexion avec le
        // mot de passe du client : lu il y a une heure, on n'y retourne pas.
        config(['selflow.portail_fne.scraper.actif' => true]);
        config(['selflow.portail_fne.scraper.fraicheur_heures' => 12]);

        $this->relever(['FACTURATION SIEGE']);

        $this->assertFalse(
            ScraperPortailFneService::relancerSiLeReleveEstVieux($this->entreprise)
        );
    }

    public function test_le_verrou_empeche_deux_releves_a_la_fois(): void
    {
        // Dix employés qui se connectent à huit heures ne doivent lancer qu'un
        // seul navigateur. Le verrou est posé avant même de regarder la
        // fraîcheur : ici il est déjà là, et le relevé est vieux.
        config(['selflow.portail_fne.scraper.actif' => true]);

        $this->relever(['FACTURATION SIEGE']);
        PortailFneImport::query()->update(['updated_at' => now()->subDays(3)]);

        Cache::put('portail_fne_releve_' . self::LOGIN, true, now()->addHours(12));

        $this->assertFalse(
            ScraperPortailFneService::relancerSiLeReleveEstVieux($this->entreprise)
        );
    }

    public function test_l_interrupteur_eteint_le_releve_a_la_connexion(): void
    {
        config([
            'selflow.portail_fne.scraper.actif'                 => true,
            'selflow.portail_fne.scraper.releve_a_la_connexion'  => false,
        ]);

        $this->assertFalse(
            ScraperPortailFneService::relancerSiLeReleveEstVieux($this->entreprise)
        );

        // Éteint veut dire éteint : le verrou n'est même pas posé, sans quoi
        // rallumer l'interrupteur ne servirait à rien pendant douze heures.
        $this->assertFalse(Cache::has('portail_fne_releve_' . self::LOGIN));
    }

    public function test_l_ecran_se_recharge_seul_quand_le_releve_apporte_un_point(): void
    {
        // Ce que le point d'entrée sert à l'écran pendant qu'un relevé tourne :
        // une empreinte. Tant qu'elle ne bouge pas, la page ne bouge pas ; dès
        // qu'elle change, la page se recharge — personne n'attend, personne ne
        // recharge à la main.
        $this->relever(['FACTURATION SIEGE']);

        $avant = $this->getJson(route('admin.pdv.etat_du_portail'))
            ->assertOk()
            ->json();

        $this->assertSame(1, $avant['a_creer']);
        $this->assertNotEmpty($avant['empreinte']);

        // Le scraper dépose pendant que l'écran regarde : le rangement se fait
        // à l'appel suivant, et l'empreinte change.
        $this->poserTableur('1864699A_20260831.xlsx', [
            ['Nom', 'Outil', 'Statut', "ID de l'établissement", 'Créé à', 'Mise à jour à'],
            ['FACTURATION SIEGE', 'Application FNE', '1', self::ETABLISSEMENT, '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z'],
            ['teste',             'Application FNE', '1', self::ETABLISSEMENT, '2026-08-31T13:00:37.674Z', '2026-08-31T13:00:37.674Z'],
        ]);

        $apres = $this->getJson(route('admin.pdv.etat_du_portail'))->assertOk()->json();

        $this->assertNotSame($avant['empreinte'], $apres['empreinte']);
        $this->assertSame(2, $apres['a_creer']);
    }

    public function test_le_lancement_repond_au_bouton_sans_recharger_la_page(): void
    {
        // Le scraper est éteint dans la suite : le bouton doit le dire en JSON,
        // et non renvoyer une page entière que le navigateur ne lira pas.
        $this->postJson(route('admin.pdv.relever_le_portail'))
            ->assertStatus(409)
            ->assertJson(['lance' => false]);
    }

    public function test_un_releve_qui_ne_dit_rien_de_neuf_se_voit_quand_meme(): void
    {
        // Le piège : un relevé identique au précédent ne crée aucune ligne et
        // n'en modifie aucune. L'écran ne voyait donc rien changer et concluait
        // « le relevé n'est pas arrivé » — alors qu'il était arrivé, et n'avait
        // simplement rien de neuf à déclarer. La date du dépôt le dit.
        $this->relever(['FACTURATION SIEGE']);

        $avant = $this->getJson(route('admin.pdv.etat_du_portail'))->assertOk()->json();

        $this->poserTableur('1864699A_20260831.xlsx', [
            ['Nom', 'Outil', 'Statut', "ID de l'établissement", 'Créé à', 'Mise à jour à'],
            ['FACTURATION SIEGE', 'Application FNE', '1', self::ETABLISSEMENT, '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z'],
        ]);
        touch($this->dossier . DIRECTORY_SEPARATOR . '1864699A_20260831.xlsx', time() + 60);

        $apres = $this->getJson(route('admin.pdv.etat_du_portail'))->assertOk()->json();

        // Rien de neuf à l'écran…
        $this->assertSame($avant['empreinte'], $apres['empreinte']);
        // … mais le scraper a bel et bien répondu.
        $this->assertNotSame($avant['depose_le'], $apres['depose_le']);
    }

    /* ═══════════════ Les rouages ═══════════════ */

    /** @param array<int, array<int, string>> $lignes */
    private function poserTableur(string $nom, array $lignes): void
    {
        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->getActiveSheet()->fromArray($lignes, null, 'A1');

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))
            ->save($this->dossier . DIRECTORY_SEPARATOR . $nom);

        $classeur->disconnectWorksheets();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }

        @rmdir($this->dossier);

        parent::tearDown();
    }

    /** @param array<int, string> $noms */
    private function relever(array $noms, ?Entreprise $entreprise = null): void
    {
        $entreprise ??= $this->entreprise;

        $import = PortailFneImport::create([
            'entreprise_id' => $entreprise->id,
            'login'         => $entreprise->ncc,
            'date_scraping' => '2026-08-31',
            'type'          => 'points',
            'fichier_nom'       => $entreprise->ncc . '_20260831.xlsx',
            // Une empreinte par relevé : deux relevés du même jour existent, et
            // c'est précisément ce que l'une des épreuves vérifie.
            'fichier_empreinte' => hash('sha256', $entreprise->ncc . '_20260831_' . implode('|', $noms)),
            'statut'            => 'importe',
        ]);

        foreach ($noms as $i => $nom) {
            PortailFnePointFacturation::create([
                'import_id'        => $import->id,
                'entreprise_id'    => $entreprise->id,
                'login'            => $entreprise->ncc,
                'date_scraping'    => '2026-08-31',
                'nom'              => $nom,
                'outil'            => 'Application FNE',
                'statut'           => '1',
                // La forme réelle du portail, relevée le 31/08/2026 : **le même
                // identifiant d'établissement pour tous les points**. Ce qui les
                // distingue est leur date de création. Les épreuves portaient
                // jusque-là un identifiant par point — une forme inventée, qui
                // cachait le défaut au lieu de le montrer.
                'etablissement_id' => self::ETABLISSEMENT,
                'cree_a'           => '2026-07-30 10:38:4' . $i,
            ]);
        }
    }

    private function unPointDeVente(string $nom): PointDeVente
    {
        return PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom'           => $nom,
            'ville'         => 'Abidjan',
            'commune'       => 'Cocody',
            'statut'        => 'Ouvert',
        ]);
    }

    private function uneEntreprise(string $ncc): Entreprise
    {
        return Entreprise::create([
            'nom'               => 'DC-KNOWING ' . $ncc,
            'ncc'               => $ncc,
            'regime_imposition' => 'RNI',
            'adresse'           => 'RIVIERA II AFRICAINE',
            'rccm'              => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes'],
        ]);
    }
}
