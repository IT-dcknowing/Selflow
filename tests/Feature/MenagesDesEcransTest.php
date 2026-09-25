<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que les écrans montraient en double, et ce qu'ils ne montraient pas.
 *
 * Relevé par le propriétaire le 25/09/2026, écran par écran. Quatre constats,
 * et le dernier n'avait été vu par personne.
 */
class MenagesDesEcransTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'TEE',
            'adresse' => 'Riviera II', 'rccm' => 'CI-ABJ-2018-B-31734',
            'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite', 'points_de_vente'],
        ]);

        $site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-menage@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id]);
    }

    private function menu(): string
    {
        return $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();
    }

    // ══════════════ Le menu ══════════════

    public function test_le_menu_ne_propose_plus_deux_fois_les_factures_du_portail(): void
    {
        $menu = $this->menu();

        /*
         * L'entrée a d'abord été dédoublée — sous Fiscalité & DGI **et** sous
         * Achats —, puis ramenée à une seule, puis retirée tout à fait le
         * 25/09/2026 : elle menait à la section « Factures achat DGI » de
         * l'écran des factures d'achat, que ses propres onglets atteignent en
         * un clic. Une entrée de menu pour un onglet d'une page déjà au menu,
         * c'est la même page deux fois.
         */
        $this->assertStringNotContainsString('Factures re&ccedil;ues du portail', $menu);
        $this->assertStringNotContainsString('Factures re&ccedil;ues (portail', $menu);

        // L'écran qui la porte, lui, est bien au menu.
        $this->assertStringContainsString(route('admin.achats.factures'), $menu);
    }

    public function test_la_gestion_des_stickers_quitte_le_menu(): void
    {
        // Les mêmes chiffres que l'écran « Gestion FNE » : solde, provision,
        // alertes. Deux écrans pour les mêmes chiffres invitent à les comparer,
        // et ils ne peuvent que concorder.
        $this->assertStringNotContainsString('Gestion des stickers', $this->menu());
    }

    public function test_les_pieces_refusees_vivent_dans_les_parametres(): void
    {
        $page = $this->menu();

        // Ce n'est pas un écran qu'on visite : c'est une alerte qu'on traite
        // quand elle survient, et elle relève de la configuration DGI.
        $this->assertStringContainsString('Pièces refusées par la DGI', $page);
        $this->assertStringContainsString(route('admin.fne.rejets'), $page);
    }

    // ══════════════ Les habilitations ══════════════

    public function test_tout_droit_exige_par_une_route_figure_au_catalogue(): void
    {
        /*
         * `historique_ventes` et `historique_achats` étaient exigés par leurs
         * routes et proposés par **aucune case** : aucune entreprise ne pouvait
         * les accorder à son personnel. Les deux adresses ont été retirées
         * depuis — elles doublaient `/factures` —, mais la règle demeure : un
         * droit qu'une route exige doit pouvoir s'accorder.
         */
        $auCatalogue = [];

        foreach (Habilitations::CATALOGUE as $groupes) {
            foreach ($groupes as $droits) {
                $auCatalogue = array_merge($auCatalogue, array_keys($droits));
            }
        }

        $absents = array_values(array_diff(
            array_unique(array_values(Habilitations::PAR_ROUTE)),
            $auCatalogue
        ));

        $this->assertSame([], $absents,
            'Ces droits sont exiges par une route et ne figurent pas au catalogue : ' . implode(', ', $absents));
    }

    public function test_une_entreprise_ne_propose_que_les_modules_qu_elle_a(): void
    {
        // Accorder « Ordres de production » à l'employé d'un commerce sans
        // atelier ne lui ouvrait rien : la route refuse d'abord sur le module.
        // Le droit ne servait qu'à encombrer l'écran.
        $this->entreprise->update(['modules_actifs' => ['principal', 'ventes', 'points_de_vente']]);

        $groupes = Habilitations::pourLEntreprise($this->entreprise->fresh());

        $this->assertArrayHasKey('Ventes', $groupes);
        $this->assertArrayNotHasKey('Production', $groupes);
        $this->assertArrayNotHasKey('Stock', $groupes);
        // `principal` n'est gardé par aucun module : tout le monde l'a.
        $this->assertArrayHasKey('Tableau de bord', $groupes);
    }

    public function test_la_production_a_son_propre_groupe(): void
    {
        // Ses fiches techniques étaient rangées sous « Ventes » et ses ordres
        // sous « Achats » : le module lui-même ne figurait nulle part.
        $this->entreprise->update([
            'modules_actifs' => ['principal', 'ventes', 'achats', 'production'],
        ]);

        $groupes = Habilitations::pourLEntreprise($this->entreprise->fresh());

        $this->assertSame(
            ['production_recettes', 'production_ordres'],
            array_keys($groupes['Production'])
        );
        $this->assertArrayNotHasKey('production_recettes', $groupes['Ventes']);
        $this->assertArrayNotHasKey('production_ordres', $groupes['Achats']);
    }

    public function test_les_deux_ecrans_dressent_les_memes_cases(): void
    {
        // Elles étaient écrites en dur des deux côtés : un droit ajouté dans
        // l'écran de création manquait dans la fiche, et l'inverse.
        foreach (['index', 'details'] as $vue) {
            $source = file_get_contents(base_path("app/Modules/Admin/Vues/personnel/{$vue}.blade.php"));

            $this->assertStringContainsString('personnel.partials.cases-habilitations', $source, $vue);
            $this->assertStringNotContainsString('name="habilitations[]"', $source, $vue);
        }
    }

    public function test_le_menu_garde_la_production_sur_ses_propres_droits(): void
    {
        /*
         * Le menu gardait la production sur `catalogue_produits` et
         * `stock_articles`, alors que ses routes exigent `production_recettes`
         * et `production_ordres`. Les deux se contredisaient : un employé à qui
         * l'on accordait la production ne voyait pas l'entrée, et celui qui la
         * voyait se faisait refuser à la porte.
         */
        $gabarit = file_get_contents(
            base_path('app/Modules/Admin/Vues/gabarits/application.blade.php')
        );

        $i = strpos($gabarit, "<!-- 5. Production -->");
        $this->assertNotFalse($i, 'Le bloc Production a disparu du menu.');

        $bloc = substr($gabarit, $i, 1400);

        $this->assertStringContainsString("aHabilitation('production_recettes')", $bloc);
        $this->assertStringContainsString("aHabilitation('production_ordres')", $bloc);
        $this->assertStringNotContainsString("aHabilitation('catalogue_produits')", $bloc);
        $this->assertStringNotContainsString("aHabilitation('stock_articles')", $bloc);
    }

    public function test_l_ecran_des_stickers_a_disparu(): void
    {
        // « Gestion FNE » porte le solde, la provision et les alertes, et
        // « Factures & Reçus émis/reçus » porte le reste. L'achat par Mobile
        // Money part avec l'écran : ce n'était pas Selflow qui vendait les
        // vignettes, et il n'en tenait qu'un journal parallèle.
        foreach (['admin.fne.stickers', 'admin.fne.stickers.acheter'] as $nom) {
            $this->assertNull(app('router')->getRoutes()->getByName($nom), $nom);
        }

        $this->assertFalse(
            file_exists(base_path('app/Modules/Admin/Vues/fne/stickers.blade.php'))
        );
    }

    public function test_les_adresses_d_historique_ont_disparu(): void
    {
        // Elles doublaient `/factures` sans rien apporter, aucun écran ne les
        // appelait, et leurs droits n'étaient proposés nulle part.
        $adresses = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($uri) => str_ends_with($uri, '/historique'));

        $this->assertCount(0, $adresses, 'Restent : ' . $adresses->implode(', '));
    }

    // ══════════════ La configuration fiscale ══════════════

    public function test_un_non_assujetti_n_a_pas_de_configuration_a_definir(): void
    {
        /*
         * Le badge réclamait une configuration qui n'avait rien à configurer.
         * En CAS B — non-assujetti (TEE, TCE, RME) —, la TVA et la TSE sont
         * grisées par la réglementation, et le timbre est décidé par la DGI à
         * la normalisation. Il ne reste aucun réglage à poser, et l'écran
         * affichait pourtant « Config. fiscale non définie » en orange.
         */
        $page = $this->get(route('admin.fne.gestion'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Config. fiscale non définie', $page);
        $this->assertStringContainsString('CAS B', $page);
    }

    public function test_un_assujetti_sans_configuration_se_le_voit_dire(): void
    {
        // En CAS A, il y a bien un choix à faire : la TVA et la TSE s'activent
        // ou non. Tant qu'il n'est pas fait, le dire.
        $this->entreprise->update(['regime_imposition' => 'RNI']);

        $page = $this->get(route('admin.fne.gestion'))->assertOk()->getContent();

        $this->assertStringContainsString('Config. fiscale à définir', $page);
    }

    // ══════════════ Le catalogue ══════════════

    public function test_le_catalogue_ne_pose_plus_de_dessin_sous_les_articles(): void
    {
        \App\Modules\Admin\Modeles\Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'INFO-001',
            'nom' => 'Imprimante HP430', 'type' => 'marchandise', 'unite' => 'pièce',
            'prix_achat' => 50000, 'prix_vente' => 65000, 'taux_tva' => 18,
        ]);

        $page = $this->get(route('admin.produits.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('images/articles/', $page);
    }
}
