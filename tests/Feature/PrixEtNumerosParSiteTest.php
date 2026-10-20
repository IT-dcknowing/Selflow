<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retour du 20/10/2026 :
 * - « quand je modifie les prix à Bondoukou, ça modifie Bongouanou aussi » ;
 * - chaque point de vente numérote ses pièces à partir de 1, sans croiser
 *   le numéro d'un autre site.
 */
class PrixEtNumerosParSiteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $bondoukou;
    private PointDeVente $bongouanou;
    private Produit $service;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Deux villes', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan', 'rccm' => 'CI-ABJ-2026-B-00111',
            'ncc' => '2601111A', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'produits', 'tiers', 'points_de_vente'],
            'normalisation_auto_factures' => false, 'normalisation_auto_recus' => false,
        ]);
        $this->bondoukou = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-Bondoukou', 'ville' => 'Bondoukou', 'commune' => 'Centre']);
        $this->bongouanou = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-Bongouanou', 'ville' => 'Bongouanou', 'commune' => 'Centre']);
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Client']);
        $this->service = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV', 'nom' => 'Livraison', 'type' => 'service',
            'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 1000, 'taux_tva' => 18,
        ]);
        $this->admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@deuxvilles.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
        ]);
    }

    private function sur(PointDeVente $site): self
    {
        return $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id, 'point_de_vente_actif_nom' => $site->nom]);
    }

    private function modifierLePrix(PointDeVente $site, float $prix, bool $tous = false)
    {
        return $this->sur($site)->put(route('admin.produits.modifier', $this->service), array_filter([
            'nom' => 'Livraison', 'type' => 'service', 'prix_vente' => $prix, 'taux_tva' => 18, 'unite' => 'u',
            'prix_tous_sites' => $tous ? 1 : null,
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors();
    }

    private function prixVu(PointDeVente $site): float
    {
        session(['point_de_vente_actif_id' => $site->id]);

        return (float) Produit::find($this->service->id)->prix_vente;
    }

    public function test_le_prix_modifie_a_bondoukou_ne_touche_pas_bongouanou(): void
    {
        $this->modifierLePrix($this->bondoukou, 1500);

        $this->assertSame(1500.0, $this->prixVu($this->bondoukou));
        $this->assertSame(1000.0, $this->prixVu($this->bongouanou));
        $this->assertSame(1000.0, (float) $this->service->fresh()->getRawOriginal('prix_vente'));
    }

    public function test_la_vente_prend_le_prix_du_site(): void
    {
        $this->modifierLePrix($this->bondoukou, 1500);

        $this->sur($this->bondoukou)->post(route('admin.ventes.enregistrer'), [
            'client_id' => Client::firstOrFail()->id, 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Espèces',
            'etape' => 'Facture', 'montant_paye' => 5000,
            'articles' => [['produit_id' => $this->service->id, 'quantite' => 1, 'unite' => 'u']],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(1500, (float) Vente::latest('id')->firstOrFail()->details()->first()->prix_unitaire, 0.01);
    }

    public function test_appliquer_a_tous_les_points_de_vente(): void
    {
        $this->modifierLePrix($this->bondoukou, 1500);
        $this->modifierLePrix($this->bongouanou, 2000, true);

        $this->assertSame(2000.0, $this->prixVu($this->bondoukou));
        $this->assertSame(2000.0, $this->prixVu($this->bongouanou));
    }

    public function test_chaque_site_numerote_a_partir_de_1_sans_se_croiser(): void
    {
        $vendre = function (PointDeVente $site) {
            $this->sur($site)->post(route('admin.ventes.enregistrer'), [
                'client_id' => Client::firstOrFail()->id, 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Espèces',
                'etape' => 'Facture', 'montant_paye' => 5000,
                'articles' => [['produit_id' => $this->service->id, 'quantite' => 1, 'prix_unitaire' => 1000, 'unite' => 'u']],
            ])->assertSessionHasNoErrors();

            return Vente::latest('id')->firstOrFail()->numero_facture;
        };

        $jour = now()->format('dmy');
        $this->assertSame("VTE-01-{$jour}-001", $vendre($this->bondoukou));
        $this->assertSame("VTE-02-{$jour}-001", $vendre($this->bongouanou));
        $this->assertSame("VTE-01-{$jour}-002", $vendre($this->bondoukou));
        $this->assertSame("VTE-02-{$jour}-002", $vendre($this->bongouanou));
    }

    public function test_une_entreprise_d_un_seul_site_garde_ses_numeros(): void
    {
        $seule = Entreprise::create(['nom' => 'Seule']);
        PointDeVente::create(['entreprise_id' => $seule->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Plateau']);

        $this->assertSame('VTE-' . now()->format('dmy') . '-001',
            \App\Modules\Admin\Services\NumerotationService::genererNumeroVente($seule->id, 'Facture'));
    }
}
