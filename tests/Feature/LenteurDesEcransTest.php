<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * « L'application est très lente : une simple page met une trentaine de
 * secondes. » — le propriétaire, 06/10/2026.
 *
 * Mesuré le jour même sur le jeu de démonstration (10 000 articles) :
 *
 * | Écran | Avant | Après |
 * |---|---|---|
 * | Articles & stock | 51 517 ms, 66 146 requêtes | ~2 700 ms, 28 requêtes |
 * | Nouvelle vente (caisse) | 13 949 ms, 20 020 requêtes | ~3 700 ms, 32 requêtes |
 *
 * Et l'écran de stock **écrivait** : afficher « Tous les sites » posait la
 * somme des sites dans la fiche du site actif.
 */
class LenteurDesEcransTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $siteA;
    private PointDeVente $siteB;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Boutique du Plateau', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-1', 'ncc' => '1234567A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);

        $this->siteA = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Site A', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->siteB = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Site B', 'ville' => 'Abidjan', 'commune' => 'Cocody']);

        $this->admin = Utilisateur::create([
            'nom' => 'Koné', 'prenom' => 'Awa', 'email' => 'awa-lenteur@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->siteA->id,
        ]);
    }

    private function unCatalogue(int $nombre): void
    {
        for ($i = 1; $i <= $nombre; $i++) {
            $p = Produit::create([
                'entreprise_id' => $this->entreprise->id, 'reference' => "ART-{$i}", 'nom' => "Article {$i}",
                'type' => 'marchandise', 'prix_achat' => 100, 'prix_vente' => 150, 'taux_tva' => 18,
            ]);
            Stock::create(['produit_id' => $p->id, 'point_de_vente_id' => $this->siteA->id, 'quantite_disponible' => 10, 'stock_minimum' => 2]);
            Stock::create(['produit_id' => $p->id, 'point_de_vente_id' => $this->siteB->id, 'quantite_disponible' => 20, 'stock_minimum' => 3]);
        }
    }

    private function requetesPour(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->siteA->id])
            ->get($url)
            ->assertOk();

        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_afficher_tous_les_sites_ne_modifie_aucune_fiche_de_stock(): void
    {
        $this->unCatalogue(3);

        $avant = Stock::orderBy('id')->get(['id', 'quantite_disponible', 'stock_minimum'])->toArray();

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->siteA->id])
            ->get(route('admin.stock.index', ['point_de_vente_id' => 'tout']))
            ->assertOk()
            // La vue d'ensemble montre bien la somme des deux sites…
            ->assertSee('30');

        // … sans l'avoir écrite dans la fiche du site A, qui garde ses 10.
        $this->assertSame($avant, Stock::orderBy('id')->get(['id', 'quantite_disponible', 'stock_minimum'])->toArray());
        $this->assertSame(10.0, (float) Stock::where('point_de_vente_id', $this->siteA->id)->value('quantite_disponible'));
    }

    public function test_poser_le_stock_pour_l_affichage_n_ecrit_rien(): void
    {
        $this->unCatalogue(1);
        $produit = Produit::first();

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->siteA->id]);
        session(['point_de_vente_actif_id' => $this->siteA->id]);

        $produit->setAttribute('stock_actuel', 999);
        $produit->setAttribute('stock_minimum', 99);

        $this->assertSame(999.0, $produit->stock_actuel);
        $this->assertSame(10.0, (float) Stock::where('produit_id', $produit->id)->where('point_de_vente_id', $this->siteA->id)->value('quantite_disponible'));
        $this->assertSame(2.0, (float) Stock::where('produit_id', $produit->id)->where('point_de_vente_id', $this->siteA->id)->value('stock_minimum'));
    }

    public function test_le_nombre_de_requetes_du_stock_ne_croit_pas_avec_le_catalogue(): void
    {
        $this->unCatalogue(3);
        $petit = $this->requetesPour(route('admin.stock.index'));

        $this->unCatalogue(30);
        $grand = $this->requetesPour(route('admin.stock.index'));

        // Avant : quatre sommes, deux lectures de fiche et une catégorie par
        // article. Trente articles de plus en coûtaient plus de deux cents.
        $this->assertLessThanOrEqual($petit + 3, $grand, "Petit catalogue : {$petit} requêtes, grand : {$grand}.");
    }

    public function test_le_nombre_de_requetes_de_la_caisse_ne_croit_pas_avec_le_catalogue(): void
    {
        $this->unCatalogue(3);
        $petit = $this->requetesPour(route('admin.ventes.nouvelle'));

        $this->unCatalogue(30);
        $grand = $this->requetesPour(route('admin.ventes.nouvelle'));

        $this->assertLessThanOrEqual($petit + 3, $grand, "Petit catalogue : {$petit} requêtes, grand : {$grand}.");
    }
}
