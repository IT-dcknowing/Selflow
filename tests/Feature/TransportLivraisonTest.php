<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Le transport d'une livraison (chantier 15.3 du plan).
 *
 * Le bon ne portait que des quantités. Tranché par le propriétaire le
 * 07/10/2026 : destination, livreur, véhicule, heures de départ et
 * d'arrivée, réceptionnaire — tout est obligatoire, et le bon imprimé le
 * porte.
 */
class TransportLivraisonTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Utilisateur $chauffeur;
    private Produit $ciment;
    private Vente $commande;

    /** Une signature tracée : un PNG d'un pixel suffit à la règle. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom' => 'Quincaillerie du port', 'regime_imposition' => 'RNI', 'adresse' => 'San-Pédro',
            'rccm' => 'CI-SP-2026-B-00101', 'ncc' => '2601239F', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'stock', 'produits', 'tiers', 'points_de_vente'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Dépôt', 'ville' => 'San-Pédro', 'commune' => 'Port']);
        $this->admin = Utilisateur::create([
            'nom' => 'Koné', 'prenom' => 'Ali', 'email' => 'ali-bl@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->chauffeur = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Paul', 'email' => 'paul-bl@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'caissier',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);

        $this->ciment = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'CIM-001', 'nom' => 'Ciment',
            'type' => 'marchandise', 'unite' => 'sac', 'prix_achat' => 5000, 'prix_vente' => 6500,
        ]);
        Stock::create(['produit_id' => $this->ciment->id, 'point_de_vente_id' => $this->site->id, 'quantite_disponible' => 100]);

        $client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Chantier Bardot', 'adresse' => 'Bardot, San-Pédro']);
        $this->commande = Vente::create([
            'point_de_vente_id' => $this->site->id, 'client_id' => $client->id, 'utilisateur_id' => $this->admin->id,
            'numero_facture' => 'BC-2026-0001', 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Crédit',
            'montant_ht' => 65000, 'montant_tva' => 0, 'montant_ttc' => 65000, 'statut' => 'En attente', 'etape' => 'Bon de commande',
        ]);
        VenteDetail::create([
            'vente_id' => $this->commande->id, 'produit_id' => $this->ciment->id, 'quantite' => 20,
            'unite' => 'sac', 'prix_unitaire' => 3250, 'montant_tva' => 0, 'montant_ttc' => 65000,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function depart(array $champs = []): array
    {
        return array_merge([
            'adresse_livraison' => 'Bardot, face au marché', 'livreur_type' => 'personnel',
            'livreur_utilisateur_id' => $this->chauffeur->id, 'vehicule' => '1234 AB 01',
            'heure_depart' => '2026-10-07 08:30',
        ], $champs);
    }

    private function detail(): VenteDetail
    {
        return $this->commande->details()->first();
    }

    // ── Au départ ────────────────────────────────────────────────────

    public function test_la_file_du_stock_refuse_une_expedition_sans_transport(): void
    {
        $this->post(route('admin.stock.livraisons.valider', $this->commande), [
            'livraison' => [$this->detail()->id => 5],
        ])->assertSessionHasErrors(['adresse_livraison', 'livreur_type', 'vehicule', 'heure_depart']);

        $this->assertSame(0, MouvementStock::count(), 'Refusée, l\'expédition ne sort rien du stock.');
        $this->assertSame(0, BonLivraison::count());
    }

    public function test_la_file_du_stock_etablit_un_bon_qui_porte_le_transport(): void
    {
        $this->post(route('admin.stock.livraisons.valider', $this->commande), $this->depart([
            'livraison' => [$this->detail()->id => 12.5],
        ]))->assertSessionHasNoErrors();

        $bl = BonLivraison::with('details')->sole();
        $this->assertSame('Bardot, face au marché', $bl->adresse_livraison);
        $this->assertSame('Paul Yao', $bl->livreur_nom, 'Le nom est figé sur le bon.');
        $this->assertSame('1234 AB 01', $bl->vehicule);
        $this->assertSame('2026-10-07 08:30', $bl->heure_depart->format('Y-m-d H:i'));
        $this->assertEqualsWithDelta(12.5, (float) $bl->details->first()->qte_livree, 0.0001, '12,5 sacs ne deviennent pas 12.');
    }

    public function test_un_livreur_d_une_autre_entreprise_est_refuse(): void
    {
        $autre = Entreprise::create(['nom' => 'Concurrent']);
        $etranger = Utilisateur::create([
            'nom' => 'X', 'prenom' => 'Y', 'email' => 'x-bl@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $autre->id,
        ]);

        $this->post(route('admin.stock.livraisons.valider', $this->commande), $this->depart([
            'livraison' => [$this->detail()->id => 5], 'livreur_utilisateur_id' => $etranger->id,
        ]))->assertSessionHasErrors('livreur_utilisateur_id');
    }

    public function test_un_prestataire_se_nomme(): void
    {
        $this->post(route('admin.stock.livraisons.valider', $this->commande), $this->depart([
            'livraison' => [$this->detail()->id => 5], 'livreur_type' => 'prestataire', 'livreur_utilisateur_id' => null,
        ]))->assertSessionHasErrors('livreur_nom');

        $this->post(route('admin.stock.livraisons.valider', $this->commande), $this->depart([
            'livraison' => [$this->detail()->id => 5], 'livreur_type' => 'prestataire',
            'livreur_utilisateur_id' => null, 'livreur_nom' => 'Transports Bakayoko',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Transports Bakayoko', BonLivraison::sole()->livreur_nom);
    }

    public function test_le_bon_des_ventes_exige_aussi_le_transport(): void
    {
        $lignes = [['produit_id' => $this->ciment->id, 'qte_commandee' => 20, 'qte_livree' => 20, 'libelle' => 'Ciment', 'unite' => 'sac']];

        $this->post(route('admin.ventes.livraison.enregistrer', $this->commande), [
            'date_livraison' => now()->toDateString(), 'lignes' => $lignes,
        ])->assertSessionHasErrors(['adresse_livraison', 'vehicule', 'heure_depart']);

        $this->post(route('admin.ventes.livraison.enregistrer', $this->commande), $this->depart([
            'date_livraison' => now()->toDateString(), 'lignes' => $lignes,
        ]))->assertSessionHasNoErrors();

        $this->assertSame('1234 AB 01', BonLivraison::sole()->vehicule);
    }

    // ── À l'arrivée ──────────────────────────────────────────────────

    private function bonExpedie(): BonLivraison
    {
        $this->post(route('admin.stock.livraisons.valider', $this->commande), $this->depart([
            'livraison' => [$this->detail()->id => 20],
        ]))->assertSessionHasNoErrors();

        return BonLivraison::sole();
    }

    public function test_marquer_livre_exige_le_receptionnaire_et_sa_signature(): void
    {
        $bl = $this->bonExpedie();

        $this->post(route('admin.ventes.livraison.livrer', $bl), [])
            ->assertSessionHasErrors(['heure_arrivee', 'receptionnaire_nom', 'receptionnaire_signature', 'observations']);
        $this->assertNotSame('livre', $bl->fresh()->statut);

        $this->post(route('admin.ventes.livraison.livrer', $bl), [
            'heure_arrivee' => '2026-10-07 10:05', 'receptionnaire_nom' => 'Aminata Diallo',
            'receptionnaire_signature' => self::SIGNATURE, 'observations' => 'RAS',
        ])->assertSessionHasNoErrors();

        $bl->refresh();
        $this->assertSame('livre', $bl->statut);
        $this->assertSame('Aminata Diallo', $bl->receptionnaire_nom);
        $this->assertSame(self::SIGNATURE, $bl->receptionnaire_signature);
    }

    public function test_l_arrivee_ne_precede_pas_le_depart(): void
    {
        $bl = $this->bonExpedie();

        $this->post(route('admin.ventes.livraison.livrer', $bl), [
            'heure_arrivee' => '2026-10-07 07:00', 'receptionnaire_nom' => 'Aminata Diallo',
            'receptionnaire_signature' => self::SIGNATURE, 'observations' => 'RAS',
        ])->assertSessionHasErrors('heure_arrivee');
    }

    public function test_une_signature_qui_n_est_pas_une_image_est_refusee(): void
    {
        $bl = $this->bonExpedie();

        $this->post(route('admin.ventes.livraison.livrer', $bl), [
            'heure_arrivee' => '2026-10-07 10:05', 'receptionnaire_nom' => 'Aminata Diallo',
            'receptionnaire_signature' => 'javascript:alert(1)', 'observations' => 'RAS',
        ])->assertSessionHasErrors('receptionnaire_signature');
    }

    public function test_le_bon_imprime_porte_le_transport(): void
    {
        $bl = $this->bonExpedie();

        $this->get(route('admin.ventes.livraison.voir', $bl))
            ->assertOk()
            ->assertSee('function blocTransport(d)', false)
            ->assertSee('1234 AB 01')
            ->assertSee('Paul Yao')
            ->assertSee('modalArriveeLivraison', false);
    }
}
