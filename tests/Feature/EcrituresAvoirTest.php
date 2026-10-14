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
 * « L'avoir, c'est l'écriture d'origine en négatif » — réunion d'octobre 2026.
 *
 * Vérifié de bout en bout, par les écrans : une facture comptant, puis son
 * avoir. Une vente annulée doit laisser chaque compte comme avant elle,
 * à une exception près, voulue : le timbre de quittance, qui frappe la
 * quittance délivrée et que la plateforme ne porte sur aucun avoir.
 *
 * Trois défauts vivaient là :
 *  - l'avoir ne recréditait que le TTC : les taxes parafiscales restaient au
 *    débit du client et au crédit de l'État ;
 *  - le remboursement en espèces ne s'écrivait qu'au journal de trésorerie :
 *    la caisse comptable ne bougeait pas, le client restait créditeur ;
 *  - la marchandise revenait au coût moyen du jour, et non au coût auquel
 *    elle était sortie : le coût des ventes ne se soldait pas.
 */
class EcrituresAvoirTest extends TestCase
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

    public function test_l_avoir_total_remet_chaque_compte_a_zero_sauf_le_timbre(): void
    {
        $stockAvant = $this->soldeCommencantPar('31');
        $coutAvant = $this->soldeCommencantPar('603');
        $facture = $this->facture();
        $timbre = $facture->timbre_quittance;

        $this->post(route('admin.ventes.avoir', $facture), ['raison' => 'Erreur de livraison'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->solde('411000'), 'Le client ne doit plus rien, et on ne lui doit plus rien.');
        $this->assertSame(0.0, $this->soldeCommencantPar('70'), 'Le chiffre d\'affaires de la vente annulée disparaît.');
        $this->assertSame(0.0, $this->soldeCommencantPar('443'), 'La TVA collectée est annulée.');
        $this->assertSame(0.0, $this->solde('447000'), 'Les taxes parafiscales sont annulées.');
        $this->assertSame($stockAvant, $this->soldeCommencantPar('31'), 'Le stock revient à sa valeur d\'avant la vente.');
        $this->assertSame($coutAvant, $this->soldeCommencantPar('603'), 'Le coût des ventes se solde.');

        // Le timbre reste dû à l'État ; la caisse garde exactement ce montant.
        $this->assertSame(-round($timbre, 2), $this->solde('447800'));
        $this->assertSame(round($timbre, 2), $this->solde('571000'));
    }

    public function test_l_avoir_partiel_contre_passe_a_proportion(): void
    {
        $coutAvant = $this->soldeCommencantPar('603');
        $facture = $this->facture();
        $ligne = $facture->details()->firstOrFail();

        $this->post(route('admin.ventes.avoir.creer_nouveau'), [
            'parent_id' => $facture->uuid, 'raison' => 'Un sac abîmé',
            'items' => [$ligne->id => ['quantite' => 1, 'prix_unitaire' => 20000, 'stock_action' => 'reinject']],
        ])->assertSessionHasNoErrors();

        $avoir = Vente::where('type_facture', 'avoir')->firstOrFail();
        $this->assertEqualsWithDelta(23600, (float) $avoir->montant_ttc, 0.01);

        // La moitié de la vente reste : un sac, sa TVA, la moitié de la taxe.
        $this->assertSame(-20000.0, $this->soldeCommencantPar('70'));
        $this->assertSame(-3600.0, $this->soldeCommencantPar('443'));
        $this->assertSame(-236.0, $this->solde('447000'));
        $this->assertSame(0.0, $this->solde('411000'));
        $this->assertSame($coutAvant + 12000.0, $this->soldeCommencantPar('603'), 'Un sac reste vendu, à son coût.');
    }

    public function test_le_remboursement_s_ecrit_et_se_lettre_avec_l_avoir(): void
    {
        $facture = $this->facture();
        $this->post(route('admin.ventes.avoir', $facture), ['raison' => 'Annulation']);

        $avoir = Vente::where('type_facture', 'avoir')->firstOrFail();
        $remboursement = EcritureComptable::where('reference_document', $avoir->numero_facture)
            ->where('compte_credit', '571000')->first();

        $this->assertNotNull($remboursement, 'L\'argent rendu doit sortir de la caisse comptable.');
        $this->assertEqualsWithDelta(47672, (float) $remboursement->credit, 0.01, 'TTC 47 200 + taxe 472.');
    }

    public function test_le_retour_entre_au_cout_de_sa_sortie_et_non_au_cout_du_jour(): void
    {
        $facture = $this->facture();
        $coutApresVente = $this->soldeCommencantPar('603');

        // Un arrivage plus cher fait monter le coût moyen après la vente.
        StockService::entree($this->riz, $this->site->id, 8, MouvementStock::RECEPTION, ['cout_unitaire' => 15000]);

        $this->post(route('admin.ventes.avoir', $facture), ['raison' => 'Annulation']);

        $retour = MouvementStock::where('sous_type', MouvementStock::RETOUR_CLIENT)->firstOrFail();
        $this->assertEqualsWithDelta(12000, (float) $retour->cout_unitaire, 0.01);

        // Entre la vente et l'avoir, seul l'arrivage (8 × 15 000) a bougé le
        // 603 ; l'avoir retire exactement les 24 000 de la vente.
        $this->assertSame($coutApresVente - 120000.0 - 24000.0, $this->soldeCommencantPar('603'));
    }
}
