<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Les durcissements de l'audit de sécurité du 16/10/2026. */
class DurcissementSecuriteTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $entreprise = Entreprise::create(['nom' => 'Boutique', 'modules_actifs' => ['principal', 'ventes', 'stock', 'produits']]);
        $site = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Site', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->admin = Utilisateur::create([
            'nom' => 'B', 'prenom' => 'C', 'email' => 'durci@exemple.ci', 'password' => bcrypt('secret-1234'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
    }

    public function test_le_jeton_d_api_n_est_garde_qu_en_empreinte(): void
    {
        $jeton = $this->postJson('/api/connexion', ['email' => 'durci@exemple.ci', 'password' => 'secret-1234'])
            ->assertOk()->json('token');

        $this->assertNotEmpty($jeton);
        $this->assertSame(hash('sha256', $jeton), $this->admin->fresh()->jeton_api);

        $this->getJson('/api/admin/stock', ['Authorization' => 'Bearer ' . $jeton])->assertOk();
        $this->getJson('/api/admin/stock', ['Authorization' => 'Bearer ' . hash('sha256', $jeton)])->assertStatus(401);
    }

    public function test_un_ancien_jeton_en_clair_marche_encore_une_fois_puis_est_remplace(): void
    {
        $this->admin->forceFill(['jeton_api' => 'ancien-jeton-en-clair'])->save();

        $this->getJson('/api/admin/stock', ['Authorization' => 'Bearer ancien-jeton-en-clair'])->assertOk();
        $this->assertSame(hash('sha256', 'ancien-jeton-en-clair'), $this->admin->fresh()->jeton_api);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/stock', ['Authorization' => 'Bearer ancien-jeton-en-clair'])->assertOk();
    }

    public function test_le_jeton_n_est_jamais_serialise(): void
    {
        $this->admin->forceFill(['jeton_api' => 'x'])->save();
        $this->assertArrayNotHasKey('jeton_api', $this->admin->fresh()->toArray());
    }

    public function test_hsts_sur_une_reponse_https_seulement(): void
    {
        $this->get('https://localhost/connexion')->assertHeader('Strict-Transport-Security');
        $this->get('http://localhost/connexion')->assertHeaderMissing('Strict-Transport-Security');
    }
}
