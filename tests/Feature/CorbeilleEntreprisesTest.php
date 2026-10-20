<?php

namespace Tests\Feature;

use App\Jobs\EffacerEntreprise;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Services\SuppressionEntrepriseService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** La corbeille des entreprises (retour du 16/10/2026). */
class CorbeilleEntreprisesTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $superadmin;
    private Entreprise $cible;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = Utilisateur::create([
            'nom' => 'S', 'prenom' => 'A', 'email' => 'super-corbeille@selflow.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME,
        ]);
        $this->cible = Entreprise::create(['nom' => 'À supprimer', 'modules_actifs' => ['principal']]);
        $site = PointDeVente::create(['entreprise_id' => $this->cible->id, 'nom' => 'Site', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->membre = Utilisateur::create([
            'nom' => 'M', 'prenom' => 'B', 'email' => 'membre-corbeille@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $this->cible->id, 'point_de_vente_id' => $site->id,
        ]);
    }

    public function test_supprimer_met_en_corbeille_instantanement_sans_effacer(): void
    {
        $this->actingAs($this->superadmin)->delete(route('superadmin.entreprises.supprimer', $this->cible))
            ->assertRedirect(route('superadmin.entreprises'));

        $this->assertTrue($this->cible->fresh()->estEnCorbeille());
        $this->assertDatabaseHas('entreprises', ['id' => $this->cible->id]); // rien n'est effacé
        $this->assertSame('inactif', $this->membre->fresh()->statut);

        // Hors des listes actives, présente dans la corbeille.
        $this->assertSame(0, Entreprise::horsCorbeille()->where('id', $this->cible->id)->count());
        // Absente de la liste active (le message flash de succès la nomme,
        // on vérifie donc la requête, pas la page), présente dans la corbeille.
        $this->get(route('superadmin.corbeille'))->assertOk()->assertSee('À supprimer');
    }

    public function test_restaurer_rouvre_l_entreprise_et_ses_utilisateurs(): void
    {
        $this->cible->forceFill(['corbeille_le' => now()])->save();
        $this->membre->forceFill(['statut' => 'inactif'])->save();

        $this->actingAs($this->superadmin)->post(route('superadmin.corbeille.restaurer', $this->cible))->assertRedirect();

        $this->assertFalse($this->cible->fresh()->estEnCorbeille());
        $this->assertSame('actif', $this->membre->fresh()->statut);
    }

    /** Retour du 20/10/2026 : l'effacement se fait tout de suite, plus en arrière-plan. */
    public function test_vider_la_corbeille_efface_tout_de_suite(): void
    {
        $this->cible->forceFill(['corbeille_le' => now()])->save();

        $this->actingAs($this->superadmin)->delete(route('superadmin.corbeille.vider_tout'))
            ->assertRedirect()->assertSessionHas('succes', fn ($m) => str_contains($m, '1 entreprise(s) effacée(s)'));

        $this->assertDatabaseMissing('entreprises', ['id' => $this->cible->id]);
    }

    public function test_effacer_une_entreprise_est_immediat(): void
    {
        $this->cible->forceFill(['corbeille_le' => now()])->save();

        $this->actingAs($this->superadmin)->delete(route('superadmin.corbeille.vider_une', $this->cible))
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'effacée définitivement'));

        $this->assertDatabaseMissing('entreprises', ['id' => $this->cible->id]);
        $this->assertDatabaseMissing('utilisateurs', ['id' => $this->membre->id]);
    }

    public function test_la_mise_en_corbeille_libere_l_adresse_et_la_restauration_la_rend(): void
    {
        $this->actingAs($this->superadmin)->delete(route('superadmin.entreprises.supprimer', $this->cible));

        $membre = $this->membre->fresh();
        $this->assertSame('membre-corbeille@exemple.ci', $membre->email_avant_corbeille);
        $this->assertStringEndsWith('@selflow.invalid', $membre->email);
        // L'adresse est libre : une nouvelle inscription (formulaire ou Google) la reprend.
        $this->assertFalse(Utilisateur::where('email', 'membre-corbeille@exemple.ci')->exists());

        $this->post(route('superadmin.corbeille.restaurer', $this->cible))->assertSessionHas('succes');
        $this->assertSame('membre-corbeille@exemple.ci', $this->membre->fresh()->email);
        $this->assertNull($this->membre->fresh()->email_avant_corbeille);
    }

    public function test_la_restauration_ne_vole_pas_une_adresse_reprise(): void
    {
        $this->actingAs($this->superadmin)->delete(route('superadmin.entreprises.supprimer', $this->cible));
        Utilisateur::create([
            'nom' => 'N', 'prenom' => 'O', 'email' => 'membre-corbeille@exemple.ci', 'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->post(route('superadmin.corbeille.restaurer', $this->cible))
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'reprise(s) entre-temps'));
        $this->assertStringEndsWith('@selflow.invalid', $this->membre->fresh()->email);
    }

    public function test_le_job_efface_reellement_l_entreprise(): void
    {
        $this->cible->forceFill(['corbeille_le' => now()])->save();

        (new EffacerEntreprise($this->cible->id))->handle();

        $this->assertDatabaseMissing('entreprises', ['id' => $this->cible->id]);
        $this->assertDatabaseMissing('utilisateurs', ['id' => $this->membre->id]);
    }

    public function test_la_purge_ne_vise_que_les_entreprises_de_plus_de_30_jours(): void
    {
        Queue::fake();
        $this->cible->forceFill(['corbeille_le' => now()->subDays(31)])->save();
        $recente = Entreprise::create(['nom' => 'Récente', 'modules_actifs' => ['principal']]);
        $recente->forceFill(['corbeille_le' => now()->subDays(10)])->save();

        $this->artisan('selflow:purger-corbeille')->assertSuccessful();

        Queue::assertPushed(EffacerEntreprise::class, 1);
        Queue::assertPushed(EffacerEntreprise::class, fn ($job) => $job->entrepriseId === $this->cible->id);
    }
}
