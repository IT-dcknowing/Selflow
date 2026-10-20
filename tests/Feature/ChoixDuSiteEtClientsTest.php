<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Décisions du 20/10/2026 :
 * - un seul point de vente : il est actif d'office ; plusieurs et aucun
 *   actif : la page demande d'en choisir un (sans rien bloquer) ;
 * - la caisse ne propose que les clients du point de vente actif.
 */
class ChoixDuSiteEtClientsTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Magasins', 'modules_actifs' => ['principal', 'ventes', 'achats', 'tiers', 'points_de_vente']]);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@magasins.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
        ]);
    }

    private function site(string $nom): PointDeVente
    {
        return PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => $nom, 'ville' => 'Abidjan', 'commune' => $nom]);
    }

    public function test_un_seul_site_est_actif_d_office(): void
    {
        $seul = $this->site('Unique');

        $this->actingAs($this->admin)->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertDontSee('id="choix-du-site"', false);
        $this->assertSame($seul->id, (int) session('point_de_vente_actif_id'));
    }

    public function test_plusieurs_sites_la_page_demande_d_en_choisir_un_sans_bloquer(): void
    {
        $a = $this->site('Treichville');
        $this->site('Adjamé');

        $this->actingAs($this->admin)->get(route('admin.ventes.nouvelle'))->assertOk()
            ->assertSee('id="choix-du-site"', false)->assertSee('Sélectionnez un point de vente')
            ->assertSee('Treichville')->assertSee('Adjamé');

        $this->post(route('admin.pdv.activer', $a))->assertSessionHas('succes');
        $this->get(route('admin.ventes.nouvelle'))->assertOk()->assertDontSee('id="choix-du-site"', false);
    }

    public function test_un_administrateur_secondaire_sans_gestion_des_sites_peut_choisir(): void
    {
        $a = $this->site('Treichville');
        $this->site('Adjamé');
        $second = Utilisateur::create([
            'nom' => 'S', 'prenom' => 'T', 'email' => 'second@magasins.ci', 'password' => bcrypt('x'), 'role' => 'admin_secondaire',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'habilitations' => ['nouvelle_vente'],
        ]);

        $this->actingAs($second)->post(route('admin.pdv.activer', $a))->assertSessionHas('succes');
        $this->assertSame($a->id, (int) session('point_de_vente_actif_id'));
    }

    public function test_la_caisse_ne_propose_que_les_clients_du_site_actif(): void
    {
        $treichville = $this->site('Treichville');
        $adjame = $this->site('Adjamé');

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $treichville->id]);
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Kouassi de Treichville']);
        $this->withSession(['point_de_vente_actif_id' => $adjame->id]);
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Kouassi d\'Adjamé']);

        $this->withSession(['point_de_vente_actif_id' => $treichville->id, 'point_de_vente_actif_nom' => 'Treichville'])
            ->get(route('admin.ventes.nouvelle'))->assertOk()
            ->assertSee('Kouassi de Treichville')->assertDontSee('Kouassi d&#039;Adjamé', false)->assertDontSee("Kouassi d'Adjamé");
    }
}
