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

        // « Factures reçues du portail », sous Fiscalité & DGI, menait à la
        // section « Factures achat DGI » que le menu Achats porte déjà. Le
        // libellé est ce qui se voit ; c'est lui qu'il ne faut plus voir deux
        // fois.
        $this->assertStringNotContainsString('Factures re&ccedil;ues du portail', $menu);

        // Et l'entrée des Achats, elle, est restée.
        $this->assertStringContainsString('Factures re&ccedil;ues (portail', $menu);
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

    public function test_tout_droit_exige_par_une_route_se_propose_a_l_ecran(): void
    {
        /*
         * `historique_ventes` et `historique_achats` étaient exigés par leurs
         * routes et proposés par **aucune case** : aucune entreprise ne pouvait
         * les accorder à son personnel, et les deux adresses restaient fermées
         * à tous sauf à l'administrateur. Le superadmin les offrait pourtant
         * depuis son propre écran.
         */
        $droits = array_unique(array_values(Habilitations::PAR_ROUTE));

        $ecrans = '';
        foreach (['index', 'details'] as $vue) {
            $ecrans .= file_get_contents(
                base_path("app/Modules/Admin/Vues/personnel/{$vue}.blade.php")
            );
        }

        $absents = [];
        foreach ($droits as $droit) {
            if (!str_contains($ecrans, 'value="' . $droit . '"')) {
                $absents[] = $droit;
            }
        }

        $this->assertSame([], $absents,
            'Ces droits sont exigés par une route et ne se proposent nulle part : ' . implode(', ', $absents));
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
