<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\IllustrationArticleService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Un article sans photo montre au moins de quelle nature il est.
 *
 * Le catalogue et l'écran de caisse posaient le **même sac gris** sous chaque
 * article : trente cartes identiques, où seul le texte séparait un sac de riz
 * d'une prestation de conseil. Sur un écran de caisse, on cherche l'article à
 * sa forme avant de lire son nom.
 *
 * Ce ne sont **pas** des photos : ce sont des dessins au trait tenus dans le
 * dépôt, choisis d'après ce que l'article dit de lui-même. Aller chercher sur
 * internet « une bouteille qui ressemble à la vôtre » montrerait une
 * marchandise que l'entreprise ne vend pas. La vraie photo passe toujours
 * devant.
 *
 * **Le 25/09/2026, le propriétaire a fait retirer le filigrane.** D'abord de
 * l'écran de caisse : la même silhouette revenait sur des articles sans
 * rapport, et la grille en paraissait salie. Puis du catalogue le même jour,
 * en constatant que les deux écrans ne se ressemblaient plus — « si c'est
 * retiré là-bas, ici doit s'appliquer ».
 *
 * Un article sans photo garde donc un cadre net, avec l'icône d'un appareil
 * photo qui dit ce qui manque. La vraie photo, elle, n'a pas bougé.
 *
 * `IllustrationArticleService` reste : il décide encore quel dessin
 * correspondrait à un article, et les épreuves de ce choix restent ici. Ce qui
 * a changé est qu'aucun écran ne s'en sert plus.
 */
class IllustrationArticleTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-Knowing CGA', 'regime_imposition' => 'RNI',
            'adresse' => 'Cocody, Abidjan', 'rccm' => 'CI-ABJ-2026-B-00042',
            'ncc' => '2601234A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'produits', 'tiers'],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-illu@dc-knowing.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function article(string $nom, string $type = 'marchandise'): Produit
    {
        return Produit::create([
            'entreprise_id' => $this->entreprise->id,
            'reference' => 'REF-' . substr(md5($nom), 0, 6),
            'nom' => $nom, 'type' => $type, 'unite' => 'pièce',
            'prix_achat' => 100, 'prix_vente' => 200, 'taux_tva' => 18,
        ]);
    }

    // ── Le choix du dessin ───────────────────────────────────────────

    /**
     * Les articles du catalogue réel du propriétaire, tels qu'ils sont écrits
     * à l'écran. C'est sur eux que la règle doit tomber juste.
     */
    public static function articlesDuCatalogue(): array
    {
        return [
            'bougie'          => ['Bougie', 'energie'],
            'clavier'         => ['clavier', 'informatique'],
            'couches'         => ['Couches bébé', 'hygiene'],
            'dentifrice'      => ['Dentifrice', 'hygiene'],
            'eau minérale'    => ['Eau minérale 1,5 L', 'eau'],
            'jus'             => ['Jus en canette', 'boisson'],
            'huile'           => ['Huile végétale 5 L', 'huile'],
            'lait'            => ['Lait en poudre 400 g', 'alimentation'],
            'cube'            => ['Cube assaisonnement', 'alimentation'],
            'imprimante'      => ['Imprimante HP430', 'informatique'],
            'logiciels'       => ['Logiciels et abonnements', 'informatique'],
            'conseil'         => ['Conseil et assistance', 'conseil'],
            'déclarations'    => ['Déclarations fiscales et sociales', 'conseil'],
            'états financiers' => ['Établissement des états financiers (liasse)', 'conseil'],
            'formation'       => ['formation FNE', 'formation'],
            'débours'         => ['Frais de greffe, timbres et formalités', 'debours'],
            'création'        => ["Création d'entreprise (formalités)", 'conseil'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('articlesDuCatalogue')]
    public function test_le_dessin_suit_ce_que_l_article_dit_de_lui(string $nom, string $attendu): void
    {
        $this->assertSame($attendu, IllustrationArticleService::cle($this->article($nom)));
    }

    public function test_l_eau_de_javel_n_est_pas_de_l_eau_minerale(): void
    {
        // L'ordre des entrées fait la règle : « javel » doit être rencontré
        // avant « eau », sans quoi le produit d'entretien s'illustrerait d'une
        // goutte d'eau de source.
        $this->assertSame('hygiene', IllustrationArticleService::cle($this->article('Eau de javel 1 L')));
    }

    public function test_un_article_muet_recoit_le_dessin_de_son_type(): void
    {
        // Rien n'est deviné : plutôt qu'une image prise au hasard qui
        // raconterait autre chose, on montre la nature de l'article.
        $this->assertSame('marchandise', IllustrationArticleService::cle($this->article('XZ-4471')));
        $this->assertSame('service', IllustrationArticleService::cle($this->article('XZ-4471 bis', 'service')));
    }

    public function test_chaque_dessin_annonce_existe_bien_sur_le_disque(): void
    {
        // Un nom de dessin sans fichier rendrait une adresse en 404
        // (Not Found — introuvable), et la carte resterait vide sans un mot —
        // exactement le défaut corrigé au lot 13 sur les photos.
        $noms = [];

        foreach (self::articlesDuCatalogue() as [$nom, $attendu]) {
            $noms[] = $attendu;
        }

        $noms = array_unique(array_merge($noms, ['marchandise', 'service', 'hygiene']));

        foreach ($noms as $dessin) {
            $this->assertFileExists(public_path('images/articles/' . $dessin . '.svg'),
                "Le dessin « {$dessin} » est annoncé et n'existe pas.");
        }
    }

    // ── La photo passe devant ────────────────────────────────────────

    public function test_une_vraie_photo_l_emporte_sur_le_dessin(): void
    {
        $article = $this->article('Bougie');
        $article->update(['photo' => 'produits/bougie.jpg']);
        Storage::disk('public')->put('produits/bougie.jpg', 'contenu');

        $this->assertStringNotContainsString('images/articles/', $article->fresh()->photo_url);
    }

    public function test_sans_photo_l_adresse_est_celle_du_dessin(): void
    {
        $article = $this->article('Bougie');

        $this->assertStringContainsString('images/articles/energie.svg', $article->photo_url);
    }

    public function test_le_sac_gris_ne_sert_plus_d_image_d_attente(): void
    {
        $this->assertStringNotContainsString(
            'placeholder-produit', $this->article('Bougie')->photo_url
        );
    }

    // ── Les écrans ───────────────────────────────────────────────────

    /**
     * La carte de l'article, et non la page entière : la feuille de style
     * nommait les deux classes, si bien qu'y chercher « avec-dessin » ne
     * prouverait rien.
     */
    private function carteDeLArticle(): string
    {
        $corps = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $debut = strpos($corps, '<div class="produit-card');
        $this->assertNotFalse($debut, "Aucune carte d'article sur l'écran de caisse.");

        return substr($corps, $debut, strpos($corps, 'onclick="ajouterAuPanier', $debut) - $debut);
    }

    public function test_la_carte_de_caisse_ne_porte_plus_de_filigrane(): void
    {
        // Retiré le 25/09/2026 à la demande du propriétaire. L'article sans
        // photo garde une carte nette : aucune classe, aucune variable, aucune
        // adresse de dessin ne sont posées.
        $this->article('Bougie');

        $carte = $this->carteDeLArticle();

        $this->assertStringNotContainsString('avec-dessin', $carte);
        $this->assertStringNotContainsString('--dessin-produit', $carte);
        $this->assertStringNotContainsString('images/articles/energie.svg', $carte);
    }

    public function test_la_carte_avec_photo_ne_porte_pas_de_filigrane(): void
    {
        // Les deux ne cohabitent pas : la photo dit déjà tout, et le dessin
        // par-dessus ferait un article de plus au coin de la carte.
        $article = $this->article('Bougie');
        $article->update(['photo' => 'produits/bougie.jpg']);
        Storage::disk('public')->put('produits/bougie.jpg', 'contenu');

        $carte = $this->carteDeLArticle();

        $this->assertStringContainsString('avec-photo', $carte);
        $this->assertStringNotContainsString('avec-dessin', $carte);
    }

    public function test_le_catalogue_ne_montre_plus_le_dessin(): void
    {
        // Le retrait du 25/09/2026 ne visait que l'écran de caisse ; le
        // propriétaire l'a étendu au catalogue le même jour, en constatant que
        // les deux écrans ne se ressemblaient plus : « si c'est retiré là-bas,
        // ici doit s'appliquer ». Un article sans photo garde un cadre net.
        $this->article('Imprimante HP430');

        $corps = $this->get(route('admin.produits.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('images/articles/informatique.svg', $corps);
        // Et ce qui manque se dit, plutôt que de laisser un cadre vide sans
        // explication.
        $this->assertStringContainsString('fa-camera', $corps);
    }

    // ── Simulation d'attaque ─────────────────────────────────────────

    public function test_un_nom_d_article_ne_peut_pas_ecrire_dans_la_page(): void
    {
        // Le nom de l'article revenait dans un attribut `style`, entre
        // apostrophes : un nom bien choisi refermerait l'attribut. Le
        // filigrane retiré, l'attribut est vide sur une carte sans photo —
        // mais le nom, lui, voyage toujours dans la carte, et c'est là que la
        // sûreté se vérifie.
        $article = $this->article("Bougie'); alert(1); //");

        // Le service reste en place : il sert le catalogue.
        $this->assertSame('energie', IllustrationArticleService::cle($article));

        $carte = $this->carteDeLArticle();

        // L'apostrophe est échappée : elle ne referme aucun attribut, et ce
        // qui suit reste du texte. C'est là qu'est la sûreté, non dans
        // l'absence du mot.
        $this->assertStringNotContainsString("Bougie');", $carte);
        $this->assertStringContainsString('Bougie&#039;);', $carte);

        // Et plus rien du nom n'atteint une adresse : il n'y en a plus.
        $this->assertStringNotContainsString('images/articles/', $carte);
    }
}
