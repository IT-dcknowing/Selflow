<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Regles\Abonnements;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 24/11/2026 : la page Abonnement / réabonnement montre toutes les
 * entreprises, les antérieures sans échéance comprises, avec Business
 * (25 000 F) proposé.
 */
class AbonnementsSansEcheanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_entreprise_sans_echeance_est_listee_et_s_abonne_en_business(): void
    {
        $ancienne = Entreprise::create(['nom' => 'Ancienne Boutique']);
        $ancienne->forceFill(['abonnement_expire_le' => null, 'plan_abonnement' => Abonnements::A_CHOISIR])->save();
        $super = Utilisateur::create(['nom' => 'S', 'prenom' => 'A', 'email' => 'super-abo@exemple.ci', 'password' => bcrypt('x-secret-de-test'),
            'role' => 'superadmin', 'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME]);

        $page = $this->actingAs($super)->get(route('superadmin.paiements.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Sans échéance', $page);
        $this->assertStringContainsString('Ancienne Boutique', $page);
        $this->assertMatchesRegularExpression('/<option value="BUSINESS" selected/', $page);

        $this->actingAs($super)->post(route('superadmin.paiements.prolonger', $ancienne), ['offre' => Abonnements::BUSINESS, 'mois' => 1])
            ->assertSessionHasNoErrors();

        $e = $ancienne->fresh();
        $this->assertSame(Abonnements::BUSINESS, $e->plan_abonnement);
        $this->assertNotNull($e->abonnement_expire_le);
        $this->assertTrue($e->abonnement_expire_le->isFuture());
    }
}
