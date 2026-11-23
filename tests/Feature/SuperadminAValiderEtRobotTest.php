<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Services\ScraperPortailFneService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** La carte « À valider » des entreprises et le robot FNE en direct (23/11/2026). */
class SuperadminAValiderEtRobotTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = Utilisateur::create(['nom' => 'S', 'prenom' => 'A', 'email' => 'sa@x.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'statut' => 'actif', 'habilitations' => ['gestion_entreprises', 'tableau_de_bord_superadmin']]);
    }

    public function test_les_inscriptions_en_attente_se_valident_d_un_coup(): void
    {
        Mail::fake();
        $a = Entreprise::create(['nom' => 'Boutique A', 'modules_actifs' => ['principal']]);
        $b = Entreprise::create(['nom' => 'Boutique B', 'modules_actifs' => ['principal']]);
        foreach ([$a, $b] as $e) {
            $e->forceFill(['statut' => 'en_attente', 'plan_abonnement' => 'solo'])->save();
        }

        $this->actingAs($this->superadmin)->get(route('superadmin.entreprises'))
            ->assertOk()->assertSee('À valider')->assertSee('Boutique A')->assertSee('Valider la sélection');

        $this->actingAs($this->superadmin)->post(route('superadmin.entreprises.valider_inscriptions'), ['entreprises' => [$a->id, $b->id]])
            ->assertSessionHas('succes');

        $this->assertSame('actif', $a->fresh()->statut);
        $this->assertSame('actif', $b->fresh()->statut);
    }

    public function test_le_robot_dit_ce_qui_tourne(): void
    {
        $e = Entreprise::create(['nom' => 'DC-KNOWING CGA', 'ncc' => '1864699A', 'modules_actifs' => ['principal']]);
        FneCredential::create(['entreprise_id' => $e->id, 'statut' => 'validee', 'ncc_associe' => '1864699A', 'acces_mot_de_passe' => 'x', 'telechargement_actif' => true]);
        Cache::put(ScraperPortailFneService::verrouVentes('1864699A'), ['fichier_etat' => '/nulle/part.json', 'lance_a' => time() - 120, 'expire_a' => time() + 600], 600);

        $donnees = $this->actingAs($this->superadmin)->getJson(route('superadmin.robot.donnees'))->assertOk()->json();

        $this->assertSame('DC-KNOWING CGA', $donnees['comptes'][0]['entreprise']);
        $this->assertSame('en_cours', $donnees['comptes'][0]['releves']['ventes']['statut']);
        $this->assertSame('libre', $donnees['comptes'][0]['releves']['achats']['statut']);
        $this->assertCount(3, $donnees['passages']);

        $this->actingAs($this->superadmin)->get(route('superadmin.robot.index'))->assertOk()->assertSee('Robot FNE en direct');
    }
}
