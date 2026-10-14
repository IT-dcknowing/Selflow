<?php

namespace Tests\Feature;

use App\Mail\CompteSelflowOuvert;
use App\Mail\LienReinitialisationMotDePasse;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Retest de production (octobre 2026) : le lien de réinitialisation
 * n'arrivait pas par courriel, et la page l'affichait à quiconque tapait une
 * adresse — n'importe qui pouvait changer le mot de passe de n'importe qui.
 * Aucun courriel ne partait non plus à la création d'un compte.
 */
class CourrielsDeCompteTest extends TestCase
{
    use RefreshDatabase;

    private function compte(): Utilisateur
    {
        $entreprise = Entreprise::create(['nom' => 'Axenova']);

        return Utilisateur::create([
            'entreprise_id' => $entreprise->id, 'nom' => 'Assem', 'prenom' => 'Eve',
            'email' => 'eve@axenova.ci', 'password' => bcrypt('ancien-mot-de-passe'),
            'role' => 'admin', 'statut' => 'actif',
        ]);
    }

    public function test_le_lien_part_par_courriel_et_ne_s_affiche_pas(): void
    {
        Mail::fake();
        $this->compte();

        $this->post(route('password.email'), ['email' => 'eve@axenova.ci'])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('lien_developpement');

        Mail::assertSent(LienReinitialisationMotDePasse::class, fn ($m) => $m->hasTo('eve@axenova.ci'));

        $page = $this->get(route('password.request'))->getContent();
        $this->assertStringNotContainsString('Mode Développement', $page);
        $this->assertStringNotContainsString('/mot-de-passe/reinitialiser/', $page);
    }

    public function test_une_adresse_inconnue_recoit_la_meme_reponse(): void
    {
        Mail::fake();
        $this->compte();

        $connue = $this->post(route('password.email'), ['email' => 'eve@axenova.ci']);
        $inconnue = $this->post(route('password.email'), ['email' => 'personne@nulle-part.ci']);

        $inconnue->assertSessionHasNoErrors();
        $this->assertSame($connue->getSession()->get('succes'), $inconnue->getSession()->get('succes'));
        Mail::assertSentCount(1);
    }

    public function test_un_serveur_sans_transport_le_dit_au_lieu_de_pretendre_avoir_envoye(): void
    {
        $this->compte();
        config(['mail.default' => 'log']);

        $this->post(route('password.email'), ['email' => 'eve@axenova.ci'])
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('succes');
    }

    public function test_un_jeton_invalide_n_ouvre_pas_le_formulaire(): void
    {
        $this->compte();

        $this->get(route('password.reset', ['token' => 'au-hasard', 'email' => 'eve@axenova.ci']))
            ->assertRedirect(route('password.request'));
    }

    public function test_le_lien_recu_permet_de_changer_le_mot_de_passe(): void
    {
        Mail::fake();
        $this->compte();
        $this->post(route('password.email'), ['email' => 'eve@axenova.ci']);

        $lien = null;
        Mail::assertSent(LienReinitialisationMotDePasse::class, function ($m) use (&$lien) {
            $lien = $m->lien;

            return true;
        });

        parse_str((string) parse_url($lien, PHP_URL_QUERY), $requete);
        $token = basename((string) parse_url($lien, PHP_URL_PATH));

        $this->get($lien)->assertOk();
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $requete['email'],
            'password' => 'nouveau-secret', 'password_confirmation' => 'nouveau-secret',
        ])->assertRedirect(route('connexion'));

        $this->assertTrue(\Hash::check('nouveau-secret', Utilisateur::where('email', 'eve@axenova.ci')->value('password')));
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_le_courriel_ne_contient_aucun_mot_de_passe(): void
    {
        $utilisateur = $this->compte();
        $rendu = (new CompteSelflowOuvert($utilisateur, true))->render();

        $this->assertStringContainsString('eve@axenova.ci', $rendu);
        $this->assertStringNotContainsString('ancien-mot-de-passe', $rendu);
    }

    public function test_l_inscription_annonce_le_compte(): void
    {
        Mail::fake();
        $this->seed(ReferentielSeeder::class);

        $this->post(route('inscription.traitement'), [
            'nom_entreprise' => 'Boutique du carrefour', 'nom' => 'Kouadio', 'prenom' => 'Lewis',
            'email' => 'lewis@exemple.ci', 'password' => 'un-mot-de-passe',
            'password_confirmation' => 'un-mot-de-passe', 'conditions' => '1',
        ]);

        Mail::assertSent(CompteSelflowOuvert::class, fn ($m) => $m->hasTo('lewis@exemple.ci') && ! $m->creeParUnTiers);
    }

    public function test_le_responsable_cree_par_le_superadmin_est_prevenu(): void
    {
        Mail::fake();
        $this->seed(ReferentielSeeder::class);
        $super = Utilisateur::create([
            'nom' => 'Meledje', 'prenom' => 'Abraham', 'email' => 'super@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => Habilitations::PLATEFORME,
        ]);

        $this->actingAs($super)->post(route('superadmin.entreprises.creer.enregistrer'), [
            'nom' => 'Quincaillerie du Plateau', 'email' => 'gerant@quincaillerie.ci',
            'gerant_password' => 'un-mot-de-passe', 'gerant_password_confirmation' => 'un-mot-de-passe',
            'quota_points_de_vente' => 5, 'plan_abonnement' => 'Starter',
        ])->assertSessionHasNoErrors();

        Mail::assertSent(CompteSelflowOuvert::class, fn ($m) => $m->hasTo('gerant@quincaillerie.ci') && $m->creeParUnTiers);
    }

    public function test_l_employe_cree_par_le_responsable_est_prevenu(): void
    {
        Mail::fake();
        $admin = $this->compte();
        $admin->entreprise->update(['modules_actifs' => ['principal', 'ventes', 'points_de_vente']]);

        $this->actingAs($admin)->post(route('admin.personnel.creer'), [
            'nom' => 'Koffi', 'prenom' => 'Ama', 'email' => 'ama@axenova.ci',
            'password' => 'secret12', 'role' => 'caissier',
        ])->assertSessionHasNoErrors();

        Mail::assertSent(CompteSelflowOuvert::class, fn ($m) => $m->hasTo('ama@axenova.ci'));
    }
}
