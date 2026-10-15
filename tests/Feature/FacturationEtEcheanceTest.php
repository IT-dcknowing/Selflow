<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Referentiel\Profil;
use App\Modules\Admin\Regles\Abonnements;
use App\Modules\Authentification\Modeles\Utilisateur;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La section Facturation et l'abonnement arrivé à échéance (15/10/2026).
 */
class FacturationEtEcheanceTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entreprise = Entreprise::create([
            'nom' => 'Boutique', 'plan_abonnement' => Abonnements::BUSINESS, 'quota_points_de_vente' => 3,
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'points_de_vente', 'personnel'],
        ]);
        $this->entreprise->forceFill(['quota_utilisateurs' => 3, 'abonnement_expire_le' => now()->addDays(10)])->save();
        PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id,
        ]);
    }

    private function aTerme(): void
    {
        $this->entreprise->forceFill(['abonnement_expire_le' => now()->subDay()])->save();
    }

    public function test_la_section_facturation_montre_l_offre_et_des_cartes(): void
    {
        $page = $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        $this->assertStringContainsString('id="section-facturation"', $page);
        $this->assertStringContainsString('10 jours restants', $page);
        $this->assertStringContainsString('Renouveler', $page);
        $this->assertStringContainsString('Passer à Premium', $page);
        $this->assertStringContainsString('Offre inférieure', $page);
        $this->assertStringContainsString('Sites et utilisateurs illimités', $page);
    }

    public function test_renouveler_avant_l_echeance_note_la_demande(): void
    {
        $this->actingAs($this->admin)->post(route('admin.abonnement.renouveler'), ['depuis' => 'facturation'])
            ->assertRedirect(route('admin.entreprise.parametres') . '#facturation')
            ->assertSessionHas('succes');

        $e = $this->entreprise->fresh();
        $this->assertSame(Abonnements::BUSINESS, $e->abonnement_demande);
        $this->assertSame(Abonnements::STATUT_A_REGLER, $e->abonnement_statut);
    }

    public function test_a_terme_rien_ne_s_enregistre(): void
    {
        $this->aTerme();

        $this->actingAs($this->admin)->post(route('admin.personnel.creer'), [
            'nom' => 'Koné', 'prenom' => 'Awa', 'email' => 'awa@exemple.ci', 'password' => 'secret-1', 'role' => 'caissier',
        ])->assertRedirect(route('admin.entreprise.parametres') . '#facturation')
          ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Abonnement à terme'));

        $this->assertDatabaseMissing('utilisateurs', ['email' => 'awa@exemple.ci']);
    }

    public function test_a_terme_on_voit_l_espace_sous_une_fenetre_avec_continuer(): void
    {
        $this->aTerme();

        $this->actingAs($this->admin)->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('id="voile-abonnement-a-terme"', false)
            ->assertSee('Abonnement à terme')
            ->assertSee('btn-continuer-a-terme', false);

        // Sur la facturation, la fenêtre laisse la place à la section.
        $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertDontSee('id="voile-abonnement-a-terme"', false)
            ->assertSee('id="bandeau-a-terme"', false)
            ->assertSee('class="abonnement-a-terme sur-facturation"', false);
    }

    public function test_a_terme_on_peut_encore_renouveler_ou_monter_en_gamme(): void
    {
        $this->aTerme();

        $this->actingAs($this->admin)->post(route('admin.abonnement.enregistrer'), ['offre' => Abonnements::PREMIUM, 'depuis' => 'facturation'])
            ->assertSessionHas('succes');

        $e = $this->entreprise->fresh();
        $this->assertSame(Abonnements::PREMIUM, $e->plan_abonnement);
        // Seul le règlement repousse l'échéance : l'espace reste à terme.
        $this->assertTrue(Abonnements::estATerme($e));
    }

    public function test_le_caissier_est_prevenu_sans_bouton_vers_la_facturation(): void
    {
        $this->aTerme();
        $caissier = Utilisateur::create([
            'nom' => 'Koné', 'prenom' => 'Awa', 'email' => 'awa@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'caissier', 'entreprise_id' => $this->entreprise->id, 'habilitations' => ['factures_vente'],
            'point_de_vente_id' => PointDeVente::where('entreprise_id', $this->entreprise->id)->value('id'),
        ]);

        $page = $this->actingAs($caissier)->get(route('caissier.ventes.factures'))->assertOk()->getContent();
        $this->assertStringContainsString('id="voile-abonnement-a-terme"', $page);
        $this->assertStringContainsString('Prévenez l\'administrateur', $page);
        $this->assertStringNotContainsString('btn-continuer-a-terme', $page);
    }

    public function test_la_poissonnerie_est_un_metier_du_parcours(): void
    {
        $this->seed(ReferentielSeeder::class);

        $profil = Profil::where('code', 'poissonnerie')->firstOrFail();
        $this->assertSame('Poissonnerie', $profil->nom);
        $this->assertGreaterThanOrEqual(5, $profil->familles()->count());
    }
}
