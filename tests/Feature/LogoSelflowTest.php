<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le logo officiel de Selflow (08/10/2026) remplace la lettre « S » et le
 * nuage générique, et devient l'icône de l'onglet du navigateur.
 */
class LogoSelflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_fichiers_du_logo_existent(): void
    {
        foreach (['favicon.ico', 'images/selflow/logo-blanc.png', 'images/selflow/logo-bleu.png',
                  'images/selflow/favicon-32.png', 'images/selflow/apple-touch-icon.png'] as $fichier) {
            $this->assertFileExists(public_path($fichier));
        }
    }

    public function test_les_pages_d_entree_portent_le_logo_et_l_icone(): void
    {
        foreach (['connexion', 'inscription'] as $route) {
            $this->get(route($route))->assertOk()
                ->assertSee('images/selflow/favicon-32.png', false)
                ->assertSee('images/selflow/logo-blanc.png', false)
                ->assertDontSee('fa-cloud"', false);
        }
    }

    public function test_la_barre_de_l_application_porte_le_logo(): void
    {
        $entreprise = Entreprise::create([
            'nom' => 'Boutique du logo', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody',
            'rccm' => 'CI-ABJ-2026-B-00777', 'ncc' => '2601777A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'ventes'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $admin = Utilisateur::create([
            'nom' => 'Logo', 'prenom' => 'Ama', 'email' => 'logo@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $entreprise->id, 'point_de_vente_id' => $site->id,
        ]);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $site->id])
            ->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('<div class="logo-icon"><img src="' . asset('images/selflow/logo-blanc.png'), false)
            ->assertSee('images/selflow/favicon-32.png', false);
    }
}
