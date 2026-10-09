<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * L'entrée de stock sans achat saisi : achat sans facture, retour, don.
 *
 * Avant elle, la seule porte était l'inventaire physique, qui faisait entrer la
 * marchandise au coût moyen du moment et l'appelait « écart ». Ici, elle entre
 * à son coût réel, le CUMP (Coût Unitaire Moyen Pondéré) se recalcule, et
 * l'écriture de stock dit pourquoi.
 */
class EntreeDeStockTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $magasin;
    private Produit $riz;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom'               => 'Boutique du carrefour',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Cocody, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00002',
            'ncc'               => '2601235B',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'OD', 'intitule' => 'Opérations diverses', 'type' => 'OD',
        ]);

        $vivres = Categorie::create([
            'entreprise_id'    => $this->entreprise->id,
            'nom'              => 'Vivres et alimentation',
            'prefixe'          => 'VIV',
            'compte_vente'     => '701000',
            'compte_achat'     => '601000',
            'compte_stock'     => '311000',
            'compte_variation' => '603100',
        ]);

        $this->riz = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'VIV-001',
            'nom' => 'Riz sac 25 kg', 'type' => 'marchandise', 'unite' => 'sac',
            'prix_achat' => 12000, 'prix_vente' => 15000,
            'categorie_id' => $vivres->id,
        ]);

        $admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    private function fiche(): Stock
    {
        return Stock::where('produit_id', $this->riz->id)
            ->where('point_de_vente_id', $this->magasin->id)
            ->firstOrFail();
    }

    private function entrer(array $lignes, string $raison = 'achat_sans_facture', ?string $commentaire = null)
    {
        return $this->post(route('admin.stock.entree.enregistrer'), [
            'point_de_vente_id' => $this->magasin->id,
            'raison_entree'     => $raison,
            'commentaire'       => $commentaire,
            'lignes'            => $lignes,
        ]);
    }

    public function test_l_ecran_s_ouvre_et_propose_les_articles_du_site(): void
    {
        $this->get(route('admin.stock.entree'))
            ->assertOk()
            ->assertSee('Entrée de stock')
            ->assertSee('Riz sac 25 kg')
            ->assertSee('Don reçu');
    }

    public function test_la_marchandise_entre_au_cout_reel_et_le_cout_moyen_se_recalcule(): void
    {
        // 10 sacs à 12 000, puis 10 sacs reçus sans facture à 14 000 :
        // le coût moyen passe à 13 000, et non au coût du moment.
        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION, ['cout_unitaire' => 12000]);

        $this->entrer([['produit_id' => $this->riz->id, 'quantite' => 10, 'cout_unitaire' => 14000]])
            ->assertRedirect()
            ->assertSessionHas('succes');

        $fiche = $this->fiche();
        $this->assertSame(20.0, (float) $fiche->quantite_disponible);
        $this->assertSame(13000.0, (float) $fiche->cump);

        $mouvement = MouvementStock::where('sous_type', MouvementStock::ENTREE_DIVERSE)->firstOrFail();
        $this->assertSame(MouvementStock::ENTREE, $mouvement->type_mouvement);
        $this->assertSame('achat_sans_facture', $mouvement->raison_entree);
        $this->assertSame(14000.0, (float) $mouvement->cout_unitaire);
        $this->assertStringStartsWith('ENT-', $mouvement->reference_document);
    }

    public function test_l_entree_passe_son_ecriture_de_stock_avec_sa_raison(): void
    {
        $this->entrer([['produit_id' => $this->riz->id, 'quantite' => 5, 'cout_unitaire' => 10000]], 'don', 'Don de la mairie');

        $debit = EcritureComptable::withoutGlobalScopes()->where('compte_debit', '311000')->firstOrFail();
        $credit = EcritureComptable::withoutGlobalScopes()->where('compte_credit', '603100')->firstOrFail();

        $this->assertSame(50000.0, (float) $debit->debit);
        $this->assertSame(50000.0, (float) $credit->credit);
        $this->assertStringContainsString('Entrée de stock (don reçu)', $debit->libelle);
        $this->assertSame('Don de la mairie', MouvementStock::firstOrFail()->commentaire);
    }

    public function test_un_cout_nul_est_refuse(): void
    {
        // Un coût oublié ferait chuter le coût moyen de tout le stock en place.
        $this->entrer([['produit_id' => $this->riz->id, 'quantite' => 5, 'cout_unitaire' => 0]])
            ->assertSessionHasErrors('lignes.0.cout_unitaire');

        $this->assertSame(0, MouvementStock::count());
    }

    public function test_la_raison_autre_exige_un_commentaire(): void
    {
        $this->entrer([['produit_id' => $this->riz->id, 'quantite' => 5, 'cout_unitaire' => 9000]], 'autre')
            ->assertSessionHasErrors('commentaire');

        $this->assertSame(0, MouvementStock::count());
    }

    public function test_l_article_d_une_autre_entreprise_est_refuse(): void
    {
        $voisine = Entreprise::create(['nom' => 'Voisine']);
        $etranger = Produit::create([
            'entreprise_id' => $voisine->id, 'reference' => 'X-1', 'nom' => 'Article voisin',
            'type' => 'marchandise', 'unite' => 'u', 'prix_achat' => 100, 'prix_vente' => 150,
        ]);

        $this->entrer([['produit_id' => $etranger->id, 'quantite' => 5, 'cout_unitaire' => 100]])
            ->assertSessionHasErrors('lignes.0.produit_id');

        $this->assertSame(0, MouvementStock::count());
    }

    public function test_les_mouvements_montrent_l_entree_et_sa_raison(): void
    {
        $this->entrer([['produit_id' => $this->riz->id, 'quantite' => 2, 'cout_unitaire' => 12000]], 'retour', 'Dépôt rendu');

        $this->get(route('admin.stock.mouvements', ['section' => 'entrees']))
            ->assertOk()
            ->assertSee('Entrée de stock')
            ->assertSee('Retour de marchandise')
            ->assertSee('Dépôt rendu');
    }
}
