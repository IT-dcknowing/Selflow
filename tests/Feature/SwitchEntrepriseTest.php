<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\JournalAudit;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Switch entreprise — l'assistance (19/10/2026) : le superadministrateur
 * entre dans l'espace d'une entreprise, y voit ce que voit le client, puis
 * revient. Tout est tracé.
 */
class SwitchEntrepriseTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $superadmin;
    private Entreprise $entreprise;
    private Utilisateur $admin;
    private Utilisateur $caissier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = Utilisateur::create([
            'nom' => 'Support', 'prenom' => 'DC', 'email' => 'support@selflow.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);
        $this->entreprise = Entreprise::create(['nom' => 'A & I VENTURE', 'modules_actifs' => ['principal', 'ventes', 'points_de_vente']]);
        $site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'Gore', 'prenom' => 'Ali', 'email' => 'gore@aiventure.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        $this->caissier = Utilisateur::create([
            'nom' => 'Kouame', 'prenom' => 'Eli', 'email' => 'kouame@aiventure.ci', 'password' => bcrypt('x'), 'role' => 'caissier',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
            'habilitations' => ['nouvelle_vente'],
        ]);
    }

    public function test_la_page_liste_les_entreprises_et_leurs_utilisateurs(): void
    {
        $this->actingAs($this->superadmin)->get(route('superadmin.switch.index'))->assertOk()
            ->assertSee('A &amp; I VENTURE', false)->assertSee('gore@aiventure.ci')->assertSee('Se connecter')->assertSee('Accéder');
    }

    public function test_acceder_ouvre_l_espace_de_l_administrateur_puis_revenir(): void
    {
        $this->actingAs($this->superadmin)->post(route('superadmin.switch.acceder', $this->entreprise))
            ->assertRedirect(route('admin.tableau_de_bord'));
        $this->assertAuthenticatedAs($this->admin);
        $this->assertSame(1, JournalAudit::where('action', 'assistance_debut')->where('entreprise_id', $this->entreprise->id)->count());

        // Le bandeau est là, et le superadmin n'a plus son espace pendant ce temps.
        $this->get(route('admin.tableau_de_bord'))->assertOk()->assertSee('id="bandeau-assistance"', false);
        $this->get(route('superadmin.tableau_de_bord'))->assertForbidden();

        $this->post(route('assistance.quitter'))->assertRedirect(route('superadmin.switch.index'));
        $this->assertAuthenticatedAs($this->superadmin);
        $this->assertSame(1, JournalAudit::where('action', 'assistance_fin')->count());
    }

    public function test_se_connecter_en_tant_que_caissier(): void
    {
        $this->actingAs($this->superadmin)->post(route('superadmin.switch.incarner', $this->caissier))
            ->assertRedirect(route('caissier.tableau_de_bord'));
        $this->assertAuthenticatedAs($this->caissier);
    }

    public function test_quitter_sans_incarnation_est_refuse(): void
    {
        $this->actingAs($this->admin)->post(route('assistance.quitter'))->assertForbidden();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_on_n_incarne_pas_un_superadmin_ni_sans_habilitation(): void
    {
        $autre = Utilisateur::create([
            'nom' => 'X', 'prenom' => 'Y', 'email' => 'autre@selflow.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => ['tableau_de_bord_superadmin'],
        ]);

        $this->actingAs($this->superadmin)->post(route('superadmin.switch.incarner', $autre))->assertNotFound();
        $this->actingAs($autre)->get(route('superadmin.switch.index'))->assertForbidden();
        $this->actingAs($autre)->post(route('superadmin.switch.incarner', $this->admin))->assertForbidden();
        $this->assertAuthenticatedAs($autre);
    }

    public function test_un_client_n_ouvre_pas_le_switch(): void
    {
        $this->actingAs($this->admin)->post(route('superadmin.switch.incarner', $this->caissier))->assertForbidden();
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_la_colonne_des_points_de_vente_et_la_fiche_detaillee(): void
    {
        foreach (['Marcory', 'Plateau', 'Yopougon'] as $nom) {
            PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => $nom, 'ville' => 'Abidjan', 'commune' => $nom]);
        }

        // Quatre sites : trois noms, puis « … » vers la fiche.
        $this->actingAs($this->superadmin)->get(route('superadmin.switch.index'))->assertOk()
            ->assertSee('4 site(s)')->assertSee('Marcory, Plateau, Siège')
            ->assertSee('title="Voir tous les points de vente"', false)
            ->assertSee(route('superadmin.switch.entreprise', $this->entreprise), false);

        $this->get(route('superadmin.switch.entreprise', $this->entreprise))->assertOk()
            ->assertSee('Points de vente (4)')->assertSee('Yopougon')
            ->assertSee('kouame@aiventure.ci')->assertSee('Accéder à son espace');
    }

    public function test_le_diagnostic_des_entrees_par_site(): void
    {
        $this->artisan('selflow:diagnostic-stock-sites', ['entreprise' => $this->entreprise->id])
            ->expectsOutputToContain('chaque entrée est restée sur son point de vente')
            ->assertSuccessful();
    }

    public function test_la_fiche_dit_qui_est_connecte_et_la_duree_de_sa_derniere_connexion(): void
    {
        $visite = fn ($u, $quand, $session) => \App\Modules\Admin\Modeles\Visite::create([
            'ip' => '10.0.0.1', 'methode' => 'GET', 'chemin' => 'admin', 'statut' => 200, 'duree_ms' => 10,
            'utilisateur_id' => $u->id, 'entreprise_id' => $this->entreprise->id, 'session_id' => $session, 'cree_le' => $quand,
        ]);
        // L'administrateur : actif il y a 2 minutes, session ouverte il y a 1 h 05.
        $visite($this->admin, now()->subMinutes(65), 'S1');
        $visite($this->admin, now()->subMinutes(2), 'S1');
        // Le caissier : vu hier, 20 minutes de session.
        $visite($this->caissier, now()->subDay()->subMinutes(20), 'S2');
        $visite($this->caissier, now()->subDay(), 'S2');

        $html = $this->actingAs($this->superadmin)->get(route('superadmin.switch.entreprise', $this->entreprise))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'presence presence-oui'));
        $this->assertSame(1, substr_count($html, 'presence presence-non'));
        $this->assertStringContainsString('1 h 03 min', $html);
        $this->assertStringContainsString('20 min', $html);
    }
}
