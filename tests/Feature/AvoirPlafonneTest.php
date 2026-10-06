<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Un avoir ne peut pas dépasser la facture — section 8 du plan.
 *
 * Validé par le propriétaire le 02/10/2026 : rendre plus que ce qui a été
 * facturé ferait passer la TVA collectée de la pièce en négatif.
 */
class AvoirPlafonneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Vente $facture;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom' => 'Coopérative du Bandama', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody',
            'rccm' => 'CI-ABJ-2026-B-00001', 'ncc' => '2601234A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Magasin', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-avoir@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $produit = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SAC-1', 'nom' => 'Sac de riz',
            'type' => 'service', 'prix_achat' => 800, 'prix_vente' => 1000, 'taux_tva' => 18,
        ]);
        $client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Chocolaterie Baoulé']);

        // 10 × 1 000 F HT, 18 % : 11 800 F TTC.
        $this->facture = Vente::create([
            'point_de_vente_id' => $this->site->id, 'client_id' => $client->id,
            'numero_facture' => 'VTE-2026-0001', 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Crédit', 'statut' => 'Payé', 'etape' => 'Facture',
            'montant_ht' => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
        ]);
        VenteDetail::create([
            'vente_id' => $this->facture->id, 'produit_id' => $produit->id, 'quantite' => 10,
            'prix_unitaire' => 1000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function avoir(float $quantite, float $prix, array $ajouts = [])
    {
        $ligne = $this->facture->details()->first();

        return $this->post(route('admin.ventes.avoir.creer_nouveau'), [
            'parent_id' => $this->facture->uuid,
            'raison'    => 'Retour',
            'items'     => [$ligne->id => ['quantite' => $quantite, 'prix_unitaire' => $prix, 'stock_action' => 'none']] + $ajouts,
        ]);
    }

    private function avoirs(): int
    {
        return Vente::where('parent_id', $this->facture->id)->where('type_facture', 'avoir')->count();
    }

    // ══════════════ 8.1 — le plafond au serveur ══════════════

    public function test_un_prix_gonfle_dans_la_modale_est_refuse(): void
    {
        // Cinq unités, quantité permise — mais à 5 000 F au lieu de 1 000 :
        // 29 500 F TTC pour une facture de 11 800.
        $this->avoir(5, 5000)->assertSessionHasErrors('items');

        $this->assertSame(0, $this->avoirs());
    }

    public function test_une_ligne_ajoutee_ne_contourne_pas_le_plafond(): void
    {
        $this->avoir(1, 1000, ['nouveau-1' => [
            'est_nouveau' => 1, 'libelle_virtuel' => 'Geste commercial',
            'quantite' => 1, 'prix_unitaire' => 50000, 'taux_tva' => 18,
        ]])->assertSessionHasErrors('items');

        $this->assertSame(0, $this->avoirs());
    }

    public function test_un_avoir_dans_le_reste_passe_et_l_avoir_total_est_ensuite_refuse(): void
    {
        $this->avoir(4, 1000)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->avoirs());

        // L'avoir total reprendrait 11 800 F alors qu'il n'en reste que 7 080.
        $this->post(route('admin.ventes.avoir', $this->facture), ['raison' => 'Annulation'])
            ->assertSessionHasErrors('items');

        $this->assertSame(1, $this->avoirs());
        $this->assertEqualsWithDelta(7080.0, $this->facture->fresh()->resteAAvoirer(), 0.01);
    }

    // ══════════════ 8.2 et 8.3 — la liste ══════════════

    public function test_une_facture_entamee_annonce_son_reste(): void
    {
        $this->avoir(4, 1000);

        $liste = $this->getJson(route('admin.ventes.factures.rechercher'))->assertOk()->json();

        $this->assertCount(1, $liste);
        $this->assertStringContainsString('reste 7 080 F sur 11 800 F', $liste[0]['text']);
    }

    public function test_une_facture_entierement_avoiree_sort_de_la_liste(): void
    {
        $this->avoir(10, 1000)->assertSessionHasNoErrors();

        $this->assertSame([], $this->getJson(route('admin.ventes.factures.rechercher'))->assertOk()->json());

        $this->get(route('admin.ventes.factures', ['type' => 'avoir']))
            ->assertOk()
            ->assertDontSee('VTE-2026-0001 - Chocolaterie');
    }
}
