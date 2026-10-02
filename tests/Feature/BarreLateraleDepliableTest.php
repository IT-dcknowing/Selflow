<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La barre latérale se déplie, et le résultat par site devient un rapport.
 *
 * Deux demandes du propriétaire, le 02/10/2026 :
 *
 * 1. « Au lieu d'afficher les sections avec leurs pages comme ça maintenant,
 *    ce sera de les déplier au clic. » Les neuf sections et toutes leurs
 *    pages s'affichaient d'un bloc : la barre faisait trois écrans de haut
 *    et on cherchait un lien en faisant défiler.
 *
 * 2. « Mets la page Résultat par site dans la section Rapport. » C'est un
 *    rapport d'exploitation — quel magasin gagne de l'argent — et non un
 *    écran de tenue de livres.
 *
 * Le second point a une conséquence que l'énoncé ne dit pas : l'écran était
 * gardé par l'habilitation `comptabilite_globale`. Le laisser ainsi aurait
 * mis dans la section Rapports un lien qui répond **403 (Forbidden — accès
 * interdit)** à qui n'a que les rapports — et qui disparaîtrait le jour où
 * la comptabilité se masque, ce que prépare le chantier suivant.
 */
class BarreLateraleDepliableTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $premierSite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'TEE',
            'adresse' => 'Riviera II', 'rccm' => 'CI-ABJ-2018-B-31734',
            'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => [
                'principal', 'ventes', 'achats', 'stock', 'produits',
                'tiers', 'comptabilite', 'points_de_vente', 'rapports',
            ],
        ]);

        $this->premierSite = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);
    }

    private function admin(): Utilisateur
    {
        return $this->compte('admin');
    }

    /**
     * Un délégué, et non le propriétaire.
     *
     * `aHabilitation()` rend `true` pour tout au propriétaire : l'éprouver
     * avec un jeu d'habilitations restreint ne prouverait rien, l'écran
     * s'ouvrirait de toute façon. Seul un `admin_secondaire` fait trancher
     * les habilitations écran par écran.
     */
    private function delegue(array $habilitations): Utilisateur
    {
        return $this->compte('admin_secondaire', $habilitations);
    }

    private function compte(string $role, ?array $habilitations = null): Utilisateur
    {
        static $rang = 0;
        $rang++;

        $utilisateur = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis',
            'email' => "lewis-barre-{$rang}@exemple.ci",
            'password' => bcrypt('secret-de-test'), 'role' => $role,
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->premierSite->id,
        ]);

        if ($habilitations !== null) {
            $utilisateur->habilitations = $habilitations;
            $utilisateur->save();
        }

        return $utilisateur;
    }

    private function secondSite(): PointDeVente
    {
        return PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-cocody', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);
    }

    private function menuDe(Utilisateur $utilisateur): string
    {
        return $this->actingAs($utilisateur)
            ->withSession(['point_de_vente_actif_id' => $this->premierSite->id])
            ->get(route('admin.entreprise.parametres'))
            ->assertOk()
            ->getContent();
    }

    // ══════════════ 1.1 — le dépliage ══════════════

    public function test_la_barre_porte_le_script_qui_groupe_les_sections(): void
    {
        $menu = $this->menuDe($this->admin());

        // Le groupement se fait à l'exécution : une entrée suit son titre à
        // plat, et le script les range ensemble.
        $this->assertStringContainsString('nav-groupe', $menu);
        $this->assertStringContainsString("classList.add('js-nav')", $menu);
    }

    public function test_le_menu_se_replie_seulement_quand_le_script_a_tourne(): void
    {
        $menu = $this->menuDe($this->admin());

        /*
         * Toute la règle de repli est préfixée par `.js-nav`, que seul le
         * script pose. Sans JavaScript — ou si le script s'interrompt —, le
         * menu reste la liste complète d'avant, et non un menu dont aucune
         * section ne s'ouvrirait.
         */
        $this->assertStringContainsString(
            '.js-nav .nav-section[aria-expanded="false"] + .nav-groupe { display: none; }',
            $menu,
            "Le repli doit être conditionné à la classe que pose le script."
        );
    }

    public function test_la_marque_js_est_posee_apres_le_groupement_et_non_avant(): void
    {
        $menu = $this->menuDe($this->admin());

        /*
         * L'ordre n'est pas indifférent : poser `js-nav` avant de ranger les
         * entrées replierait un menu que le script n'a pas encore groupé —
         * c'est-à-dire tout cacher.
         */
        $positionGroupement = strpos($menu, "menu.insertBefore(groupe");
        $positionMarque     = strpos($menu, "classList.add('js-nav')");

        $this->assertNotFalse($positionGroupement);
        $this->assertNotFalse($positionMarque);
        $this->assertLessThan($positionMarque, $positionGroupement);
    }

    public function test_chaque_role_garde_sa_propre_memoire_de_ce_qui_est_deplie(): void
    {
        $menu = $this->menuDe($this->admin());

        /*
         * Les trois barres ne portent pas les mêmes sections. Une mémoire
         * commune ferait qu'un superadministrateur repliant « Supervision »
         * refermerait « Ventes » dans l'espace d'administration.
         */
        $this->assertStringContainsString('data-menu="admin"', $menu);
        $this->assertStringContainsString("'selflow.menu.' + (menu.dataset.menu", $menu);
    }

    public function test_la_memoire_du_menu_survit_a_un_stockage_refuse(): void
    {
        $menu = $this->menuDe($this->admin());

        /*
         * En navigation privée, ou données de site bloquées, `localStorage`
         * lève. Le menu doit alors fonctionner sans mémoire plutôt que de
         * refuser de s'ouvrir : les deux accès sont enveloppés.
         */
        $this->assertStringContainsString('function lireLaMemoire()', $menu);
        $this->assertStringContainsString('function ecrireLaMemoire(', $menu);
        $this->assertSame(
            2,
            substr_count($menu, 'catch (e)') >= 2 ? 2 : substr_count($menu, 'catch (e)'),
            "Les deux accès au stockage doivent être enveloppés."
        );
    }

    public function test_la_section_de_l_ecran_courant_s_ouvre_malgre_la_memoire(): void
    {
        $menu = $this->menuDe($this->admin());

        // Sans cela, on arrive sur une page sans voir d'où elle vient.
        $this->assertStringContainsString('var ouverte = porteLActif || memoire[titre] === true;', $menu);
    }

    public function test_un_titre_de_section_sans_aucune_entree_ne_s_affiche_pas(): void
    {
        $menu = $this->menuDe($this->admin());

        /*
         * Les habilitations retirent les entrées une à une ; le titre, lui,
         * est porté par une condition plus large. Un titre seul mène à rien.
         */
        $this->assertStringContainsString("noeud.classList.add('nav-section-vide')", $menu);
        $this->assertStringContainsString('.js-nav .nav-section.nav-section-vide { display: none; }', $menu);
    }

    public function test_une_section_se_deplie_aussi_au_clavier(): void
    {
        $menu = $this->menuDe($this->admin());

        // Un titre devenu commande doit s'annoncer comme tel et s'actionner
        // sans souris.
        $this->assertStringContainsString("setAttribute('role', 'button')", $menu);
        $this->assertStringContainsString("setAttribute('tabindex', '0')", $menu);
        $this->assertStringContainsString("evenement.key === 'Enter'", $menu);
    }

    // ══════════════ 1.2 — le résultat par site ══════════════

    public function test_le_resultat_par_site_a_quitte_le_menu_comptabilite(): void
    {
        $this->secondSite();
        $menu = $this->menuDe($this->admin());

        $positionComptabilite = strpos($menu, '<span>Comptabilité</span>');
        $positionRapports     = strpos($menu, '<span>Rapports</span>');
        $positionResultat     = strpos($menu, 'Résultat par site');

        $this->assertNotFalse($positionResultat, "Le lien doit rester au menu.");
        $this->assertNotFalse($positionRapports);
        $this->assertGreaterThan(
            $positionRapports,
            $positionResultat,
            "« Résultat par site » doit venir après le titre Rapports."
        );
        $this->assertGreaterThan($positionComptabilite, $positionRapports);
    }

    public function test_le_resultat_par_site_ne_parait_qu_a_partir_de_deux_sites(): void
    {
        // Comparer un magasin à lui-même n'apprend rien, et le lien
        // encombrerait le menu d'un commerce qui n'en a qu'un.
        $this->assertStringNotContainsString('Résultat par site', $this->menuDe($this->admin()));

        $this->secondSite();
        $this->assertStringContainsString('Résultat par site', $this->menuDe($this->admin()));
    }

    public function test_l_ecran_s_ouvre_a_qui_n_a_que_les_rapports(): void
    {
        $this->secondSite();

        /*
         * C'est le point que l'énoncé ne disait pas. Déplacer le lien sans
         * déplacer l'habilitation aurait donné, dans la section Rapports, un
         * lien répondant 403 (Forbidden — accès interdit).
         */
        $utilisateur = $this->delegue(['rapports_analyse']);

        $this->actingAs($utilisateur)
            ->withSession(['point_de_vente_actif_id' => $this->premierSite->id])
            ->get(route('admin.comptabilite.analytique'))
            ->assertOk();
    }

    public function test_l_ecran_se_refuse_a_qui_n_a_pas_les_rapports(): void
    {
        $this->secondSite();

        // Le déplacement ne doit pas ouvrir l'écran à tout le monde : il
        // change de clé, il n'en perd pas.
        $utilisateur = $this->delegue(['catalogue_produits']);

        $reponse = $this->actingAs($utilisateur)
            ->withSession(['point_de_vente_actif_id' => $this->premierSite->id])
            ->get(route('admin.comptabilite.analytique'));

        $this->assertContains($reponse->getStatusCode(), [302, 403],
            "Un accès sans l'habilitation doit être refusé ou renvoyé.");
    }

    public function test_la_route_est_rangee_sous_l_habilitation_des_rapports(): void
    {
        // La vérification des habilitations refuse ce qui n'est pas classé :
        // l'entrée doit exister, et porter la bonne clé.
        $this->assertSame(
            'rapports_analyse',
            Habilitations::PAR_ROUTE['admin.comptabilite.analytique'] ?? null
        );
    }
}
