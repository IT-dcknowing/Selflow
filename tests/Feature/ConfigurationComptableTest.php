<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\ImputationGlobale;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\ImputationService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Saisir les comptes une fois, et non par produit (sections 3.3, 3.6, 5 et 6
 * du plan de correction).
 *
 * Chaque fiche produit portait deux menus obligatoires — compte de vente,
 * compte d'achat — comptabilité ouverte ou non. Ils disparaissent quand elle
 * est fermée ; ouverte, l'article hérite de la configuration globale, et une
 * case en fait une exception.
 */
class ConfigurationComptableTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Categorie $boissons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Cave du plateau', 'regime_imposition' => 'TEE', 'ncc' => '1864699A',
            'modules_actifs' => [
                'principal', 'ventes', 'achats', 'stock', 'produits',
                'tiers', 'comptabilite', 'points_de_vente', 'rapports',
            ],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-plateau', 'ville' => 'Abidjan', 'commune' => 'Plateau',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Aka', 'prenom' => 'Serge', 'email' => 'serge-config@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        $this->boissons = Categorie::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Boissons', 'prefixe' => 'BOI',
            'compte_vente' => '701200', 'compte_achat' => '601200',
        ]);

        foreach ([
            '701000' => 'Ventes de marchandises', '701200' => 'Ventes — boissons', '706000' => 'Services vendus',
            '601000' => 'Achats de marchandises', '601200' => 'Achats — boissons', '604000' => 'Achats stockés',
            '311000' => 'Marchandises', '603100' => 'Variation des stocks de marchandises',
        ] as $numero => $libelle) {
            PlanComptable::create(['numero' => $numero, 'libelle' => $libelle, 'type' => 'general']);
        }
    }

    private function connecte(): self
    {
        $this->actingAs($this->admin)
             ->withSession(['point_de_vente_actif_id' => $this->site->id]);

        return $this;
    }

    private function ouvrirLaComptabilite(): void
    {
        $this->entreprise->comptabilite_activee = true;
        $this->entreprise->save();
        $this->admin->unsetRelation('entreprise');
    }

    private function nouvelArticle(array $champs = []): array
    {
        return array_merge([
            'nom' => 'Bissap 50 cl', 'type' => 'marchandise', 'categorie_id' => (string) $this->boissons->id,
            'prix_achat' => 300, 'prix_vente' => 500, 'taux_tva' => 18,
            'stock_actuel' => 0, 'stock_minimum' => 0,
        ], $champs);
    }

    // ── 6.1 — La page ────────────────────────────────────────────────

    public function test_la_page_n_existe_pas_comptabilite_fermee(): void
    {
        $this->connecte()->get(route('admin.comptabilite.configuration'))->assertNotFound();
    }

    public function test_la_page_s_ouvre_avec_la_comptabilite(): void
    {
        $this->ouvrirLaComptabilite();

        $this->connecte()->get(route('admin.comptabilite.configuration'))
            ->assertOk()
            ->assertSee('Configuration générale')
            ->assertSee('Par catégorie')
            ->assertSee('Par type')
            ->assertSee('Stock et variations de stock')
            ->assertSee('Boissons');
    }

    // ── 6.2 / 6.3 — L'enregistrement ─────────────────────────────────

    public function test_la_configuration_s_enregistre_aux_trois_niveaux(): void
    {
        $this->ouvrirLaComptabilite();

        $this->connecte()->put(route('admin.comptabilite.configuration.enregistrer'), [
            'vente' => ['general' => '701000', 'type' => ['service' => '706000'], 'categorie' => [$this->boissons->id => '701000']],
            'achat' => ['general' => '604000'],
            'stock' => ['categorie' => [$this->boissons->id => '311000']],
            'variation' => ['categorie' => [$this->boissons->id => '603100']],
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.comptabilite.configuration'));

        $globale = ImputationGlobale::pour($this->entreprise->id);
        $this->assertSame('701000', $globale['general']['compte_vente']);
        $this->assertSame('604000', $globale['general']['compte_achat']);
        $this->assertSame('706000', $globale['type:service']['compte_vente']);

        $boissons = $this->boissons->fresh();
        $this->assertSame('701000', $boissons->compte_vente);
        $this->assertSame('311000', $boissons->compte_stock);
        $this->assertSame('603100', $boissons->compte_variation);
    }

    public function test_un_compte_de_la_mauvaise_classe_est_refuse(): void
    {
        $this->ouvrirLaComptabilite();

        $this->connecte()->put(route('admin.comptabilite.configuration.enregistrer'), [
            'vente' => ['general' => '601000'],
        ])->assertSessionHasErrors('vente.general');

        $this->assertSame([], ImputationGlobale::pour($this->entreprise->id));
    }

    public function test_un_compte_absent_du_plan_est_refuse(): void
    {
        $this->ouvrirLaComptabilite();

        $this->connecte()->put(route('admin.comptabilite.configuration.enregistrer'), [
            'vente' => ['general' => '709999'],
        ])->assertSessionHasErrors('vente.general');
    }

    public function test_la_categorie_d_une_autre_entreprise_n_est_pas_touchee(): void
    {
        $this->ouvrirLaComptabilite();
        $autre = Entreprise::create(['nom' => 'Concurrent']);
        $saCategorie = Categorie::create(['entreprise_id' => $autre->id, 'nom' => 'Vins', 'prefixe' => 'VIN', 'compte_vente' => '701200']);

        $this->connecte()->put(route('admin.comptabilite.configuration.enregistrer'), [
            'vente' => ['categorie' => [$saCategorie->id => '706000']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('701200', $saCategorie->fresh()->compte_vente);
    }

    public function test_vider_une_ligne_la_supprime(): void
    {
        $this->ouvrirLaComptabilite();
        ImputationGlobale::create(['entreprise_id' => $this->entreprise->id, 'cle' => 'general', 'compte_vente' => '706000']);

        $this->connecte()->put(route('admin.comptabilite.configuration.enregistrer'), [
            'vente' => ['general' => ''], 'achat' => ['general' => ''],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, ImputationGlobale::where('entreprise_id', $this->entreprise->id)->count());
    }

    // ── 3.3 / 6.4 — La fiche produit ─────────────────────────────────

    public function test_comptabilite_fermee_l_article_ne_prend_aucun_compte(): void
    {
        $this->connecte()->post(route('admin.produits.creer'), $this->nouvelArticle([
            'compte_vente' => '706000', 'comptes_personnalises' => '1',
        ]))->assertSessionHasNoErrors();

        $article = Produit::where('nom', 'Bissap 50 cl')->firstOrFail();
        $this->assertFalse($article->comptes_personnalises);
        $this->assertNull($article->compte_vente);
        $this->assertSame('701200', ImputationService::compteVente($article), 'Il hérite de sa catégorie.');
    }

    public function test_comptabilite_fermee_la_modification_ne_touche_pas_aux_comptes(): void
    {
        $article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'BOI-001', 'nom' => 'Bissap 50 cl',
            'type' => 'marchandise', 'categorie_id' => $this->boissons->id, 'prix_achat' => 300, 'prix_vente' => 500,
            'compte_vente' => '706000', 'comptes_personnalises' => true,
        ]);

        $this->connecte()->put(route('admin.produits.modifier', $article), $this->nouvelArticle([
            'stock_minimum' => 0,
        ]))->assertSessionHasNoErrors();

        $this->assertTrue($article->fresh()->comptes_personnalises);
        $this->assertSame('706000', $article->fresh()->compte_vente);
    }

    public function test_comptabilite_ouverte_sans_la_case_l_article_herite(): void
    {
        $this->ouvrirLaComptabilite();

        $this->connecte()->post(route('admin.produits.creer'), $this->nouvelArticle([
            'compte_vente' => '706000',
        ]))->assertSessionHasNoErrors();

        $article = Produit::where('nom', 'Bissap 50 cl')->firstOrFail();
        $this->assertFalse($article->comptes_personnalises);
        $this->assertNull($article->compte_vente);
    }

    public function test_comptabilite_ouverte_la_case_fait_l_exception(): void
    {
        $this->ouvrirLaComptabilite();

        $this->connecte()->post(route('admin.produits.creer'), $this->nouvelArticle([
            'comptes_personnalises' => '1', 'compte_vente' => '706000', 'compte_achat' => '604000',
        ]))->assertSessionHasNoErrors();

        $article = Produit::where('nom', 'Bissap 50 cl')->firstOrFail();
        $this->assertTrue($article->comptes_personnalises);
        $this->assertSame('706000', ImputationService::compteVente($article));
        $this->assertSame('604000', ImputationService::compteAchat($article));
    }

    public function test_l_exception_refuse_un_compte_de_la_mauvaise_classe(): void
    {
        $this->ouvrirLaComptabilite();

        $this->connecte()->post(route('admin.produits.creer'), $this->nouvelArticle([
            'nouvelle_categorie' => 'Ne doit pas naître', 'categorie_id' => 'nouvelle',
            'comptes_personnalises' => '1', 'compte_vente' => '601000', 'compte_achat' => '604000',
        ]))->assertSessionHasErrors('compte_vente');

        $this->assertDatabaseMissing('categories', ['nom' => 'Ne doit pas naître']);
    }

    public function test_decocher_la_case_ne_garde_pas_l_ancienne_valeur(): void
    {
        $this->ouvrirLaComptabilite();
        $article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'BOI-002', 'nom' => 'Bissap 50 cl',
            'type' => 'marchandise', 'categorie_id' => $this->boissons->id, 'prix_achat' => 300, 'prix_vente' => 500,
            'compte_vente' => '706000', 'compte_achat' => '604000', 'comptes_personnalises' => true,
        ]);

        $this->connecte()->put(route('admin.produits.modifier', $article), $this->nouvelArticle())
            ->assertSessionHasNoErrors();

        $article->refresh();
        $this->assertFalse($article->comptes_personnalises);
        $this->assertNull($article->compte_vente);
        $this->assertNull($article->compte_achat);
    }

    public function test_la_fiche_dit_de_quoi_l_article_herite(): void
    {
        $this->ouvrirLaComptabilite();
        ImputationGlobale::create(['entreprise_id' => $this->entreprise->id, 'cle' => 'type:marchandise', 'compte_vente' => '701000']);
        $article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'BOI-003', 'nom' => 'Bissap 50 cl',
            'type' => 'marchandise', 'categorie_id' => $this->boissons->id, 'prix_achat' => 300, 'prix_vente' => 500,
        ]);

        $this->connecte()->get(route('admin.produits.fiche', $article))
            ->assertOk()
            ->assertSee('Hérite de la configuration globale')
            ->assertSee('701000')
            ->assertSee('601200');
    }

    // ── 6.5 — La reprise de l'existant ───────────────────────────────

    public function test_la_reprise_ne_tient_pour_voulu_que_ce_qui_differe(): void
    {
        $creer = fn (string $ref, ?string $vente, ?string $achat) => Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => $ref, 'nom' => $ref,
            'type' => 'marchandise', 'categorie_id' => $this->boissons->id, 'prix_achat' => 1, 'prix_vente' => 2,
            'compte_vente' => $vente, 'compte_achat' => $achat,
        ]);

        $voulu        = $creer('VOULU', '706000', '601000');
        $defaut       = $creer('DEFAUT', '701000', '601000');
        $ancienDefaut = $creer('ANCIEN', '701100', '601100');
        $commeRayon   = $creer('RAYON', '701200', '601200');
        $achatVoulu   = $creer('ACHAT', '701000', '604000');

        DB::table('produits')->update(['comptes_personnalises' => false]);
        (require base_path('app/Modules/Admin/Migrations/2026_10_07_000001_la_configuration_comptable_globale.php'))->up();

        $this->assertTrue($voulu->fresh()->comptes_personnalises);
        $this->assertTrue($achatVoulu->fresh()->comptes_personnalises);
        $this->assertFalse($defaut->fresh()->comptes_personnalises);
        $this->assertFalse($ancienDefaut->fresh()->comptes_personnalises, '701100 était le défaut de la colonne, pas un choix.');
        $this->assertFalse($commeRayon->fresh()->comptes_personnalises, 'Recopier le compte du rayon n\'est pas une exception.');
        $this->assertSame('706000', $voulu->fresh()->compte_vente, 'Rien n\'est effacé.');
    }

    // ── 3.6 — Plus rien de comptable à l'écran ───────────────────────

    /**
     * Ce que la comptabilité fermée ne doit plus jamais montrer. Une épreuve
     * plutôt qu'une relecture à l'œil : le prochain champ ajouté à un
     * formulaire la fera tomber.
     */
    private const MARQUES_COMPTABLES = [
        'name="compte_', 'Compte comptable', 'Compte général', 'Compte de vente', "Compte d'achat",
        'Compte d&#039;achat', 'Nature de la vente', 'Plan Comptable', 'Codes Journaux',
        'Configuration comptable', 'Hérite de la configuration globale', 'Grand livre',
    ];

    public function test_aucun_element_comptable_ne_subsiste_quand_c_est_ferme(): void
    {
        $article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'BOI-004', 'nom' => 'Bissap 50 cl',
            'type' => 'marchandise', 'categorie_id' => $this->boissons->id, 'prix_achat' => 300, 'prix_vente' => 500,
        ]);

        $ecrans = [
            route('admin.clients.index'),
            route('admin.fournisseurs.index'),
            route('admin.produits.index'),
            route('admin.produits.fiche', $article),
        ];

        foreach ($ecrans as $ecran) {
            $corps = $this->connecte()->get($ecran)->assertOk()->getContent();

            foreach (self::MARQUES_COMPTABLES as $marque) {
                $this->assertStringNotContainsString($marque, $corps, "« {$marque} » subsiste sur {$ecran}.");
            }
        }
    }

    public function test_les_ecrans_comptables_restants_repondent_404(): void
    {
        foreach ([
            route('admin.comptabilite.configuration'),
            route('admin.immobilisations.index'),
            route('admin.immobilisations.creer'),
        ] as $ecran) {
            $this->connecte()->get($ecran)->assertNotFound();
        }
    }
}
