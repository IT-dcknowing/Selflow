<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qui configure la FNE.
 *
 * **Le superadministrateur seul.** Les clés d'API, et tout ce qui relève de la
 * plateforme, lui appartiennent ; l'entreprise ne fournit que des
 * informations — si elle a déjà un compte, son NCC, son régime, ses points de
 * vente — et c'est lui qui les reporte.
 *
 * Un réglage échappait à cette règle : **le timbre de quittance**. Il vivait
 * dans les paramètres de l'entreprise, sous une étiquette « Informatif » qui
 * promettait que « cocher ou décocher ici ne change aucun montant ».
 *
 * C'était faux, et de plus en plus coûteux :
 *
 * - `TimbreQuittanceService::estApplicable()` lit cette colonne pour décider
 *   si le droit est dû ;
 * - `net_a_payer` l'ajoute au TTC, et c'est ce total que la facture imprime ;
 * - depuis que le timbre entre en comptabilité, il commande aussi le débit du
 *   compte client et le crédit du `447800`.
 *
 * Coché à tort, il faisait **payer au client un droit que la plateforme ne
 * retenait pas**. Et la case était à la portée de n'importe quel
 * administrateur d'entreprise.
 */
class ConfigurationFneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom'               => 'Quincaillerie du Plateau',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Plateau, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00321',
            'ncc'               => '2603210A',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'timbre_quittance'  => false,
        ]);

        PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Plateau',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
        ]);

        $this->superadmin = Utilisateur::create([
            'nom' => 'Meledje', 'prenom' => 'Abraham', 'email' => 'super@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    /** Le corps minimal que l'écran des paramètres exige. */
    private function parametres(array $extra = []): array
    {
        return array_merge([
            'nom'               => $this->entreprise->nom,
            'regime_imposition' => 'RNI',
            'adresse'           => 'Plateau, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00321',
            'ncc'               => '2603210A',
            'gerant_fonction'   => 'Gérant',
            'secteurs_activite' => ['Commerce'],
        ], $extra);
    }

    // ── Le timbre échappe à l'entreprise ─────────────────────────────

    public function test_l_entreprise_ne_peut_pas_activer_le_timbre(): void
    {
        // Le cas qui coûte : l'administrateur coche, et chaque client paie un
        // droit que la plateforme ne retiendra pas.
        $this->actingAs($this->admin)
            ->put(route('admin.entreprise.parametres.enregistrer'),
                  $this->parametres(['timbre_quittance' => '1']))
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->entreprise->fresh()->timbre_quittance,
            "Le timbre est un réglage de la plateforme : l'entreprise ne le pose pas.");
    }

    public function test_l_entreprise_ne_peut_pas_desactiver_le_timbre(): void
    {
        // Le symetrique, tout aussi couteux : la plateforme retient le droit,
        // la facture ne l'annonce plus, et l'entreprise le paie de sa poche.
        $this->entreprise->update(['timbre_quittance' => true]);

        $this->actingAs($this->admin)
            ->put(route('admin.entreprise.parametres.enregistrer'), $this->parametres())
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $this->entreprise->fresh()->timbre_quittance);
    }

    public function test_l_entreprise_voit_l_etat_du_timbre_sans_pouvoir_le_changer(): void
    {
        // Le retirer de l'ecran sans rien dire serait pire : l'entreprise doit
        // pouvoir constater un desaccord avec son espace FNE et le signaler.
        $this->entreprise->update(['timbre_quittance' => true]);

        $this->actingAs($this->admin)
            ->get(route('admin.entreprise.parametres'))
            ->assertOk()
            ->assertSee('Timbre de quittance', false)
            ->assertSee('Repris de votre espace FNE', false)
            ->assertSee('Le timbre de quittance est coché sur votre espace FNE.', false)
            ->assertDontSee('name="timbre_quittance"', false);
    }

    // ── Le superadministrateur le pose ───────────────────────────────

    public function test_le_superadministrateur_active_le_timbre(): void
    {
        $this->actingAs($this->superadmin)
            ->post(route('superadmin.fne.timbre', $this->entreprise), ['timbre_quittance' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $this->entreprise->fresh()->timbre_quittance);
    }

    public function test_le_superadministrateur_desactive_le_timbre(): void
    {
        $this->entreprise->update(['timbre_quittance' => true]);

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.fne.timbre', $this->entreprise), ['timbre_quittance' => '0']);

        $this->assertFalse((bool) $this->entreprise->fresh()->timbre_quittance);
    }

    public function test_un_administrateur_d_entreprise_n_atteint_pas_l_ecran_fne(): void
    {
        $this->actingAs($this->admin)
            ->post(route('superadmin.fne.timbre', $this->entreprise), ['timbre_quittance' => '1'])
            ->assertForbidden();

        $this->assertFalse((bool) $this->entreprise->fresh()->timbre_quittance);
    }

    // ── Ce que l'entreprise fournit, le superadministrateur le voit ──

    public function test_le_tableau_fne_montre_ce_que_l_entreprise_a_declare(): void
    {
        // Sans ces colonnes, le superadministrateur devait ouvrir les
        // parametres de chaque entreprise pour savoir laquelle avait deja un
        // compte et a laquelle il manquait de quoi en ouvrir un.
        $this->entreprise->update(['possede_compte_fne' => true]);

        $this->actingAs($this->superadmin)
            ->get(route('superadmin.fne.index'))
            ->assertOk()
            ->assertSee('Déclaré', false)
            ->assertSee('Timbre', false);
    }

    public function test_le_tableau_fne_compte_les_informations_manquantes(): void
    {
        // Une entreprise a qui il manque de quoi ouvrir un compte doit se
        // reperer sans ouvrir sa fiche.
        $incomplete = Entreprise::create(['nom' => 'Atelier sans papiers']);

        $this->assertGreaterThan(0, $incomplete->informationsFneManquantes());

        $this->actingAs($this->superadmin)
            ->get(route('superadmin.fne.index'))
            ->assertOk()
            ->assertSee('information(s) manquante(s)', false);
    }

    public function test_la_liste_des_informations_exigees_est_la_meme_des_deux_cotes(): void
    {
        // Ecrite deux fois -- une fois dans chaque vue -- elle aurait diverge
        // au premier champ ajoute.
        $champs = collect($this->entreprise->informationsFne())->pluck('champ');

        $this->assertContains('NCC — Numéro de Compte Contribuable', $champs);
        $this->assertContains('Points de vente', $champs);
        $this->assertSame(10, $champs->count());
    }
}
