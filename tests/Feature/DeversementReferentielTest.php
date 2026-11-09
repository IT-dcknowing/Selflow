<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Services\RecevoirReferentielComptaflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le référentiel vient de Comptaflow vers Selflow.
 *
 * Ces tests verrouillent la séparation : les données reçues portent
 * source=comptaflow, les lignes locales ne sont jamais écrasées/supprimées,
 * et aucun référentiel Selflow n'est envoyé à l'API Comptaflow.
 */
class DeversementReferentielTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'selflow.comptaflow_api_url' => 'https://comptaflow.test',
            'selflow.comptaflow_api_secret' => 'secret-de-test',
        ]);

        $this->entreprise = Entreprise::create([
            'nom' => 'Quincaillerie du Plateau',
            'comptaflow_sync_status' => 'active',
            'comptaflow_company_id' => 42,
        ]);
        $this->entreprise->comptaflow_sync_key = 'cle-de-liaison';
        $this->entreprise->save();
    }

    private function comptaflowRepond(array $donnees = []): void
    {
        Http::fake([
            '*/api/external/link-company' => Http::response(array_merge([
                'success' => true,
                'company_id' => 42,
                'plan_comptable' => [],
                'codes_journaux' => [],
                'tiers' => [],
            ], $donnees), 200),
        ]);
    }

    public function test_le_plan_journaux_clients_et_fournisseurs_sont_recus(): void
    {
        $this->comptaflowRepond([
            'plan_comptable' => [
                ['id' => 1, 'numero_de_compte' => '411000', 'intitule' => 'Clients Comptaflow', 'numero_original' => '411'],
                ['id' => 2, 'numero_de_compte' => '401000', 'intitule' => 'Fournisseurs Comptaflow', 'numero_original' => '401'],
            ],
            'codes_journaux' => [
                ['id' => 1, 'code_journal' => 'VTE', 'intitule' => 'Ventes', 'type' => 'Ventes', 'compte_numero' => '701000'],
                ['id' => 2, 'code_journal' => 'CSH', 'intitule' => 'Caisse', 'type' => 'Trésorerie', 'compte_numero' => '571000'],
            ],
            'tiers' => [
                ['id' => 1, 'numero_de_tiers' => '41100001', 'intitule' => 'Client Comptaflow', 'type_de_tiers' => 'client', 'compte_general' => '411000'],
                ['id' => 2, 'numero_de_tiers' => '40100001', 'intitule' => 'Fournisseur Comptaflow', 'type_de_tiers' => 'fournisseur', 'compte_general' => '401000'],
            ],
        ]);

        $resultat = RecevoirReferentielComptaflowService::synchroniser($this->entreprise->fresh());

        $this->assertTrue($resultat['success'], $resultat['message']);
        $this->assertDatabaseHas('plan_comptable', [
            'entreprise_id' => $this->entreprise->id,
            'numero' => '411000',
            'source' => 'comptaflow',
            'libelle' => 'Clients Comptaflow',
        ]);
        $this->assertDatabaseHas('codes_journaux', [
            'entreprise_id' => $this->entreprise->id,
            'code' => 'VTE',
            'source' => 'comptaflow',
            'type' => 'Vente',
        ]);
        $this->assertDatabaseHas('codes_journaux', [
            'entreprise_id' => $this->entreprise->id,
            'code' => 'CSH',
            'source' => 'comptaflow',
            'type' => 'Caisse',
        ]);
        $this->assertDatabaseHas('clients', [
            'entreprise_id' => $this->entreprise->id,
            'numero_tiers' => '41100001',
            'source' => 'comptaflow',
        ]);
        $this->assertDatabaseHas('fournisseurs', [
            'entreprise_id' => $this->entreprise->id,
            'numero_tiers' => '40100001',
            'source' => 'comptaflow',
        ]);
    }

    public function test_une_reception_ne_modifie_jamais_les_lignes_locales(): void
    {
        PlanComptable::create([
            'entreprise_id' => $this->entreprise->id,
            'numero' => '411000',
            'libelle' => 'Compte local à préserver',
            'source' => 'local',
        ]);
        CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'VTE',
            'intitule' => 'Journal local à préserver',
            'type' => 'Vente',
            'source' => 'local',
        ]);
        Client::create([
            'entreprise_id' => $this->entreprise->id,
            'numero_tiers' => '41100001',
            'nom' => 'Client local à préserver',
            'compte_comptable' => '411000',
            'source' => 'local',
        ]);
        Fournisseur::create([
            'entreprise_id' => $this->entreprise->id,
            'numero_tiers' => '40100001',
            'nom' => 'Fournisseur local à préserver',
            'compte_comptable' => '401000',
            'source' => 'local',
        ]);

        $this->comptaflowRepond([
            'plan_comptable' => [['numero_de_compte' => '411000', 'intitule' => 'Libellé distant']],
            'codes_journaux' => [['code_journal' => 'VTE', 'intitule' => 'Journal distant', 'type' => 'Ventes']],
            'tiers' => [
                ['numero_de_tiers' => '41100001', 'intitule' => 'Client distant', 'type_de_tiers' => 'client'],
                ['numero_de_tiers' => '40100001', 'intitule' => 'Fournisseur distant', 'type_de_tiers' => 'fournisseur'],
            ],
        ]);

        $resultat = RecevoirReferentielComptaflowService::synchroniser($this->entreprise->fresh());

        $this->assertTrue($resultat['success']);
        $this->assertDatabaseHas('plan_comptable', ['numero' => '411000', 'libelle' => 'Compte local à préserver', 'source' => 'local']);
        $this->assertDatabaseHas('codes_journaux', ['code' => 'VTE', 'intitule' => 'Journal local à préserver', 'source' => 'local']);
        $this->assertDatabaseHas('clients', ['numero_tiers' => '41100001', 'nom' => 'Client local à préserver', 'source' => 'local']);
        $this->assertDatabaseHas('fournisseurs', ['numero_tiers' => '40100001', 'nom' => 'Fournisseur local à préserver', 'source' => 'local']);
        $this->assertSame(4, $resultat['bilan']['conflits_locaux']);
    }

    public function test_une_actualisation_ne_supprime_pas_les_lignes_comptaflow_absentes_de_la_reponse(): void
    {
        PlanComptable::create([
            'entreprise_id' => $this->entreprise->id,
            'numero' => '512000',
            'libelle' => 'Compte reçu précédemment',
            'source' => 'comptaflow',
        ]);

        $this->comptaflowRepond();

        $resultat = RecevoirReferentielComptaflowService::synchroniser($this->entreprise->fresh());

        $this->assertTrue($resultat['success']);
        $this->assertDatabaseHas('plan_comptable', [
            'entreprise_id' => $this->entreprise->id,
            'numero' => '512000',
            'source' => 'comptaflow',
        ]);
    }

    public function test_la_reception_ne_transmet_aucun_referentiel_a_comptaflow(): void
    {
        $this->comptaflowRepond();

        $resultat = RecevoirReferentielComptaflowService::synchroniser($this->entreprise->fresh());

        $this->assertTrue($resultat['success']);
        Http::assertSent(fn ($requete) => str_contains($requete->url(), '/api/external/link-company')
            && $requete['secret'] === 'secret-de-test'
            && $requete['selflow_sync_key'] === 'cle-de-liaison'
            && !array_key_exists('clients', $requete->data())
            && !array_key_exists('fournisseurs', $requete->data())
            && !array_key_exists('plan_comptable', $requete->data())
            && !array_key_exists('codes_journaux', $requete->data())
            && !array_key_exists('tiers', $requete->data()));
        Http::assertNotSent(fn ($requete) => str_contains($requete->url(), '/referentiel/deverser'));
    }

    public function test_les_autres_types_de_tiers_sont_signales_et_non_mal_classes(): void
    {
        $this->comptaflowRepond([
            'tiers' => [[
                'numero_de_tiers' => '47100001',
                'intitule' => 'Tiers divers',
                'type_de_tiers' => 'autre',
                'compte_general' => '471000',
            ]],
        ]);

        $resultat = RecevoirReferentielComptaflowService::synchroniser($this->entreprise->fresh());

        $this->assertTrue($resultat['success']);
        $this->assertSame(1, $resultat['bilan']['autres_tiers_non_pris_en_charge']);
        $this->assertStringContainsString('non représenté', implode(' ', $resultat['refus']));
        $this->assertDatabaseMissing('clients', ['numero_tiers' => '47100001']);
        $this->assertDatabaseMissing('fournisseurs', ['numero_tiers' => '47100001']);
    }
}
