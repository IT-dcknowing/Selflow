<?php

namespace Tests\Feature;

use App\Jobs\DeverserOperationComptaflow;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Les défauts trouvés par la session parallèle du 07/10/2026 (branche
 * `claude/tender-keller-o95s21`, IT-dcknowing/Selflow), que la branche des
 * lots 41 à 52 ne couvrait pas — reportés au lot 55.
 */
class CorrectifsReportesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom' => 'Librairie du lycée', 'regime_imposition' => 'RNI', 'adresse' => 'Bouaké',
            'rccm' => 'CI-BKE-2026-B-00002', 'ncc' => '2601235B', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite', 'points_de_vente'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Librairie', 'ville' => 'Bouaké', 'commune' => 'Bouaké']);
        $this->admin = Utilisateur::create([
            'nom' => 'Koffi', 'prenom' => 'Ama', 'email' => 'ama-reports@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    /** Un avoir du mois dernier a bien rendu ce qu'il a rendu, quelle que soit la période affichée. */
    public function test_un_avoir_d_une_autre_periode_compte_dans_le_plafond(): void
    {
        $client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Lycée moderne']);
        $facture = Vente::create([
            'point_de_vente_id' => $this->site->id, 'client_id' => $client->id, 'utilisateur_id' => $this->admin->id,
            'numero_facture' => 'VTE-2026-0001', 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Crédit',
            'montant_ht' => 100000, 'montant_tva' => 18000, 'montant_ttc' => 118000, 'statut' => 'Non payé', 'etape' => 'Facture',
        ]);
        Vente::create([
            'point_de_vente_id' => $this->site->id, 'client_id' => $client->id, 'utilisateur_id' => $this->admin->id,
            'numero_facture' => 'AV-2025-0001', 'date_vente' => now()->subYear()->toDateString(), 'mode_paiement' => 'Crédit',
            'montant_ht' => 60000, 'montant_tva' => 10800, 'montant_ttc' => 70800, 'statut' => 'Payé', 'etape' => 'Facture',
            'type_facture' => 'avoir', 'parent_id' => $facture->id,
        ]);

        session(['active_periode_debut' => now()->startOfMonth()->toDateString(), 'active_periode_fin' => now()->endOfMonth()->toDateString()]);

        $this->assertEqualsWithDelta(47200, $facture->fresh()->resteAAvoirer(), 0.01);
    }

    /** L'API, le B2B et le tiers d'un BAPA créaient des fiches sans numéro ni collectif. */
    public function test_une_fiche_creee_hors_de_l_ecran_recoit_son_numero(): void
    {
        $client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Venu par l\'API']);
        $fournisseur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Vendeur BAPA']);
        $comptaflow = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Venu de Comptaflow', 'numero_tiers' => '41KON7']);

        $this->assertSame('411000', $client->compte_comptable);
        $this->assertSame('401000', $fournisseur->compte_comptable);
        $this->assertMatchesRegularExpression('/^41\w{4}$/', (string) $client->numero_tiers);
        $this->assertMatchesRegularExpression('/^40\w{4}$/', (string) $fournisseur->numero_tiers);
        $this->assertSame('41KON7', $comptaflow->numero_tiers, 'Un numéro fourni n\'est pas remplacé.');
    }

    public function test_les_immobilisations_n_existent_pas_comptabilite_fermee(): void
    {
        $this->get(route('admin.immobilisations.index'))->assertNotFound();
    }

    /** Comptaflow ne lit qu'un compte par ligne : une ligne à deux comptes en perdrait un. */
    public function test_une_ligne_a_deux_comptes_ne_part_pas_chez_comptaflow(): void
    {
        Http::fake();
        $this->entreprise->forceFill(['comptaflow_sync_status' => 'active', 'comptaflow_sync_key' => 'cle-de-liaison'])->save();

        $operation = Operation::creer($this->entreprise->id, $this->site->id, '2026-03-10', 'test', 'VTE', 'FAC-002', 'Vente');
        foreach ([
            ['compte_debit' => '411000', 'compte_credit' => '701000', 'debit' => 1000, 'credit' => 0],
            ['compte_credit' => '701000', 'debit' => 0, 'credit' => 1000],
        ] as $ligne) {
            EcritureComptable::create($ligne + [
                'operation_id' => $operation->id, 'entreprise_id' => $this->entreprise->id,
                'point_de_vente_id' => $this->site->id, 'date_ecriture' => '2026-03-10',
                'libelle' => 'Vente', 'reference_document' => 'FAC-002', 'code_journal' => 'VTE',
            ]);
        }
        $operation->cloturerEquilibre();

        (new DeverserOperationComptaflow($operation->id))->handle();

        Http::assertNothingSent();
        $this->assertSame('ligne 1 : montant du mauvais côté', DeverserOperationComptaflow::anomalie(collect([
            (new EcritureComptable())->forceFill(['id' => 1, 'compte_debit' => '411000', 'debit' => 0, 'credit' => 5]),
        ])));
    }

    /** Le prix de consignation et le délai de retour ne se saisissaient nulle part. */
    public function test_la_consignation_se_saisit_sur_la_fiche(): void
    {
        $casier = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'EMB-01', 'nom' => 'Casier',
            'type' => 'marchandise', 'prix_achat' => 1, 'prix_vente' => 2,
        ]);

        $this->get(route('admin.produits.fiche', $casier))->assertOk()->assertSee('name="prix_consignation"', false);

        $this->put(route('admin.produits.modifier', $casier), [
            'nom' => 'Casier', 'type' => 'marchandise', 'prix_achat' => 1, 'prix_vente' => 2,
            'taux_tva' => 18, 'stock_minimum' => 0, 'prix_consignation' => 2000, 'delai_retour_jours' => 21,
        ])->assertSessionHasNoErrors();

        $this->assertSame(2000.0, (float) $casier->fresh()->prix_consignation);
        $this->assertSame(21, $casier->fresh()->delai_retour_jours);
    }

    public function test_la_visite_ouvre_la_barre_d_un_telephone(): void
    {
        $this->get(route('admin.tableau_de_bord'))
            ->assertOk()
            ->assertSee("document.body.classList.add('sidebar-open')", false);
    }
}
