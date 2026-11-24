<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 24/11/2026 : audit du parcours de paramétrage (domaines proposés, modules à cocher). */
class ParcoursDomainesEtModulesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);
        $this->entreprise = Entreprise::create(['nom' => 'Atelier Kone']);
        $this->admin = Utilisateur::create(['nom' => 'Kone', 'prenom' => 'Awa', 'email' => 'awa-parcours@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin', 'entreprise_id' => $this->entreprise->id]);
    }

    public function test_un_domaine_propose_s_affiche_en_premier_et_se_choisit(): void
    {
        $this->actingAs($this->admin)->post(route('admin.souscription.enregistrer', 1), ['nouveau_domaine' => '  Couture   sur mesure ']);
        $this->assertSame(['Couture sur mesure'], $this->entreprise->fresh()->domaines_personnalises);

        $page = $this->actingAs($this->admin)->get(route('admin.souscription.index', ['etape' => 1]))->getContent();
        $this->assertLessThan(strpos($page, 'value="' . \App\Modules\Admin\Modeles\Referentiel\Categorie::first()->id . '"'), strpos($page, 'value="Couture sur mesure"'));

        $this->actingAs($this->admin)->post(route('admin.souscription.enregistrer', 1), ['domaine_personnalise' => 'Couture sur mesure'])
            ->assertRedirect(route('admin.souscription.index', ['etape' => 2]));
        $this->assertSame(['Couture sur mesure'], (array) $this->entreprise->fresh()->secteur_activite, 'Un domaine propre complète l\'inscription.');
    }

    public function test_un_domaine_forge_est_refuse(): void
    {
        $this->actingAs($this->admin)->post(route('admin.souscription.enregistrer', 1), ['domaine_personnalise' => 'Domaine inventé'])
            ->assertSessionHasErrors('domaine_personnalise');
        $this->assertEmpty((array) $this->entreprise->fresh()->domaines_souscrits);
    }

    public function test_les_modules_se_cochent_et_se_decochent(): void
    {
        $this->entreprise->forceFill(['modules_actifs' => ['principal', 'points_de_vente', 'ventes', 'stock'], 'souscription_etape' => 2])->save();

        $this->actingAs($this->admin)->post(route('admin.souscription.enregistrer', 3), [
            'modules_choisis' => 1, 'modules' => ['ventes', 'achats'],
        ]);

        $modules = (array) $this->entreprise->fresh()->modules_actifs;
        $this->assertContains('achats', $modules);
        $this->assertNotContains('stock', $modules, 'Décoché, sans données : il se ferme.');
        $this->assertContains('principal', $modules, 'Les modules structurels restent.');
    }
}
