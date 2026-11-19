<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Services\StockService;
use App\Modules\Admin\Services\InventairePeriodiqueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le stock en valeur : CUMP (Coût Unitaire Moyen Pondéré) et écritures.
 *
 * Deux manques comblés d'un coup :
 *
 * - **`produits.prix_achat` tenait lieu de coût** — un prix de catalogue, figé,
 *   saisi une fois. La marge affichée était donc fausse dès que le fournisseur
 *   changeait ses prix.
 * - **Aucun compte de classe 3 n'était mouvementé.** Le stock existait en
 *   quantité, pas en valeur : la balance n'en portait pas trace, et aucun bilan
 *   ne pouvait être établi.
 */
class ComptabilisationStockPeriodiqueTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $magasin;
    private Produit $riz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Boutique du carrefour']);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'OD', 'intitule' => 'Opérations diverses', 'type' => 'OD',
        ]);

        $vivres = Categorie::create([
            'entreprise_id'    => $this->entreprise->id,
            'nom'              => 'Vivres et alimentation',
            'prefixe'          => 'VIV',
            'compte_vente'     => '701000',
            'compte_achat'     => '601000',
            'compte_stock'     => '311000',
            'compte_variation' => '603100',
        ]);

        $this->riz = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'VIV-001',
            'nom' => 'Riz sac 25 kg', 'type' => 'marchandise', 'unite' => 'sac',
            'prix_achat' => 12000, 'prix_vente' => 15000,
            'categorie_id' => $vivres->id,
        ]);
    }

    private function cump(): float
    {
        return (float) Stock::where('produit_id', $this->riz->id)
            ->where('point_de_vente_id', $this->magasin->id)
            ->value('cump');
    }

    private function entree(float $quantite, ?float $cout = null): ?MouvementStock
    {
        return StockService::entree(
            $this->riz, $this->magasin->id, $quantite, MouvementStock::RECEPTION,
            $cout === null ? [] : ['cout_unitaire' => $cout]
        );
    }

    // ══════════════ Le CUMP (Coût Unitaire Moyen Pondéré) ══════════════

    public function test_la_premiere_entree_fixe_le_cout(): void
    {
        $this->entree(10, 12000);

        $this->assertSame(12000.0, $this->cump());
    }

    public function test_une_seconde_entree_pondere_les_deux_couts(): void
    {
        // C'est le cas que `prix_achat` ne savait pas traiter : le fournisseur
        // a change ses prix, et le stock melange les deux arrivages.
        //
        //   (10 × 12 000 + 10 × 15 000) ÷ 20 = 13 500
        $this->entree(10, 12000);
        $this->entree(10, 15000);

        $this->assertSame(13500.0, $this->cump());
    }

    public function test_une_pondération_qui_ne_tombe_pas_rond(): void
    {
        //   (10 × 12 000 + 5 × 15 000) ÷ 15 = 13 000
        $this->entree(10, 12000);
        $this->entree(5, 15000);

        $this->assertSame(13000.0, $this->cump());
    }

    public function test_une_sortie_consomme_le_cout_moyen_sans_le_deplacer(): void
    {
        // C'est la definition meme du procede, et ce qui le rend independant
        // de l'ordre des ventes.
        $this->entree(10, 12000);
        $this->entree(10, 15000);

        $sortie = StockService::sortie($this->riz, $this->magasin->id, 5, MouvementStock::LIVRAISON);

        $this->assertSame(13500.0, $this->cump(), 'Une sortie ne déplace pas le coût moyen.');
        $this->assertSame(13500.0, $sortie->cout_unitaire, 'La sortie est valorisée au coût moyen.');
    }

    public function test_une_entree_sans_cout_connu_reprend_le_cout_en_place(): void
    {
        // Un transfert, un retour client, un ecart d'inventaire en plus : la
        // marchandise n'a pas change de valeur en changeant de main.
        $this->entree(10, 12000);
        $this->entree(10);

        $this->assertSame(12000.0, $this->cump());
    }

    public function test_une_premiere_entree_sans_cout_retombe_sur_le_prix_d_achat(): void
    {
        // Faute de mieux — mais mieux que de valoriser a zero.
        $this->entree(10);

        $this->assertSame(12000.0, $this->cump());
    }

    public function test_le_cout_du_mouvement_est_fige_a_l_ecriture(): void
    {
        // Un journal dont les valeurs bougent n'est pas un journal : le cout
        // retenu au moment du mouvement doit rester lisible ensuite.
        $premier = $this->entree(10, 12000);
        $this->entree(10, 15000);

        $this->assertSame(12000.0, $premier->fresh()->cout_unitaire);
        $this->assertSame(12000.0, $premier->fresh()->cump_apres);
    }

    public function test_le_cump_est_par_site_et_non_par_article(): void
    {
        // Le meme sac peut arriver a des couts differents a Abidjan et a
        // Bouake, transport compris. Un cout global melangerait les deux.
        $depot = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Dépôt de Bouaké', 'ville' => 'Bouaké', 'commune' => 'Bouaké',
        ]);

        $this->entree(10, 12000);
        StockService::entree($this->riz, $depot->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 14000]);

        $this->assertSame(12000.0, $this->cump());
        $this->assertSame(14000.0, (float) Stock::where('produit_id', $this->riz->id)
            ->where('point_de_vente_id', $depot->id)->value('cump'));
    }

    public function test_la_valeur_du_stock_est_la_quantite_au_cout_moyen(): void
    {
        $this->entree(10, 12000);
        $this->entree(10, 15000);

        // 20 sacs × 13 500 = 270 000
        $this->assertSame(270000.0, StockService::valeurDuStock($this->riz, $this->magasin->id));
    }

    // ══════════════ La comptabilisation différée du stock ══════════════

    public function test_une_entree_de_stock_ne_genere_pas_d_ecriture_immediate(): void
    {
        // Le stock est actif par défaut, mais l'écriture attend l'échéance périodique.
        $this->entree(10, 12000);

        $this->assertSame(0, EcritureComptable::withoutGlobalScopes()->count());
    }

    public function test_la_configuration_par_defaut_active_le_stock_tous_les_trois_mois(): void
    {
        $this->assertTrue((bool) $this->entreprise->comptabilite_stock_active);
        $this->assertFalse((bool) $this->entreprise->comptabilite_stock_quotidienne);
        $this->assertSame(3, (int) $this->entreprise->comptabilite_stock_periode_mois);
        $this->assertNotNull($this->entreprise->comptabilite_stock_date_debut);
        $this->assertNull($this->entreprise->comptabilite_stock_date_fin);
    }

    public function test_une_periode_trimestrielle_sans_date_de_fin_est_comptabilisee(): void
    {
        $this->entreprise->update([
            'comptabilite_stock_active' => true,
            'comptabilite_stock_quotidienne' => false,
            'comptabilite_stock_periode_mois' => 3,
            'comptabilite_stock_date_debut' => '2026-01-01',
            'comptabilite_stock_date_fin' => null,
        ]);

        $this->travelTo(\\Illuminate\\Support\\Carbon::parse('2026-01-01 10:00'));
        $this->entree(10, 12000);
        $this->travelTo(\\Illuminate\\Support\\Carbon::parse('2026-04-01 00:10'));

        $bilan = InventairePeriodiqueService::traiterEntreprise($this->entreprise->fresh());

        $this->assertSame(1, $bilan['periodes']);
        $this->assertSame(1, $bilan['operations']);
        $this->assertSame('2026-03-31', \\Illuminate\\Support\\Facades\\DB::table('stock_comptabilites_periodiques')
            ->where('entreprise_id', $this->entreprise->id)->value('date_fin'));

        $this->travelBack();
    }

    public function test_une_sortie_de_stock_ne_genere_pas_d_ecriture_par_defaut(): void
    {
        $this->entree(10, 12000);
        StockService::sortie($this->riz, $this->magasin->id, 4, MouvementStock::LIVRAISON);

        $this->assertSame(0, EcritureComptable::withoutGlobalScopes()->count());
    }

    public function test_le_journal_de_stock_reste_independant_de_la_comptabilisation(): void
    {
        $this->entree(10, 12000);
        StockService::sortie($this->riz, $this->magasin->id, 2, MouvementStock::REBUT);

        $this->assertSame(0, EcritureComptable::withoutGlobalScopes()->count());
    }

    public function test_un_article_sans_compte_de_stock_n_ecrit_rien(): void
    {
        // Ecrire le stock sans la variation desequilibrerait la balance, et le
        // desequilibre n'apparaitrait que des semaines plus tard.
        $this->riz->categorieRelation->update(['compte_variation' => null]);

        $this->entree(10, 12000);

        $this->assertSame(0, EcritureComptable::count());
    }

    public function test_un_service_n_ecrit_rien(): void
    {
        $mission = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'PRESTA-001',
            'nom' => 'Livraison', 'type' => 'service',
            'prix_achat' => 0, 'prix_vente' => 2000,
        ]);

        StockService::entree($mission, $this->magasin->id, 3, MouvementStock::RECEPTION,
            ['cout_unitaire' => 1000]);

        $this->assertSame(0, EcritureComptable::count());
    }

    public function test_un_mouvement_valorise_a_zero_n_ecrit_rien(): void
    {
        // Une ligne a zero encombre le grand livre sans rien y apporter.
        $gratuit = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'ECH-001',
            'nom' => 'Échantillon', 'type' => 'marchandise',
            'prix_achat' => 0, 'prix_vente' => 0,
            'categorie_id' => $this->riz->categorie_id,
        ]);

        StockService::entree($gratuit, $this->magasin->id, 5, MouvementStock::RECEPTION);

        $this->assertSame(0, EcritureComptable::count());
    }

    public function test_aucune_operation_comptable_n_est_creee_par_defaut(): void
    {
        $this->entree(10, 12000);
        StockService::sortie($this->riz, $this->magasin->id, 3, MouvementStock::LIVRAISON);

        $this->assertSame(0, EcritureComptable::withoutGlobalScopes()->count());
    }

    public function test_un_mouvement_lie_a_un_achat_ne_genere_pas_d_ecriture_par_defaut(): void
    {
        $fournisseur = \App\Modules\Admin\Modeles\Fournisseur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Grossiste du port',
        ]);

        $achat = \App\Modules\Admin\Modeles\Achat::create([
            'point_de_vente_id' => $this->magasin->id,
            'fournisseur_id'    => $fournisseur->id,
            'numero_facture'    => 'ACH-TEST-001',
            'date_achat'        => now()->subDays(6)->toDateString(),
            'etape'             => 'Facture',
            'montant_ttc'       => 120000,
            'montant_ht'        => 120000,
            'montant_tva'       => 0,
            'mode_paiement'     => 'Espèces',
        ]);

        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION, [
            'cout_unitaire' => 12000, 'piece' => $achat,
        ]);

        $this->assertSame(0, EcritureComptable::withoutGlobalScopes()->count());
    }

    public function test_une_reception_de_commande_ne_genere_pas_d_ecriture_par_defaut(): void
    {
        $fournisseur = \App\Modules\Admin\Modeles\Fournisseur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Grossiste du port',
        ]);

        $this->travelTo(now()->subDays(6));
        $commande = \App\Modules\Admin\Modeles\Achat::create([
            'point_de_vente_id' => $this->magasin->id,
            'fournisseur_id'    => $fournisseur->id,
            'numero_facture'    => 'BC-TEST-001',
            'date_achat'        => now()->toDateString(),
            'etape'             => 'Bon de commande',
            'montant_ttc'       => 120000,
            'montant_ht'        => 120000,
            'montant_tva'       => 0,
            'mode_paiement'     => 'Espèces',
        ]);
        $this->travelBack();

        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION, [
            'cout_unitaire' => 12000, 'piece' => $commande,
        ]);

        $this->assertSame(0, EcritureComptable::withoutGlobalScopes()->count());
    }


    public function test_la_comptabilisation_quotidienne_est_agregee_et_idempotente(): void
    {
        $journee = now()->subDay()->toDateString();

        $this->entreprise->update([
            'comptabilite_stock_active' => true,
            'comptabilite_stock_quotidienne' => true,
            'comptabilite_stock_periode_mois' => 1,
            'comptabilite_stock_date_debut' => $journee,
            'comptabilite_stock_date_fin' => $journee,
        ]);

        $this->travelTo(\Illuminate\Support\Carbon::parse($journee . ' 23:50'));
        $this->entree(10, 12000);
        $this->travelTo(\Illuminate\Support\Carbon::parse($journee)->addDay()->setTime(0, 10));

        $premier = InventairePeriodiqueService::traiterEntreprise($this->entreprise->fresh());
        $second = InventairePeriodiqueService::traiterEntreprise($this->entreprise->fresh());

        $this->assertSame(1, $premier['periodes']);
        $this->assertSame(1, $premier['operations']);
        $this->assertSame(0, $second['periodes']);
        $this->assertSame(2, EcritureComptable::withoutGlobalScopes()->count());
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('stock_comptabilites_periodiques')
            ->where('entreprise_id', $this->entreprise->id)->count());

        $this->travelBack();
    }

    public function test_la_comptabilisation_mensuelle_attend_la_fin_des_trois_mois(): void
    {
        $this->entreprise->update([
            'comptabilite_stock_active' => true,
            'comptabilite_stock_quotidienne' => false,
            'comptabilite_stock_periode_mois' => 3,
            'comptabilite_stock_date_debut' => '2026-01-01',
            'comptabilite_stock_date_fin' => '2026-03-31',
        ]);

        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-03-31 23:50'));
        $this->entree(10, 12000);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-04-01 00:10'));

        $bilan = InventairePeriodiqueService::traiterEntreprise($this->entreprise->fresh());

        $this->assertSame(1, $bilan['periodes']);
        $this->assertSame(1, $bilan['operations']);
        $this->assertSame(2, EcritureComptable::withoutGlobalScopes()->count());
        $this->assertSame('2026-03-31', \Illuminate\Support\Facades\DB::table('stock_comptabilites_periodiques')
            ->where('entreprise_id', $this->entreprise->id)->value('date_fin'));

        $this->travelBack();
    }
}

