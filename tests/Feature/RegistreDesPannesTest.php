<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\PanneConsignee;
use App\Modules\Admin\Modeles\ParametrePlateforme;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Le registre des pannes du superadministrateur (15/10/2026) : un code et une
 * adresse par ligne, le détail au clic ; une panne corrigée quitte la liste,
 * et revient si elle se reproduit.
 */
class RegistreDesPannesTest extends TestCase
{
    use RefreshDatabase;

    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false]);

        Route::get('/_panne-registre', function () {
            throw new \LogicException('Colonne inconnue dans la requête.');
        })->middleware('web');

        $this->superadmin = Utilisateur::create([
            'nom' => 'Agnimel', 'prenom' => 'Meledje', 'email' => 'super@selflow.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => ['tableau_de_bord_superadmin'],
        ]);
    }

    public function test_le_superadmin_voit_le_code_l_adresse_et_le_detail(): void
    {
        $this->get('/_panne-registre')->assertStatus(500);
        $panne = PanneConsignee::sole();

        $this->actingAs($this->superadmin)->get(route('superadmin.pannes.index'))
            ->assertOk()
            ->assertSee($panne->code)
            ->assertSee('/_panne-registre', false)
            ->assertSee('Colonne inconnue dans la requête.', false)
            ->assertSee('basculerPanne(' . $panne->id . ')', false);
    }

    public function test_corrigee_elle_quitte_la_liste_et_revient_si_elle_se_reproduit(): void
    {
        $this->get('/_panne-registre');
        $panne = PanneConsignee::sole();

        $this->actingAs($this->superadmin)->post(route('superadmin.pannes.corriger', $panne))->assertRedirect();
        $this->assertNotNull($panne->fresh()->corrigee_le);
        $this->actingAs($this->superadmin)->get(route('superadmin.pannes.index'))
            ->assertDontSee('/_panne-registre', false);

        auth()->logout();
        $this->get('/_panne-registre');
        $this->assertNull($panne->fresh()->corrigee_le, 'Revenue, elle se rouvre.');
    }

    public function test_un_fichier_modifie_depuis_vaut_correction(): void
    {
        $panne = PanneConsignee::create([
            'empreinte' => str_repeat('a', 64), 'code' => 'SF-261015-ABCDEF', 'type' => 'RuntimeException',
            'fichier' => 'app/Modules/Admin/Modeles/Entreprise.php', 'ligne' => 10,
            'empreinte_fichier' => sha1('version-d-avant'), 'derniere_le' => now(),
        ]);

        $this->assertSame(1, PanneConsignee::fermerCellesCorrigeesParUnDeploiement());
        $this->assertNotNull($panne->fresh()->corrigee_le);
    }

    public function test_le_superadmin_renseigne_les_contacts_de_l_assistance(): void
    {
        $this->actingAs($this->superadmin)->post(route('superadmin.pannes.assistance'), [
            'assistance_email' => 'support@dc-knowing.com', 'assistance_telephone' => '+225 27 22 00 00 00',
        ])->assertSessionHasNoErrors();

        $this->assertSame('support@dc-knowing.com', ParametrePlateforme::lire('assistance_email'));
        $this->assertSame('+225 27 22 00 00 00', ParametrePlateforme::lire('assistance_telephone'));
    }

    /** 16/10/2026 : une panne corrigée passe dans l'onglet « Corrigées », paginé. */
    public function test_les_deux_onglets_separent_ouvertes_et_corrigees(): void
    {
        $this->get('/_panne-registre');
        $panne = PanneConsignee::sole();
        $this->actingAs($this->superadmin)->post(route('superadmin.pannes.corriger', $panne));

        // Onglet ouvertes (défaut) : la panne corrigée n'y est plus.
        $this->get(route('superadmin.pannes.index'))->assertOk()
            ->assertSee('Corrigées', false)->assertDontSee('/_panne-registre', false);

        // Onglet corrigées : elle y est.
        $this->get(route('superadmin.pannes.index', ['onglet' => 'corrigees']))->assertOk()
            ->assertSee($panne->code);
    }

    /** 22/10/2026 : qui a rencontré la panne — nom, poste et entreprise — se lit dans la ligne. */
    public function test_la_ligne_dit_qui_a_rencontre_la_panne_son_poste_et_son_entreprise(): void
    {
        $entreprise = \App\Modules\Admin\Modeles\Entreprise::create(['nom' => 'poissonnerie EDEN']);
        $caissier = Utilisateur::create([
            'nom' => 'YAO', 'prenom' => 'YVES', 'email' => 'yves@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'caissier', 'fonction' => 'Vendeur', 'entreprise_id' => $entreprise->id,
        ]);
        $admin = Utilisateur::create([
            'nom' => 'Kouamé', 'prenom' => 'Ama', 'email' => 'ama@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $entreprise->id,
        ]);
        foreach ([[$caissier, 10], [$admin, 20], [null, 30]] as [$u, $ligne]) {
            PanneConsignee::create([
                'empreinte' => 'p' . $ligne, 'code' => 'ERR-' . $ligne, 'type' => 'Exception', 'message' => 'm',
                'fichier' => 'app/x.php', 'ligne' => $ligne, 'utilisateur_id' => $u?->id, 'entreprise_id' => $u?->entreprise_id,
                'occurrences' => 1, 'premiere_le' => now(), 'derniere_le' => now(),
            ]);
        }

        $page = $this->actingAs($this->superadmin)->get(route('superadmin.pannes.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Rencontrée par', $page);
        $this->assertStringContainsString('YVES YAO', $page);
        $this->assertStringContainsString('Vendeur (Caissier)', $page);
        $this->assertStringContainsString('Administrateur', $page);
        $this->assertStringContainsString('poissonnerie EDEN', $page);
        $this->assertStringContainsString('Visiteur non connecté', $page);
    }
}
