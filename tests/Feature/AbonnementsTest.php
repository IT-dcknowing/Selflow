<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Regles\Abonnements;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les offres d'abonnement — décision du propriétaire du 15/10/2026.
 */
class AbonnementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferentielSeeder::class);
    }

    private function inscrire(array $extra = [])
    {
        return $this->post(route('inscription.traitement'), array_merge([
            'nom_entreprise' => 'Boutique du carrefour', 'nom' => 'Kouadio', 'prenom' => 'Lewis',
            'email' => 'lewis@exemple.ci', 'password' => 'un-mot-de-passe',
            'password_confirmation' => 'un-mot-de-passe', 'conditions' => '1',
        ], $extra));
    }

    private function entreprise(): Entreprise
    {
        return Entreprise::where('nom', 'Boutique du carrefour')->firstOrFail();
    }

    public function test_l_inscription_mene_aux_offres_en_cartes(): void
    {
        $this->inscrire()->assertRedirect(route('admin.abonnement.choisir'));

        $entreprise = $this->entreprise();
        $this->assertSame(Abonnements::A_CHOISIR, $entreprise->plan_abonnement);
        $this->assertSame(3, (int) $entreprise->quota_points_de_vente);
        $this->assertSame(3, $entreprise->quota_utilisateurs);

        $page = $this->get(route('admin.abonnement.choisir'))->assertOk()->getContent();
        foreach (['Solo', 'Business', 'Premium', 'Sur mesure', 'Pass cadeau', '10 000 F', '25 000 F', '45 000 F'] as $texte) {
            $this->assertStringContainsString($texte, $page);
        }
        $this->assertSame(5, substr_count($page, 'class="carte '));
    }

    public function test_la_vitrine_des_offres_est_publique(): void
    {
        $this->get(route('offres'))->assertOk()->assertSee('Commencer gratuitement')->assertSee(route('inscription'), false);
    }

    public function test_chaque_offre_pose_ses_contraintes(): void
    {
        $this->inscrire();
        $attendu = [
            Abonnements::SOLO => [1, 1], Abonnements::BUSINESS => [3, 3],
            Abonnements::PREMIUM => [Abonnements::ILLIMITE, Abonnements::ILLIMITE],
        ];
        foreach ($attendu as $offre => [$pdv, $utilisateurs]) {
            $this->post(route('admin.abonnement.enregistrer'), ['offre' => $offre, 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'TX-0001'])->assertRedirect(route('admin.tableau_de_bord'));
            $e = $this->entreprise();
            $this->assertSame($offre, $e->plan_abonnement);
            $this->assertSame($pdv, (int) $e->quota_points_de_vente);
            $this->assertSame($utilisateurs, $e->quota_utilisateurs);
            $this->assertSame(Abonnements::STATUT_A_REGLER, $e->abonnement_statut);
        }
    }

    public function test_le_pass_cadeau_dure_sept_jours_et_ne_s_offre_qu_une_fois(): void
    {
        $this->inscrire();
        $this->post(route('admin.abonnement.enregistrer'), ['offre' => Abonnements::PASS_CADEAU])->assertSessionHas('succes');

        $e = $this->entreprise();
        $this->assertSame(5, (int) $e->quota_points_de_vente);
        $this->assertSame(5, $e->quota_utilisateurs);
        $this->assertSame(Abonnements::STATUT_ESSAI, $e->abonnement_statut);
        $this->assertTrue($e->abonnement_expire_le->between(now()->addDays(6), now()->addDays(8)));

        $this->post(route('admin.abonnement.enregistrer'), ['offre' => Abonnements::SOLO, 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'TX-0001']);
        $this->post(route('admin.abonnement.enregistrer'), ['offre' => Abonnements::PASS_CADEAU])->assertSessionHas('erreur');
        $this->assertSame(Abonnements::SOLO, $this->entreprise()->plan_abonnement);
    }

    public function test_solo_n_admet_que_le_gerant(): void
    {
        $this->inscrire();
        $this->post(route('admin.abonnement.enregistrer'), ['offre' => Abonnements::SOLO, 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'TX-0001']);
        PointDeVente::create(['entreprise_id' => $this->entreprise()->id, 'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->entreprise()->forceFill(['modules_actifs' => ['principal', 'ventes', 'points_de_vente', 'personnel']])->save();

        $this->post(route('admin.personnel.creer'), [
            'nom' => 'Koné', 'prenom' => 'Awa', 'email' => 'awa@exemple.ci', 'password' => 'secret-1', 'role' => 'caissier',
        ])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Solo'));

        $this->assertDatabaseMissing('utilisateurs', ['email' => 'awa@exemple.ci']);
    }

    public function test_le_superadmin_releve_les_limites_sans_changer_d_offre(): void
    {
        $superadmin = Utilisateur::create([
            'nom' => 'Meledje', 'prenom' => 'Abraham', 'email' => 'super@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);
        $this->actingAs($superadmin)->post(route('superadmin.entreprises.creer.enregistrer'), [
            'nom' => 'Quincaillerie', 'email' => 'q@exemple.ci',
            'gerant_password' => 'un-mot-de-passe', 'gerant_password_confirmation' => 'un-mot-de-passe',
            'plan_abonnement' => Abonnements::SOLO, 'quota_points_de_vente' => 2, 'quota_utilisateurs' => 4,
        ])->assertSessionHasNoErrors();

        $e = Entreprise::where('nom', 'Quincaillerie')->firstOrFail();
        $this->assertSame(Abonnements::SOLO, $e->plan_abonnement);
        $this->assertSame(2, (int) $e->quota_points_de_vente);
        $this->assertSame(4, $e->quota_utilisateurs);

        $this->actingAs($superadmin)->get(route('superadmin.entreprises.creer'))
            ->assertOk()->assertSee('name="quota_utilisateurs"', false)->assertSee('Pass cadeau');
    }
}
