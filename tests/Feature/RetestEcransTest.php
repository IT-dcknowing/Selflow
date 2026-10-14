<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retest de production, octobre 2026 : les écrans.
 *
 *  - une vente refusée (stock insuffisant) rechargeait la page sans un mot :
 *    le refus partait sous la clé `error`, que le gabarit n'affiche pas ;
 *  - la vente enregistrée ne s'annonçait pas assez clairement ;
 *  - la recherche du catalogue ne voyait que la page affichée ;
 *  - l'avatar cassé de qui n'a pas chargé de photo ;
 *  - la fusée de la bannière de configuration.
 */
class RetestEcransTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Axenova', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody, Abidjan',
            'rccm' => 'CI-ABJ-2026-B-00042', 'ncc' => '2601234A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Services'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);
        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);
        $this->admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Ève', 'email' => 'eve@axenova.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Konan Yao']);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function produit(string $nom, string $type = 'service', string $reference = null): Produit
    {
        static $rang = 0;
        $rang++;

        return Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => $reference ?? 'ART-' . $rang,
            'nom' => $nom, 'type' => $type, 'unite' => 'u',
            'prix_achat' => 1000, 'prix_vente' => 2000, 'taux_tva' => 18,
        ]);
    }

    private function vendre(Produit $produit): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('admin.ventes.nouvelle'))->post(route('admin.ventes.enregistrer'), [
            'client_id' => Client::firstOrFail()->id, 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Espèces', 'etape' => 'Facture', 'montant_paye' => 2360,
            'articles' => [['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire' => 2000, 'unite' => 'u']],
        ]);
    }

    // ── La vente ─────────────────────────────────────────────────────

    public function test_une_vente_refusee_pour_rupture_le_dit_a_l_ecran(): void
    {
        $riz = $this->produit('Riz parfumé 25 kg', 'marchandise');

        $this->vendre($riz)->assertRedirect(route('admin.ventes.nouvelle'));
        $this->assertSame(0, Vente::count());

        // La page suivante montre le refus : il était perdu sous `error`.
        $page = $this->get(route('admin.ventes.nouvelle'))->getContent();
        $this->assertStringContainsString('Stock insuffisant', $page);
    }

    public function test_la_vente_enregistree_s_annonce_trois_secondes_et_vide_le_panier(): void
    {
        $lavage = $this->produit('Lavage complet');

        $reponse = $this->vendre($lavage);
        $vente = Vente::firstOrFail();

        $reponse->assertSessionHas('succes', fn ($m) => str_contains($m, $vente->numero_facture))
            ->assertSessionHas('succes_duree', 3000);

        $page = $this->get($reponse->headers->get('Location'))->getContent();
        $this->assertStringContainsString('"duree":3000', $page);
        $this->assertStringContainsString("localStorage.removeItem('selflow_vente_panier')", $page);
    }

    // ── Le catalogue ─────────────────────────────────────────────────

    public function test_la_recherche_voit_tout_le_catalogue_et_non_la_page_affichee(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->produit(sprintf('Article %02d', $i));
        }
        $this->produit('Zinc ondulé', 'service', 'ZINC-01');

        // Trié par nom, « Zinc » tombe en page 2 : la recherche le trouve.
        $this->get(route('admin.produits.index'))->assertDontSee('Zinc ondulé');
        $this->get(route('admin.produits.index', ['q' => 'zinc']))
            ->assertOk()->assertSee('Zinc ondulé')->assertDontSee('Article 01');
        $this->get(route('admin.produits.index', ['q' => 'ZINC-01']))->assertSee('Zinc ondulé');
    }

    public function test_les_filtres_restent_dans_la_pagination(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->produit(sprintf('Article %02d', $i));
        }

        $html = $this->get(route('admin.produits.index', ['q' => 'Article']))->getContent();
        $this->assertMatchesRegularExpression('/[?&;]q=Article[^"]*page=2|page=2[^"]*q=Article/', $html);
    }

    // ── L'avatar ─────────────────────────────────────────────────────

    public function test_sans_photo_l_avatar_est_fait_des_initiales_accentuees(): void
    {
        $url = $this->admin->avatar_url;

        $this->assertStringStartsWith('data:image/svg+xml', $url);
        $svg = rawurldecode(substr($url, strlen('data:image/svg+xml;utf8,')));
        $this->assertTrue(mb_check_encoding($svg, 'UTF-8'), "Une initiale accentuée coupée rendait l'image illisible.");
        $this->assertStringContainsString('>ÈA<', $svg);
    }

    public function test_une_photo_google_ou_un_fichier_disparu_retombe_sur_les_initiales(): void
    {
        // La politique de sécurité refuse les images d'un autre domaine.
        $this->admin->avatar_path = 'https://lh3.googleusercontent.com/a/photo';
        $this->assertStringStartsWith('data:image/svg+xml', $this->admin->avatar_url);

        $this->admin->avatar_path = 'avatars/effacee.png';
        $this->assertStringStartsWith('data:image/svg+xml', $this->admin->avatar_url);
    }

    public function test_l_image_du_profil_a_un_repli_si_elle_ne_se_charge_pas(): void
    {
        $html = $this->get(route('admin.produits.index'))->getContent();

        $this->assertStringContainsString('this.onerror=null; this.src=', $html);
        $this->assertStringNotContainsString('alt="Avatar"', $html);
    }

    // ── La bannière ──────────────────────────────────────────────────

    public function test_la_banniere_de_configuration_n_a_plus_de_fusee(): void
    {
        $html = $this->get(route('admin.produits.index'))->getContent();

        $this->assertStringContainsString('Configurez votre métier en cinq minutes', $html);
        $this->assertStringNotContainsString('&#128640;', $html);
        $this->assertStringNotContainsString('🚀', $html);
    }

    // ── Le parcours de configuration ─────────────────────────────────

    public function test_choisir_le_domaine_mene_a_l_etape_suivante_sans_continuer(): void
    {
        $this->seed(\Database\Seeders\ReferentielSeeder::class);

        $html = $this->get(route('admin.souscription.index', ['etape' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('data-etape-suivante', $html);
        $this->assertStringContainsString("getElementById('form-souscription')", $html);
        $this->assertStringContainsString('id="form-souscription"', $html);
    }
}
