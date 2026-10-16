<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\DemandeExercicesAnterieurs;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Retour du 16/10/2026 : à la liaison, le superadministrateur choisit entre
 * créer un dossier et lier un dossier existant, déverse maintenant ou plus
 * tard, et coche les exercices ; l'entreprise coche les siens en demandant.
 */
class LiaisonComptaflowChoixTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private Utilisateur $superadmin;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['selflow.comptaflow_api_secret' => 'secret-serveur-de-test']);
        config(['selflow.comptaflow_api_url' => 'http://comptaflow.test']);

        $this->entreprise = Entreprise::create([
            'nom' => 'Choix SARL', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-1', 'ncc' => '2601999A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'ventes', 'comptabilite', 'tiers'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'K', 'prenom' => 'L', 'email' => 'choix-liaison@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->superadmin = Utilisateur::create([
            'nom' => 'S', 'prenom' => 'A', 'email' => 'super-choix@selflow.ci', 'password' => bcrypt('x'),
            'role' => 'superadmin', 'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME,
        ]);

        // Deux exercices antérieurs et l'exercice en cours.
        foreach ([now()->year - 2, now()->year - 1, now()->year] as $annee) {
            Operation::withoutGlobalScopes()->create([
                'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
                'date_operation' => "{$annee}-03-10", 'type_operation' => 'vente', 'code_journal' => 'VTE',
                'numero_saisie' => 'S-' . $annee, 'libelle_general' => 'Vente', 'solde_equilibre' => 0, 'est_equilibree' => true,
            ]);
        }

        Http::fake([
            '*/api/external/list-companies' => Http::response(['success' => true, 'companies' => [
                ['id' => 7, 'nom' => 'Cabinet Existant SA'],
            ]]),
            '*/api/external/companies/provision' => Http::response(['success' => true, 'company_id' => 7, 'sync_key' => 'cptf_cle_test_1234']),
            '*' => Http::response(['success' => true]),
        ]);
    }

    public function test_l_ecran_propose_de_lier_un_dossier_existant_et_les_exercices(): void
    {
        $this->actingAs($this->superadmin)->get(route('superadmin.liaisons.index'))->assertOk()
            ->assertSee('Lier à un dossier existant')
            ->assertSee('#7 — Cabinet Existant SA')
            ->assertSee('Déverser les écritures maintenant');
    }

    public function test_lier_a_un_dossier_existant_garde_les_acces_et_accorde_les_exercices(): void
    {
        $this->actingAs($this->superadmin)->post(route('superadmin.liaisons.valider', $this->entreprise), [
            'mode' => 'lier', 'comptaflow_company_id' => 7, 'deverser' => 0,
            'annees' => [now()->year - 1],
        ])->assertSessionHas('success');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/companies/provision')
            && $r['comptaflow_company_id'] === 7
            && $r['conserver_acces'] === true
            && !isset($r['entreprise']['admin_password_hash'])
            && !isset($r['entreprise']['admin_email']));

        $e = $this->entreprise->fresh();
        $this->assertSame(7, (int) $e->comptaflow_company_id);
        $this->assertSame([now()->year - 1], DemandeExercicesAnterieurs::anneesAccordees($e->id));

        // Plus tard : l'historique n'est pas parti.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/ecritures/deverser'));

        // Et le nom du dossier se lit dans le tableau.
        $this->get(route('superadmin.liaisons.index'))->assertSee('Cabinet Existant SA');
    }

    public function test_lier_exige_le_choix_du_dossier(): void
    {
        $this->actingAs($this->superadmin)->post(route('superadmin.liaisons.valider', $this->entreprise), ['mode' => 'lier'])
            ->assertSessionHasErrors('comptaflow_company_id');

        $this->assertNull($this->entreprise->fresh()->comptaflow_company_id);
    }

    public function test_creer_et_deverser_maintenant_envoie_l_historique(): void
    {
        $this->actingAs($this->superadmin)->post(route('superadmin.liaisons.valider', $this->entreprise), [
            'mode' => 'creer', 'deverser' => 1,
        ])->assertSessionHas('success', fn ($m) => str_contains($m, 'Référentiel') || str_contains($m, 'référentiel'));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/companies/provision') && isset($r['entreprise']['admin_password_hash']));
    }

    public function test_l_entreprise_coche_ses_exercices_en_demandant_la_liaison(): void
    {
        $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('Exercices à déverser dans Comptaflow');

        $this->post(route('admin.entreprise.comptaflow.demander'), ['annees' => [now()->year - 2]])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->entreprise->fresh()->demandeComptaflowEnAttente());
        $this->assertSame([now()->year - 2], DemandeExercicesAnterieurs::anneesEnAttente($this->entreprise->id));

        // Le superadministrateur valide sans la cocher : la demande est tranchée.
        $this->actingAs($this->superadmin)->post(route('superadmin.liaisons.valider', $this->entreprise), [
            'mode' => 'creer', 'annees' => [now()->year - 1],
        ]);
        $this->assertSame([], DemandeExercicesAnterieurs::anneesEnAttente($this->entreprise->id));
        $this->assertSame([now()->year - 1], DemandeExercicesAnterieurs::anneesAccordees($this->entreprise->id));
    }
}
