<?php

namespace Tests\Feature;

use App\Exceptions\Panne;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ce que l'utilisateur voit quand le serveur tombe.
 *
 * Laravel affichait sa propre page de trace : sur un écran de caisse, un
 * utilisateur voyait le nom des fichiers du serveur, les versions des
 * bibliothèques et le contenu des variables. **C'est illisible pour lui, et
 * trop lisible pour qui passait par là** — un chemin absolu dit le système
 * d'exploitation et l'arborescence, une version de bibliothèque dit quelles
 * failles connues essayer.
 *
 * La page porte le message d'attente, une référence à donner au service
 * informatique et les contacts de l'assistance ; le détail va au registre des
 * pannes du superadministrateur (15/10/2026).
 */
class PageDePanneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // La page ne remplace la trace qu'en production : en développement, la
        // trace de Laravel reste ce qu'il y a de plus utile.
        config(['app.debug' => false]);

        Route::get('/_panne-de-test', function () {
            throw new \RuntimeException('La base de données ne répond pas.');
        })->middleware('web');
    }

    private function provoquerLaPanne()
    {
        return $this->get('/_panne-de-test');
    }

    // ══════════════ Ce que l'utilisateur lit ══════════════

    public function test_la_panne_rend_la_page_de_maintenance_et_non_la_trace(): void
    {
        $reponse = $this->provoquerLaPanne();

        $reponse->assertStatus(500);
        $reponse->assertSee('Page en maintenance');
        $reponse->assertSee('notre service technique est prévenu', false);
        $reponse->assertSee('pour le désagrément', false);
    }

    public function test_la_page_porte_une_reference_a_donner_au_service(): void
    {
        // Sans référence, la conversation avec le service informatique commence
        // par « quelle page, à quelle heure, quel message ? », et le journal du
        // serveur contient mille lignes de la même minute.
        $reponse = $this->provoquerLaPanne();

        $reponse->assertSee('Référence à donner au service informatique', false);
        $reponse->assertSee('SF-' . now()->format('ymd'), false);
    }

    public function test_aucun_detail_technique_n_est_montre_a_l_utilisateur(): void
    {
        // Décision du 15/10/2026 : le détail part au registre des pannes du
        // superadministrateur ; l'utilisateur n'en voit rien.
        $reponse = $this->provoquerLaPanne();

        $reponse->assertDontSee('La base de données ne répond pas.', false);
        $reponse->assertDontSee('Détail technique', false);
        $reponse->assertDontSee('<pre', false);
        $reponse->assertSee('résolu dans un instant', false);
    }

    public function test_la_page_reprend_la_palette_de_l_application(): void
    {
        $reponse = $this->provoquerLaPanne();

        // Le bleu royal de la barre latérale, et la police de l'application.
        $reponse->assertSee('#002B5C', false);
        $reponse->assertSee('Inter', false);
    }

    public function test_le_fond_ne_depend_d_aucun_fichier_a_deployer(): void
    {
        // Le jour où le serveur va mal est le pire moment pour dépendre d'une
        // image qui doit se charger : le motif est dessiné dans la page.
        $reponse = $this->provoquerLaPanne();

        $reponse->assertSee('data:image/svg+xml', false);
    }

    // ══════════════ L'assistance et le registre ══════════════

    public function test_la_page_donne_les_contacts_saisis_par_le_superadmin(): void
    {
        \App\Modules\Admin\Modeles\ParametrePlateforme::ecrire('assistance_email', 'support@dc-knowing.com');
        \App\Modules\Admin\Modeles\ParametrePlateforme::ecrire('assistance_telephone', '+225 07 01 02 03 04');

        $this->provoquerLaPanne()
            ->assertSee('contactez l\'assistance technique', false)
            ->assertSee('mailto:support@dc-knowing.com', false)
            ->assertSee('tel:+2250701020304', false);
    }

    public function test_la_panne_est_consignee_une_fois_par_defaut(): void
    {
        $this->provoquerLaPanne();
        $this->provoquerLaPanne();

        $panne = \App\Modules\Admin\Modeles\PanneConsignee::sole();
        $this->assertSame(2, $panne->occurrences);
        $this->assertSame('La base de données ne répond pas.', $panne->message);
        $this->assertStringContainsString('/_panne-de-test', $panne->url);
        $this->assertNull($panne->corrigee_le);
    }

    public function test_le_chemin_du_serveur_est_ramene_au_projet(): void
    {
        // `/home/deploy/selflow-prod/app/…` dit où le projet est installé et
        // sous quel compte il tourne. La racine est retirée.
        $reponse = $this->provoquerLaPanne();

        $reponse->assertDontSee(base_path(), false);
    }

    // ══════════════ La référence ══════════════

    public function test_le_meme_defaut_porte_la_meme_reference(): void
    {
        // Deux occurrences du même défaut se regroupent ; un défaut nouveau se
        // distingue tout de suite d'un défaut connu.
        $premiere = new \RuntimeException('Peu importe le message');
        $seconde  = new \RuntimeException('Un autre message, même endroit');

        // Construites sur la même ligne : même fichier, même ligne, même type.
        $this->assertSame(
            substr(Panne::reference($premiere), 0, 10),
            substr(Panne::reference($seconde), 0, 10)
        );
    }

    public function test_la_reference_est_lisible_au_telephone(): void
    {
        // C'est un utilisateur qui la dicte : elle tient en trois groupes
        // courts, sans caractère ambigu à l'oral.
        $reference = Panne::reference(new \RuntimeException('x'));

        $this->assertMatchesRegularExpression('/^SF-\d{6}-[0-9A-F]{6}$/', $reference);
    }

    // ══════════════ Ce qui ne doit pas déclencher la page ══════════════

    public function test_une_page_introuvable_ne_declenche_pas_la_panne(): void
    {
        // Une adresse mal tapée n'est pas une panne : elle répond 404 (page
        // introuvable), et l'afficher en maintenance ferait appeler le service
        // informatique pour rien.
        $this->get('/adresse-qui-n-existe-pas')
            ->assertNotFound()
            ->assertDontSee('Page en maintenance');
    }

    public function test_la_page_de_panne_ne_repond_pas_aux_appels_d_api(): void
    {
        // Une application mobile attend du JSON : lui rendre une page HTML de
        // maintenance lui ferait afficher une erreur d'analyse au lieu du
        // message.
        Route::get('/api/_panne-de-test', function () {
            throw new \RuntimeException('Panne côté API.');
        })->middleware('api');

        $this->getJson('/api/_panne-de-test')
            ->assertStatus(500)
            ->assertHeader('content-type', 'application/json');
    }
}
