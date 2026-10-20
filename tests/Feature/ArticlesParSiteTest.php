<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retour du 20/10/2026 : « chacun voit ce qui le concerne ». Le catalogue est
 * celui de l'entreprise ; un point de vente ne propose, en caisse, en stock et
 * au catalogue, que les articles qui y sont référencés.
 */
class ArticlesParSiteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $marcory;
    private PointDeVente $plateau;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Deux magasins', 'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'points_de_vente'],
        ]);
        $this->marcory = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory']);
        $this->plateau = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-plateau', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@deuxmag.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
        ]);
    }

    private function sur(PointDeVente $site): self
    {
        return $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id, 'point_de_vente_actif_nom' => $site->nom]);
    }

    private function creerA(PointDeVente $site, string $nom): Produit
    {
        $this->sur($site)->post(route('admin.produits.creer'), [
            'nom' => $nom, 'type' => 'marchandise', 'prix_achat' => 1000, 'prix_vente' => 1500, 'taux_tva' => 18,
            'stock_actuel' => 0, 'stock_minimum' => 5, 'unite' => 'u',
        ])->assertSessionHasNoErrors();

        return Produit::where('nom', $nom)->firstOrFail();
    }

    public function test_l_article_cree_a_marcory_n_apparait_pas_au_plateau(): void
    {
        $this->creerA($this->marcory, 'Thiof frais');

        $this->sur($this->marcory)->get(route('admin.ventes.nouvelle'))->assertOk()->assertSee('Thiof frais');
        $this->sur($this->plateau)->get(route('admin.ventes.nouvelle'))->assertOk()->assertDontSee('Thiof frais');
        $this->sur($this->plateau)->get(route('admin.stock.index'))->assertOk()->assertDontSee('Thiof frais');
        $this->sur($this->plateau)->get(route('admin.produits.index'))->assertOk()->assertDontSee('Thiof frais');

        // L'administrateur peut afficher tout le catalogue de l'entreprise.
        $this->sur($this->plateau)->get(route('admin.produits.index', ['tout' => 1]))->assertOk()->assertSee('Thiof frais');
    }

    public function test_une_entree_ou_un_transfert_reference_l_article_sur_le_site(): void
    {
        $thiof = $this->creerA($this->marcory, 'Thiof frais');
        $this->actingAs($this->admin);
        StockService::entree($thiof, $this->plateau->id, 4, MouvementStock::RECEPTION, ['cout_unitaire' => 1000]);

        $this->sur($this->plateau)->get(route('admin.ventes.nouvelle'))->assertOk()->assertSee('Thiof frais');
    }

    public function test_les_alertes_d_un_site_ignorent_les_articles_d_un_autre(): void
    {
        $this->creerA($this->marcory, 'Thiof frais'); // 0 en stock, minimum 5 : en alerte à Marcory seulement

        $this->sur($this->plateau)->get(route('admin.stock.index'))->assertOk()->assertDontSee('Thiof frais');
        $this->assertFalse((bool) Stock::where('point_de_vente_id', $this->plateau->id)->value('reference'));
    }

    public function test_proposer_puis_retirer_un_article_d_un_site(): void
    {
        $thiof = $this->creerA($this->marcory, 'Thiof frais');

        $this->sur($this->marcory)->post(route('admin.produits.referencer', $thiof), ['point_de_vente_id' => $this->plateau->id, 'reference' => 1])
            ->assertSessionHas('succes');
        $this->sur($this->plateau)->get(route('admin.ventes.nouvelle'))->assertSee('Thiof frais');

        // Avec du stock, on ne le retire pas.
        $this->actingAs($this->admin);
        StockService::entree($thiof, $this->plateau->id, 2, MouvementStock::RECEPTION, ['cout_unitaire' => 1000]);
        $this->sur($this->plateau)->post(route('admin.produits.referencer', $thiof), ['point_de_vente_id' => $this->plateau->id, 'reference' => 0])
            ->assertSessionHas('erreur');
    }

    public function test_le_caissier_du_plateau_ne_voit_pas_les_articles_de_marcory(): void
    {
        $this->creerA($this->marcory, 'Thiof frais');
        $caissier = Utilisateur::create([
            'nom' => 'C', 'prenom' => 'D', 'email' => 'caisse@deuxmag.ci', 'password' => bcrypt('x'), 'role' => 'caissier',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->plateau->id,
            'habilitations' => ['nouvelle_vente'],
        ]);

        $this->actingAs($caissier)->get(route('caissier.ventes.nouvelle'))->assertOk()->assertDontSee('Thiof frais');
    }

    public function test_un_service_et_un_article_sans_fiche_restent_proposes_partout(): void
    {
        Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'SRV', 'nom' => 'Écaillage', 'type' => 'service',
            'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 500, 'taux_tva' => 18]);
        Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'IMP', 'nom' => 'Article importé', 'type' => 'marchandise',
            'unite' => 'u', 'prix_achat' => 100, 'prix_vente' => 200, 'taux_tva' => 18]);

        $this->sur($this->plateau)->get(route('admin.ventes.nouvelle'))->assertSee('Écaillage')->assertSee('Article importé');
    }

    public function test_la_reprise_de_l_existant_ne_cache_que_les_fiches_jamais_servies(): void
    {
        $actif = Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'A', 'nom' => 'Actif', 'type' => 'marchandise',
            'unite' => 'u', 'prix_achat' => 1, 'prix_vente' => 2, 'taux_tva' => 18]);
        $neuf = Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'N', 'nom' => 'Neuf', 'type' => 'marchandise',
            'unite' => 'u', 'prix_achat' => 1, 'prix_vente' => 2, 'taux_tva' => 18]);
        foreach ([$this->marcory, $this->plateau] as $site) {
            foreach ([$actif, $neuf] as $p) {
                Stock::create(['produit_id' => $p->id, 'point_de_vente_id' => $site->id, 'quantite_disponible' => 0, 'stock_minimum' => 5, 'stock_maximum' => 100]);
            }
        }
        $this->actingAs($this->admin);
        StockService::entree($actif, $this->marcory->id, 3, MouvementStock::RECEPTION, ['cout_unitaire' => 1]);

        (require base_path('app/Modules/Admin/Migrations/2026_10_20_000001_l_article_reference_au_point_de_vente.php'))->up();

        $ref = fn ($p, $s) => (bool) Stock::where('produit_id', $p->id)->where('point_de_vente_id', $s->id)->value('reference');
        $this->assertTrue($ref($actif, $this->marcory));
        $this->assertFalse($ref($actif, $this->plateau), 'Servi à Marcory seulement : caché au Plateau.');
        $this->assertTrue($ref($neuf, $this->marcory), 'Jamais servi : reste partout.');
        $this->assertTrue($ref($neuf, $this->plateau));
    }
}
