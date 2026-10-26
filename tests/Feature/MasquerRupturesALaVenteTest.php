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
 * Masquer à la vente les articles en rupture (26/10/2026) : un réglage de
 * l'entreprise, éteint par défaut. Coché, le vendeur ne voit plus le stockable
 * à zéro sur son site ; les prestations restent ; l'administrateur voit tout.
 */
class MasquerRupturesALaVenteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Utilisateur $vendeur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entreprise = Entreprise::create([
            'nom' => 'Épicerie', 'modules_actifs' => ['principal', 'ventes', 'stock', 'produits', 'tiers', 'points_de_vente'],
            'normalisation_auto_factures' => false, 'normalisation_auto_recus' => false,
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Comptoir', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $sucre = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SUC', 'nom' => 'Sucre en morceaux', 'type' => 'marchandise',
            'unite' => 'paquet', 'prix_achat' => 500, 'prix_vente' => 800, 'taux_tva' => 18,
        ]);
        StockService::entree($sucre, $this->site->id, 5, MouvementStock::RECEPTION, ['cout_unitaire' => 500]);
        $lait = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'LAI', 'nom' => 'Lait concentré', 'type' => 'marchandise',
            'unite' => 'boîte', 'prix_achat' => 400, 'prix_vente' => 600, 'taux_tva' => 18,
        ]);
        StockService::entree($lait, $this->site->id, 2, MouvementStock::RECEPTION, ['cout_unitaire' => 400]);
        StockService::sortie($lait, $this->site->id, 2, MouvementStock::LIVRAISON);
        Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV', 'nom' => 'Livraison à domicile', 'type' => 'service',
            'prix_achat' => 0, 'prix_vente' => 1000, 'taux_tva' => 18,
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@epicerie.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
        ]);
        $this->vendeur = Utilisateur::create([
            'nom' => 'V', 'prenom' => 'W', 'email' => 'vendeur@epicerie.ci', 'password' => bcrypt('x'), 'role' => 'caissier',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
            'habilitations' => ['nouvelle_vente'],
        ]);
    }

    private function caisse(Utilisateur $qui, string $prefixe): string
    {
        return $this->actingAs($qui)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->get(route($prefixe . '.ventes.nouvelle'))->assertOk()->getContent();
    }

    public function test_sans_le_reglage_le_vendeur_voit_tout(): void
    {
        $page = $this->caisse($this->vendeur, 'caissier');
        $this->assertStringContainsString('Lait concentré', $page);
        $this->assertStringContainsString('Sucre en morceaux', $page);
    }

    public function test_avec_le_reglage_le_vendeur_ne_voit_plus_la_rupture_mais_garde_les_prestations(): void
    {
        $this->entreprise->update(['masquer_ruptures_vente' => true]);

        $page = $this->caisse($this->vendeur, 'caissier');
        $this->assertStringNotContainsString('Lait concentré', $page);
        $this->assertStringContainsString('Sucre en morceaux', $page);
        $this->assertStringContainsString('Livraison à domicile', $page);

    }

    public function test_l_administrateur_voit_toujours_tout(): void
    {
        $this->entreprise->update(['masquer_ruptures_vente' => true]);

        $page = $this->caisse($this->admin, 'admin');
        $this->assertStringContainsString('Lait concentré', $page);
    }

    public function test_le_reglage_se_coche_dans_les_parametres(): void
    {
        $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('Masquer les articles en rupture à la vente');
    }
}
