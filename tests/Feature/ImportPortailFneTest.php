<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use App\Modules\Admin\Services\ImportPortailFneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce qu'un relevé du portail FNE devient une fois rangé.
 *
 * Trois choses se vérifient ici, et aucune n'est décorative :
 *
 * 1. **Le rattachement passe par le login.** Un relevé rangé chez la mauvaise
 *    entreprise mettrait les données fiscales d'un client dans le dossier d'un
 *    autre. Le login du portail est un NCC, et le NCC ne s'écrit pas partout de
 *    la même façon — `1864699A` ici, `1864699 A` là.
 * 2. **Le même fichier ne se range pas deux fois.** La commande est faite pour
 *    être relancée sur un dossier entier ; sans l'empreinte, chaque passage
 *    dupliquerait l'historique et fausserait « depuis quand ».
 * 3. **Rien ne remonte dans `entreprises`.** Le relevé dit que le timbre de
 *    quittance est actif au portail ; l'entreprise, dans Selflow, dit le
 *    contraire. C'est un écart à regarder, pas une mise à jour à appliquer —
 *    `timbre_quittance` change ce qui part à la DGI.
 */
class ImportPortailFneTest extends TestCase
{
    use RefreshDatabase;

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('framework/testing/portail-fne-' . uniqid());
        mkdir($this->dossier, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        @rmdir($this->dossier);

        parent::tearDown();
    }

    public function test_la_fiche_json_est_rangee_et_rattachee_par_le_ncc(): void
    {
        // Le NCC est écrit avec un espace côté Selflow, sans espace au portail.
        $entreprise = $this->uneEntreprise(['ncc' => '1864699 A']);

        $this->poser('1864699A_20260821.json', json_encode([
            'Email'                                                => 'it.dcknowing@gmail.com',
            'Téléphone'                                            => '2722421443',
            'Adresse'                                              => '8XVQ+29Q',
            'Commune'                                              => 'COCODY',
            'Quartier'                                             => 'RIVIERA II AFRICAINE',
            'Référence Cadastrale'                                 => '*',
            'IDU'                                                  => '*',
            "Propriétaire du local professionnel de l'entreprise"  => null,
            "Sticker : solde d'alerte"                             => '5000',
            'Références bancaires'                                 => null,
            'Timbre de quittance'                                  => true,
            "Bordereau d'achat de produits agricoles"              => true,
            'Pied de page des factures'                            => null,
            'Factures autres mentions'                             => null,
        ], JSON_UNESCAPED_UNICODE));

        $rapport = app(ImportPortailFneService::class)->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(0, $rapport['erreurs']);

        $fiche = PortailFneFiche::sole();

        $this->assertSame($entreprise->id, $fiche->entreprise_id);
        $this->assertSame('1864699A', $fiche->login);
        $this->assertSame('21/08/2026', $fiche->date_scraping->format('d/m/Y'));
        $this->assertSame('COCODY', $fiche->commune);

        // « 5000 » entre guillemets au portail, un entier en base.
        $this->assertSame(5000, $fiche->sticker_solde_alerte);
        $this->assertTrue($fiche->timbre_quittance);
        $this->assertTrue($fiche->bapa);

        // « * » est la façon dont le portail écrit « je n'ai pas cette valeur ».
        $this->assertNull($fiche->idu);
        $this->assertNull($fiche->reference_cadastrale);

        // Tous les libellés du portail ont trouvé une colonne.
        $this->assertNull($fiche->champs_inconnus);
    }

    public function test_un_libelle_inconnu_du_portail_est_conserve_et_non_jete(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $this->poser('1864699A_20260821.json', json_encode([
            'Email'                => 'contact@exemple.ci',
            'Nouveau champ DGI'    => 'valeur inattendue',
        ], JSON_UNESCAPED_UNICODE));

        app(ImportPortailFneService::class)->importerDossier($this->dossier);

        $fiche = PortailFneFiche::sole();

        $this->assertSame('contact@exemple.ci', $fiche->email);
        $this->assertSame(
            ['Nouveau champ DGI' => 'valeur inattendue'],
            $fiche->champs_inconnus
        );
    }

    public function test_le_meme_fichier_relu_ne_se_range_pas_deux_fois(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $this->poser('1864699A_20260821.json', json_encode(['Email' => 'a@b.ci']));

        $service = app(ImportPortailFneService::class);

        $premier = $service->importerDossier($this->dossier);
        $second  = $service->importerDossier($this->dossier);

        $this->assertSame(1, $premier['importes']);
        $this->assertSame(0, $second['importes']);
        $this->assertSame(1, $second['ignores']);
        $this->assertSame(1, PortailFneImport::count());
        $this->assertSame(1, PortailFneFiche::count());
    }

    public function test_un_nom_hors_nomenclature_est_refuse_plutot_que_devine(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $this->poser('5e7062d7c617200950f6340ad47cedd1.json', json_encode(['Email' => 'a@b.ci']));

        $rapport = app(ImportPortailFneService::class)->importerDossier($this->dossier);

        $this->assertSame(0, $rapport['importes']);
        $this->assertSame(1, $rapport['erreurs']);
        $this->assertSame(0, PortailFneImport::count());
    }

    public function test_un_login_inconnu_conserve_le_releve_sans_le_rattacher(): void
    {
        $this->poser('0000000Z_20260821.json', json_encode(['Email' => 'a@b.ci']));

        $rapport = app(ImportPortailFneService::class)->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);

        $import = PortailFneImport::sole();

        $this->assertNull($import->entreprise_id);
        $this->assertTrue($import->estOrphelin());
    }

    public function test_le_tableur_des_points_de_facturation_est_lu_par_entete(): void
    {
        $entreprise = $this->uneEntreprise(['ncc' => '1864699A']);

        // Colonnes volontairement dans un autre ordre que celui du portail :
        // la lecture doit suivre les en-têtes, pas les positions.
        $this->poserTableur('1864699A_20260821.xlsx', [
            ["ID de l'établissement", 'Nom', 'Statut', 'Outil', 'ID du terminal', 'Raison de statut', 'Créé à', 'Mise à jour à'],
            ['42200613-f402-40a8-bd4d-a778bb5b96f0', 'FACTURATION SIEGE', '1', 'Application FNE', '', '', '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z'],
            ['', '', '', '', '', '', '', ''],
        ]);

        $rapport = app(ImportPortailFneService::class)->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);

        // La ligne vide du tableur n'est pas un point de facturation.
        $point = PortailFnePointFacturation::sole();

        $this->assertSame($entreprise->id, $point->entreprise_id);
        $this->assertSame('FACTURATION SIEGE', $point->nom);
        $this->assertSame('Application FNE', $point->outil);
        $this->assertSame('42200613-f402-40a8-bd4d-a778bb5b96f0', $point->etablissement_id);
        $this->assertNull($point->terminal_id);
        $this->assertTrue($point->estActif());
        $this->assertSame('30/07/2026 10:38', $point->cree_a->format('d/m/Y H:i'));
    }

    public function test_le_releve_ne_modifie_pas_l_entreprise(): void
    {
        $entreprise = $this->uneEntreprise([
            'ncc'                  => '1864699A',
            'commune'              => null,
            'timbre_quittance'     => false,
            'bapa'                 => false,
            'sticker_solde_alerte' => 5,
        ]);

        $this->poser('1864699A_20260821.json', json_encode([
            'Commune'                                 => 'COCODY',
            "Sticker : solde d'alerte"                => '5000',
            'Timbre de quittance'                     => true,
            "Bordereau d'achat de produits agricoles" => true,
        ], JSON_UNESCAPED_UNICODE));

        app(ImportPortailFneService::class)->importerDossier($this->dossier);

        $entreprise->refresh();

        $this->assertNull($entreprise->commune);
        $this->assertFalse((bool) $entreprise->timbre_quittance);
        $this->assertFalse((bool) $entreprise->bapa);
        $this->assertSame(5, (int) $entreprise->sticker_solde_alerte);

        // L'écart est visible, il n'est simplement pas appliqué.
        $ecarts = PortailFneFiche::sole()->ecartsAvecEntreprise();

        $this->assertArrayHasKey('timbre_quittance', $ecarts);
        $this->assertArrayHasKey('sticker_solde_alerte', $ecarts);
        $this->assertSame(5000, $ecarts['sticker_solde_alerte']['portail']);
    }

    public function test_un_releve_identique_au_precedent_n_ecrit_pas_une_seconde_fiche(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $service = app(ImportPortailFneService::class);

        $this->poser('1864699A_20260821.json', json_encode([
            'Email'                                   => 'a@b.ci',
            'Commune'                                 => 'COCODY',
            "Sticker : solde d'alerte"                => '5000',
            'Timbre de quittance'                     => true,
            "Bordereau d'achat de produits agricoles" => true,
        ], JSON_UNESCAPED_UNICODE));

        $service->importerDossier($this->dossier);

        // Le lendemain, le portail dit la même chose — écrite autrement. Clés
        // dans un autre ordre, nombre typé plutôt qu'entre guillemets, « * »
        // là où il n'y avait rien : autant de libertés que le portail prend, et
        // que l'import accepte depuis toujours.
        //
        // L'empreinte du fichier ne peut rien contre ça : les octets diffèrent.
        // Seule une comparaison du contenu lu voit qu'il n'y a rien de neuf.
        $this->poser('1864699A_20260822.json', json_encode([
            "Bordereau d'achat de produits agricoles" => 'true',
            'Timbre de quittance'                     => true,
            "Sticker : solde d'alerte"                => 5000,
            'Commune'                                 => 'COCODY',
            'Email'                                   => 'a@b.ci',
            'IDU'                                     => '*',
        ], JSON_UNESCAPED_UNICODE));

        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(0, $rapport['importes']);
        $this->assertSame(1, $rapport['inchanges']);

        // Pas une ligne de plus, nulle part : la ligne existante est confirmée.
        $this->assertSame(1, PortailFneImport::count());
        $this->assertSame(1, PortailFneFiche::count());

        $import = PortailFneImport::sole();

        // `date_scraping` dit depuis quand le portail affiche cela ;
        // `dernier_releve_le` dit qu'on l'a revu hier. Écraser la première
        // effacerait l'ancienneté du paramétrage.
        $this->assertSame('21/08/2026', $import->date_scraping->format('d/m/Y'));
        $this->assertSame('22/08/2026', $import->dernier_releve_le->format('d/m/Y'));
        $this->assertSame(2, $import->releves);
        $this->assertSame(PortailFneImport::STATUT_IMPORTE, $import->statut);
    }

    public function test_une_valeur_qui_change_ouvre_bien_une_nouvelle_fiche(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $service = app(ImportPortailFneService::class);

        $this->poser('1864699A_20260821.json', json_encode(['Timbre de quittance' => true]));
        $service->importerDossier($this->dossier);

        // Le timbre de quittance désactivé au portail : c'est précisément ce
        // qu'on ne doit jamais rater.
        $this->poser('1864699A_20260822.json', json_encode(['Timbre de quittance' => false]));
        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(0, $rapport['inchanges']);
        $this->assertSame(2, PortailFneFiche::count());
    }

    public function test_un_champ_inedit_du_portail_compte_comme_un_changement(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $service = app(ImportPortailFneService::class);

        $this->poser('1864699A_20260821.json', json_encode(['Email' => 'a@b.ci']));
        $service->importerDossier($this->dossier);

        // Les quatorze champs suivis n'ont pas bougé, mais le portail en a
        // ajouté un. Le taire reviendrait à ne le découvrir que le jour où il
        // sert à quelque chose.
        $this->poser('1864699A_20260822.json', json_encode([
            'Email'                => 'a@b.ci',
            "Régime d'imposition"  => 'RSI',
        ], JSON_UNESCAPED_UNICODE));

        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(2, PortailFneFiche::count());
    }

    public function test_des_points_inchanges_ne_sont_pas_reecrits(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $service = app(ImportPortailFneService::class);

        // Les colonnes de date ne sont pas décoratives ici : elles sont les
        // seules à traverser la base sous une autre forme que celle du relevé
        // (`Carbon` d'un côté, chaîne sérialisée de l'autre). Un tableur sans
        // elles laisserait passer une comparaison qui croit tout comparer.
        $this->poserTableur('1864699A_20260821.xlsx', [
            ['Nom', 'Outil', 'Statut', "ID de l'établissement", 'Créé à', 'Mise à jour à'],
            ['FACTURATION SIEGE', 'Application FNE', '1', '42200613-f402-40a8-bd4d-a778bb5b96f0', '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z'],
            ['CAISSE 2', 'Application FNE', '1', '9f3c1a55-0000-4000-8000-aaaaaaaaaaaa', '2026-07-30T10:40:00.000Z', '2026-08-02T09:15:00.000Z'],
        ]);
        $service->importerDossier($this->dossier);

        // Les mêmes points, dans un tableur écrit autrement : colonnes
        // réordonnées, ligne vide en fin de feuille. Le portail se permet cela,
        // et il réécrit de surcroît `dcterms:created` à chaque export — deux
        // relevés identiques ne partagent donc jamais leur empreinte.
        $this->poserTableur('1864699A_20260822.xlsx', [
            ['Statut', "ID de l'établissement", 'Créé à', 'Nom', 'Mise à jour à', 'Outil'],
            ['1', '42200613-f402-40a8-bd4d-a778bb5b96f0', '2026-07-30T10:38:40.726Z', 'FACTURATION SIEGE', '2026-07-30T10:38:40.726Z', 'Application FNE'],
            ['1', '9f3c1a55-0000-4000-8000-aaaaaaaaaaaa', '2026-07-30T10:40:00.000Z', 'CAISSE 2', '2026-08-02T09:15:00.000Z', 'Application FNE'],
            ['', '', '', '', '', ''],
        ]);
        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['inchanges']);
        $this->assertSame(2, PortailFnePointFacturation::count());
        $this->assertSame(1, PortailFneImport::count());
        $this->assertSame(2, PortailFneImport::sole()->releves);
    }

    public function test_un_seul_point_modifie_fait_reecrire_tout_le_jeu(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $service = app(ImportPortailFneService::class);

        $this->poserTableur('1864699A_20260821.xlsx', [
            ['Nom', 'Outil', 'Statut', "ID de l'établissement"],
            ['FACTURATION SIEGE', 'Application FNE', '1', '42200613-f402-40a8-bd4d-a778bb5b96f0'],
            ['CAISSE 2', 'Application FNE', '1', '9f3c1a55-0000-4000-8000-aaaaaaaaaaaa'],
        ]);
        $service->importerDossier($this->dossier);

        // Un seul point fermé. Le relevé est un jeu complet : n'écrire que la
        // ligne modifiée ferait répondre « le portail ne déclare qu'un point »
        // à qui demande ce que le portail déclare.
        $this->poserTableur('1864699A_20260822.xlsx', [
            ['Nom', 'Outil', 'Statut', "ID de l'établissement"],
            ['FACTURATION SIEGE', 'Application FNE', '1', '42200613-f402-40a8-bd4d-a778bb5b96f0'],
            ['CAISSE 2', 'Application FNE', '0', '9f3c1a55-0000-4000-8000-aaaaaaaaaaaa'],
        ]);
        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(4, PortailFnePointFacturation::count());

        $dernier = PortailFnePointFacturation::whereDate('date_scraping', '2026-08-22')->get();

        $this->assertCount(2, $dernier);
        $this->assertSame(
            ['CAISSE 2', 'FACTURATION SIEGE'],
            $dernier->pluck('nom')->sort()->values()->all()
        );
    }

    public function test_deux_points_du_meme_etablissement_ne_s_ecrasent_pas(): void
    {
        // La forme **réelle** du portail, relevée le 31/08/2026 : deux points
        // de facturation, un seul identifiant d'établissement pour les deux.
        // Les épreuves d'au-dessus donnaient un identifiant par point — une
        // forme inventée. Indexés sur l'établissement seul, les deux points
        // s'écrasaient : le relevé se réduisait au dernier lu.
        $this->uneEntreprise(['ncc' => '1864699A']);

        $this->poserTableur('1864699A_20260831.xlsx', [
            ['Nom', 'Outil', 'Statut', "ID de l'établissement", 'Créé à', 'Mise à jour à'],
            ['FACTURATION TEST 2', 'Application FNE', '1', '42200613-f402-40a8-bd4d-a778bb5b96f0', '2026-08-31T12:27:44.987Z', '2026-08-31T12:27:44.987Z'],
            ['FACTURATION SIEGE',  'Application FNE', '1', '42200613-f402-40a8-bd4d-a778bb5b96f0', '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z'],
        ]);

        $rapport = app(ImportPortailFneService::class)->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(
            ['FACTURATION SIEGE', 'FACTURATION TEST 2'],
            PortailFnePointFacturation::orderBy('nom')->pluck('nom')->all()
        );
    }

    public function test_un_point_cree_au_portail_ne_passe_pas_pour_un_releve_inchange(): void
    {
        // Le défaut tel que le propriétaire du projet l'a rencontré : il crée un
        // point de facturation au portail, le scraper le relève, et l'import
        // répond « identique au relevé du 21/08 ». Le point n'entrait jamais.
        $this->uneEntreprise(['ncc' => '1864699A']);

        $service = app(ImportPortailFneService::class);

        $this->poserTableur('1864699A_20260830.xlsx', [
            ['Nom', 'Outil', 'Statut', "ID de l'établissement", 'Créé à', 'Mise à jour à'],
            ['FACTURATION SIEGE', 'Application FNE', '1', '42200613-f402-40a8-bd4d-a778bb5b96f0', '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z'],
        ]);
        $service->importerDossier($this->dossier);

        $this->poserTableur('1864699A_20260831.xlsx', [
            ['Nom', 'Outil', 'Statut', "ID de l'établissement", 'Créé à', 'Mise à jour à'],
            ['FACTURATION TEST 2', 'Application FNE', '1', '42200613-f402-40a8-bd4d-a778bb5b96f0', '2026-08-31T12:27:44.987Z', '2026-08-31T12:27:44.987Z'],
            ['FACTURATION SIEGE',  'Application FNE', '1', '42200613-f402-40a8-bd4d-a778bb5b96f0', '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z'],
        ]);
        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(0, $rapport['inchanges']);

        $dernier = PortailFnePointFacturation::whereDate('date_scraping', '2026-08-31')->get();

        $this->assertCount(2, $dernier);
        $this->assertTrue($dernier->contains('nom', 'FACTURATION TEST 2'));
    }

    public function test_une_fiche_orpheline_se_rattache_quand_l_entreprise_arrive(): void
    {
        $service = app(ImportPortailFneService::class);

        // Le relevé arrive avant que l'entreprise n'existe dans Selflow.
        $this->poser('1864699A_20260821.json', json_encode(['Email' => 'a@b.ci']));
        $service->importerDossier($this->dossier);

        $this->assertNull(PortailFneFiche::sole()->entreprise_id);

        $entreprise = $this->uneEntreprise(['ncc' => '1864699A']);

        // Le relevé suivant ne dit rien de neuf, donc n'écrit pas de fiche.
        // Sans rattrapage, la fiche resterait orpheline pour toujours et
        // l'écran des rejets, qui cherche par entreprise, ne la verrait jamais.
        $this->poser('1864699A_20260822.json', json_encode([
            'Email' => 'a@b.ci',
            'IDU'   => '*',
        ]));

        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['inchanges']);
        $this->assertSame(1, PortailFneFiche::count());
        $this->assertSame($entreprise->id, PortailFneFiche::sole()->entreprise_id);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function uneEntreprise(array $attributs = []): Entreprise
    {
        return Entreprise::create(array_merge([
            'nom'               => 'DC-KNOWING CGA',
            'regime_imposition' => 'RNI',
            'adresse'           => 'RIVIERA II AFRICAINE',
            'rccm'              => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats'],
        ], $attributs));
    }

    private function poser(string $nom, string $contenu): void
    {
        file_put_contents($this->dossier . DIRECTORY_SEPARATOR . $nom, $contenu);
    }

    /**
     * @param  array<int, array<int, string>>  $lignes
     */
    private function poserTableur(string $nom, array $lignes): void
    {
        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->getActiveSheet()->fromArray($lignes, null, 'A1');

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))
            ->save($this->dossier . DIRECTORY_SEPARATOR . $nom);

        $classeur->disconnectWorksheets();
    }
}
