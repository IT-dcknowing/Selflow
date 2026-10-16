<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Annuler une facture non normalisée (16/10/2026) : la marchandise revient en
 * stock comme si elle n'avait pas quitté l'entrepôt, les écritures sont
 * contre-passées (le timbre reste dû, décision du 14/10), l'espèce est rendue.
 * Une facture normalisée ne s'annule pas : elle passe par l'avoir.
 */
class AnnulationFactureTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Produit $riz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Axenova', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody, Abidjan',
            'rccm' => 'CI-ABJ-2026-B-00042', 'ncc' => '2601234A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'timbre_quittance' => true,
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite'],
        ]);
        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);
        foreach ([['VTE', 'Ventes', 'Vente'], ['CAI', 'Caisse', 'Trésorerie'], ['OD', 'Opérations diverses', 'OD']] as [$code, $intitule, $type]) {
            CodeJournal::create(['entreprise_id' => $this->entreprise->id, 'code' => $code, 'intitule' => $intitule, 'type' => $type]);
        }
        $admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@axenova.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Konan Yao']);

        $vivres = Categorie::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Vivres', 'prefixe' => 'VIV',
            'compte_vente' => '701000', 'compte_achat' => '601000',
            'compte_stock' => '311000', 'compte_variation' => '603100',
        ]);
        $this->riz = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'VIV-001', 'nom' => 'Riz sac 25 kg',
            'type' => 'marchandise', 'unite' => 'sac', 'prix_achat' => 12000, 'prix_vente' => 20000,
            'taux_tva' => 18, 'categorie_id' => $vivres->id,
        ]);
        StockService::entree($this->riz, $this->site->id, 10, MouvementStock::RECEPTION, ['cout_unitaire' => 12000]);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    /** Une facture comptant de deux sacs, avec une taxe parafiscale de 1 % sur le TTC. */
    private function facture(): Vente
    {
        // 2 × 20 000 = 40 000 HT ; TVA 7 200 ; TTC 47 200 ; taxe 472.
        $this->post(route('admin.ventes.enregistrer'), [
            'client_id' => Client::firstOrFail()->id, 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Espèces', 'etape' => 'Facture', 'montant_paye' => 60000,
            'articles' => [['produit_id' => $this->riz->id, 'quantite' => 2, 'prix_unitaire' => 20000, 'unite' => 'sac']],
            'taxes_ttc' => [['nom' => 'Taxe de développement touristique', 'taux' => 1]],
        ])->assertSessionHasNoErrors();

        $facture = Vente::where('type_facture', '!=', 'avoir')->orWhereNull('type_facture')->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(472, (float) $facture->montant_autres_taxes, 0.01);
        $this->assertGreaterThan(0, $facture->timbre_quittance, 'La facture comptant porte un timbre.');

        return $facture;
    }

    /** Solde débiteur d'un compte (débit − crédit), toutes écritures confondues. */
    private function solde(string $compte): float
    {
        $debit = EcritureComptable::where('entreprise_id', $this->entreprise->id)->where('compte_debit', $compte)->sum('debit');
        $credit = EcritureComptable::where('entreprise_id', $this->entreprise->id)->where('compte_credit', $compte)->sum('credit');

        return round((float) $debit - (float) $credit, 2);
    }

    private function soldeCommencantPar(string $racine): float
    {
        $debit = EcritureComptable::where('entreprise_id', $this->entreprise->id)->where('compte_debit', 'like', $racine . '%')->sum('debit');
        $credit = EcritureComptable::where('entreprise_id', $this->entreprise->id)->where('compte_credit', 'like', $racine . '%')->sum('credit');

        return round((float) $debit - (float) $credit, 2);
    }

    public function test_annuler_reinjecte_le_stock_et_contre_passe(): void
    {
        $stockAvant = (float) \App\Modules\Admin\Modeles\Stock::where('produit_id', $this->riz->id)->value('quantite_disponible');
        $facture = $this->facture();
        $timbre = (float) $facture->timbre_quittance;
        $this->assertSame($stockAvant - 2, (float) \App\Modules\Admin\Modeles\Stock::where('produit_id', $this->riz->id)->value('quantite_disponible'));

        $this->post(route('admin.ventes.annuler', $facture), ['motif' => 'Saisie en double'])
            ->assertSessionHasNoErrors()->assertSessionHas('succes');

        $this->assertSame(Vente::STATUT_ANNULEE, $facture->fresh()->statut);
        $this->assertSame($stockAvant, (float) \App\Modules\Admin\Modeles\Stock::where('produit_id', $this->riz->id)->value('quantite_disponible'),
            'La marchandise revient comme si elle n\'avait pas quitté l\'entrepôt.');
        $this->assertSame(0.0, $this->soldeCommencantPar('70'));
        $this->assertSame(0.0, $this->soldeCommencantPar('443'));
        $this->assertSame(0.0, $this->solde('411000'));
        $this->assertSame(round($timbre, 2), $this->solde('571000'), 'La caisse ne garde que le timbre.');

        $annulation = Vente::where('parent_id', $facture->id)->firstOrFail();
        $this->assertTrue($annulation->estAnnulationInterne());
        $this->assertStringContainsString('Annulation : Saisie en double', $annulation->raison_avoir);
    }

    public function test_une_facture_annulee_ne_se_normalise_ni_ne_s_annule_deux_fois(): void
    {
        $facture = $this->facture();
        $this->post(route('admin.ventes.annuler', $facture), ['motif' => 'Erreur']);

        $this->post(route('admin.ventes.normaliser', $facture))->assertSessionHas('erreur');
        $avoir = Vente::where('parent_id', $facture->id)->firstOrFail();
        $this->post(route('admin.ventes.normaliser', $avoir))->assertSessionHas('erreur');
        $this->post(route('admin.ventes.annuler', $facture->fresh()), ['motif' => 'Encore'])->assertSessionHas('erreur');
        $this->assertSame(1, Vente::where('parent_id', $facture->id)->count());
    }

    public function test_une_facture_normalisee_ne_s_annule_pas(): void
    {
        $facture = $this->facture();
        $facture->forceFill(['normalise' => true])->save();

        $page = $this->get(route('admin.ventes.factures'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('admin.ventes.annuler', $facture), $page, 'Pas de bouton Annuler sur une facture normalisée.');

        $this->post(route('admin.ventes.annuler', $facture), ['motif' => 'Erreur'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'avoir'));
        $this->assertSame(0, Vente::where('parent_id', $facture->id)->count());
    }

    public function test_la_liste_propose_annuler_sur_une_facture_non_normalisee(): void
    {
        $facture = $this->facture();
        $this->get(route('admin.ventes.factures'))->assertOk()
            ->assertSee(route('admin.ventes.annuler', $facture), false);

        $this->post(route('admin.ventes.annuler', $facture), ['motif' => 'Erreur']);
        $this->get(route('admin.ventes.factures'))->assertSee('Annulée');
    }

    public function test_le_motif_est_obligatoire(): void
    {
        $facture = $this->facture();
        $this->post(route('admin.ventes.annuler', $facture), [])->assertSessionHasErrors('motif');
        $this->assertNotSame(Vente::STATUT_ANNULEE, $facture->fresh()->statut);
    }
}
