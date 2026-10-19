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
 * Retour du 19/10/2026 : les listes de factures vente et achat montrent le
 * vendu, l'annulé, le normalisé et le non normalisé, en nombre et en montant.
 */
class KpiDesFacturesTest extends TestCase
{
    use RefreshDatabase;

    private PointDeVente $site;
    private Produit $service;

    protected function setUp(): void
    {
        parent::setUp();

        $e = Entreprise::create([
            'nom' => 'Kpi SARL', 'regime_imposition' => 'RNI', 'adresse' => 'Plateau', 'rccm' => 'CI-ABJ-2026-B-00099',
            'ncc' => '2609999A', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'produits', 'tiers'],
            'normalisation_auto_factures' => false, 'normalisation_auto_recus' => false,
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        Client::create(['entreprise_id' => $e->id, 'nom' => 'Client']);
        $this->service = Produit::create([
            'entreprise_id' => $e->id, 'reference' => 'SRV-1', 'nom' => 'Prestation', 'type' => 'service',
            'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 10000, 'taux_tva' => 18,
        ]);
        $admin = Utilisateur::create([
            'nom' => 'A', 'prenom' => 'B', 'email' => 'admin@kpi.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $e->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function vendre(int $quantite): Vente
    {
        $this->post(route('admin.ventes.enregistrer'), [
            'client_id' => Client::firstOrFail()->id, 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Espèces', 'etape' => 'Facture', 'montant_paye' => 100000,
            'articles' => [['produit_id' => $this->service->id, 'quantite' => $quantite, 'prix_unitaire' => 10000, 'unite' => 'u']],
        ])->assertSessionHasNoErrors();

        return Vente::where(fn ($q) => $q->whereNull('type_facture')->orWhere('type_facture', '!=', 'avoir'))->latest('id')->firstOrFail();
    }

    public function test_les_kpi_des_ventes_separent_annule_et_non_normalise(): void
    {
        $this->vendre(1);                 // 11 800 TTC
        $annulee = $this->vendre(2);      // 23 600 TTC
        $this->post(route('admin.ventes.annuler', $annulee), ['motif' => 'Erreur'])->assertSessionHas('succes');

        $page = $this->get(route('admin.ventes.factures', ['etape' => 'Facture']))->assertOk();
        $html = $page->getContent();

        $this->assertStringContainsString('class="kpi-factures"', $html);
        // Ventes : la seule facture active ; Annulées : l'autre ; Non normalisées : l'active.
        $this->assertMatchesRegularExpression('/Ventes\s*<\/div>\s*<div[^>]*>11 800 F/u', $html);
        $this->assertMatchesRegularExpression('/Annulées\s*<\/div>\s*<div[^>]*>23 600 F/u', $html);
        $this->assertMatchesRegularExpression('/Normalisées\s*<\/div>\s*<div[^>]*>0 F/u', $html);
        $this->assertMatchesRegularExpression('/Non normalisées\s*<\/div>\s*<div[^>]*>11 800 F/u', $html);
    }

    public function test_pas_de_kpi_sur_les_devis_ni_les_avoirs(): void
    {
        $this->get(route('admin.ventes.factures', ['etape' => 'Devis']))->assertOk()->assertDontSee('class="kpi-factures"', false);
        $this->get(route('admin.ventes.factures', ['type' => 'avoir']))->assertOk()->assertDontSee('class="kpi-factures"', false);
    }

    public function test_les_kpi_des_achats_s_affichent(): void
    {
        $this->get(route('admin.achats.factures', ['etape' => 'Facture']))->assertOk()
            ->assertSee('class="kpi-factures"', false)->assertSee('Non normalisés')->assertSee('bordereaux en attente');
    }
}
