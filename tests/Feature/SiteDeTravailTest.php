<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retour du 19/10/2026 : le point de vente activé ne sert pas qu'à normaliser.
 * Ce qu'on saisit concerne le site actif ; un membre affecté à un site y est
 * lié ; sans site actif, la barre latérale le dit et mène à l'écran qui règle.
 */
class SiteDeTravailTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $boutique;
    private PointDeVente $depot;
    private Produit $riz;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Deux sites', 'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'points_de_vente'],
        ]);
        $this->boutique = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->depot = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Dépôt', 'ville' => 'Abidjan', 'commune' => 'Yopougon']);
        $this->riz = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'RIZ', 'nom' => 'Riz', 'type' => 'marchandise',
            'unite' => 'sac', 'prix_achat' => 10000, 'prix_vente' => 15000, 'taux_tva' => 18,
        ]);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@deuxsites.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
        ]);
    }

    private function stock(PointDeVente $site): float
    {
        return (float) Stock::where('produit_id', $this->riz->id)->where('point_de_vente_id', $site->id)->value('quantite_disponible');
    }

    public function test_le_membre_affecte_au_depot_ecrit_dans_le_depot_meme_s_il_vise_un_autre_site(): void
    {
        $magasinier = Utilisateur::create([
            'nom' => 'M', 'prenom' => 'N', 'email' => 'magasinier@deuxsites.ci', 'password' => bcrypt('x'),
            'role' => 'responsable_pdv', 'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->depot->id, 'habilitations' => ['stock_articles', 'stock_mouvements'],
        ]);

        $this->actingAs($magasinier)->withSession(['point_de_vente_actif_id' => $this->boutique->id])
            ->post(route('admin.stock.inventaire.enregistrer'), [
                'point_de_vente_id' => $this->boutique->id,
                'comptages' => [$this->riz->id => 12],
            ])->assertSessionHas('succes');

        $this->assertSame(12.0, $this->stock($this->depot));
        $this->assertSame(0.0, $this->stock($this->boutique));
        $this->assertSame($this->depot->id, (int) session('point_de_vente_actif_id'));
    }

    public function test_le_membre_affecte_ne_peut_pas_activer_un_autre_site(): void
    {
        $caissier = Utilisateur::create([
            'nom' => 'C', 'prenom' => 'D', 'email' => 'caissier@deuxsites.ci', 'password' => bcrypt('x'),
            'role' => 'responsable_pdv', 'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->boutique->id, 'habilitations' => ['gestion_pdv'],
        ]);

        $this->actingAs($caissier)->post(route('admin.pdv.activer', $this->depot))->assertSessionHas('erreur');
        $this->assertSame($this->boutique->id, (int) session('point_de_vente_actif_id'));
    }

    public function test_l_administrateur_ecrit_dans_le_site_qu_il_a_active(): void
    {
        $this->actingAs($this->admin)->post(route('admin.pdv.activer', $this->depot))->assertSessionHas('succes');

        $this->post(route('admin.stock.inventaire.enregistrer'), [
            'point_de_vente_id' => $this->depot->id,
            'comptages' => [$this->riz->id => 7],
        ])->assertSessionHas('succes');

        $this->assertSame(7.0, $this->stock($this->depot));
    }

    public function test_sans_site_actif_la_barre_propose_de_l_activer(): void
    {
        $this->actingAs($this->admin)->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('Aucun point de vente actif')->assertSee('Activer un point de vente')
            ->assertSee(route('admin.pdv.index'), false);
    }

    public function test_sans_aucun_site_la_barre_propose_d_en_creer_un(): void
    {
        PointDeVente::query()->delete();

        $this->actingAs($this->admin)->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('Créer un point de vente')->assertSee(route('admin.pdv.index', ['nouveau' => 1]), false);
    }

    public function test_un_site_d_une_autre_entreprise_reste_pas_en_session(): void
    {
        $autre = Entreprise::create(['nom' => 'Autre']);
        $etranger = PointDeVente::create(['entreprise_id' => $autre->id, 'nom' => 'Ailleurs', 'ville' => 'Bouaké', 'commune' => 'Centre']);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $etranger->id, 'point_de_vente_actif_nom' => 'Ailleurs'])
            ->get(route('admin.tableau_de_bord'))->assertOk()->assertDontSee('Ailleurs');
        $this->assertNull(session('point_de_vente_actif_id'));
    }

    public function test_le_site_actif_se_change_d_un_clic_dans_la_barre(): void
    {
        $this->actingAs($this->admin)->post(route('admin.pdv.activer', $this->depot));

        $this->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('class="sidebar-pdv sidebar-pdv-lien"', false)->assertSee('Changer de point de vente');
    }

    public function test_le_membre_affecte_n_a_pas_de_lien_pour_changer(): void
    {
        $caissier = Utilisateur::create([
            'nom' => 'C', 'prenom' => 'D', 'email' => 'c2@deuxsites.ci', 'password' => bcrypt('x'),
            'role' => 'responsable_pdv', 'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->boutique->id, 'habilitations' => ['gestion_pdv', 'tableau_de_bord_personnel'],
        ]);

        $this->actingAs($caissier)->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('Boutique')->assertDontSee('Changer de point de vente');
    }

    public function test_le_diagnostic_signale_une_entree_passee_hors_du_site_de_son_auteur(): void
    {
        $magasinier = Utilisateur::create([
            'nom' => 'M', 'prenom' => 'N', 'email' => 'm2@deuxsites.ci', 'password' => bcrypt('x'),
            'role' => 'responsable_pdv', 'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->depot->id,
        ]);

        // Ce qui pouvait arriver avant le lot 114 : une entrée sur la boutique.
        $this->actingAs($magasinier);
        \App\Modules\Admin\Services\StockService::entree($this->riz, $this->boutique->id, 5,
            \App\Modules\Admin\Modeles\MouvementStock::RECEPTION, ['cout_unitaire' => 10000]);

        $this->artisan('selflow:diagnostic-stock-sites', ['entreprise' => $this->entreprise->id])
            ->expectsOutputToContain('1 mouvement(s) passé(s) par un membre affecté')
            ->assertSuccessful();
    }
}
