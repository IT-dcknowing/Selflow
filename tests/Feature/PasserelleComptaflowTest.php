<?php

namespace Tests\Feature;

use App\Jobs\DeverserOperationComptaflow;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\Periode;
use App\Modules\Admin\Modeles\PointDeVente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ce que Selflow envoie à Comptaflow.
 *
 * Le déversement partait amputé de quatre informations, et chacune manquait
 * pour une raison différente :
 *
 * - **le compte de tiers** — Comptaflow le cherchait dans son plan de tiers à
 *   partir du compte général, ne le trouvait pas, et rattachait l'écriture au
 *   seul compte collectif. Le relevé d'un client particulier devenait
 *   impossible à établir ;
 * - **une clé d'idempotence** — `n_saisie` recevait la référence de pièce, qui
 *   ne distingue pas un renvoi d'une écriture nouvelle. Rejouer une
 *   synchronisation dupliquait tout, et la balance doublait ;
 * - **le point de vente** — chez Comptaflow, un axe analytique et sa section.
 *   Sans lui, aucune ventilation par magasin n'est possible en aval ;
 * - **l'exercice** — Comptaflow prenait le sien, actif, sans jamais comparer.
 *   Une pièce d'un exercice clos se serait rangée dans l'exercice courant.
 */
class PasserelleComptaflowTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'selflow.comptaflow_api_url'    => 'https://comptaflow.test',
            'selflow.comptaflow_api_secret' => 'secret-de-test',
        ]);

        $this->entreprise = Entreprise::create([
            'nom' => 'Boutique du carrefour',
            'comptaflow_sync_status' => 'active',
        ]);

        // La clé ne passe plus par l'affectation en masse : elle n'est pas
        // `$fillable`, précisément pour qu'aucune requête ne puisse l'y
        // glisser. Elle se pose comme le service la pose.
        $this->entreprise->comptaflow_sync_key = 'cle-de-liaison';
        $this->entreprise->save();

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        Periode::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Exercice 2026',
            'date_debut' => '2026-01-01', 'date_fin' => '2026-12-31',
            'est_active' => true,
        ]);

        Http::fake([
            '*' => Http::response(['success' => true, 'count' => 1], 200),
        ]);
    }

    /**
     * Une opération entière : son débit, son crédit, et sa clôture.
     *
     * Elle n'écrivait qu'une ligne, et le déversement partait sur `created`.
     * Il part désormais à la clôture, et **seulement si l'opération est
     * équilibrée** : une ligne seule ne partirait pas, et c'est voulu —
     * déverser la moitié d'une opération porterait le déséquilibre chez
     * Comptaflow.
     */
    private function ecrire(array $attributs = []): EcritureComptable
    {
        $operation = Operation::creer(
            $this->entreprise->id, $this->site->id, '2026-03-10',
            'test', 'VTE', 'FAC-001', 'Vente de marchandises'
        );

        $commun = [
            'operation_id'       => $operation->id,
            'entreprise_id'      => $this->entreprise->id,
            'point_de_vente_id'  => $this->site->id,
            'date_ecriture'      => '2026-03-10',
            'libelle'            => 'Vente de marchandises',
            'reference_document' => 'FAC-001',
            'code_journal'       => 'VTE',
        ];

        $debit = EcritureComptable::create(array_merge($commun, [
            'compte_debit'  => '411000',
            'compte_tiers'  => 'CL0001',
            'debit'         => 100000,
            'credit'        => 0,
        ], $attributs));

        // La contrepartie, sans quoi l'opération ne serait pas équilibrée et
        // ne partirait pas.
        EcritureComptable::create(array_merge($commun, [
            'compte_credit' => '701000',
            'debit'         => 0,
            'credit'        => 100000,
        ]));

        $operation->cloturerEquilibre();

        return $debit;
    }

    /** L'opération que la dernière écriture porte. */
    private function operation(EcritureComptable $ecriture): Operation
    {
        return Operation::findOrFail($ecriture->operation_id);
    }

    /**
     * Le corps de la requête tel qu'il part sur le fil.
     *
     * L'exercice y vit désormais, et non dans chaque ligne : il vaut pour
     * l'opération entière, et c'est là que Comptaflow le lit.
     */
    private function corps(): array
    {
        $envoye = [];

        Http::recorded(function ($requete) use (&$envoye) {
            if (isset($requete->data()['ecritures'])) {
                $envoye = $requete->data();
            }

            return true;
        });

        return $envoye;
    }

    /**
     * L'écriture telle qu'elle part sur le fil.
     */
    private function payload(): array
    {
        $envoyees = [];

        Http::recorded(function ($requete) use (&$envoyees) {
            $corps = $requete->data();

            if (isset($corps['ecritures'])) {
                $envoyees = $corps['ecritures'][0];
            }

            return true;
        });

        return $envoyees;
    }

    // ── Ce qui manquait ──────────────────────────────────────────────

    public function test_le_compte_de_tiers_part_avec_l_ecriture(): void
    {
        // Sans lui, le releve d'un client particulier est impossible : tout
        // atterrit sur le compte collectif 411000.
        $this->ecrire();

        $this->assertSame('CL0001', $this->payload()['compte_tiers']);
    }

    public function test_une_cle_d_idempotence_accompagne_chaque_ecriture(): void
    {
        // `n_saisie` recevait la reference de piece : rejouer une
        // synchronisation dupliquait tout, et la balance doublait.
        $ecriture = $this->ecrire();

        $this->assertSame(
            "SELFLOW-{$this->entreprise->id}-{$ecriture->id}",
            $this->payload()['cle_selflow']
        );
    }

    public function test_la_cle_distingue_deux_ecritures_de_la_meme_piece(): void
    {
        // Une facture produit plusieurs ecritures sous la meme reference : la
        // cle doit les distinguer, sinon la seconde passerait pour un doublon
        // de la premiere et serait perdue.
        $this->ecrire();

        // L'opération porte son débit et son crédit, sous la même référence de
        // pièce. Les deux partent dans le même envoi : leurs clés doivent
        // différer, sinon la seconde passerait pour un renvoi de la première
        // et serait perdue.
        $lignes = $this->corps()['ecritures'];

        $this->assertCount(2, $lignes);
        $this->assertNotSame($lignes[0]['cle_selflow'], $lignes[1]['cle_selflow']);
        $this->assertSame($lignes[0]['reference_document'], $lignes[1]['reference_document']);
    }

    public function test_le_point_de_vente_part_comme_section_analytique(): void
    {
        // Les points de vente sont les axes, leurs noms les sections.
        $this->ecrire();

        $this->assertSame('Magasin central', $this->payload()['point_de_vente']);
    }

    public function test_l_exercice_ouvert_chez_nous_est_annonce(): void
    {
        // Comptaflow prenait le sien sans comparer : une piece d'un exercice
        // clos se serait rangee dans l'exercice courant.
        $this->ecrire();

        $this->assertSame('2026-01-01', $this->corps()['exercice_debut']);
        $this->assertSame('2026-12-31', $this->corps()['exercice_fin']);
    }

    // ── Ce qui doit continuer de partir ──────────────────────────────

    public function test_les_champs_d_origine_partent_toujours(): void
    {
        $this->ecrire();
        $payload = $this->payload();

        $this->assertSame('2026-03-10', $payload['date_ecriture']);
        $this->assertSame('FAC-001', $payload['reference_document']);
        $this->assertSame('VTE', $payload['code_journal']);
        $this->assertSame('411000', $payload['compte_debit']);
        $this->assertSame(100000.0, $payload['debit']);
    }

    // ── Le secret ────────────────────────────────────────────────────

    public function test_le_secret_part_avec_la_requete(): void
    {
        $this->ecrire();

        $vu = false;

        Http::recorded(function ($requete) use (&$vu) {
            if (($requete->data()['secret'] ?? null) === 'secret-de-test') {
                $vu = true;
            }

            return true;
        });

        $this->assertTrue($vu, 'Le secret partagé doit accompagner chaque déversement.');
    }

    public function test_sans_secret_configure_rien_ne_part_avec_un_secret_devinable(): void
    {
        // Six sites posaient un repli en dur — « selflow-comptaflow-secret-2026 »
        // — qui annulait la correction du lot 0 : la variable d'environnement
        // pouvait manquer sans que rien ne s'en aperçoive.
        config(['selflow.comptaflow_api_secret' => null]);

        $this->ecrire();

        Http::recorded(function ($requete) {
            $this->assertNull(
                $requete->data()['secret'] ?? null,
                'Aucun secret de repli ne doit être inventé.'
            );

            return true;
        });
    }

    // ── L'entreprise non liée ────────────────────────────────────────

    public function test_une_entreprise_non_liee_n_envoie_rien(): void
    {
        $this->entreprise->update(['comptaflow_sync_status' => 'inactive']);

        $this->ecrire();

        Http::assertNothingSent();
    }

    // ── Le déversement part en arrière-plan, et par opération ──────────
    //
    // L'appel HTTP partait en synchrone dans le hook `created()` : chaque
    // écriture faisait attendre la caisse. Il est passé en file, puis — le
    // 25/09/2026 — **de la ligne à l'opération**.
    //
    // Ligne par ligne, une opération pouvait arriver à moitié chez Comptaflow :
    // le débit du client passait, le crédit de la vente était refusé, et la
    // balance ne balançait plus sans que rien ne le dise. Rien ne recollait
    // les morceaux.

    public function test_l_operation_met_le_deversement_en_file_au_lieu_d_appeler_en_direct(): void
    {
        Queue::fake();

        $ecriture = $this->ecrire();

        Queue::assertPushed(DeverserOperationComptaflow::class, function ($job) use ($ecriture) {
            return $job->operationId === $ecriture->operation_id;
        });

        // La file étant simulée, le Job ne s'exécute pas : rien ne part
        // encore sur le réseau à cet instant.
        Http::assertNothingSent();
    }

    public function test_une_operation_desequilibree_ne_part_pas(): void
    {
        Queue::fake();

        // Un débit sans son crédit. La déverser porterait le déséquilibre
        // chez Comptaflow, où il serait invisible jusqu'à la révision.
        $operation = Operation::creer(
            $this->entreprise->id, $this->site->id, '2026-03-10',
            'test', 'VTE', 'FAC-002', 'Vente bancale'
        );

        EcritureComptable::create([
            'operation_id'       => $operation->id,
            'entreprise_id'      => $this->entreprise->id,
            'point_de_vente_id'  => $this->site->id,
            'date_ecriture'      => '2026-03-10',
            'libelle'            => 'Vente bancale',
            'reference_document' => 'FAC-002',
            'code_journal'       => 'VTE',
            'compte_debit'       => '411000',
            'debit'              => 100000,
            'credit'             => 0,
        ]);

        $operation->cloturerEquilibre();

        Queue::assertNotPushed(DeverserOperationComptaflow::class);
    }

    public function test_une_ligne_seule_ne_declenche_rien(): void
    {
        Queue::fake();

        // Le déversement ne part plus à la création d'une ligne : à cet
        // instant, les autres lignes de l'opération n'existent pas encore, et
        // la transaction qui les écrit n'est pas refermée.
        $operation = Operation::creer(
            $this->entreprise->id, $this->site->id, '2026-03-10',
            'test', 'VTE', 'FAC-003', 'Une ligne'
        );

        EcritureComptable::create([
            'operation_id'       => $operation->id,
            'entreprise_id'      => $this->entreprise->id,
            'point_de_vente_id'  => $this->site->id,
            'date_ecriture'      => '2026-03-10',
            'libelle'            => 'Une ligne',
            'reference_document' => 'FAC-003',
            'code_journal'       => 'VTE',
            'compte_debit'       => '411000',
            'debit'              => 100000,
            'credit'             => 0,
        ]);

        Queue::assertNotPushed(DeverserOperationComptaflow::class);
    }

    public function test_l_operation_part_d_un_bloc_et_se_dit_atomique(): void
    {
        $this->ecrire();

        $corps = [];

        Http::recorded(function ($requete) use (&$corps) {
            if (isset($requete->data()['ecritures'])) {
                $corps = $requete->data();
            }

            return true;
        });

        // Les deux lignes dans un seul appel, et le drapeau qui dit à
        // Comptaflow de tout refuser plutôt que d'en garder la moitié.
        $this->assertCount(2, $corps['ecritures']);
        $this->assertTrue($corps['atomique']);
    }

    public function test_une_entreprise_non_liee_ne_met_rien_en_file(): void
    {
        Queue::fake();

        $this->entreprise->update(['comptaflow_sync_status' => 'inactive']);

        $this->ecrire();

        Queue::assertNotPushed(DeverserOperationComptaflow::class);
    }
}
