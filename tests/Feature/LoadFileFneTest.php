<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * `LoadFileFne` : charger un relevé du portail FNE et l'enregistrer.
 *
 * La fonction ne fait qu'une chose : lire le fichier déposé et écrire ses
 * lignes dans les modèles du portail. Ce que ces épreuves suivent est donc le
 * trajet entier — le fichier part du navigateur, on va lire la ligne en base.
 *
 * | Ce qui est chargé | Ce qui doit apparaître |
 * |---|---|
 * | `.json` | une ligne `PortailFneFiche` |
 * | `.xlsx`, `.xls` | une ligne `PortailFnePointFacturation` par point |
 * | hors nomenclature | refusé, rien en base |
 *
 * Les trois règles qui gouvernent l'écriture :
 *
 * 1. **Le NCC vient du nom du fichier** (`NCC_AAAAMMJJ.<ext>`) et se retrouve
 *    dans chaque ligne enregistrée. Aucun login n'est vérifié.
 * 2. **Une ligne qui existe est mise à jour**, jamais dupliquée. La fiche
 *    n'existe qu'une fois par NCC ; un point de facturation, une fois par NCC
 *    et par nom.
 * 3. **Le dépôt porte le NCC** : le fichier est rangé sous `<import>/<NCC>/`.
 */
class LoadFileFneTest extends TestCase
{
    use RefreshDatabase;

    private const NCC = '1864699A';

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        // Un utilisateur connecté : la route vit dans l'espace admin. Son
        // entreprise ne sert à rien d'autre — `LoadFileFne` ne la regarde pas.
        $entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-01111', 'ncc' => self::NCC, 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'points_de_vente'],
        ]);

        $magasin = PointDeVente::create([
            'entreprise_id' => $entreprise->id, 'nom' => 'FACTURATION SIEGE',
            'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->actingAs(Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Adjoua', 'email' => 'adjoua@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $entreprise->id, 'point_de_vente_id' => $magasin->id,
        ]))->withSession(['point_de_vente_actif_id' => $magasin->id]);

        $this->dossier = storage_path('framework/testing/loadfile-' . uniqid());
        mkdir($this->dossier, 0777, true);
        config(['selflow.portail_fne.dossier_import' => $this->dossier]);
    }

    protected function tearDown(): void
    {
        $this->viderLeDossier($this->dossier);

        parent::tearDown();
    }

    /**
     * Le trajet complet, sur l'export réellement fourni par le portail.
     *
     * `FNE.xlsx`, renommé à la nomenclature, est celui que le propriétaire du
     * projet a versé au dépôt : ses en-têtes, son UUID d'établissement, son
     * horodatage ISO.
     */
    public function test_l_export_reel_du_portail_va_du_navigateur_jusqu_a_la_base(): void
    {
        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->exportReel('1864699A_20260831.xlsx'),
        ])->assertRedirect()->assertSessionHas('succes');

        $point = PortailFnePointFacturation::sole();

        $this->assertSame('FACTURATION SIEGE', $point->nom);
        $this->assertSame(self::NCC, $point->login);
        $this->assertSame('2026-08-31', $point->date_scraping->format('Y-m-d'));
        $this->assertSame('Application FNE', $point->outil);
        $this->assertSame('42200613-f402-40a8-bd4d-a778bb5b96f0', $point->etablissement_id);
        $this->assertSame('2026-07-30', $point->cree_a->format('Y-m-d'));
    }

    public function test_le_json_ecrit_la_fiche_du_portail(): void
    {
        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->uneFiche('1864699A_20260831.json'),
        ])->assertRedirect()->assertSessionHas('succes');

        $fiche = PortailFneFiche::sole();

        $this->assertSame(self::NCC, $fiche->login);
        $this->assertSame('2026-08-31', $fiche->date_scraping->format('Y-m-d'));
        $this->assertSame('2722421443', $fiche->telephone);
        $this->assertSame('COCODY', $fiche->commune);
        $this->assertSame(5000, $fiche->sticker_solde_alerte);
        $this->assertTrue((bool) $fiche->timbre_quittance);
        $this->assertTrue((bool) $fiche->bapa);

        // « * » est ce que le portail écrit pour un champ qu'il n'a pas : le
        // garder ferait passer une absence pour une valeur.
        $this->assertNull($fiche->idu);
    }

    public function test_un_classeur_au_vieux_format_xls_est_accepte(): void
    {
        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->unClasseur('1864699A_20260831.xls', ['FACTURATION SIEGE', 'FACTURATION TEST 2']),
        ])->assertRedirect()->assertSessionHas('succes');

        $this->assertSame(2, PortailFnePointFacturation::count());
        $this->assertEqualsCanonicalizing(
            ['FACTURATION SIEGE', 'FACTURATION TEST 2'],
            PortailFnePointFacturation::pluck('nom')->all()
        );
    }

    /**
     * La règle demandée : si la ligne existe pour ce NCC, on la met à jour.
     *
     * Le second classeur porte les mêmes deux points, à une date plus récente,
     * et l'un d'eux a changé d'outil au portail. Il ne doit pas y avoir quatre
     * lignes, mais deux — remises à jour.
     */
    public function test_une_ligne_deja_enregistree_pour_ce_ncc_est_mise_a_jour(): void
    {
        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->unClasseur('1864699A_20260830.xlsx', ['FACTURATION SIEGE', 'FACTURATION TEST 2']),
        ])->assertSessionHas('succes');

        $this->assertSame(2, PortailFnePointFacturation::count());
        $premier = PortailFnePointFacturation::where('nom', 'FACTURATION SIEGE')->sole();

        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->unClasseur(
                '1864699A_20260831.xlsx',
                ['FACTURATION SIEGE', 'FACTURATION TEST 2'],
                'Caisse enregistreuse'
            ),
        ])->assertSessionHas('succes');

        $this->assertSame(2, PortailFnePointFacturation::count());

        $apres = PortailFnePointFacturation::where('nom', 'FACTURATION SIEGE')->sole();

        // La même ligne, mise à jour — pas une seconde.
        $this->assertSame($premier->id, $apres->id);
        $this->assertSame('Caisse enregistreuse', $apres->outil);
        $this->assertSame('2026-08-31', $apres->date_scraping->format('Y-m-d'));
    }

    public function test_la_fiche_n_existe_qu_une_fois_par_ncc(): void
    {
        $this->post(route('admin.pdv.load_file_fne'), ['fichier_fne' => $this->uneFiche('1864699A_20260830.json')])
            ->assertSessionHas('succes');

        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->uneFiche('1864699A_20260831.json', ['Commune' => 'PLATEAU']),
        ])->assertSessionHas('succes');

        $fiche = PortailFneFiche::sole();

        $this->assertSame('PLATEAU', $fiche->commune);
        $this->assertSame('2026-08-31', $fiche->date_scraping->format('Y-m-d'));
    }

    /**
     * Deux NCC, deux jeux de lignes : la mise à jour ne franchit pas le NCC.
     */
    public function test_un_autre_ncc_ecrit_ses_propres_lignes(): void
    {
        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->unClasseur('1864699A_20260831.xlsx', ['FACTURATION SIEGE']),
        ])->assertSessionHas('succes');

        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->unClasseur('9999999Z_20260831.xlsx', ['FACTURATION SIEGE']),
        ])->assertSessionHas('succes');

        $this->assertSame(2, PortailFnePointFacturation::count());
        $this->assertEqualsCanonicalizing(
            [self::NCC, '9999999Z'],
            PortailFnePointFacturation::pluck('login')->all()
        );
    }

    public function test_le_fichier_est_depose_dans_un_dossier_qui_porte_le_ncc(): void
    {
        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->exportReel('1864699A_20260831.xlsx'),
        ])->assertSessionHas('succes');

        $this->assertFileExists(
            $this->dossier . DIRECTORY_SEPARATOR . self::NCC . DIRECTORY_SEPARATOR . '1864699A_20260831.xlsx'
        );
    }

    public function test_un_nom_hors_nomenclature_est_refuse(): void
    {
        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->exportReel('FNE.xlsx'),
        ])->assertRedirect()->assertSessionHas('erreur');

        $this->assertSame(0, PortailFnePointFacturation::count());
    }

    public function test_une_date_qui_n_en_est_pas_une_est_refusee(): void
    {
        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => $this->exportReel('1864699A_20261345.xlsx'),
        ])->assertRedirect()->assertSessionHas('erreur');

        $this->assertSame(0, PortailFnePointFacturation::count());
    }

    public function test_un_fichier_qui_n_est_pas_un_releve_est_refuse(): void
    {
        $this->from(route('admin.pdv.index'))
            ->post(route('admin.pdv.load_file_fne'), [
                'fichier_fne' => UploadedFile::fake()->create('1864699A_20260831.pdf', 12, 'application/pdf'),
            ])
            ->assertRedirect(route('admin.pdv.index'))
            ->assertSessionHasErrors('fichier_fne');

        $this->assertSame(0, PortailFnePointFacturation::count());
        $this->assertSame(0, PortailFneFiche::count());
    }

    public function test_un_json_illisible_le_dit_au_lieu_de_rompre(): void
    {
        $chemin = $this->dossier . DIRECTORY_SEPARATOR . 'brut-' . uniqid() . '.json';
        file_put_contents($chemin, '{ ceci n\'est pas du JSON');

        $this->post(route('admin.pdv.load_file_fne'), [
            'fichier_fne' => new UploadedFile($chemin, '1864699A_20260831.json', null, null, true),
        ])->assertRedirect()->assertSessionHas('erreur');

        $this->assertSame(0, PortailFneFiche::count());
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * L'export réel du dépôt, copié pour l'épreuve.
     *
     * Copié, et non ouvert en place : le contrôleur **déplace** le fichier
     * déposé, et l'épreuve emporterait le `FNE.xlsx` du dépôt.
     */
    private function exportReel(string $nomDepose): UploadedFile
    {
        $source = base_path('FNE.xlsx');

        if (!is_file($source)) {
            $this->markTestSkipped("FNE.xlsx n'est pas à la racine du projet.");
        }

        $copie = $this->dossier . DIRECTORY_SEPARATOR . 'brut-' . uniqid() . '.xlsx';
        copy($source, $copie);

        return new UploadedFile($copie, $nomDepose, null, null, true);
    }

    /** @param  array<int, string>  $noms */
    private function unClasseur(string $nomDepose, array $noms, string $outil = 'Application FNE'): UploadedFile
    {
        $lignes = [[
            'Nom', 'Outil', 'ID du terminal', 'Statut', 'Raison de statut',
            "ID de l'établissement", 'Créé à', 'Mise à jour à',
        ]];

        foreach ($noms as $nom) {
            $lignes[] = [
                $nom, $outil, '', '1', '',
                '42200613-f402-40a8-bd4d-a778bb5b96f0',
                '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z',
            ];
        }

        $extension = strtolower(pathinfo($nomDepose, PATHINFO_EXTENSION));
        $chemin    = $this->dossier . DIRECTORY_SEPARATOR . 'brut-' . uniqid() . '.' . $extension;

        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->getActiveSheet()->fromArray($lignes, null, 'A1');

        $ecrivain = $extension === 'xls'
            ? new \PhpOffice\PhpSpreadsheet\Writer\Xls($classeur)
            : new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur);
        $ecrivain->save($chemin);
        $classeur->disconnectWorksheets();

        return new UploadedFile($chemin, $nomDepose, null, null, true);
    }

    /** @param  array<string, mixed>  $remplace */
    private function uneFiche(string $nomDepose, array $remplace = []): UploadedFile
    {
        $chemin = $this->dossier . DIRECTORY_SEPARATOR . 'brut-' . uniqid() . '.json';

        file_put_contents($chemin, json_encode(array_merge([
            'Email' => 'it@dcknowing.ci', 'Téléphone' => '2722421443', 'Adresse' => '8XVQ+29Q',
            'Commune' => 'COCODY', 'Quartier' => 'RIVIERA II AFRICAINE',
            'Référence Cadastrale' => '*', 'IDU' => '*',
            "Propriétaire du local professionnel de l'entreprise" => null,
            "Sticker : solde d'alerte" => '5000', 'Références bancaires' => null,
            'Timbre de quittance' => true, "Bordereau d'achat de produits agricoles" => true,
            'Pied de page des factures' => null, 'Factures autres mentions' => null,
        ], $remplace), JSON_UNESCAPED_UNICODE));

        return new UploadedFile($chemin, $nomDepose, null, null, true);
    }

    private function viderLeDossier(string $dossier): void
    {
        foreach (glob($dossier . DIRECTORY_SEPARATOR . '*') ?: [] as $entree) {
            is_dir($entree) ? $this->viderLeDossier($entree) : unlink($entree);
        }

        @rmdir($dossier);
    }
}
