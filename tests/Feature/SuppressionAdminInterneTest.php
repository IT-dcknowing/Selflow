<?php

namespace Tests\Feature;

use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Production du 19/10/2026 : supprimer un administrateur interne tombait en
 * « Class App\Modules\Admin\Controleurs\Auth not found » — la façade n'était
 * pas importée dans `SuperadminControleur`.
 */
class SuppressionAdminInterneTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(string $email): Utilisateur
    {
        return Utilisateur::create([
            'nom' => 'Admin', 'prenom' => 'Interne', 'email' => $email, 'password' => bcrypt('secret-de-test'),
            'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    public function test_supprimer_un_admin_interne_fonctionne(): void
    {
        $moi = $this->superadmin('moi@selflow.ci');
        $autre = $this->superadmin('autre@selflow.ci');

        $this->actingAs($moi)->delete(route('superadmin.admins.supprimer', $autre))
            ->assertRedirect(route('superadmin.admins.index'))->assertSessionHas('succes');
        $this->assertNull(Utilisateur::find($autre->id));
    }

    public function test_on_ne_se_supprime_pas_soi_meme(): void
    {
        $moi = $this->superadmin('moi@selflow.ci');

        $this->actingAs($moi)->delete(route('superadmin.admins.supprimer', $moi))->assertSessionHas('erreur');
        $this->assertNotNull(Utilisateur::find($moi->id));
    }

    public function test_un_compte_d_entreprise_ne_se_supprime_pas_d_ici(): void
    {
        $moi = $this->superadmin('moi@selflow.ci');
        $client = Utilisateur::create([
            'nom' => 'C', 'prenom' => 'D', 'email' => 'client@exemple.ci', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->actingAs($moi)->delete(route('superadmin.admins.supprimer', $client))->assertNotFound();
        $this->assertNotNull(Utilisateur::find($client->id));
    }
}
