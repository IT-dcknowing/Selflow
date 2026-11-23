<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\PropositionStock;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rien ne fuit d'un point de vente à l'autre (audit du 23/11/2026).
 *
 * L'audit a prouvé : l'API écrivait un mouvement sur le site d'une autre
 * entreprise (en-tête X-Point-De-Vente-Id non vérifié), montrait à un
 * responsable les mouvements de tous les sites, et la page « Proposition
 * entrée/sortie » laissait valider la sortie d'un autre site.
 */
class CloisonnementStockEntreSitesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;
    private PointDeVente $marcory;
    private PointDeVente $plateau;
    private Utilisateur $admin;
    private Utilisateur $responsable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e = Entreprise::create(['nom' => 'Deux magasins', 'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'points_de_vente']]);
        $this->marcory = PointDeVente::create(['entreprise_id' => $this->e->id, 'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory']);
        $this->plateau = PointDeVente::create(['entreprise_id' => $this->e->id, 'nom' => 'PDV-plateau', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->admin = Utilisateur::create(['nom' => 'A', 'prenom' => 'B', 'email' => 'a@x.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $this->e->id]);
        $this->responsable = Utilisateur::create(['nom' => 'R', 'prenom' => 'P', 'email' => 'r@x.ci', 'password' => bcrypt('x'), 'role' => 'responsable_pdv', 'statut' => 'actif',
            'entreprise_id' => $this->e->id, 'point_de_vente_id' => $this->marcory->id, 'habilitations' => ['stock_articles', 'stock_mouvements', 'catalogue_produits']]);
    }

    private function article(PointDeVente $site, string $nom, float $quantite): Produit
    {
        $a = Produit::create(['entreprise_id' => $this->e->id, 'reference' => strtoupper(substr($nom, 0, 3)) . uniqid(), 'nom' => $nom, 'type' => 'marchandise',
            'unite' => 'u', 'prix_achat' => 1000, 'prix_vente' => 1500, 'taux_tva' => 18]);
        StockService::entree($a, $site->id, $quantite, MouvementStock::ENTREE_DIVERSE, ['cout_unitaire' => 1000]);

        return $a;
    }

    public function test_l_api_refuse_le_site_d_une_autre_entreprise(): void
    {
        $autre = Entreprise::create(['nom' => 'Concurrent', 'modules_actifs' => ['principal', 'stock']]);
        $siteAutre = PointDeVente::create(['entreprise_id' => $autre->id, 'nom' => 'PDV-concurrent', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $a = $this->article($this->plateau, 'Riz', 10);
        $f = Fournisseur::create(['entreprise_id' => $this->e->id, 'nom' => 'F']);
        $this->admin->forceFill(['jeton_api' => hash('sha256', 'tok')])->save();

        $r = $this->withHeaders(['Authorization' => 'Bearer tok', 'X-Point-De-Vente-Id' => (string) $siteAutre->id, 'Accept' => 'application/json'])
            ->postJson('/api/admin/achats/enregistrer', ['fournisseur_id' => $f->id, 'date_achat' => now()->toDateString(), 'mode_paiement' => 'Crédit',
                'articles' => [['produit_id' => $a->id, 'quantite' => 3, 'prix_unitaire' => 100]]]);

        $this->assertSame(403, $r->status());
        $this->assertSame(0, MouvementStock::where('point_de_vente_id', $siteAutre->id)->count(), 'Aucun mouvement sur le site d’une autre entreprise.');
    }

    public function test_un_responsable_ne_voit_par_l_api_que_les_mouvements_de_son_site(): void
    {
        $this->article($this->plateau, 'Secret Plateau', 5);
        $this->article($this->marcory, 'Sucre Marcory', 5);
        $this->responsable->forceFill(['jeton_api' => hash('sha256', 'tok2')])->save();

        $r = $this->withHeaders(['Authorization' => 'Bearer tok2', 'X-Point-De-Vente-Id' => (string) $this->plateau->id, 'Accept' => 'application/json'])
            ->getJson('/api/admin/stock/mouvements')->assertOk();

        $this->assertStringNotContainsString('PDV-plateau', $r->getContent());
        $this->assertStringContainsString('PDV-marcory', $r->getContent());
    }

    public function test_une_proposition_d_un_autre_site_n_est_ni_visible_ni_validable(): void
    {
        $a = $this->article($this->plateau, 'Secret Plateau', 77);
        $prop = PropositionStock::create(['entreprise_id' => $this->e->id, 'point_de_vente_id' => $this->plateau->id, 'produit_id' => $a->id,
            'sens' => 'sortie', 'quantite' => 10, 'reference' => 'VTE-X', 'statut' => 'proposee']);

        $this->actingAs($this->responsable)->get(route('admin.stock.propositions'))->assertOk()->assertDontSee('Secret Plateau');
        $this->actingAs($this->responsable)->post(route('admin.stock.propositions.valider', $prop))->assertForbidden();

        $this->assertEqualsWithDelta(77, (float) Stock::where('produit_id', $a->id)->where('point_de_vente_id', $this->plateau->id)->value('quantite_disponible'), 0.001);
    }

    public function test_le_service_de_stock_refuse_un_site_d_une_autre_entreprise(): void
    {
        $autre = Entreprise::create(['nom' => 'Concurrent', 'modules_actifs' => ['principal', 'stock']]);
        $siteAutre = PointDeVente::create(['entreprise_id' => $autre->id, 'nom' => 'PDV-concurrent', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $a = $this->article($this->plateau, 'Riz', 1);

        $this->expectException(\DomainException::class);
        StockService::entree($a, $siteAutre->id, 5, MouvementStock::ENTREE_DIVERSE, ['cout_unitaire' => 1]);
    }

    public function test_une_livraison_d_un_autre_site_n_est_ni_listee_ni_ouvrable(): void
    {
        $a = $this->article($this->plateau, 'Secret Plateau', 10);
        $vente = Vente::create(['point_de_vente_id' => $this->plateau->id, 'numero_facture' => 'BC-PLATEAU', 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Crédit', 'montant_ht' => 1500, 'montant_tva' => 270, 'montant_ttc' => 1770, 'etape' => 'Bon de commande', 'statut' => 'Crédit']);
        VenteDetail::create(['vente_id' => $vente->id, 'produit_id' => $a->id, 'quantite' => 2, 'quantite_livree' => 0, 'prix_unitaire' => 1500, 'montant_tva' => 0, 'montant_ttc' => 3000]);

        $this->actingAs($this->responsable)->get(route('admin.stock.livraisons'))->assertOk()->assertDontSee('BC-PLATEAU');
        $this->actingAs($this->responsable)->get(route('admin.stock.livraisons.fiche', $vente))->assertForbidden();
        $this->actingAs($this->responsable)->post(route('admin.stock.livraisons.valider', $vente))->assertForbidden();
    }

    public function test_la_fiche_d_un_article_d_un_autre_site_est_fermee(): void
    {
        $a = $this->article($this->plateau, 'Secret Plateau', 10);
        Stock::where('produit_id', $a->id)->where('point_de_vente_id', '!=', $this->plateau->id)->update(['reference' => false]);

        $this->actingAs($this->responsable)->get(route('admin.produits.fiche', $a))->assertNotFound();
    }

    public function test_les_transferts_ne_montrent_pas_les_articles_d_un_autre_site(): void
    {
        $a = $this->article($this->plateau, 'Secret Plateau', 10);
        Stock::where('produit_id', $a->id)->where('point_de_vente_id', '!=', $this->plateau->id)->update(['reference' => false]);

        $this->actingAs($this->responsable)->get(route('admin.stock.transferts.index'))->assertOk()->assertDontSee('Secret Plateau');
    }
}
