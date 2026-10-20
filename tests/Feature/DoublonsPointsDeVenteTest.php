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
 * Retour du 20/10/2026 : « GEUDE AYA JAQUELINE » et « GUEDE AYA JACQUELINE »,
 * deux points de vente pour un seul. Chaque site a une clé d'identité ; un
 * nom identique est refusé, un nom très proche demande confirmation.
 */
class DoublonsPointsDeVenteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entreprise = Entreprise::create(['nom' => 'poissonnerie LA GRACE', 'quota_points_de_vente' => 10,
            'modules_actifs' => ['principal', 'ventes', 'stock', 'points_de_vente']]);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'H', 'email' => 'admin@lagrace.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
        ]);
    }

    private function creer(string $nom, bool $confirmer = false)
    {
        return $this->actingAs($this->admin)->post(route('admin.pdv.creer'), array_filter([
            'nom' => $nom, 'ville' => 'Abidjan', 'confirmer_distinct' => $confirmer ? 1 : null,
        ]));
    }

    public function test_le_meme_nom_ecrit_autrement_est_refuse(): void
    {
        $this->creer('GUEDE AYA JACQUELINE')->assertSessionHasNoErrors();
        $this->creer('Guede  Aya-Jacqueline')->assertSessionHasErrors('nom');
        $this->creer('PDV GUEDE AYA JACQUELINE', true)->assertSessionHasErrors('nom');

        $this->assertSame(1, PointDeVente::where('entreprise_id', $this->entreprise->id)->count());
    }

    public function test_un_nom_tres_proche_demande_confirmation(): void
    {
        $this->creer('GUEDE AYA JACQUELINE');

        $this->creer('GEUDE AYA JAQUELINE')->assertSessionHasErrors('nom')->assertSessionHas('pdv_doublon_possible');
        $this->assertSame(1, PointDeVente::count());

        $this->creer('GEUDE AYA JAQUELINE', true)->assertSessionHasNoErrors();
        $this->assertSame(2, PointDeVente::count());
    }

    public function test_des_sites_numerotes_ou_simplement_voisins_restent_distincts(): void
    {
        $this->creer('Boutique 1')->assertSessionHasNoErrors();
        $this->creer('Boutique 2')->assertSessionHasNoErrors();
        $this->creer('PDV-Bondoukou')->assertSessionHasNoErrors();
        $this->creer('PDV-Bongouanou')->assertSessionHasNoErrors();

        $this->assertSame(4, PointDeVente::count());
        $this->assertSame(4, PointDeVente::distinct()->count('cle_nom'));
    }

    public function test_la_fusion_ramene_les_pieces_et_additionne_le_stock(): void
    {
        $bon = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'GUEDE AYA JACQUELINE', 'ville' => 'Abidjan', 'commune' => '']);
        $faux = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'GEUDE AYA JAQUELINE', 'ville' => 'Abidjan', 'commune' => '']);
        $poisson = Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'PO-1', 'nom' => 'PELON 27KG', 'type' => 'marchandise',
            'unite' => 'carton', 'prix_achat' => 1000, 'prix_vente' => 1500, 'taux_tva' => 18]);
        $this->actingAs($this->admin);
        StockService::entree($poisson, $bon->id, 4, MouvementStock::RECEPTION, ['cout_unitaire' => 1000]);
        StockService::entree($poisson, $faux->id, 6, MouvementStock::RECEPTION, ['cout_unitaire' => 2000]);
        $caissier = Utilisateur::create(['nom' => 'C', 'prenom' => 'D', 'email' => 'c@lagrace.ci', 'password' => bcrypt('x'),
            'role' => 'caissier', 'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $faux->id]);

        $this->artisan('selflow:doublons-points-de-vente', ['entreprise' => $this->entreprise->id])
            ->expectsOutputToContain('--fusionner')->assertSuccessful();
        $this->artisan('selflow:doublons-points-de-vente', ['--fusionner' => $faux->id, '--vers' => $bon->id])->assertSuccessful();
        $this->assertNotNull(PointDeVente::find($faux->id), 'Sans --appliquer, rien ne change.');

        $this->artisan('selflow:doublons-points-de-vente', ['--fusionner' => $faux->id, '--vers' => $bon->id, '--appliquer' => true])->assertSuccessful();

        $this->assertNull(PointDeVente::find($faux->id));
        $fiche = Stock::where('produit_id', $poisson->id)->where('point_de_vente_id', $bon->id)->firstOrFail();
        $this->assertEqualsWithDelta(10, (float) $fiche->quantite_disponible, 0.001);
        $this->assertEqualsWithDelta(1600, (float) $fiche->cump, 0.01);
        $this->assertSame($bon->id, (int) $caissier->fresh()->point_de_vente_id);
        $this->assertSame(0, MouvementStock::where('point_de_vente_id', $faux->id)->count());
    }

    public function test_siege_et_siege_accentue_sont_deux_noms_pour_la_dgi(): void
    {
        // La DGI compare à la lettre près : la correction d'un rejet crée à
        // dessein le point au nom exact du portail.
        $this->creer('FACTURATION SIEGE')->assertSessionHasNoErrors();
        $this->creer('FACTURATION SIÈGE')->assertSessionHasErrors('nom');
        $this->creer('FACTURATION SIÈGE', true)->assertSessionHasNoErrors();

        $this->assertSame(2, PointDeVente::distinct()->count('cle_nom'));
    }
}
