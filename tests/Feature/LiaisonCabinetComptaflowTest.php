<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Services\LiaisonComptaflowService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 24/11/2026 : à la liaison, le dossier Comptaflow peut être affecté à un
 * cabinet (son espace) ou à un membre précis du cabinet. Facultatif.
 */
class LiaisonCabinetComptaflowTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['selflow.comptaflow_api_secret' => 'secret-serveur-de-test', 'selflow.comptaflow_api_url' => 'http://comptaflow.test']);

        $this->entreprise = Entreprise::create([
            'nom' => 'SAS\'T', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody', 'ncc' => '2196217J',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'comptabilite'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        Utilisateur::create([
            'nom' => 'Kone', 'prenom' => 'Awa', 'email' => 'awa-cabinet@exemple.ci', 'password' => bcrypt('secret-de-test'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        $this->superadmin = Utilisateur::create([
            'nom' => 'Selflow', 'prenom' => 'Support', 'email' => 'support-cabinet@selflow.ci', 'password' => bcrypt('secret-de-test'),
            'role' => 'superadmin', 'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME,
        ]);
    }

    private function comptaflow(array $affectation = ['success' => true, 'message' => 'Dossier affecté au cabinet « Cabinet Yao ».']): void
    {
        Http::fake([
            '*/api/external/cabinets' => Http::response(['success' => true, 'cabinets' => [
                ['id' => 7, 'nom' => 'Cabinet Yao', 'code' => 'CAB-YAO-ABC123', 'membres' => [
                    ['id' => 40, 'nom' => 'Yao Koffi', 'email' => 'yao@cabinet.ci', 'role' => 'gerant'],
                    ['id' => 41, 'nom' => 'Ble Ines', 'email' => 'ines@cabinet.ci', 'role' => 'collaborateur'],
                ]],
            ]]),
            '*/api/external/companies/provision' => Http::response(['success' => true, 'company_id' => 42, 'sync_key' => 'cptf_live_cabinet', 'affectation' => $affectation]),
            '*/api/external/companies/affecter' => Http::response($affectation),
            '*' => Http::response(['success' => true]),
        ]);
    }

    public function test_la_liste_des_cabinets_et_de_leurs_membres_est_proposee(): void
    {
        $this->comptaflow();

        $page = $this->actingAs($this->superadmin)->get(route('superadmin.liaisons.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Rechercher un cabinet, un membre', $page);
        $this->assertStringContainsString('value="c:7"', $page);
        $this->assertStringContainsString('value="u:7:41"', $page);
        $this->assertStringContainsString('Aucun cabinet', $page);
    }

    public function test_sans_le_point_d_entree_l_ecran_le_dit_et_la_liaison_reste_possible(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $page = $this->actingAs($this->superadmin)->get(route('superadmin.liaisons.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Comptaflow ne fournit pas encore la liste des cabinets', $page);
    }

    public function test_la_liaison_affecte_le_dossier_a_un_membre_du_cabinet(): void
    {
        LiaisonComptaflowService::demander($this->entreprise);
        $this->comptaflow(['success' => true, 'message' => 'Dossier affecté au cabinet « Cabinet Yao » et confié au membre choisi.']);

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.liaisons.valider', $this->entreprise), ['mode' => 'creer', 'cabinet' => 'u:7:41'])
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/companies/provision')
            && $r['affectation'] === ['cabinet_id' => 7, 'user_id' => 41]
            // Les accès Selflow partent bien : même adresse, même mot de passe.
            && $r['entreprise']['admin_email'] === 'awa-cabinet@exemple.ci'
            && str_starts_with((string) $r['entreprise']['admin_password_hash'], '$2y$'));

        $e = $this->entreprise->fresh();
        $this->assertSame(7, (int) $e->comptaflow_cabinet_id);
        $this->assertSame('Cabinet Yao', $e->comptaflow_cabinet_nom);
        $this->assertSame('Ble Ines', $e->comptaflow_cabinet_membre);
    }

    public function test_sans_cabinet_rien_ne_part(): void
    {
        LiaisonComptaflowService::demander($this->entreprise);
        $this->comptaflow();

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.liaisons.valider', $this->entreprise), ['mode' => 'creer', 'cabinet' => ''])
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/companies/provision') && !isset($r['affectation']));
        $this->assertNull($this->entreprise->fresh()->comptaflow_cabinet_id);
    }

    public function test_un_dossier_deja_lie_s_affecte_a_l_espace_du_cabinet(): void
    {
        $this->entreprise->forceFill(['comptaflow_company_id' => 42, 'comptaflow_sync_status' => 'active', 'comptaflow_sync_key' => 'cle'])->save();
        $this->comptaflow();

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.liaisons.affecter_cabinet', $this->entreprise), ['cabinet' => 'c:7'])
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/companies/affecter')
            && $r['selflow_company_id'] === $this->entreprise->id && $r['cabinet_id'] === 7 && $r['user_id'] === null);
        $this->assertSame('Cabinet Yao', $this->entreprise->fresh()->comptaflow_cabinet_nom);
        $this->assertNull($this->entreprise->fresh()->comptaflow_cabinet_membre);
    }

    public function test_un_refus_de_comptaflow_est_dit(): void
    {
        $this->entreprise->forceFill(['comptaflow_company_id' => 42, 'comptaflow_sync_status' => 'active', 'comptaflow_sync_key' => 'cle'])->save();
        $this->comptaflow(['success' => false, 'message' => "Cet utilisateur n'est pas membre du cabinet « Cabinet Yao »."]);

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.liaisons.affecter_cabinet', $this->entreprise), ['cabinet' => 'u:7:99'])
            ->assertSessionHas('error');
        $this->assertNull($this->entreprise->fresh()->comptaflow_cabinet_id);
    }
}
