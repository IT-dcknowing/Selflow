<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retour du 20/10/2026 :
 * - « Créances & règlements » est dans la Trésorerie, comptabilité ouverte
 *   ou non ;
 * - une entreprise qui crée des articles stockables voit le volet Stock,
 *   même sans avoir fait sa configuration.
 */
class CreancesEtStockOuvertsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'poissonnerie LA GRACE', 'comptabilite_activee' => false,
            'modules_actifs' => ['principal', 'ventes', 'achats', 'produits', 'tiers', 'comptabilite', 'points_de_vente'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'GUEDE AYA', 'ville' => 'Abidjan', 'commune' => 'Yopougon']);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'H', 'email' => 'admin@lagrace.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
    }

    public function test_les_creances_s_ouvrent_sans_comptabilite_et_figurent_dans_la_tresorerie(): void
    {
        $this->assertFalse($this->entreprise->comptabiliteOuverte());

        $this->actingAs($this->admin)->get(route('admin.comptabilite.creances'))->assertOk()->assertSee('Soldes de départ');
        $this->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('<span>Trésorerie</span>', false)->assertSee('Créances &amp; règlements', false);
    }

    public function test_creer_un_article_stockable_ouvre_le_volet_stock(): void
    {
        $this->assertFalse(in_array('stock', $this->entreprise->modules_actifs, true));

        Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'PO-1', 'nom' => 'PELON 27KG', 'type' => 'marchandise',
            'unite' => 'carton', 'prix_achat' => 1000, 'prix_vente' => 1500, 'taux_tva' => 18]);

        $this->assertContains('stock', $this->entreprise->fresh()->modules_actifs);
        $this->actingAs($this->admin)->get(route('admin.stock.index'))->assertOk();
        $this->get(route('admin.tableau_de_bord'))->assertSee('fa-boxes-stacked nav-section-icone', false);
    }

    public function test_un_service_n_ouvre_pas_le_stock(): void
    {
        Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'S-1', 'nom' => 'Écaillage', 'type' => 'service',
            'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 500, 'taux_tva' => 18]);

        $this->assertNotContains('stock', $this->entreprise->fresh()->modules_actifs);
    }

    public function test_la_reprise_ouvre_le_stock_des_entreprises_qui_stockent_deja(): void
    {
        Produit::withoutEvents(fn () => Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'PO-2', 'nom' => 'MULET 20KG',
            'type' => 'marchandise', 'unite' => 'carton', 'prix_achat' => 1, 'prix_vente' => 2, 'taux_tva' => 18]));
        $this->assertNotContains('stock', $this->entreprise->fresh()->modules_actifs);

        (require base_path('app/Modules/Admin/Migrations/2026_10_20_000005_le_stock_ouvert_a_qui_stocke.php'))->up();

        $this->assertContains('stock', $this->entreprise->fresh()->modules_actifs);
    }

    public function test_un_module_stock_non_autorise_reste_ferme(): void
    {
        $this->entreprise->forceFill(['modules_autorises' => ['principal', 'ventes', 'achats', 'produits', 'tiers', 'comptabilite', 'points_de_vente']])->save();

        Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'PO-3', 'nom' => 'MUSEAU', 'type' => 'marchandise',
            'unite' => 'carton', 'prix_achat' => 1, 'prix_vente' => 2, 'taux_tva' => 18]);

        $this->assertNotContains('stock', $this->entreprise->fresh()->modules_actifs);
    }
}
