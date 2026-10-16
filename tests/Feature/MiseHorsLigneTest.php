<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\ParametrePlateforme;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Regles\MiseHorsLigne;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Retour du 16/10/2026 : couper la plateforme d'un bouton, et la rallumer. */
class MiseHorsLigneTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $superadmin;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['selflow.hors_ligne_fichier' => storage_path('framework/testing/hors-ligne-' . uniqid() . '.json')]);

        $this->superadmin = Utilisateur::create([
            'nom' => 'S', 'prenom' => 'A', 'email' => 'super-hl@selflow.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME,
        ]);
        $entreprise = Entreprise::create(['nom' => 'Boutique', 'modules_actifs' => ['principal', 'ventes']]);
        $site = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Site', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->admin = Utilisateur::create([
            'nom' => 'B', 'prenom' => 'C', 'email' => 'admin-hl@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        ParametrePlateforme::ecrire(ParametrePlateforme::ASSISTANCE_TELEPHONE, '+225 07 11 22 33 44');
        ParametrePlateforme::ecrire(ParametrePlateforme::ASSISTANCE_EMAIL, 'support@dc-knowing.com');
    }

    protected function tearDown(): void
    {
        MiseHorsLigne::rallumer();
        parent::tearDown();
    }

    public function test_couper_montre_la_page_rassurante_a_tous_sauf_au_superadmin(): void
    {
        $this->actingAs($this->superadmin)->get(route('superadmin.pannes.index'))->assertOk()->assertSee('Mettre hors ligne');

        $this->post(route('superadmin.pannes.hors_ligne'), ['action' => 'couper', 'message' => 'Retour vers 14 h'])
            ->assertSessionHas('succes');
        $this->assertTrue(MiseHorsLigne::active());

        // Le superadministrateur continue d'entrer.
        $this->get(route('superadmin.pannes.index'))->assertOk()->assertSee('Remettre en ligne');

        // Les autres voient la page de maintenance évolutive, avec les contacts.
        $this->actingAs($this->admin)->get(route('admin.tableau_de_bord'))
            ->assertStatus(503)
            ->assertSee('Maintenance évolutive')
            ->assertSee('Ne vous inquiétez pas')
            ->assertSee('+225 07 11 22 33 44')
            ->assertSee('support@dc-knowing.com')
            ->assertSee('Retour vers 14 h');

        // La page de connexion reste ouverte : sans elle, personne ne rallume.
        auth()->logout();
        $this->get(route('connexion'))->assertOk();
        $this->postJson('/api/external/list-companies')->assertStatus(503);

        // Rallumer.
        $this->actingAs($this->superadmin)->post(route('superadmin.pannes.hors_ligne'), ['action' => 'rallumer']);
        $this->assertFalse(MiseHorsLigne::active());
        $this->actingAs($this->admin)->get(route('admin.tableau_de_bord'))->assertStatus(200);
    }

    public function test_un_admin_d_entreprise_ne_peut_pas_couper(): void
    {
        $this->actingAs($this->admin)->post(route('superadmin.pannes.hors_ligne'), ['action' => 'couper']);
        $this->assertFalse(MiseHorsLigne::active());
    }
}
