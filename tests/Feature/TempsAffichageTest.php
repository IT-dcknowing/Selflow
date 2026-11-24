<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 24/11/2026 : Maintenance › Temps d'affichage, par page et par bouton. */
class TempsAffichageTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_temps_sont_notes_par_route_et_montres_au_superadmin(): void
    {
        $e = Entreprise::create(['nom' => 'Mesurée']);
        $site = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $admin = Utilisateur::create(['nom' => 'A', 'prenom' => 'B', 'email' => 'temps-admin@exemple.ci', 'password' => bcrypt('x-secret-de-test'),
            'role' => 'admin', 'entreprise_id' => $e->id, 'point_de_vente_id' => $site->id]);
        $super = Utilisateur::create(['nom' => 'S', 'prenom' => 'A', 'email' => 'temps-super@exemple.ci', 'password' => bcrypt('x-secret-de-test'),
            'role' => 'superadmin', 'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME]);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $site->id])->get(route('admin.entreprise.parametres'));
        $this->assertTrue(DB::table('visites')->where('route', 'admin.entreprise.parametres')->exists(), 'La requête porte le nom de sa route.');

        $this->actingAs($admin)->post(route('mesure.affichage'), ['route' => 'admin.entreprise.parametres', 'ms' => 1800])->assertNoContent();
        $this->actingAs($admin)->post(route('mesure.affichage'), ['route' => 'admin.entreprise.parametres', 'ms' => 1200])->assertNoContent();
        $ligne = DB::table('mesures_affichage')->where('route', 'admin.entreprise.parametres')->first();
        $this->assertSame(2, (int) $ligne->nb);
        $this->assertSame(3000, (int) $ligne->total_ms);
        $this->assertSame(1800, (int) $ligne->max_ms);

        $this->actingAs($super)->get(route('superadmin.temps_affichage', ['jours' => 7]))->assertOk()
            ->assertSee('entreprise › parametres')
            ->assertSee('1,5 s');
    }
}
