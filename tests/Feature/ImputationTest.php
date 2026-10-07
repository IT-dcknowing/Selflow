<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\ImputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sur quel compte s'impute un article.
 *
 * La question se résolvait par une paire — le compte de l'article, ou le
 * défaut de configuration — et le niveau le plus utile, **le rayon**, sautait.
 * Un article créé à la main après la souscription n'héritait donc de rien et
 * tombait sur `701000` : la balance d'un magasin qui a soigneusement réparti
 * ses rayons n'avait qu'une seule ligne de ventes.
 */
class ImputationTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Categorie $boissons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Boutique du carrefour']);

        $this->boissons = Categorie::create([
            'entreprise_id'    => $this->entreprise->id,
            'nom'              => 'Boissons fraîches',
            'prefixe'          => 'BOI',
            'compte_vente'     => '701200',
            'compte_achat'     => '601200',
            'compte_stock'     => '311200',
            'compte_variation' => '603120',
        ]);
    }

    private function article(array $attributs = []): Produit
    {
        return Produit::create(array_merge([
            'entreprise_id' => $this->entreprise->id,
            'reference'     => 'ART-' . uniqid(),
            'nom'           => 'Sucrerie 33 cl',
            'type'          => 'marchandise',
            'prix_achat'    => 300,
            'prix_vente'    => 500,
            'categorie_id'  => $this->boissons->id,
        ], $attributs));
    }

    // ── Le rang 2 : le rayon ─────────────────────────────────────────

    public function test_un_article_sans_compte_herite_de_son_rayon(): void
    {
        // C'est le cas qui manquait : un article cree a la main apres la
        // souscription tombait sur le compte generique.
        $article = $this->article();

        $this->assertSame('701200', ImputationService::compteVente($article));
        $this->assertSame('601200', ImputationService::compteAchat($article));
        $this->assertSame('311200', ImputationService::compteStock($article));
        $this->assertSame('603120', ImputationService::compteVariation($article));
    }

    public function test_changer_le_compte_d_un_rayon_change_celui_de_ses_articles(): void
    {
        // C'est tout l'interet de porter l'imputation sur le rayon : un geste,
        // et non une fiche a rouvrir par article.
        $article = $this->article();

        $this->boissons->update(['compte_vente' => '701900']);

        $this->assertSame('701900', ImputationService::compteVente($article->fresh()));
    }

    // ── Le rang 1 : l'article ────────────────────────────────────────

    public function test_le_compte_de_l_article_prime_sur_celui_du_rayon(): void
    {
        // C'est l'exception que l'utilisateur assume : il l'a saisie expres.
        // Depuis le lot 42, c'est la case qui en fait une exception.
        $article = $this->article(['compte_vente' => '701700', 'comptes_personnalises' => true]);

        $this->assertSame('701700', ImputationService::compteVente($article));
        $this->assertSame('601200', ImputationService::compteAchat($article), 'Les autres comptes suivent le rayon.');
    }

    public function test_un_compte_rempli_d_espaces_ne_vaut_pas_imputation(): void
    {
        // Un import maladroit remplit une colonne d'espaces : la traiter comme
        // un compte imputerait la vente sur un numero vide.
        $article = $this->article(['compte_vente' => '   ', 'comptes_personnalises' => true]);

        $this->assertSame('701200', ImputationService::compteVente($article));
    }

    // ── Le rang 3 : le filet ─────────────────────────────────────────

    public function test_un_article_sans_rayon_tombe_sur_le_defaut(): void
    {
        $article = $this->article(['categorie_id' => null]);

        $this->assertSame(
            config('selflow.plan_comptable_defaut.vente_defaut'),
            ImputationService::compteVente($article)
        );
    }

    public function test_le_stock_n_a_pas_de_filet(): void
    {
        // Il n'existe pas de « compte de stock generique » qui voudrait dire
        // quelque chose : les marchandises vont en 31, les matieres en 32, les
        // produits finis en 36. Les confondre rendrait le bilan faux plutot
        // qu'imprecis.
        $article = $this->article(['categorie_id' => null]);

        $this->assertNull(ImputationService::compteStock($article));
        $this->assertNull(ImputationService::compteVariation($article));
    }

    // ── L'inventaire permanent ───────────────────────────────────────

    public function test_un_article_complet_peut_tenir_l_inventaire_permanent(): void
    {
        $this->assertTrue(ImputationService::peutTenirLInventairePermanent($this->article()));
    }

    public function test_il_faut_les_deux_comptes_et_pas_un_seul(): void
    {
        // Le stock sans la variation ecrirait une entree de bilan sans
        // contrepartie de gestion, et le desequilibre n'apparaitrait qu'a la
        // balance, des semaines plus tard.
        $this->boissons->update(['compte_variation' => null]);

        $this->assertFalse(ImputationService::peutTenirLInventairePermanent($this->article()->fresh()));
    }

    public function test_un_service_ne_tient_pas_d_inventaire(): void
    {
        $mission = $this->article(['type' => 'service']);

        $this->assertFalse(ImputationService::peutTenirLInventairePermanent($mission));
    }

    // ── Ce que les écrans doivent pouvoir dire ───────────────────────

    public function test_les_comptes_manquants_se_nomment(): void
    {
        // Un article mal impute ne se voit pas avant la balance, et a ce
        // moment-la le mois est passe.
        $orphelin = $this->article(['categorie_id' => null]);

        $manquants = ImputationService::manqueUnCompte($orphelin);

        $this->assertContains('compte de vente', $manquants);
        $this->assertContains('compte de stock', $manquants);
        $this->assertContains('compte de variation de stock', $manquants);
    }

    public function test_un_service_orphelin_ne_reclame_pas_de_compte_de_stock(): void
    {
        $mission = $this->article(['type' => 'service', 'categorie_id' => null]);

        $this->assertNotContains('compte de stock', ImputationService::manqueUnCompte($mission));
    }

    public function test_un_article_complet_ne_manque_de_rien(): void
    {
        $this->assertSame([], ImputationService::manqueUnCompte($this->article()));
    }

    // ── Lot 42 : la configuration globale, et la case ────────────────

    private function configurer(string $cle, ?string $vente, ?string $achat): void
    {
        \App\Modules\Admin\Modeles\ImputationGlobale::updateOrCreate(
            ['entreprise_id' => $this->entreprise->id, 'cle' => $cle],
            ['compte_vente' => $vente, 'compte_achat' => $achat]
        );
    }

    /**
     * L'ancien formulaire exigeait un compte sur chaque fiche : la colonne
     * est remplie partout, la plupart du temps de 701000 faute de mieux. La
     * lire comme un choix figeait l'article hors de toute configuration.
     */
    public function test_une_colonne_sans_la_case_n_est_pas_lue(): void
    {
        $article = $this->article(['compte_vente' => '701000']);

        $this->assertSame('701200', ImputationService::compteVente($article));
    }

    public function test_la_configuration_par_type_prime_sur_la_categorie(): void
    {
        $this->configurer('type:marchandise', '701500', null);

        $article = $this->article();

        $this->assertSame('701500', ImputationService::compteVente($article));
        $this->assertSame('601200', ImputationService::compteAchat($article), 'Un champ vide du type laisse parler la catégorie.');
    }

    public function test_la_configuration_generale_vient_apres_la_categorie(): void
    {
        $this->configurer('general', '706000', '604000');

        $this->assertSame('701200', ImputationService::compteVente($this->article()));
        $this->assertSame('706000', ImputationService::compteVente($this->article(['categorie_id' => null])));
        $this->assertSame('604000', ImputationService::compteAchat($this->article(['categorie_id' => null])));
    }

    public function test_l_exception_prime_sur_toute_la_configuration(): void
    {
        $this->configurer('general', '706000', '604000');
        $this->configurer('type:marchandise', '701500', '601500');

        $article = $this->article(['compte_vente' => '701700', 'compte_achat' => '601700', 'comptes_personnalises' => true]);

        $this->assertSame('701700', ImputationService::compteVente($article));
        $this->assertSame(['compte_vente' => '701500', 'compte_achat' => '601500'], ImputationService::heritage($article),
            'La fiche montre ce dont l\'article hériterait sans sa case.');
    }

    public function test_modifier_la_configuration_se_voit_aussitot(): void
    {
        $article = $this->article(['categorie_id' => null]);
        $this->assertSame('701000', ImputationService::compteVente($article));

        $this->configurer('general', '706000', null);

        $this->assertSame('706000', ImputationService::compteVente($article));
    }

    public function test_le_stock_ne_passe_pas_par_la_configuration_globale(): void
    {
        $this->configurer('general', '706000', '604000');

        $this->assertNull(ImputationService::compteStock($this->article(['categorie_id' => null])));
    }

    public function test_les_tiers_portent_leur_collectif(): void
    {
        $client = \App\Modules\Admin\Modeles\Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Koné']);
        $client->compte_comptable = '';

        $this->assertSame('411000', ImputationService::compteClient($client), 'Une chaîne vide n\'est pas un compte.');
        $this->assertSame('411000', ImputationService::compteClient(null));
        $this->assertSame('401000', ImputationService::compteFournisseur(null));
    }
}
