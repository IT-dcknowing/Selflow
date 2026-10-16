<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\IpBloquee;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Visite;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Maintenance & traçabilité (16/10/2026). */
class TracabiliteTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $superadmin;
    private Utilisateur $membre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superadmin = Utilisateur::create([
            'nom' => 'S', 'prenom' => 'A', 'email' => 'super-traca@selflow.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME,
        ]);
        $e = Entreprise::create(['nom' => 'Bou', 'modules_actifs' => ['principal', 'ventes']]);
        $site = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'S', 'ville' => 'Abidjan', 'commune' => 'P']);
        $this->membre = Utilisateur::create([
            'nom' => 'M', 'prenom' => 'B', 'email' => 'membre-traca@exemple.ci', 'password' => bcrypt('secret-1234'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $e->id, 'point_de_vente_id' => $site->id,
        ]);
    }

    public function test_une_requete_est_journalisee_mais_pas_celles_du_superadmin(): void
    {
        $this->actingAs($this->membre)->get(route('admin.tableau_de_bord'));
        $this->assertGreaterThan(0, Visite::where('utilisateur_id', $this->membre->id)->count());

        $avant = Visite::count();
        $this->actingAs($this->superadmin)->get(route('superadmin.tracabilite.index'));
        $this->assertSame($avant, Visite::count(), 'Le superadmin n\'est pas tracé.');
    }

    public function test_l_audit_par_entreprise_est_pagine(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            \App\Modules\Admin\Modeles\JournalAudit::create([
                'utilisateur_id' => $this->membre->id, 'entreprise_id' => $this->membre->entreprise_id,
                'action' => 'action_test_' . $i, 'entite' => 'Vente', 'entite_id' => $i,
            ]);
        }

        $this->actingAs($this->superadmin)->get(route('superadmin.tracabilite.audit'))
            ->assertOk()->assertSee('sur <strong>25</strong>', false)->assertSee('page 1 / 2');
        $this->actingAs($this->superadmin)->get(route('superadmin.tracabilite.audit', ['page' => 2]))
            ->assertOk()->assertSee('page 2 / 2');
    }

    public function test_le_tableau_de_tracabilite_s_affiche(): void
    {
        Visite::create(['ip' => '10.0.0.9', 'methode' => 'GET', 'chemin' => 'admin/ventes', 'statut' => 200, 'duree_ms' => 42, 'cree_le' => now()]);
        $this->actingAs($this->superadmin)->get(route('superadmin.tracabilite.index'))
            ->assertOk()->assertSee('10.0.0.9')->assertSee('Requêtes par heure');
    }

    public function test_bloquer_une_ip_lui_coupe_tout_acces(): void
    {
        $this->actingAs($this->superadmin)->post(route('superadmin.tracabilite.bloquer'), ['ip' => '1.2.3.4', 'motif' => 'Attaque'])
            ->assertRedirect();
        $this->assertTrue(IpBloquee::estBloquee('1.2.3.4'));

        // Depuis cette IP, 403 partout — même la connexion.
        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])->get(route('connexion'))->assertStatus(403);

        // Débloquée (depuis le poste du superadmin, non bloqué), l'accès revient.
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->actingAs($this->superadmin)->delete(route('superadmin.tracabilite.debloquer', '1.2.3.4'));
        $this->assertFalse(IpBloquee::estBloquee('1.2.3.4'));
        auth()->logout();
        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])->get(route('connexion'))->assertStatus(200);
    }

    public function test_la_tracabilite_se_met_en_pause(): void
    {
        $this->actingAs($this->superadmin)->post(route('superadmin.tracabilite.basculer'))->assertRedirect();
        $this->assertFalse(\App\Http\Middleware\JournaliserVisite::active());

        $avant = Visite::count();
        $this->actingAs($this->membre)->get(route('admin.tableau_de_bord'));
        $this->assertSame($avant, Visite::count(), 'En pause, rien n\'est enregistré.');
    }

    public function test_la_purge_retire_les_vieilles_visites(): void
    {
        Visite::create(['ip' => '1.1.1.1', 'methode' => 'GET', 'chemin' => 'x', 'statut' => 200, 'cree_le' => now()->subDays(40)]);
        Visite::create(['ip' => '1.1.1.1', 'methode' => 'GET', 'chemin' => 'y', 'statut' => 200, 'cree_le' => now()]);
        $this->artisan('selflow:purger-visites')->assertSuccessful();
        $this->assertSame(1, Visite::count());
    }
}
