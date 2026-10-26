<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\ConfigurationCompte;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\ImputationService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La configuration globale des comptes — section 6 du plan, et l'ordre de
 * priorité du chantier 5.2 : exception de l'article, configuration globale
 * (type, catégorie, général), famille, défaut.
 */
class ConfigurationGlobaleDesComptesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private Categorie $boissons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Boutique', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan', 'rccm' => 'CI-1',
            'ncc' => '1234567A', 'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite', 'points_de_vente'],
        ]);
        $this->entreprise->forceFill(['comptabilite_activee' => true])->save();
        $site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Site', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'config-comptes@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        $this->boissons = Categorie::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Boissons', 'prefixe' => 'BOI',
            'compte_vente' => '701000', 'compte_achat' => '601000', 'compte_stock' => '311000', 'compte_variation' => '603100',
        ]);
        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id]);
    }

    private function article(array $ajouts = []): Produit
    {
        return Produit::create(array_merge([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'A-' . uniqid(), 'nom' => 'Eau',
            'type' => 'marchandise', 'categorie_id' => $this->boissons->id,
            'prix_achat' => 100, 'prix_vente' => 150, 'taux_tva' => 18,
            'compte_vente' => '701000', 'compte_achat' => '601000',
        ], $ajouts));
    }

    private function configurer(array $config, array $stock = [])
    {
        return $this->put(route('admin.comptabilite.configuration.enregistrer'), ['config' => $config, 'stock' => $stock]);
    }

    public function test_sans_configuration_rien_ne_change(): void
    {
        $this->assertSame('701000', ImputationService::compteVente($this->article()));
        $this->assertSame('706000', ImputationService::compteVente($this->article(['compte_vente' => '706000'])));
    }

    public function test_la_configuration_generale_s_applique_aux_articles_qui_heritent(): void
    {
        $this->configurer(['general:' => ['compte_vente' => '702000', 'compte_achat' => '602000']])->assertSessionHasNoErrors();

        $article = $this->article();
        $this->assertSame('702000', ImputationService::compteVente($article));
        $this->assertSame('602000', ImputationService::compteAchat($article));
    }

    public function test_le_plus_precis_l_emporte_et_l_exception_passe_devant_tout(): void
    {
        $this->configurer([
            'general:'                              => ['compte_vente' => '702000'],
            'categorie:' . $this->boissons->id      => ['compte_vente' => '701200'],
            'type:service'                          => ['compte_vente' => '706000'],
        ]);

        $this->assertSame('701200', ImputationService::compteVente($this->article()));
        $this->assertSame('706000', ImputationService::compteVente($this->article(['type' => 'service'])));
        // Une exception portée par l'article lui-même.
        $this->assertSame('707100', ImputationService::compteVente($this->article(['compte_vente' => '707100'])));
    }

    public function test_une_categorie_d_une_autre_entreprise_est_ignoree(): void
    {
        $autre = Entreprise::create(['nom' => 'Autre']);
        $sienne = Categorie::create(['entreprise_id' => $autre->id, 'nom' => 'X', 'prefixe' => 'X']);

        $this->configurer(['categorie:' . $sienne->id => ['compte_vente' => '702000']], [
            $sienne->id => ['compte_stock' => '321000'],
        ]);

        $this->assertSame(0, ConfigurationCompte::count());
        $this->assertNull($sienne->fresh()->compte_stock);
    }

    public function test_les_comptes_de_stock_se_lisent_et_se_corrigent(): void
    {
        $this->get(route('admin.comptabilite.configuration'))->assertOk()
            ->assertSee('Variations de stock')->assertSee('311000');

        $this->configurer([], [$this->boissons->id => ['compte_stock' => '311200', 'compte_variation' => '603100']])
            ->assertSessionHasNoErrors();

        $this->assertSame('311200', $this->boissons->fresh()->compte_stock);
    }

    public function test_decocher_l_exception_rend_l_article_a_l_heritage(): void
    {
        $this->configurer(['general:' => ['compte_vente' => '702000', 'compte_achat' => '602000']]);
        $article = $this->article(['compte_vente' => '707100', 'type' => 'service', 'prix_achat' => 0]);

        $this->put(route('admin.produits.modifier', $article), [
            'nom' => 'Eau', 'type' => 'service', 'categorie_id' => (string) $this->boissons->id,
            'prix_vente' => 150, 'taux_tva' => 18, 'stock_minimum' => 0,
        ])->assertSessionHasNoErrors();

        $article->refresh();
        $this->assertSame('702000', $article->compte_vente);
        $this->assertSame('702000', ImputationService::compteVente($article));
    }

    public function test_la_fiche_dit_ce_dont_l_article_herite(): void
    {
        $this->configurer(['general:' => ['compte_vente' => '702000']]);
        $article = $this->article();

        $this->get(route('admin.produits.fiche', $article))->assertOk()
            ->assertSee('Hérite de la configuration globale')
            ->assertSee('702000')
            ->assertSee('name="comptes_personnalises"', false);

        $this->get(route('admin.produits.index'))->assertOk()->assertSee('Hérite de la configuration globale');
    }

    public function test_la_page_n_existe_pas_quand_la_comptabilite_est_eteinte(): void
    {
        $this->entreprise->forceFill(['comptabilite_activee' => false])->save();
        $this->admin->refresh();

        $this->get(route('admin.comptabilite.configuration'))->assertNotFound();
    }

    /* Retour du 16/10/2026 : une section « Articles » à côté des types. */
    public function test_la_section_articles_pose_et_retire_l_exception_d_un_article(): void
    {
        $eau = $this->article(['nom' => 'Eau minérale']);
        $autre = Produit::create([
            'entreprise_id' => Entreprise::create(['nom' => 'Autre'])->id, 'reference' => 'X-1', 'nom' => 'Étranger',
            'type' => 'marchandise', 'prix_achat' => 1, 'prix_vente' => 2, 'taux_tva' => 18,
        ]);

        $this->get(route('admin.comptabilite.configuration'))->assertOk()
            ->assertSee('Par article')->assertSee('Eau minérale')->assertDontSee('Étranger');

        $this->put(route('admin.comptabilite.configuration.enregistrer'), [
            'article' => [
                $eau->id   => ['compte_vente' => '707200'],
                $autre->id => ['compte_vente' => '707900'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('707200', ImputationService::compteVente($eau->fresh()));
        $this->assertSame('601000', $eau->fresh()->compte_achat, 'un champ non envoyé reste tel quel');
        $this->assertNull($autre->fresh()->compte_vente);

        $this->put(route('admin.comptabilite.configuration.enregistrer'), [
            'article' => [$eau->id => ['compte_vente' => '']],
        ]);
        $this->assertSame('701000', ImputationService::compteVente($eau->fresh()));

        $this->put(route('admin.comptabilite.configuration.enregistrer'), [
            'article' => [$eau->id => ['compte_vente' => '601000']],
        ])->assertSessionHasErrors('article.' . $eau->id . '.compte_vente');
    }

    /**
     * 26/10/2026 : un seul tableau par niveau — vente, achat et variation de
     * stock côte à côte, « Proposé » et « Personnalisé ». Le type reçoit son
     * compte proposé ; la variation se personnalise par type, par catégorie
     * et par article, et le personnalisé l'emporte.
     */
    public function test_la_page_montre_propose_et_personnalise_avec_la_variation(): void
    {
        $this->article(['nom' => 'Jus de bissap']);

        $page = $this->get(route('admin.comptabilite.configuration'))->assertOk()->getContent();
        $this->assertStringContainsString('Proposé', $page);
        $this->assertStringContainsString('Personnalisé', $page);
        $this->assertStringContainsString('Variation de stock', $page);
        // Le proposé du type « Produit fini » : 702000 à la vente, 736000 en variation.
        $this->assertStringContainsString('name="config[type:produit_fini][compte_variation]"', $page);
        $this->assertStringContainsString('736000', $page);
        // Un service ne se stocke pas : pas de variation à régler.
        $this->assertStringNotContainsString('name="config[type:service][compte_variation]"', $page);
        // La catégorie stockée porte sa variation, l'article stockable aussi.
        $this->assertStringContainsString('name="config[categorie:' . $this->boissons->id . '][compte_variation]"', $page);
        $this->assertStringContainsString('[compte_variation]" value=""', $page);
    }

    public function test_la_variation_personnalisee_l_emporte_sur_la_famille(): void
    {
        $eau = $this->article();
        $this->assertSame('603100', ImputationService::compteVariation($eau));

        $this->configurer(['categorie:' . $this->boissons->id => ['compte_variation' => '603110']])->assertSessionHasNoErrors();
        $this->assertSame('603110', ImputationService::compteVariation($eau->fresh()));

        // Le type passe devant la catégorie.
        $this->configurer(['type:marchandise' => ['compte_variation' => '603120']])->assertSessionHasNoErrors();
        $this->assertSame('603120', ImputationService::compteVariation($eau->fresh()));

        // L'article personnalisé passe devant tout ; vidé, il reprend l'héritage.
        $this->put(route('admin.comptabilite.configuration.enregistrer'), [
            'article' => [$eau->id => ['compte_variation' => '603190']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('603190', ImputationService::compteVariation($eau->fresh()));
        $this->put(route('admin.comptabilite.configuration.enregistrer'), [
            'article' => [$eau->id => ['compte_variation' => '']],
        ]);
        $this->assertSame('603120', ImputationService::compteVariation($eau->fresh()));

        // Un compte de variation qui n'en est pas un est refusé.
        $this->configurer(['type:marchandise' => ['compte_variation' => '701000']])
            ->assertSessionHasErrors('config.type:marchandise.compte_variation');
    }

    public function test_enregistrer_la_vente_ne_perd_pas_la_variation_deja_personnalisee(): void
    {
        $this->configurer(['categorie:' . $this->boissons->id => ['compte_variation' => '603110']]);
        // Le formulaire d'un autre onglet n'envoie que la vente.
        $this->configurer(['categorie:' . $this->boissons->id => ['compte_vente' => '701200']]);

        $ligne = ConfigurationCompte::where('portee', 'categorie')->sole();
        $this->assertSame('701200', $ligne->compte_vente);
        $this->assertSame('603110', $ligne->compte_variation);

        // Tout vidé : la ligne part.
        $this->configurer(['categorie:' . $this->boissons->id => ['compte_vente' => '', 'compte_achat' => '', 'compte_variation' => '']]);
        $this->assertSame(0, ConfigurationCompte::count());
    }
}
