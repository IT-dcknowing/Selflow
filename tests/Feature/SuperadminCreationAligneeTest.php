<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'écran de création du superadministrateur, aligné sur les décisions prises
 * pour l'inscription (retour du 15/10/2026).
 */
class SuperadminCreationAligneeTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);
        $this->superadmin = Utilisateur::create([
            'nom' => 'Meledje', 'prenom' => 'Abraham', 'email' => 'super@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => Habilitations::PLATEFORME,
        ]);
        $this->actingAs($this->superadmin);
    }

    private function creer(array $extra = [])
    {
        return $this->post(route('superadmin.entreprises.creer.enregistrer'), array_merge([
            'nom' => 'Quincaillerie du Plateau', 'email' => 'gerant@quincaillerie.ci',
            'gerant_password' => 'un-mot-de-passe', 'gerant_password_confirmation' => 'un-mot-de-passe',
            'plan_abonnement' => 'BUSINESS', 'quota_points_de_vente' => 3,
        ], $extra));
    }

    public function test_le_formulaire_est_aligne(): void
    {
        $page = $this->get(route('superadmin.entreprises.creer'))->assertOk()->getContent();

        // Quota par défaut : 3.
        $this->assertMatchesRegularExpression('/name="quota_points_de_vente"[^>]*value="3"/', $page);
        // Plus de modules, plus de grille de secteurs, plus de compte contribuable ni de forme juridique.
        $this->assertStringNotContainsString('name="modules_actifs[]"', $page);
        $this->assertStringNotContainsString('name="secteur_activite[]"', $page);
        $this->assertStringNotContainsString('name="compte_contribuable"', $page);
        $this->assertStringNotContainsString('name="forme_juridique"', $page);
        // Un seul champ par nom fiscal : celui du bloc FNE.
        $this->assertSame(1, substr_count($page, 'name="ncc"'));
        $this->assertSame(1, substr_count($page, 'name="rccm"'));
        // Le bouton « Suivant » du domaine, le « Voir » et la jauge du mot de passe.
        $this->assertStringContainsString('name="suite" value="parcours"', $page);
        $this->assertStringContainsString('basculerLesMotsDePasse', $page);
        $this->assertStringContainsString('id="jauge-mdp-barre"', $page);
        // Le bloc FNE : un choix se retire.
        $this->assertStringContainsString('data-fne-retirer', $page);
    }

    public function test_un_ncc_mal_forme_ne_bloque_pas_la_creation(): void
    {
        $this->creer(['possede_compte_fne' => '1', 'fne_ncc' => '2603'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('superadmin.entreprises'))
            ->assertSessionHas('avertissement');

        $this->assertNull(Entreprise::where('nom', 'Quincaillerie du Plateau')->value('ncc'));
    }

    public function test_suivant_deroule_le_parcours_de_l_entreprise(): void
    {
        $this->creer(['suite' => 'parcours'])->assertSessionHasNoErrors();
        $entreprise = Entreprise::where('nom', 'Quincaillerie du Plateau')->firstOrFail();

        $this->get(route('superadmin.entreprises.parcours', $entreprise))
            ->assertOk()
            ->assertSee(route('superadmin.entreprises.parcours.enregistrer', [$entreprise, 1]), false);

        $categorie = \App\Modules\Admin\Modeles\Referentiel\Categorie::query()->firstOrFail();
        $this->post(route('superadmin.entreprises.parcours.enregistrer', [$entreprise, 1]), ['categorie_id' => $categorie->id])
            ->assertRedirect(route('superadmin.entreprises.parcours', [$entreprise, 'etape' => 2]));

        $this->assertSame(1, (int) $entreprise->fresh()->souscription_etape);
        $this->assertSame([$categorie->nom], $entreprise->fresh()->secteur_activite);
    }

    public function test_les_modules_s_ouvrent_par_defaut(): void
    {
        $this->creer()->assertSessionHasNoErrors();

        $modules = Entreprise::where('nom', 'Quincaillerie du Plateau')->value('modules_actifs');
        $modules = is_array($modules) ? $modules : json_decode($modules, true);
        foreach (['ventes', 'achats', 'stock', 'b2b', 'fne'] as $module) {
            $this->assertContains($module, $modules);
        }
    }
}
