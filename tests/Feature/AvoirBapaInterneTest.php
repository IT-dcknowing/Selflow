<?php

namespace Tests\Feature;

use App\Jobs\NormaliserAchatBapaJob;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\AvoirBapa;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * L'avoir interne d'un BAPA (chantier 8.4 du plan).
 *
 * Confirmé par le propriétaire le 07/10/2026 : la DGI ne normalise toujours
 * pas l'avoir d'un bordereau d'achat. L'avoir est interne, non certifié, et
 * ne doit JAMAIS partir à la plateforme — d'où ses tables à part.
 */
class AvoirBapaInterneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Produit $cacao;
    private Achat $bapa;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom' => 'Coopérative de Soubré', 'regime_imposition' => 'RNI', 'adresse' => 'Soubré',
            'rccm' => 'CI-SB-2026-B-00007', 'ncc' => '2601240G', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'points_de_vente'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Magasin', 'ville' => 'Soubré', 'commune' => 'Soubré']);
        $this->admin = Utilisateur::create([
            'nom' => 'Gbagbo', 'prenom' => 'Marie', 'email' => 'marie-avb@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->cacao = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'CAC-01', 'nom' => 'Fèves de cacao',
            'type' => 'marchandise', 'unite' => 'kg', 'prix_achat' => 1000, 'prix_vente' => 1500,
        ]);
        Stock::create(['produit_id' => $this->cacao->id, 'point_de_vente_id' => $this->site->id, 'quantite_disponible' => 100]);

        $planteur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Kouamé Planteur']);
        $this->bapa = $this->achat($planteur, 'bapa', 'BA-2026-0001');

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function achat(Fournisseur $fournisseur, string $type, string $numero): Achat
    {
        $achat = Achat::create([
            'point_de_vente_id' => $this->site->id, 'fournisseur_id' => $fournisseur->id,
            'utilisateur_id' => $this->admin->id, 'numero_facture' => $numero, 'date_achat' => now()->toDateString(),
            'mode_paiement' => 'Espèces', 'montant_ht' => 100000, 'montant_tva' => 0, 'montant_ttc' => 100000,
            'statut' => 'Payé', 'etape' => 'Facture', 'type_facture' => $type,
        ]);
        AchatDetail::create([
            'achat_id' => $achat->id, 'produit_id' => $this->cacao->id, 'quantite' => 100, 'quantite_receptionnee' => 100,
            'unite' => 'kg', 'prix_unitaire' => 1000, 'montant_tva' => 0, 'montant_ttc' => 100000,
        ]);

        return $achat;
    }

    private function avoir(Achat $achat, float $quantite, bool $retour = true)
    {
        return $this->post(route('admin.achats.avoir_bapa.enregistrer', $achat), [
            'motif' => 'Refusé à la pesée',
            'lignes' => [$achat->details()->first()->id => ['quantite' => $quantite, 'retour_stock' => $retour ? '1' : '0']],
        ]);
    }

    public function test_l_avoir_corrige_la_comptabilite_et_le_stock(): void
    {
        $this->avoir($this->bapa, 30)->assertSessionHasNoErrors();

        $avoir = AvoirBapa::sole();
        $this->assertSame(30000.0, $avoir->montant_ttc);
        $this->assertSame(70.0, (float) Stock::where('produit_id', $this->cacao->id)->value('quantite_disponible'));

        $operation = Operation::where('reference_document', $avoir->numero)->sole();
        $this->assertTrue((bool) $operation->est_equilibree);
        $this->assertSame(30000.0, (float) EcritureComptable::where('operation_id', $operation->id)->sum('debit'));
    }

    public function test_l_avoir_ne_part_jamais_a_la_dgi(): void
    {
        $this->avoir($this->bapa, 30)->assertSessionHasNoErrors();

        $this->assertSame(1, Achat::withoutGlobalScopes()->count(), 'L\'avoir n\'est pas une ligne `achats`, que les chemins de la DGI lisent.');
        Queue::assertNotPushed(NormaliserAchatBapaJob::class);

        $this->get(route('admin.achats.avoir_bapa.voir', AvoirBapa::sole()))
            ->assertOk()->assertSee('NON CERTIFIÉ PAR LA DGI');
    }

    public function test_on_ne_rend_pas_plus_que_le_bordereau(): void
    {
        $this->avoir($this->bapa, 120)->assertSessionHasErrors('lignes');

        $this->avoir($this->bapa, 60)->assertSessionHasNoErrors();
        $this->avoir($this->bapa, 50)->assertSessionHasErrors('lignes');

        $this->assertSame(1, AvoirBapa::count());
    }

    public function test_un_achat_ordinaire_ne_s_avoire_pas(): void
    {
        $grossiste = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'SOCOCE', 'ncc' => '2169728N']);
        $ordinaire = $this->achat($grossiste, 'normale', 'AC-2026-0001');

        $this->get(route('admin.achats.avoir_bapa', $ordinaire))->assertRedirect(route('admin.achats.factures'));
        $this->avoir($ordinaire, 10)->assertSessionHasErrors('lignes');
        $this->assertSame(0, AvoirBapa::count());
    }

    public function test_l_avoir_d_une_autre_entreprise_n_existe_pas(): void
    {
        $this->avoir($this->bapa, 10)->assertSessionHasNoErrors();
        $avoir = AvoirBapa::sole();

        $autre = Entreprise::create([
            'nom' => 'Autre coopérative', 'regime_imposition' => 'RNI', 'adresse' => 'Daloa',
            'rccm' => 'CI-DL-2026-B-00008', 'ncc' => '2601241H', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'achats', 'points_de_vente'],
        ]);
        PointDeVente::create(['entreprise_id' => $autre->id, 'nom' => 'Daloa', 'ville' => 'Daloa', 'commune' => 'Daloa']);
        $intrus = Utilisateur::create([
            'nom' => 'X', 'prenom' => 'Y', 'email' => 'intrus-avb@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $autre->id,
        ]);

        $this->actingAs($intrus)->get(route('admin.achats.avoir_bapa.voir', $avoir))->assertNotFound();
    }
}
