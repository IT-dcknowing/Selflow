<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\SoldeInitial;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\SoldeClientService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Le solde du client sur sa facture (26/10/2026) : une option de
 * l'entreprise. Allumée, la facture d'un client connu qui a des restes à
 * payer porte le solde antérieur, le reste de la facture et le solde cumulé.
 */
class SoldeClientSurFactureTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Client $client;
    private Produit $riz;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->entreprise = Entreprise::create([
            'nom' => 'Épicerie du marché', 'regime_imposition' => 'RNI', 'adresse' => 'Adjamé',
            'modules_actifs' => ['principal', 'ventes', 'produits', 'tiers'],
            'normalisation_auto_factures' => false, 'normalisation_auto_recus' => false,
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Comptoir', 'ville' => 'Abidjan', 'commune' => 'Adjamé']);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@marche.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Maquis Chez Tantie']);
        $this->riz = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV-RIZ', 'nom' => 'Préparation de riz', 'type' => 'service',
            'unite' => 'plat', 'prix_achat' => 0, 'prix_vente' => 10000, 'taux_tva' => 0,
        ]);
        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function facturerACredit(int $quantite): Vente
    {
        $this->post(route('admin.ventes.enregistrer'), [
            'client_id' => $this->client->id, 'mode_paiement' => 'Crédit', 'etape' => 'Facture',
            'articles' => [['produit_id' => $this->riz->id, 'quantite' => $quantite, 'unite' => 'plat']],
        ])->assertSessionHasNoErrors();

        return Vente::where('etape', 'Facture')->latest('id')->firstOrFail();
    }

    public function test_sans_l_option_rien_n_est_imprime(): void
    {
        $this->facturerACredit(2);
        $seconde = $this->facturerACredit(1);

        $this->assertNull(SoldeClientService::pourFacture($seconde));
        $this->get(route('admin.ventes.imprimer', $seconde))->assertOk()->assertSee('solde_client: null', false);
    }

    public function test_avec_l_option_la_facture_porte_le_solde_anterieur_et_cumule(): void
    {
        $this->entreprise->update(['afficher_solde_client_facture' => true]);
        SoldeInitial::create([
            'entreprise_id' => $this->entreprise->id, 'type' => SoldeInitial::CLIENT, 'tiers_id' => $this->client->id,
            'montant' => 5000, 'date_solde' => now()->subMonth()->toDateString(), 'reference' => 'SI-0001', 'libelle' => 'Solde de départ',
        ]);
        $premiere = $this->facturerACredit(2);
        $seconde = $this->facturerACredit(1);

        $resteSeconde = (float) $seconde->montant_ttc + (float) $seconde->montant_autres_taxes + (float) $seconde->timbre_quittance;
        $restePremiere = (float) $premiere->montant_ttc + (float) $premiere->montant_autres_taxes + (float) $premiere->timbre_quittance;

        $solde = SoldeClientService::pourFacture($seconde->fresh());
        $this->assertEqualsWithDelta($restePremiere + 5000, $solde['anterieur'], 0.01);
        $this->assertEqualsWithDelta($resteSeconde, $solde['piece'], 0.01);
        $this->assertEqualsWithDelta($restePremiere + 5000 + $resteSeconde, $solde['cumule'], 0.01);

        $this->get(route('admin.ventes.imprimer', $seconde))->assertOk()
            ->assertSee('"cumule":', false)
            ->assertSee('function blocSoldeClient', false);

        $pdf = $this->get(route('admin.ventes.pdf', $seconde))->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_un_client_de_passage_n_a_pas_de_solde(): void
    {
        $this->entreprise->update(['afficher_solde_client_facture' => true]);
        $this->client->update(['nom' => 'Client de passage']);
        $this->facturerACredit(2);
        $this->assertNull(SoldeClientService::pourFacture($this->facturerACredit(1)));
    }

    public function test_l_option_se_coche_dans_les_parametres(): void
    {
        $this->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('Afficher le solde du client sur les factures');
    }
}
