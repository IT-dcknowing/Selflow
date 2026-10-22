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
 * L'abonnement en attente — retour du propriétaire du 22/10/2026.
 *
 * Choisir une offre payante et saisir la référence du paiement ne donne pas
 * accès à l'entreprise : l'espace reste bloqué, avec « En attente de
 * confirmation de l'abonnement », jusqu'à la confirmation par le
 * superadministrateur — et reste bloqué si le paiement échoue. Confirmé,
 * « Abonnement confirmé » s'annonce une fois. Un paiement en échec se
 * confirme encore, et l'échéance part alors d'aujourd'hui, un mois plus loin.
 */
class AbonnementEnAttenteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entreprise = Entreprise::create([
            'nom' => 'Neuve', 'plan_abonnement' => Abonnements::A_CHOISIR, 'quota_points_de_vente' => 3,
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'points_de_vente', 'personnel'],
        ]);
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

    private function souscrireBusiness(string $reference = 'MTN-2026-01'): PaiementAbonnement
    {
        $this->actingAs($this->admin)->post(route('admin.abonnement.enregistrer'), [
            'offre' => Abonnements::BUSINESS, 'operateur_paiement' => 'MTN', 'reference_paiement' => $reference,
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.tableau_de_bord'));

        return PaiementAbonnement::latest('id')->first();
    }

    private function creerUnEmploye(string $email = 'awa@exemple.ci')
    {
        return $this->actingAs($this->admin)->post(route('admin.personnel.creer'), [
            'nom' => 'Koné', 'prenom' => 'Awa', 'email' => $email, 'password' => 'secret-1', 'role' => 'caissier',
        ]);
    }

    public function test_la_souscription_payee_bloque_l_espace_en_attente_de_confirmation(): void
    {
        $paiement = $this->souscrireBusiness();
        $this->assertTrue($paiement->bloquant);
        $this->assertSame($paiement->id, Abonnements::paiementEnSuspens($this->entreprise->fresh())?->id);

        $this->actingAs($this->admin)->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('class="abonnement-a-terme', false)
            ->assertSee('En attente de confirmation de l\'abonnement', false)
            ->assertSee('MTN-2026-01')
            ->assertSee(route('admin.abonnement.etat'), false);

        $this->creerUnEmploye()
            ->assertRedirect(route('admin.entreprise.parametres') . '#facturation')
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'En attente de confirmation'));
        $this->assertDatabaseMissing('utilisateurs', ['email' => 'awa@exemple.ci']);

        $this->getJson(route('admin.abonnement.etat'))->assertOk()->assertJson(['statut' => 'en_attente']);
    }

    public function test_l_echec_garde_le_blocage_avec_le_motif(): void
    {
        $paiement = $this->souscrireBusiness();
        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $paiement), [
            'action' => 'echec', 'motif_echec' => 'Référence introuvable',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('Paiement non confirmé')
            ->assertSee('Référence introuvable')
            ->assertSee('Saisir une nouvelle référence');

        $this->creerUnEmploye()->assertSessionHas('erreur', fn ($m) => str_contains($m, 'n\'a pas été confirmé'));
        $this->assertDatabaseMissing('utilisateurs', ['email' => 'awa@exemple.ci']);
        $this->getJson(route('admin.abonnement.etat'))->assertJson(['statut' => 'echec']);

        // Une nouvelle référence se saisit depuis la Facturation : la section
        // reste utilisable, et le nouveau paiement attend sa confirmation.
        $this->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('class="abonnement-a-terme sur-facturation"', false);
        $this->post(route('admin.abonnement.renouveler'), [
            'depuis' => 'facturation', 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'WV-2026-02',
        ])->assertSessionHasNoErrors();
        $this->getJson(route('admin.abonnement.etat'))->assertJson(['statut' => 'en_attente']);
    }

    public function test_le_superadmin_confirme_un_paiement_en_echec_pour_un_mois(): void
    {
        $paiement = $this->souscrireBusiness();
        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $paiement), [
            'action' => 'echec', 'motif_echec' => 'Montant non reçu',
        ]);

        $this->get(route('superadmin.paiements.index'))->assertOk()->assertSee('Renouveler / Confirmer');

        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(10, 0));
        $this->put(route('superadmin.paiements.traiter', $paiement), ['action' => 'activer'])
            ->assertSessionHasNoErrors()->assertSessionHas('succes');

        $paiement->refresh();
        $entreprise = $this->entreprise->fresh();
        $this->assertSame('active', $paiement->statut);
        $this->assertSame('Confirmé — abonnement activé', $paiement->libelleStatut());
        $this->assertSame(Abonnements::STATUT_ACTIF, $entreprise->abonnement_statut);
        // Un mois depuis la confirmation : 22/10 → 22/11 (31 jours en octobre).
        $this->assertSame('2026-11-22', $entreprise->abonnement_expire_le->toDateString());
        $this->assertNull(Abonnements::paiementEnSuspens($entreprise));

        // L'historique garde l'échec, puis la confirmation, dans l'ordre.
        $libelles = array_column($paiement->etapes(), 'libelle');
        $this->assertSame(['Reçu', 'Échec', 'Confirmé — abonnement activé'], $libelles);

        // Une ligne confirmée ne se traite plus.
        $this->put(route('superadmin.paiements.traiter', $paiement), ['action' => 'activer'])->assertSessionHas('erreur');
    }

    public function test_abonnement_confirme_s_annonce_une_fois_puis_l_espace_est_ouvert(): void
    {
        $paiement = $this->souscrireBusiness();
        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $paiement), ['action' => 'activer']);

        $this->actingAs($this->admin)->getJson(route('admin.abonnement.etat'))->assertJson(['statut' => 'actif']);
        $this->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertSee('Abonnement confirmé')
            ->assertDontSee('class="abonnement-a-terme', false);

        $this->post(route('admin.abonnement.confirmation_vue'))->assertRedirect();
        $this->assertNotNull($paiement->fresh()->confirmation_vue_le);
        $this->get(route('admin.tableau_de_bord'))->assertOk()->assertDontSee('Abonnement confirmé');

        $this->creerUnEmploye()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('utilisateurs', ['email' => 'awa@exemple.ci']);
    }

    public function test_le_renouvellement_avant_l_echeance_ne_bloque_pas(): void
    {
        $this->entreprise->forceFill([
            'plan_abonnement' => Abonnements::BUSINESS, 'abonnement_statut' => Abonnements::STATUT_ACTIF,
            'abonnement_expire_le' => now()->addDays(10),
        ])->save();

        $this->actingAs($this->admin)->post(route('admin.abonnement.renouveler'), [
            'depuis' => 'facturation', 'operateur_paiement' => 'WAVE', 'reference_paiement' => 'WV-RENOUV',
        ])->assertSessionHasNoErrors();
        $paiement = PaiementAbonnement::sole();
        $this->assertFalse($paiement->bloquant);

        $this->get(route('admin.tableau_de_bord'))->assertOk()
            ->assertDontSee('En attente de confirmation de l\'abonnement', false)
            ->assertSee('Renouvellement en cours de vérification');
        $this->creerUnEmploye()->assertSessionHasNoErrors();

        // Confirmé, le mois s'ajoute à l'échéance en cours.
        $echeance = $this->entreprise->fresh()->abonnement_expire_le->copy();
        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $paiement), ['action' => 'activer']);
        $this->assertSame($echeance->addMonthsNoOverflow(1)->toDateString(), $this->entreprise->fresh()->abonnement_expire_le->toDateString());
    }

    public function test_quitter_le_pass_cadeau_pour_une_offre_payante_bloque_aussi(): void
    {
        $this->actingAs($this->admin)->post(route('admin.abonnement.enregistrer'), ['offre' => Abonnements::PASS_CADEAU]);
        $paiement = $this->souscrireBusiness();
        $this->assertTrue($paiement->bloquant);
        $this->assertNotNull(Abonnements::paiementEnSuspens($this->entreprise->fresh()));

        // Confirmé : un mois depuis aujourd'hui, les jours du pass ne s'ajoutent pas.
        $this->actingAs($this->superadmin)->put(route('superadmin.paiements.traiter', $paiement), ['action' => 'activer']);
        $this->assertSame(now()->addMonthsNoOverflow(1)->toDateString(), $this->entreprise->fresh()->abonnement_expire_le->toDateString());
    }

    public function test_le_caissier_est_bloque_sans_bouton_vers_la_facturation(): void
    {
        $this->souscrireBusiness();
        $caissier = Utilisateur::create([
            'nom' => 'K', 'prenom' => 'A', 'email' => 'caisse@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'caissier', 'entreprise_id' => $this->entreprise->id,
            'habilitations' => ['factures_vente'],
            'point_de_vente_id' => PointDeVente::where('entreprise_id', $this->entreprise->id)->value('id'),
        ]);

        $page = $this->actingAs($caissier)->get(route('caissier.ventes.factures'))->assertOk()->getContent();
        $this->assertStringContainsString('En attente de confirmation de l\'abonnement', $page);
        $this->assertStringContainsString('Prévenez l\'administrateur', $page);
        $this->assertStringNotContainsString('btn-facturation-en-attente', $page);
        $this->getJson(route('admin.abonnement.etat'))->assertOk()->assertJson(['statut' => 'en_attente']);
    }
}
