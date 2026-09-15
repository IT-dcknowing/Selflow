<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Database\Seeders\SelflowCompleteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le modal des clés FNE du superadmin, et ce qui effaçait la production.
 *
 * Le bouton « Gérer » passait le numéro de ligne de l'entreprise, là où ses
 * routes se lient par l'identifiant public : chaque appel répondait 404, et
 * l'écran l'annonçait « Erreur réseau », en ligne comme en local. Le
 * superadmin ne pouvait donc saisir aucune clé.
 *
 * Et `deploy-production.sh` lançait à chaque mise en ligne un jeu de
 * démonstration qui commence par vider les tables.
 */
class GestionDesClesFneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'ncc' => '1864699A', 'regime_imposition' => 'RNI',
            'adresse' => 'Abidjan', 'rccm' => 'CI-ABJ-2026-B-1', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
        ]);

        $this->superadmin = Utilisateur::create([
            'nom' => 'Meledje', 'prenom' => 'Abraham', 'email' => 'super@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    public function test_le_bouton_gerer_porte_l_identifiant_public(): void
    {
        $page = $this->actingAs($this->superadmin)->get(route('superadmin.fne.index'))->assertOk()->getContent();

        $this->assertStringContainsString("ouvrirModalGestion('{$this->entreprise->uuid}'", $page);
        $this->assertStringNotContainsString("ouvrirModalGestion({$this->entreprise->id},", $page);
    }

    public function test_sans_cle_le_mot_de_passe_ouvre_la_gestion_en_json(): void
    {
        // 404 en JSON « Aucune clé enregistrée » : l'écran passe à la saisie.
        $this->actingAs($this->superadmin)
            ->postJson(route('superadmin.fne.voir_cle', $this->entreprise), ['mot_de_passe' => 'secret-de-test', 'type' => 'test'])
            ->assertNotFound()
            ->assertJson(['success' => false, 'message' => 'Aucune clé enregistrée.']);
    }

    public function test_une_cle_enregistree_se_lit_avec_le_bon_mot_de_passe(): void
    {
        FneCredential::create(['entreprise_id' => $this->entreprise->id, 'cle_test' => 'fne_cle_de_test', 'statut' => 'test']);

        $this->actingAs($this->superadmin)
            ->postJson(route('superadmin.fne.voir_cle', $this->entreprise), ['mot_de_passe' => 'secret-de-test', 'type' => 'test'])
            ->assertOk()
            ->assertJson(['success' => true, 'cle' => 'fne_cle_de_test']);

        $this->actingAs($this->superadmin)
            ->postJson(route('superadmin.fne.voir_cle', $this->entreprise), ['mot_de_passe' => 'mauvais', 'type' => 'test'])
            ->assertForbidden();
    }

    public function test_le_numero_de_ligne_ne_designe_aucune_entreprise(): void
    {
        // En ligne, Laravel rend 404 (Not Found — introuvable) ; ici, l'entreprise
        // introuvable remonte telle quelle. Les deux disent la même chose : le
        // numéro de ligne ne désigne aucune entreprise.
        try {
            $this->actingAs($this->superadmin)
                ->postJson("/superadmin/fne/{$this->entreprise->id}/voir-cle", ['mot_de_passe' => 'secret-de-test', 'type' => 'test'])
                ->assertNotFound()
                ->assertJsonMissing(['message' => 'Aucune clé enregistrée.']);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            $this->assertStringContainsString('Entreprise', $e->getMessage());
        }
    }

    public function test_le_deploiement_ne_peuple_plus_la_production(): void
    {
        $script = file_get_contents(base_path('deploy-production.sh'));

        // Le référentiel seul est permis : il ne supprime rien. Un `db:seed`
        // sans classe lancerait `DonneesInitialesSeeder` et ses entreprises
        // fictives ; `SelflowCompleteSeeder` vide les tables.
        preg_match_all('/^\s*php artisan db:seed(.*)$/m', $script, $appels);
        foreach ($appels[1] as $arguments) {
            $this->assertStringContainsString('--class=ReferentielSeeder', $arguments);
        }
        $this->assertStringContainsString('--class=ReferentielSeeder', $script);
    }

    public function test_aucun_mot_de_passe_en_clair_dans_les_scripts_de_deploiement(): void
    {
        foreach (['deploy-production.sh', 'deploy-seed.sh'] as $script) {
            $contenu = file_get_contents(base_path($script));

            $this->assertStringNotContainsString('12345678SUPER@', $contenu, $script);
            $this->assertStringNotContainsString('Selflow2026@', $contenu, $script);
        }
    }

    public function test_le_jeu_de_demonstration_refuse_une_base_qui_porte_des_entreprises(): void
    {
        try {
            $this->seed(SelflowCompleteSeeder::class);
            $this->fail('Le jeu de démonstration aurait vidé la base.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusé', $e->getMessage());
        }

        $this->assertTrue(Entreprise::where('ncc', '1864699A')->exists());
        $this->assertTrue(Utilisateur::where('email', 'super@exemple.ci')->exists());
    }

    public function test_le_peuplement_massif_refuse_la_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('selflow:seed-massif', ['--force' => true])->assertExitCode(1);

        $this->assertTrue(Entreprise::where('ncc', '1864699A')->exists());
    }
}
