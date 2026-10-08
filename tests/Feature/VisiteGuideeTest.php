<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La visite guidée et la bannière de configuration.
 *
 * Un utilisateur qui ouvre Selflow pour la première fois voit une main lui
 * désigner les quatre endroits qui comptent, et une bannière lui dire par où
 * commencer. Ces tests fixent quand cela apparaît — et surtout quand cela
 * n'apparaît plus.
 */
class VisiteGuideeTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);

        $this->entreprise = Entreprise::create(['nom' => 'Boutique du carrefour']);
        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
        ]);
    }

    public function test_la_visite_s_ouvre_a_la_premiere_venue(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.tableau_de_bord'))
            ->assertOk()
            ->assertSee('visite-guidee')
            ->assertSee('Bienvenue dans Selflow');
    }

    public function test_la_visite_ne_revient_pas_une_fois_faite(): void
    {
        $this->admin->forceFill(['visite_guidee_terminee_le' => now()])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.tableau_de_bord'))
            ->assertOk()
            ->assertDontSee('Bienvenue dans Selflow');
    }

    public function test_terminer_la_visite_la_retient_en_base(): void
    {
        // En base et non dans le navigateur : changer de poste ne doit pas la
        // faire recommencer, ni la faire disparaitre pour qui ne l'a jamais vue.
        $this->actingAs($this->admin)
            ->post(route('admin.visite.terminer'))
            ->assertOk()
            ->assertJson(['statut' => 'ok']);

        $this->assertNotNull($this->admin->fresh()->visite_guidee_terminee_le);
        $this->assertTrue($this->admin->fresh()->aFaitLaVisite());
    }

    public function test_la_visite_se_rejoue_a_la_demande(): void
    {
        // L'entree de menu est un formulaire ordinaire : elle doit ramener
        // l'utilisateur sur un ecran, la visite ouverte.
        $this->admin->forceFill(['visite_guidee_terminee_le' => now()])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.visite.rejouer'))
            ->assertRedirect(route('admin.tableau_de_bord'));

        $this->assertNull($this->admin->fresh()->visite_guidee_terminee_le);

        $this->actingAs($this->admin)
            ->get(route('admin.tableau_de_bord'))
            ->assertSee('Bienvenue dans Selflow');
    }

    public function test_rejouer_repond_en_json_a_un_appel_de_script(): void
    {
        $this->admin->forceFill(['visite_guidee_terminee_le' => now()])->save();

        $this->actingAs($this->admin)
            ->postJson(route('admin.visite.rejouer'))
            ->assertOk()
            ->assertJson(['statut' => 'ok']);
    }

    public function test_le_menu_propose_de_revoir_la_visite(): void
    {
        // Une visite qu'on ne peut pas relancer se ferme une fois pour toutes,
        // souvent par mégarde des la premiere seconde.
        $this->admin->forceFill(['visite_guidee_terminee_le' => now()])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.tableau_de_bord'))
            ->assertOk()
            ->assertSee('Revoir la visite guidée', false)
            ->assertSee(route('admin.visite.rejouer'), false);
    }

    public function test_chaque_utilisateur_a_sa_propre_visite(): void
    {
        // Un vendeur qui rejoint une entreprise deja configuree decouvre
        // l'application pour la premiere fois, lui aussi.
        $this->admin->forceFill(['visite_guidee_terminee_le' => now()])->save();

        $vendeur = Utilisateur::create([
            'nom' => 'Aka', 'prenom' => 'Marie', 'email' => 'marie@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
        ]);

        $this->assertFalse($vendeur->aFaitLaVisite());
        $this->assertTrue($this->admin->fresh()->aFaitLaVisite());
    }

    public function test_la_banniere_annonce_la_configuration_et_les_prix(): void
    {
        // Le point que l'utilisateur doit savoir avant sa premiere facture : le
        // catalogue arrive rempli, mais sans prix.
        $this->actingAs($this->admin)
            ->get(route('admin.tableau_de_bord'))
            ->assertOk()
            ->assertSee('Configurez votre métier en cinq minutes')
            ->assertSee('saisir vos prix');
    }

    public function test_la_banniere_disparait_une_fois_la_configuration_faite(): void
    {
        $this->entreprise->update(['souscription_terminee_le' => now()]);

        $this->actingAs($this->admin)
            ->get(route('admin.tableau_de_bord'))
            ->assertOk()
            ->assertDontSee('Configurez votre metier en cinq minutes');
    }

    public function test_la_banniere_ne_s_affiche_pas_pendant_le_parcours(): void
    {
        // La proposer sur l'ecran ou l'on est deja n'aurait aucun sens.
        $this->actingAs($this->admin)
            ->get(route('admin.souscription.index'))
            ->assertOk()
            ->assertDontSee('Configurez votre metier en cinq minutes');
    }

    public function test_terminer_la_visite_exige_d_etre_connecte(): void
    {
        $this->post(route('admin.visite.terminer'))->assertRedirect();
    }
}
