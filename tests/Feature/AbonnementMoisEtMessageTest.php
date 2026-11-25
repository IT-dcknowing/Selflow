<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PaiementAbonnement;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Regles\Abonnements;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 25/11/2026 : durée choisie et échéance calculée (inscription, renouvellement),
 * échéances manquantes, message du superadministrateur.
 */
class AbonnementMoisEtMessageTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = Utilisateur::create(['nom' => 'S', 'prenom' => 'A', 'email' => 'super-mois@exemple.ci', 'password' => bcrypt('x-secret-de-test'),
            'role' => 'superadmin', 'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME]);
    }

    private function entreprise(string $nom): array
    {
        $e = Entreprise::create(['nom' => $nom]);
        $site = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $admin = Utilisateur::create(['nom' => 'A', 'prenom' => 'B', 'email' => strtolower(preg_replace('/\W/', '', $nom)) . '@exemple.ci',
            'password' => bcrypt('x-secret-de-test'), 'role' => 'admin', 'entreprise_id' => $e->id, 'point_de_vente_id' => $site->id]);

        return [$e, $admin, $site];
    }

    public function test_la_validation_de_l_inscription_pose_l_offre_et_l_echeance_des_mois_choisis(): void
    {
        [$e] = $this->entreprise('Nouvelle Boutique');
        $e->forceFill(['statut' => 'en_attente', 'plan_abonnement' => Abonnements::A_CHOISIR, 'abonnement_expire_le' => null,
            'abonnement_demande' => Abonnements::BUSINESS, 'abonnement_demande_mois' => 3])->save();

        $this->actingAs($this->superadmin)->post(route('superadmin.entreprises.valider_inscription', $e))->assertSessionHas('succes');

        $e->refresh();
        $this->assertSame(Abonnements::BUSINESS, $e->plan_abonnement);
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), $e->abonnement_expire_le->toDateString());
        $this->assertSame(75000, (int) PaiementAbonnement::where('entreprise_id', $e->id)->value('montant'));
    }

    public function test_le_renouvellement_prend_la_duree_choisie(): void
    {
        [$e, $admin] = $this->entreprise('Boutique Renouvelle');
        Abonnements::appliquer($e, Abonnements::BUSINESS);
        $e->forceFill(['abonnement_statut' => Abonnements::STATUT_ACTIF, 'abonnement_expire_le' => now()->addDays(20)])->save();

        $this->actingAs($admin)->post(route('admin.abonnement.renouveler'), [
            'depuis' => 'facturation', 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'WV-2026-77', 'mois' => 6,
        ])->assertSessionHasNoErrors();

        $p = PaiementAbonnement::where('entreprise_id', $e->id)->latest('id')->first();
        $this->assertSame(6, (int) $p->mois);
        $this->assertSame(150000, (int) $p->montant);
    }

    public function test_les_echeances_manquantes_un_mois_apres_la_creation_ou_a_terme(): void
    {
        [$recente] = $this->entreprise('Recente');
        [$ancienne] = $this->entreprise('Ancienne');
        $recente->forceFill(['created_at' => now()->subDays(5), 'abonnement_expire_le' => null, 'plan_abonnement' => Abonnements::A_CHOISIR, 'statut' => 'actif'])->save();
        $ancienne->forceFill(['created_at' => now()->subMonths(2), 'abonnement_expire_le' => null, 'plan_abonnement' => Abonnements::A_CHOISIR, 'statut' => 'actif'])->save();

        $this->artisan('selflow:fixer-echeances')->assertExitCode(0);
        $this->assertNull($recente->fresh()->abonnement_expire_le, 'Sans --appliquer, rien ne change.');

        $this->artisan('selflow:fixer-echeances', ['--appliquer' => true])->assertExitCode(0);
        $this->assertSame(now()->subDays(5)->addMonthNoOverflow()->toDateString(), $recente->fresh()->abonnement_expire_le->toDateString());
        $this->assertSame(Abonnements::BUSINESS, $recente->fresh()->plan_abonnement);
        $this->assertTrue(Abonnements::estATerme($ancienne->fresh()), 'Le mois est écoulé : à terme.');
        $this->assertSame(now()->subMonths(2)->addMonthNoOverflow()->toDateString(), $ancienne->fresh()->abonnement_expire_le->toDateString(),
            'La vraie échéance, passée, et non aujourd\'hui.');
    }

    public function test_recaler_remet_la_vraie_echeance_et_le_message_compte_les_vrais_jours(): void
    {
        [$e] = $this->entreprise('Poissonnerie La Grace');
        // Ce que la première version avait posé : à terme « aujourd'hui ».
        $e->forceFill(['created_at' => Carbon::parse('2026-10-15 09:00'), 'abonnement_expire_le' => now()->subMinute(),
            'message_abonnement' => 'Votre abonnement Selflow est arrivé à échéance le ' . now()->format('d/m/Y') . ', il y a 0 jours.'])->save();

        $this->artisan('selflow:fixer-echeances', ['--recaler' => true, '--appliquer' => true])->assertExitCode(0);

        $e->refresh();
        $this->assertSame('2026-11-15', $e->abonnement_expire_le->toDateString());
        $jours = (int) Carbon::parse('2026-11-15')->diffInDays(now()->startOfDay());
        $this->assertSame("Votre abonnement Selflow est arrivé à échéance le 15/11/2026, il y a {$jours} jours.", Abonnements::messageDe($e));
    }

    public function test_le_message_du_superadmin_s_affiche_en_haut_et_dans_la_fenetre(): void
    {
        [$e, $admin, $site] = $this->entreprise('Boutique Message');
        $e->forceFill(['abonnement_expire_le' => Carbon::parse('2026-10-01')->endOfDay()])->save();

        $this->actingAs($this->superadmin)->post(route('superadmin.paiements.message', $e), ['message' => Abonnements::MESSAGES['expire_longtemps']])
            ->assertSessionHas('succes');
        // Gardé en modèle : la date et le nombre de jours se calculent à l'affichage.
        $this->assertStringContainsString('{echeance}', $e->fresh()->message_abonnement);
        $this->assertStringContainsString('il y a {jours} jours', $e->fresh()->message_abonnement);

        // Côté superadmin, les messages proposés arrivent déjà remplis.
        $this->actingAs($this->superadmin)->get(route('superadmin.paiements.index'))->assertOk()
            ->assertSee('arrivé à échéance le 01/10/2026', false);

        $page = $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $site->id])->get(route('admin.tableau_de_bord'))->getContent();
        $this->assertStringContainsString('Message de DC-Knowing', $page);
        $this->assertStringContainsString('arrivé à échéance le 01/10/2026', $page);
        $this->assertSame(2, substr_count($page, 'Message de DC-Knowing'), 'En haut de la page et dans la fenêtre de blocage.');

        $this->actingAs($this->superadmin)->post(route('superadmin.paiements.message', $e), ['message' => '']);
        $this->assertNull($e->fresh()->message_abonnement);
    }

    public function test_un_reabonnement_confirme_efface_le_message_et_la_fenetre_se_ferme_sans_recharger(): void
    {
        [$e, $admin, $site] = $this->entreprise('Boutique Reabonnee');
        Abonnements::appliquer($e, Abonnements::BUSINESS);
        $e->forceFill(['abonnement_statut' => Abonnements::STATUT_ACTIF, 'abonnement_expire_le' => now()->subDays(3)])->save();
        $this->actingAs($this->superadmin)->post(route('superadmin.paiements.message', $e), ['message' => Abonnements::MESSAGES['expire']]);

        $this->actingAs($admin)->post(route('admin.abonnement.renouveler'), [
            'depuis' => 'facturation', 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'WV-2026-88', 'mois' => 1,
        ]);
        $p = PaiementAbonnement::where('entreprise_id', $e->id)->latest('id')->first();
        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $p), ['action' => 'activer'])->assertSessionHas('succes');

        $this->assertNull($e->fresh()->message_abonnement, 'Confirmé : le message disparaît.');

        $this->actingAs($admin)->postJson(route('admin.abonnement.confirmation_vue'))->assertNoContent();
    }
}
