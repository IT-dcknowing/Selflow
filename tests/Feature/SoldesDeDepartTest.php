<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\SoldeInitial;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retour client du 19/10/2026 : saisir ce que les clients devaient et ce
 * qu'on devait aux fournisseurs avant Selflow, et le voir se solder.
 */
class SoldesDeDepartTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Client $client;
    private Fournisseur $fournisseur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Reprise SARL', 'comptabilite_activee' => true,
            'modules_actifs' => ['principal', 'ventes', 'achats', 'tiers', 'comptabilite', 'points_de_vente'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        foreach ([['CAI', 'Caisse', 'Trésorerie'], ['OD', 'Opérations diverses', 'OD']] as [$code, $intitule, $type]) {
            CodeJournal::create(['entreprise_id' => $this->entreprise->id, 'code' => $code, 'intitule' => $intitule, 'type' => $type]);
        }
        $admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@reprise.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Boutique Yao']);
        $this->fournisseur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Grossiste Koné']);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function saisir(string $type, int $tiersId, float $montant)
    {
        return $this->post(route('admin.comptabilite.soldes_initiaux.enregistrer'), [
            'type' => $type, 'tiers_id' => $tiersId, 'montant' => $montant, 'date_solde' => '2026-10-01',
        ]);
    }

    private function regler(string $type, int $tiersId, string $reference, float $montant)
    {
        return $this->post(route('admin.comptabilite.enregistrer_reglement'), [
            'type' => $type, 'tier_id' => $tiersId, 'numero_facture' => $reference,
            'montant' => $montant, 'mode_paiement' => 'Caisse', 'date_operation' => '2026-10-19',
        ]);
    }

    public function test_le_solde_client_se_saisit_et_se_regle_en_partie(): void
    {
        $this->saisir('client', $this->client->id, 150000)->assertSessionHas('succes');
        $solde = SoldeInitial::firstOrFail();
        $this->assertSame('SD-C-' . sprintf('%06d', $this->client->id) . '-P' . $this->site->id, $solde->reference);
        $this->assertSame($this->site->id, (int) $solde->point_de_vente_id);

        $this->regler('client', $this->client->id, $solde->reference, 60000)->assertSessionHas('succes');

        $this->assertEqualsWithDelta(60000, $solde->regle(), 0.01);
        $this->assertEqualsWithDelta(90000, $solde->reste(), 0.01);
        $this->assertSame(60000.0, (float) TresorerieJournal::where('reference_document', $solde->reference)->sum('montant_entree'));

        // L'écriture : la caisse au débit, le client au crédit, équilibrée.
        $lignes = EcritureComptable::where('reference_document', $solde->reference)->get();
        $this->assertCount(2, $lignes);
        $this->assertEqualsWithDelta($lignes->sum('debit'), $lignes->sum('credit'), 0.01);

        // La page : KPI initial / réglé / reste, et le solde dans le bouton de règlement.
        $page = $this->get(route('admin.comptabilite.creances'))->assertOk();
        $page->assertSee('Soldes de départ')->assertSee('150 000 F')->assertSee('60 000 F')->assertSee('90 000 F');
    }

    public function test_on_ne_regle_pas_plus_que_le_reste(): void
    {
        $this->saisir('client', $this->client->id, 10000);
        $solde = SoldeInitial::firstOrFail();

        $this->regler('client', $this->client->id, $solde->reference, 15000)->assertStatus(422);
        $this->assertSame(0.0, $solde->regle());
    }

    public function test_le_solde_fournisseur_se_regle_en_decaissement(): void
    {
        $this->saisir('fournisseur', $this->fournisseur->id, 80000);
        $solde = SoldeInitial::where('type', 'fournisseur')->firstOrFail();

        $this->regler('fournisseur', $this->fournisseur->id, $solde->reference, 80000)->assertSessionHas('succes');

        $this->assertSame(80000.0, (float) TresorerieJournal::where('reference_document', $solde->reference)->sum('montant_sortie'));
        $this->assertSame(0.0, $solde->reste());
    }

    public function test_corriger_ne_descend_pas_sous_le_regle_et_supprimer_seulement_sans_reglement(): void
    {
        $this->saisir('client', $this->client->id, 50000);
        $solde = SoldeInitial::firstOrFail();
        $this->regler('client', $this->client->id, $solde->reference, 20000);

        $this->saisir('client', $this->client->id, 10000)->assertSessionHas('erreur');
        $this->assertEqualsWithDelta(50000, (float) $solde->fresh()->montant, 0.01);

        $this->delete(route('admin.comptabilite.soldes_initiaux.supprimer', $solde))->assertSessionHas('erreur');
        $this->assertNotNull($solde->fresh());

        // Un seul solde par tiers : le corriger le remplace.
        $this->saisir('client', $this->client->id, 70000)->assertSessionHas('succes');
        $this->assertSame(1, SoldeInitial::count());
    }

    public function test_un_tiers_d_une_autre_entreprise_est_refuse(): void
    {
        $autre = Entreprise::create(['nom' => 'Autre']);
        $etranger = Client::create(['entreprise_id' => $autre->id, 'nom' => 'Étranger']);

        $this->saisir('client', $etranger->id, 1000)->assertNotFound();
        $this->assertSame(0, SoldeInitial::count());
    }
}
