<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Services\DeversementHistoriqueService;
use App\Support\Journaux;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** 24/11/2026 : journaux rangés par dossier et par jour ; minuteur et reprise du déversement. */
class JournauxEtDeversementTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_dossier_par_type_et_un_fichier_par_jour(): void
    {
        $jour = Carbon::create(2026, 11, 24);
        $this->assertStringEndsWith('logs/achat/241126-portail-fne-achat.log', Journaux::chemin('achat', $jour));
        $this->assertStringEndsWith('logs/vente/241126-portail-fne-vente.log', Journaux::chemin('vente', $jour));
        $this->assertStringEndsWith('logs/avoir/241126-portail-fne-avoir.log', Journaux::chemin('avoir', $jour));
        $this->assertStringEndsWith('logs/comptaflow/241126-comptaflow-deversement.log', Journaux::chemin('comptaflow', $jour));
        $this->assertSame(Journaux::chemin('vente'), \App\Modules\Admin\Services\ScraperPortailFneService::sorties('ventes'));
    }

    public function test_des_operations_en_cours_sans_envoi_sont_remises_en_file_et_minutees(): void
    {
        config(['queue.default' => 'database']);
        Bus::fake();
        $e = Entreprise::create(['nom' => 'DC-KNOWING CGA']);
        $e->forceFill(['comptaflow_sync_status' => 'active', 'comptaflow_sync_key' => 'cle'])->save();
        $op = Operation::forceCreate(['entreprise_id' => $e->id, 'date_operation' => now()->toDateString(), 'type_operation' => 'OD',
            'code_journal' => 'OD', 'numero_saisie' => 'OD-1', 'est_equilibree' => true]);
        EcritureComptable::withoutGlobalScopes()->forceCreate(['entreprise_id' => $e->id, 'operation_id' => $op->id, 'date_ecriture' => now()->toDateString(),
            'libelle' => 'x', 'reference_document' => 'R', 'code_journal' => 'OD', 'compte_debit' => '411000', 'debit' => 10, 'credit' => 0,
            'comptaflow_sync_status' => 'pending']);

        $etat = DeversementHistoriqueService::etat($e);
        $this->assertSame(1, $etat['en_cours']);
        $this->assertTrue($etat['reprise_auto'], 'Aucun travail en file : la reprise part d\'elle-même.');
        Bus::assertDispatched(\App\Jobs\MettreEnFileDeversementComptaflow::class);
        $this->assertNotNull($etat['chrono'] ?? DeversementHistoriqueService::etat($e)['chrono']);

        $this->assertFalse(DeversementHistoriqueService::etat($e)['reprise_auto'], 'Pas deux reprises en trois minutes.');
    }

    public function test_relancer_les_echecs_efface_le_rouge_tout_de_suite(): void
    {
        Bus::fake();
        $e = Entreprise::create(['nom' => 'DC-KNOWING CGA']);
        $e->forceFill(['comptaflow_sync_status' => 'active', 'comptaflow_sync_key' => 'cle'])->save();
        $op = Operation::forceCreate(['entreprise_id' => $e->id, 'date_operation' => now()->toDateString(), 'type_operation' => 'OD',
            'code_journal' => 'OD', 'numero_saisie' => 'OD-2', 'est_equilibree' => true]);
        EcritureComptable::withoutGlobalScopes()->forceCreate(['entreprise_id' => $e->id, 'operation_id' => $op->id, 'date_ecriture' => now()->toDateString(),
            'libelle' => 'x', 'reference_document' => 'R', 'code_journal' => 'OD', 'compte_debit' => '411000', 'debit' => 10, 'credit' => 0,
            'comptaflow_sync_status' => 'failed', 'comptaflow_sync_error' => 'Les exercices ne se recouvrent pas']);

        $this->assertSame(1, DeversementHistoriqueService::etat($e)['en_echec']);

        $retour = DeversementHistoriqueService::relancerEchecs($e);

        $this->assertTrue($retour['success']);
        $this->assertSame(1, $retour['operations']);
        $this->assertStringContainsString('relancée', $retour['message']);
        $this->assertSame(0, $retour['etat']['en_echec'], 'Le cadre rouge doit disparaître dès la réponse.');
        $this->assertSame([], $retour['etat']['erreurs']);
        $this->assertSame('pending', EcritureComptable::withoutGlobalScopes()->where('operation_id', $op->id)->value('comptaflow_sync_status'));
        Bus::assertDispatched(\App\Jobs\MettreEnFileDeversementComptaflow::class);
    }
}
