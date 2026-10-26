<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'export de l'inventaire de stock, en PDF et en Excel (26/10/2026) : la
 * liste du site affiché, stock théorique, CUMP (Coût Unitaire Moyen Pondéré)
 * et valeur, avec une colonne « Compté » vide.
 */
class ExportInventaireTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $boutique;
    private PointDeVente $depot;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entreprise = Entreprise::create([
            'nom' => 'Deux sites', 'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'points_de_vente'],
        ]);
        $this->boutique = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->depot = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Dépôt', 'ville' => 'Abidjan', 'commune' => 'Yopougon']);
        $riz = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'RIZ-25', 'nom' => 'Riz parfumé', 'type' => 'marchandise',
            'unite' => 'sac', 'prix_achat' => 10000, 'prix_vente' => 15000, 'taux_tva' => 18,
        ]);
        Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV-1', 'nom' => 'Livraison', 'type' => 'service', 'prix_achat' => 0,
            'prix_vente' => 1000, 'taux_tva' => 18,
        ]);
        StockService::entree($riz, $this->boutique->id, 12, MouvementStock::RECEPTION, ['cout_unitaire' => 10000]);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@deuxsites.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
        ]);
    }

    public function test_l_ecran_d_inventaire_propose_les_deux_exports(): void
    {
        $this->actingAs($this->admin)->get(route('admin.stock.inventaire', ['point_de_vente_id' => $this->boutique->id]))
            ->assertOk()
            ->assertSee('id="export-inventaire-pdf"', false)
            ->assertSee('id="export-inventaire-excel"', false);
    }

    public function test_l_export_excel_porte_les_articles_du_site_et_leur_valeur(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.stock.inventaire.exporter', ['format' => 'excel', 'point_de_vente_id' => $this->boutique->id]))
            ->assertOk();
        $this->assertStringContainsString('inventaire-boutique-', $reponse->headers->get('content-disposition'));

        $fichier = tempnam(sys_get_temp_dir(), 'inv') . '.xlsx';
        file_put_contents($fichier, $reponse->streamedContent());
        $feuille = \PhpOffice\PhpSpreadsheet\IOFactory::load($fichier)->getActiveSheet();
        @unlink($fichier);

        $this->assertSame('INVENTAIRE DE STOCK', $feuille->getCell('A1')->getValue());
        $this->assertSame('Référence', $feuille->getCell('A7')->getValue());
        $this->assertSame('RIZ-25', $feuille->getCell('A8')->getValue());
        $this->assertEquals(12, $feuille->getCell('E8')->getValue());
        $this->assertEquals(10000, $feuille->getCell('F8')->getValue());
        $this->assertEquals(120000, $feuille->getCell('G8')->getCalculatedValue());
        // La prestation ne se compte pas : la ligne suivante est le total.
        $this->assertSame('TOTAL', $feuille->getCell('A9')->getValue());
        $this->assertEquals(120000, $feuille->getCell('G9')->getCalculatedValue());
        // Les chiffres clés, en formules.
        $this->assertEquals(1, $feuille->getCell('A5')->getCalculatedValue());
        $this->assertEquals(120000, $feuille->getCell('G5')->getCalculatedValue());
    }

    /** 27/10/2026 : le filigrane au nom de l'entreprise, à l'écran et à l'impression. */
    public function test_l_export_excel_porte_le_filigrane(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.stock.inventaire.exporter', ['format' => 'excel', 'point_de_vente_id' => $this->boutique->id]));
        $fichier = tempnam(sys_get_temp_dir(), 'inv') . '.xlsx';
        file_put_contents($fichier, $reponse->streamedContent());

        $zip = new \ZipArchive();
        $zip->open($fichier);
        $contenus = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $contenus[] = $zip->getNameIndex($i);
        }
        $feuilleXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($fichier);

        $this->assertNotEmpty(preg_grep('#^xl/media/.+\.png$#', $contenus), 'une image de filigrane est embarquée');
        $this->assertStringContainsString('<picture ', $feuilleXml, 'fond de feuille à l\'écran');
        $this->assertStringContainsString('&amp;G', $feuilleXml, 'image d\'en-tête à l\'impression');
    }

    public function test_le_filigrane_est_une_image_png(): void
    {
        $png = \App\Modules\Admin\Services\FiligraneService::png('Deux sites', 'Inventaire de stock');
        $this->assertNotNull($png);
        $this->assertSame("\x89PNG", substr($png, 0, 4));
    }

    public function test_l_export_pdf_se_telecharge(): void
    {
        $reponse = $this->actingAs($this->admin)
            ->get(route('admin.stock.inventaire.exporter', ['format' => 'pdf', 'point_de_vente_id' => $this->boutique->id]))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_un_site_d_une_autre_entreprise_ne_s_exporte_pas(): void
    {
        $autre = Entreprise::create(['nom' => 'Voisin']);
        $siteVoisin = PointDeVente::create(['entreprise_id' => $autre->id, 'nom' => 'Chez le voisin', 'ville' => 'Abidjan', 'commune' => 'Plateau']);

        $this->actingAs($this->admin)
            ->get(route('admin.stock.inventaire.exporter', ['format' => 'excel', 'point_de_vente_id' => $siteVoisin->id]))
            ->assertRedirect(route('admin.stock.inventaire'));
    }
}
