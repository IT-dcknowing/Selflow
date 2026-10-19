<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retour client du 19/10/2026 : « le volet Stock ne s'affiche pas », alors
 * que la case était cochée. Le module n'était pas ouvert pour l'entreprise :
 * l'écran le dit désormais, et la matrice ne montre plus un droit qui
 * n'ouvre rien.
 */
class VoletStockEtRecuTest extends TestCase
{
    use RefreshDatabase;

    private function dossier(array $modules): array
    {
        $e = Entreprise::create(['nom' => 'Prestations', 'modules_actifs' => $modules]);
        $site = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'S', 'ville' => 'Abidjan', 'commune' => 'P']);
        $admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@presta.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $e->id, 'point_de_vente_id' => $site->id,
        ]);
        $employe = Utilisateur::create([
            'nom' => 'E', 'prenom' => 'F', 'email' => 'employe@presta.ci', 'password' => bcrypt('x'),
            'role' => 'responsable_pdv', 'statut' => 'actif', 'entreprise_id' => $e->id, 'point_de_vente_id' => $site->id,
            'habilitations' => ['tableau_de_bord_personnel', 'stock_articles'],
        ]);

        return [$e, $admin, $employe];
    }

    public function test_module_ferme_la_matrice_le_dit_et_ne_montre_pas_stock(): void
    {
        [, $admin] = $this->dossier(['principal', 'ventes', 'achats', 'points_de_vente']);

        $page = $this->actingAs($admin)->get(route('admin.personnel.index', ['tab' => 'habilitations']))->assertOk();
        $page->assertSee('Module(s) non ouvert(s)', false)->assertSee('Stock');
        $this->assertStringNotContainsString('title="Articles, stock &amp; entrées"', $page->getContent());
    }

    public function test_module_ouvert_le_volet_apparait_pour_qui_a_le_droit(): void
    {
        [, , $employe] = $this->dossier(['principal', 'ventes', 'achats', 'points_de_vente', 'stock']);

        $this->actingAs($employe)->get(route('admin.stock.index'))->assertOk()
            ->assertSee('Articles & stock', false)->assertSee('fa-boxes-stacked nav-section-icone', false);
    }

    public function test_le_diagnostic_dit_pourquoi_le_volet_manque(): void
    {
        $this->dossier(['principal', 'ventes', 'points_de_vente']);

        $this->artisan('selflow:diagnostic-acces', ['email' => 'employe@presta.ci'])
            ->expectsOutputToContain('Droits cochés sans effet')
            ->assertSuccessful();
    }
}
