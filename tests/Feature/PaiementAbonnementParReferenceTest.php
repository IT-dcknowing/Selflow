<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PaiementAbonnement;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Regles\Abonnements;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le paiement d'abonnement par référence (16/10/2026), en attendant l'API :
 * l'entreprise déclare l'opérateur et la référence, le superadministrateur
 * confirme et active — l'échéance avance, une entreprise à terme est débloquée.
 */
class PaiementAbonnementParReferenceTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entreprise = Entreprise::create(['nom' => 'Boutique', 'plan_abonnement' => Abonnements::BUSINESS, 'quota_points_de_vente' => 3]);
        $this->entreprise->forceFill(['abonnement_expire_le' => now()->subDay()])->save();
        PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id,
        ]);
        $this->superadmin = Utilisateur::create([
            'nom' => 'Super', 'prenom' => 'Admin', 'email' => 'super@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    public function test_une_offre_payante_exige_la_reference(): void
    {
        $this->actingAs($this->admin)->post(route('admin.abonnement.renouveler'), ['depuis' => 'facturation'])
            ->assertSessionHasErrors(['operateur_paiement', 'reference_paiement']);
        $this->assertSame(0, PaiementAbonnement::count());
    }

    public function test_le_renouvellement_paye_puis_active_debloque_l_entreprise(): void
    {
        $this->actingAs($this->admin)->post(route('admin.abonnement.renouveler'), [
            'depuis' => 'facturation', 'operateur_paiement' => 'ORANGE', 'reference_paiement' => 'OM-2026-77',
        ])->assertSessionHas('succes');

        $paiement = PaiementAbonnement::sole();
        $this->assertSame('recu', $paiement->statut);
        $this->assertSame(25000.0, (float) $paiement->montant);
        $this->assertTrue(Abonnements::estATerme($this->entreprise->fresh()), 'Encore à terme tant que DC-Knowing n\'a pas confirmé.');

        $this->actingAs($this->superadmin)->get(route('superadmin.paiements.index'))->assertOk()
            ->assertSee('OM-2026-77')->assertSee('Confirmer / Activer');

        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $paiement), ['action' => 'en_cours'])->assertSessionHasNoErrors();
        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $paiement), ['action' => 'activer'])->assertSessionHasNoErrors();

        $entreprise = $this->entreprise->fresh();
        $this->assertFalse(Abonnements::estATerme($entreprise));
        $this->assertTrue($entreprise->abonnement_expire_le->between(now()->addDays(27), now()->addDays(32)));
        $this->assertSame(Abonnements::STATUT_ACTIF, $entreprise->abonnement_statut);
        $this->assertSame('active', $paiement->fresh()->statut);
        $this->assertNotNull($paiement->fresh()->en_cours_le);

        // L'entreprise voit le paiement confirmé dans sa section Facturation.
        $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))->assertSee('Confirmé — abonnement activé');
    }

    public function test_l_echec_garde_l_entreprise_a_terme_et_dit_le_motif(): void
    {
        $this->actingAs($this->admin)->post(route('admin.abonnement.renouveler'), [
            'depuis' => 'facturation', 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'FAUX-123',
        ]);
        $paiement = PaiementAbonnement::sole();

        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $paiement), ['action' => 'echec', 'motif_echec' => 'Référence inconnue'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Abonnements::estATerme($this->entreprise->fresh()));
        $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))->assertSee('Référence inconnue');
    }

    public function test_le_pass_cadeau_ne_demande_aucun_paiement(): void
    {
        $nouvelle = Entreprise::create(['nom' => 'Neuve', 'plan_abonnement' => Abonnements::A_CHOISIR]);
        $admin = Utilisateur::create([
            'nom' => 'N', 'prenom' => 'N', 'email' => 'n@exemple.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'entreprise_id' => $nouvelle->id,
        ]);
        $this->actingAs($admin)->post(route('admin.abonnement.enregistrer'), ['offre' => Abonnements::PASS_CADEAU])->assertSessionHas('succes');
        $this->assertSame(0, PaiementAbonnement::count());
    }

    /** 16/10/2026 : l'offre sur mesure se renouvelle aussi par référence, au montant du devis. */
    public function test_le_sur_mesure_se_renouvelle_par_reference_au_montant_convenu(): void
    {
        $this->entreprise->forceFill(['plan_abonnement' => Abonnements::SUR_MESURE])->save();

        $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('Paiement du montant convenu dans votre devis');

        $this->post(route('admin.abonnement.renouveler'), ['depuis' => 'facturation', 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'TX-1234'])
            ->assertSessionHasErrors('montant');

        $this->post(route('admin.abonnement.renouveler'), [
            'depuis' => 'facturation', 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'TX-1234', 'montant' => 150000,
        ])->assertSessionHasNoErrors();

        $paiement = PaiementAbonnement::sole();
        $this->assertSame(Abonnements::SUR_MESURE, $paiement->offre);
        $this->assertSame(150000, (int) $paiement->montant);
    }

    /**
     * 22/10/2026 : l'accès immédiat du lot 102 est retiré. Un paiement déclaré
     * sans période payée en cours bloque l'espace jusqu'à la confirmation.
     */
    public function test_un_paiement_en_attente_bloque_l_espace_jusqu_a_la_confirmation(): void
    {
        $this->actingAs($this->admin)->post(route('admin.abonnement.renouveler'), [
            'depuis' => 'facturation', 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'TX-AVIS-1',
        ])->assertSessionHasNoErrors();

        $paiement = PaiementAbonnement::sole();
        $this->assertTrue($paiement->bloquant);

        $this->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('En attente de confirmation de l\'abonnement')
            ->assertSee('TX-AVIS-1')
            ->assertDontSee('Vous pouvez déjà travailler', false);

        // Confirmé : la fenêtre cède la place à « Abonnement confirmé ».
        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $paiement), ['action' => 'activer']);
        $this->actingAs($this->admin)->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertDontSee('En attente de confirmation de l\'abonnement')
            ->assertSee('Abonnement confirmé');
    }
}
