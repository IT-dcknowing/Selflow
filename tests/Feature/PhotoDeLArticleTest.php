<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La photo de l'article se pose en arrière-plan de sa carte.
 *
 * Sur un écran de caisse, on reconnaît un article à son image avant de lire son
 * nom. La photo existait déjà sur la fiche, et l'écran de vente ne l'utilisait
 * nulle part : les cartes se ressemblaient toutes.
 *
 * Deux points demandent de l'attention, et ce sont eux que ces épreuves
 * gardent :
 *
 *  1. **l'image d'attente n'est pas une photo.** `photo_url` rend toujours
 *     quelque chose — c'est ce qu'il faut pour une vignette. En arrière-plan,
 *     ce serait un même placeholder gris sous toutes les cartes, qui
 *     n'apprendrait rien et brouillerait le texte ;
 *  2. **le chemin de la photo entre dans un attribut `style`.** Une apostrophe
 *     y refermerait l'attribut.
 */
class PhotoDeLArticleTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // La situation fiscale est renseignée : sans elle, le garde
        // `inscription.complete` renvoie l'écran de vente vers la
        // configuration, et la page ne se rend jamais.
        $this->entreprise = Entreprise::create([
            'nom'               => 'Quincaillerie du Plateau',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Plateau, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00042',
            'ncc'               => '2601234A',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Plateau',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis@quincaillerie.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Konan Yao']);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);

        Produit::oublierLeLienDeStockage();
    }

    protected function tearDown(): void
    {
        $this->rendreLeDossierPublic();

        parent::tearDown();
    }

    /**
     * Faire comme si `php artisan storage:link` avait été lancé.
     *
     * L'adresse d'une photo dépend de ce lien : posé, elle est servie
     * directement par le serveur web ; absent, elle passe par une route de
     * l'application. Les deux branches doivent être éprouvées, et une épreuve
     * qui laisserait le lien derrière elle fausserait les suivantes.
     */
    private function poserLeLienDeStockage(): void
    {
        $this->deplacerLeDossierPublic(avecLien: true);
    }

    private function retirerLeLienDeStockage(): void
    {
        $this->deplacerLeDossierPublic(avecLien: false);
    }

    /**
     * Déplacer le dossier public plutôt que toucher le vrai.
     *
     * La version précédente creusait et supprimait `public/storage` pour de
     * bon, et ne supprimait que ce qu'elle avait elle-même créé — une
     * précaution juste, mais qui rendait l'épreuve dépendante de la machine :
     * **sur tout poste où `php artisan storage:link` a été lancé**, ce qui est
     * l'installation normale, le lien était déjà là, « retirer » ne retirait
     * rien, et l'épreuve du chemin sans lien tombait. Elle ne passait que sur
     * une machine mal installée.
     *
     * `usePublicPath()` change l'endroit où le code va regarder. Le lien réel
     * du développeur n'est ni lu, ni créé, ni détruit.
     */
    private function deplacerLeDossierPublic(bool $avecLien): void
    {
        $this->publicOrigine ??= public_path();

        $this->dossierFactice = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'selflow-public-' . bin2hex(random_bytes(6));

        @mkdir($this->dossierFactice, 0777, true);

        if ($avecLien) {
            @mkdir($this->dossierFactice . DIRECTORY_SEPARATOR . 'storage', 0777, true);
        }

        $this->app->usePublicPath($this->dossierFactice);

        Produit::oublierLeLienDeStockage();
    }

    /**
     * Remettre le dossier public d'origine, et effacer le factice.
     */
    private function rendreLeDossierPublic(): void
    {
        if ($this->publicOrigine !== null) {
            $this->app->usePublicPath($this->publicOrigine);
            $this->publicOrigine = null;
        }

        if ($this->dossierFactice !== null) {
            @rmdir($this->dossierFactice . DIRECTORY_SEPARATOR . 'storage');
            @rmdir($this->dossierFactice);
            $this->dossierFactice = null;
        }

        Produit::oublierLeLienDeStockage();
    }

    private ?string $publicOrigine = null;
    private ?string $dossierFactice = null;

    // ── La source : photoReelle() ────────────────────────────────────

    public function test_un_article_sans_photo_n_en_a_pas(): void
    {
        $this->assertNull($this->article('Ciment 50 kg')->photoReelle());
    }

    /**
     * Le fichier a pu être supprimé du disque sans que la colonne soit vidée.
     * Rendre son adresse afficherait une image cassée sur la carte.
     */
    public function test_un_fichier_absent_du_disque_ne_compte_pas_comme_photo(): void
    {
        $article = $this->article('Fer à béton', ['photo' => 'produits/disparu.jpg']);

        $this->assertNull($article->photoReelle());
    }

    public function test_avec_le_lien_de_stockage_l_adresse_passe_par_public(): void
    {
        $this->poserLeLienDeStockage();

        Storage::disk('public')->put('produits/ciment.jpg', 'contenu');
        $article = $this->article('Ciment 50 kg', ['photo' => 'produits/ciment.jpg']);

        $this->assertStringContainsString('storage/produits/ciment.jpg', (string) $article->photoReelle());
    }

    /**
     * Le défaut qui rendait le fond de carte invisible.
     *
     * Sans `public/storage`, le fichier est bien là — `exists()` répond oui —
     * mais `asset('storage/…')` désigne une adresse qui n'existe pas : 404
     * (Not Found — introuvable). La vignette d'un article ne le montrait pas,
     * son `onerror` basculant sur l'image d'attente ; le fond de carte, lui,
     * n'a pas d'`onerror` et restait vide sans un mot.
     */
    public function test_sans_lien_de_stockage_l_adresse_passe_par_l_application(): void
    {
        $this->retirerLeLienDeStockage();

        Storage::disk('public')->put('produits/ciment.jpg', 'contenu');
        $article = $this->article('Ciment 50 kg', ['photo' => 'produits/ciment.jpg']);

        $this->assertSame(
            route('admin.produits.photo.voir', $article),
            $article->photoReelle()
        );
    }

    public function test_la_route_de_repli_sert_bien_le_fichier(): void
    {
        Storage::disk('public')->put('produits/ciment.jpg', 'contenu');
        $article = $this->article('Ciment 50 kg', ['photo' => 'produits/ciment.jpg']);

        $reponse = $this->get(route('admin.produits.photo.voir', $article))->assertOk();

        $this->assertSame('contenu', $reponse->streamedContent());
    }

    /**
     * Simulation d'attaque : la photo d'un article appartient à son entreprise.
     * Un identifiant deviné ne doit pas ouvrir le catalogue du voisin.
     */
    public function test_la_photo_d_une_autre_entreprise_se_refuse(): void
    {
        $voisine = Entreprise::create(['nom' => 'Voisine SARL']);
        Storage::disk('public')->put('produits/secret.jpg', 'contenu');

        $article = Produit::create([
            'entreprise_id' => $voisine->id, 'reference' => 'REF-VOISIN',
            'nom' => 'Article du voisin', 'type' => 'marchandise', 'unite' => 'unité',
            'prix_achat' => 1, 'prix_vente' => 2, 'taux_tva' => 18,
            'photo' => 'produits/secret.jpg',
        ]);

        $this->get(route('admin.produits.photo.voir', $article))->assertNotFound();
    }

    public function test_une_adresse_distante_est_rendue_telle_quelle(): void
    {
        $article = $this->article('Tôle', ['photo' => 'https://exemple.ci/tole.jpg']);

        $this->assertSame('https://exemple.ci/tole.jpg', $article->photoReelle());
    }

    /**
     * `photo_url` garde son rôle : une vignette a besoin d'une image, toujours.
     *
     * Ce n'est plus le sac gris qui la fournit. Il était le même sous chaque
     * article — trente cartes identiques où seul le texte distinguait un sac de
     * ciment d'une prestation de conseil. À défaut de photo, on montre
     * désormais la nature de l'article ; voir `IllustrationArticleTest`.
     */
    public function test_photo_url_rend_toujours_une_image_en_repli(): void
    {
        $adresse = $this->article('Ciment 50 kg')->photo_url;

        $this->assertStringContainsString('images/articles/construction.svg', $adresse);
        $this->assertStringNotContainsString('placeholder-produit', $adresse);
    }

    // ── L'écran de vente ─────────────────────────────────────────────

    public function test_la_carte_d_un_article_photographie_porte_son_image_en_fond(): void
    {
        Storage::disk('public')->put('produits/ciment.jpg', 'contenu');
        $article = $this->article('Ciment 50 kg', ['photo' => 'produits/ciment.jpg']);

        $html = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        // La classe se pose en fin de liste : `avec-photo"` ne peut venir que
        // de la carte, jamais de la feuille de style, qui l'écrit suivie de
        // `::before` ou de `:hover`.
        $this->assertStringContainsString('avec-photo"', $html);
        $this->assertStringContainsString('style="--fond-produit: url(', $html);

        // L'adresse posée est **celle que le modèle rend**, quelle que soit la
        // branche empruntée. L'épreuve écrivait ici le chemin du fichier : elle
        // ne tenait que sur une installation où `public/storage` existe, et
        // c'est justement l'installation où le défaut ne se voit pas.
        $this->assertStringContainsString(e($article->photoReelle()), $html);
    }

    /**
     * Le placeholder en arrière-plan couvrirait toutes les cartes d'un même
     * gris. Un article sans photo garde donc la carte d'avant.
     */
    public function test_la_carte_d_un_article_sans_photo_reste_sans_fond(): void
    {
        $this->article('Ciment 50 kg');

        $html = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        // La feuille de style porte toujours la règle `.avec-photo` : c'est la
        // **carte** qui ne doit pas la recevoir, et l'attribut de fond qui ne
        // doit pas être écrit.
        $this->assertStringNotContainsString('avec-photo"', $html);
        $this->assertStringNotContainsString('style="--fond-produit', $html);
        $this->assertStringNotContainsString('placeholder-produit.png', $html);
    }

    public function test_l_ecran_de_modification_pose_le_meme_fond(): void
    {
        Storage::disk('public')->put('produits/ciment.jpg', 'contenu');
        $article = $this->article('Ciment 50 kg', ['photo' => 'produits/ciment.jpg']);

        $vente = \App\Modules\Admin\Modeles\Vente::create([
            'point_de_vente_id' => $this->site->id,
            'numero_facture'    => 'FV-0001',
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 0, 'montant_tva' => 0, 'montant_ttc' => 0,
            'etape'             => 'Devis', 'statut' => 'En attente',
        ]);

        $html = $this->get(route('admin.ventes.modifier', $vente))->assertOk()->getContent();

        // La classe se pose en fin de liste : `avec-photo"` ne peut venir que
        // de la carte, jamais de la feuille de style, qui l'écrit suivie de
        // `::before` ou de `:hover`.
        $this->assertStringContainsString('avec-photo"', $html);
        $this->assertStringContainsString(e($article->photoReelle()), $html);
    }

    /**
     * Simulation d'attaque : le chemin de la photo entre dans un attribut
     * `style`, entre apostrophes. Une apostrophe dans le nom du fichier
     * refermerait l'attribut et laisserait écrire du HTML dans la page —
     * une porte ouverte à qui peut téléverser une image.
     */
    public function test_une_apostrophe_dans_le_chemin_ne_sort_pas_de_l_attribut(): void
    {
        // Le lien de stockage est posé exprès : c'est la branche où le **chemin
        // du fichier** entre dans l'attribut. Par la route de repli, l'adresse
        // ne porte que l'identifiant de l'article, et l'apostrophe n'y arrive
        // jamais — l'épreuve ne prouverait plus rien.
        $this->poserLeLienDeStockage();

        $chemin = "produits/x'onerror=alert(1).jpg";
        Storage::disk('public')->put($chemin, 'contenu');
        $this->article('Article piégé', ['photo' => $chemin]);

        $html = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        // L'apostrophe est rendue en entité : elle ne referme rien.
        $this->assertStringNotContainsString("x'onerror", $html);
        $this->assertStringContainsString('&#039;onerror', $html);
    }

    // ─────────────────────────────────────────────────────────────────

    private function article(string $nom, array $attributs = []): Produit
    {
        return Produit::create(array_merge([
            'entreprise_id' => $this->entreprise->id,
            'reference'     => 'REF-' . uniqid(),
            'nom'           => $nom,
            'type'          => 'marchandise',
            'unite'         => 'unité',
            'prix_achat'    => 5000,
            'prix_vente'    => 6500,
            'taux_tva'      => 18,
        ], $attributs));
    }
}
