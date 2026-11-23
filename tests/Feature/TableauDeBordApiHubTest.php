<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Vente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le tableau de bord que le FlowHub lit annonce les montants de Selflow.
 *
 * Repris le 23/11/2026 du serveur de production, où il avait été modifié sans
 * passer par le dépôt. L'ancienne version comptait les devis dans le chiffre
 * d'affaires, additionnait les avoirs et affichait toutes les factures
 * impayées faute d'un statut « Payée » qui n'existe pas.
 */
class TableauDeBordApiHubTest extends TestCase
{
    use RefreshDatabase;

    private const JETON = 'jeton-du-hub-pour-les-epreuves';

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.hub.token' => self::JETON]);

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-01111', 'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats'],
        ]);
        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'SIEGE', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);
        $this->client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Client X']);
    }

    private function vente(string $numero, float $ht, array $enPlus = []): Vente
    {
        return Vente::create(array_merge([
            'point_de_vente_id' => $this->site->id,
            'client_id'         => $this->client->id,
            'numero_facture'    => $numero,
            'date_vente'        => '2026-03-10',
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => $ht,
            'montant_tva'       => round($ht * 0.18, 2),
            'montant_ttc'       => round($ht * 1.18, 2),
            'etape'             => 'Facture',
            'statut'            => 'Payé',
        ], $enPlus));
    }

    private function kpis(array $requete = [])
    {
        return $this->getJson('/api/dashboard/kpis?' . http_build_query(array_merge([
            'company_id'  => $this->entreprise->id,
            'exercice_id' => 2026,
        ], $requete)), ['X-Hub-Token' => self::JETON]);
    }

    public function test_le_chiffre_d_affaires_ignore_les_devis_et_deduit_les_avoirs(): void
    {
        $this->vente('VTE-001', 100000);
        $this->vente('DV-001', 500000, ['etape' => 'Devis']);
        $this->vente('AVO-001', 20000, ['type_facture' => 'avoir']);

        $reponse = $this->kpis()->assertOk();
        $this->assertSame([], $reponse->json('avertissements'), 'Aucun bloc ne doit échouer sur une base à jour.');
        $donnees = $reponse->json('data');

        $this->assertEqualsWithDelta(80000, $donnees['activite']['ventes_ht'], 0.01);
        $this->assertEqualsWithDelta(94400, $donnees['total_ventes'], 0.01, "L'ancienne clé lue par le Hub suit le même calcul.");
        $this->assertSame(1, $donnees['activite']['nb_ventes'], "Ni le devis ni l'avoir ne sont des ventes.");
    }

    public function test_un_mois_choisi_borne_la_periode(): void
    {
        $this->vente('VTE-001', 100000);
        $this->vente('VTE-002', 50000, ['date_vente' => '2026-04-02']);

        $donnees = $this->kpis(['month' => 3])->assertOk()->json('data');

        $this->assertSame(['debut' => '2026-03-01', 'fin' => '2026-03-31'], $donnees['periode']);
        $this->assertEqualsWithDelta(100000, $donnees['activite']['ventes_ht'], 0.01);
        // La courbe couvre l'exercice entier.
        $this->assertCount(12, $donnees['chart_ventes']['data']);
    }

    public function test_une_facture_reglee_n_est_pas_affichee_impayee(): void
    {
        $this->vente('VTE-001', 100000);

        $dernieres = $this->kpis()->assertOk()->json('data.dernieres_factures');

        $this->assertSame('payee', $dernieres[0]['statut']);
    }

    public function test_les_exercices_remontent_a_la_premiere_vente(): void
    {
        $this->vente('VTE-OLD', 1000, ['date_vente' => (date('Y') - 3) . '-06-01']);

        $annees = collect($this->getJson('/api/dashboard/exercices?company_id=' . $this->entreprise->id, ['X-Hub-Token' => self::JETON])
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame((string) date('Y'), $annees[0]);
        $this->assertContains((string) (date('Y') - 3), $annees);
    }

    public function test_sans_jeton_du_hub_rien_ne_sort(): void
    {
        $this->getJson('/api/dashboard/kpis?company_id=' . $this->entreprise->id)->assertStatus(401);
    }
}
