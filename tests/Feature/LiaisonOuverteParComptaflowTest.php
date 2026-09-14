<?php

namespace Tests\Feature;

use App\Jobs\DeverserReferentielComptaflow;
use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * La liaison lancée depuis Comptaflow : `POST /api/external/link-company`.
 *
 * Comptaflow l'appelle depuis ses deux écrans de liaison, et **la route
 * n'existait pas chez Selflow** : 404 (Not Found — introuvable). Comptaflow
 * affichait « créé et lié avec succès », Selflow n'avait aucune clé à
 * présenter, et aucun déversement ne partait.
 */
class LiaisonOuverteParComptaflowTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secret-serveur-de-test';

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        config(['selflow.comptaflow_api_secret' => self::SECRET]);
        Queue::fake();

        $this->entreprise = Entreprise::create(['nom' => 'Quincaillerie du Plateau']);
    }

    private function lier(array $corps = [])
    {
        return $this->postJson('/api/external/link-company', array_merge([
            'secret'                => self::SECRET,
            'selflow_company_id'    => $this->entreprise->id,
            'comptaflow_company_id' => 42,
            'comptaflow_sync_key'   => 'cptf_live_cle_delivree_par_comptaflow_0001',
        ], $corps));
    }

    public function test_la_cle_est_rangee_chiffree_et_le_referentiel_part(): void
    {
        $this->lier()->assertOk()->assertJson(['success' => true]);

        $entreprise = $this->entreprise->fresh();

        $this->assertTrue($entreprise->liaisonComptaflowActive());
        $this->assertSame(42, (int) $entreprise->comptaflow_company_id);
        $this->assertSame('cptf_live_cle_delivree_par_comptaflow_0001', $entreprise->comptaflow_sync_key);
        $this->assertSame('0001', $entreprise->comptaflow_cle_indice);

        // En base, la clé ne se lit pas.
        $this->assertStringNotContainsString('cptf_live',
            (string) \DB::table('entreprises')->where('id', $entreprise->id)->value('comptaflow_sync_key'));

        // Hors de la requête : Comptaflow attend encore la réponse.
        Queue::assertPushed(DeverserReferentielComptaflow::class,
            fn ($job) => $job->entrepriseId === $entreprise->id);
    }

    public function test_sans_le_bon_secret_rien_n_est_range(): void
    {
        $this->lier(['secret' => 'pas-le-bon'])->assertUnauthorized();

        $this->assertFalse($this->entreprise->fresh()->liaisonComptaflowActive());
        Queue::assertNothingPushed();
    }

    public function test_une_liaison_active_ne_se_detourne_pas_vers_un_autre_dossier(): void
    {
        // Le secret autorise l'appel ; il ne doit pas suffire à envoyer les
        // livres d'une entreprise liée vers un autre dossier.
        $this->lier()->assertOk();

        $this->lier([
            'comptaflow_company_id' => 99,
            'comptaflow_sync_key'   => 'cptf_live_cle_d_un_autre_dossier_000009',
        ])->assertStatus(409);

        $entreprise = $this->entreprise->fresh();
        $this->assertSame(42, (int) $entreprise->comptaflow_company_id);
        $this->assertSame('cptf_live_cle_delivree_par_comptaflow_0001', $entreprise->comptaflow_sync_key);
    }

    public function test_le_meme_dossier_peut_reposer_sa_cle(): void
    {
        $this->lier()->assertOk();

        $this->lier(['comptaflow_sync_key' => 'cptf_live_cle_reposee_par_le_meme_dossier_02'])->assertOk();

        $this->assertSame('cptf_live_cle_reposee_par_le_meme_dossier_02',
            $this->entreprise->fresh()->comptaflow_sync_key);
    }

    public function test_une_entreprise_inconnue_est_introuvable(): void
    {
        $this->lier(['selflow_company_id' => 999999])->assertNotFound();
    }
}
