<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deux indicateurs qui mentaient.
 *
 * Relevés le 25/09/2026 en vérifiant les deux tableaux de bord, à la demande du
 * propriétaire : *« vérifie chaque tableau, chaque élément… les alertes stock,
 * les KPI »*.
 *
 * Aucun des deux ne se voyait à l'œil : un compteur qui plafonne et une marge
 * qui se compte de travers rassurent, ils n'alertent pas.
 */
class TableauxDeBordDisentLeVraiTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI',
            'adresse' => 'Riviera II', 'rccm' => 'CI-ABJ-2018-B-31734',
            'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite', 'points_de_vente'],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-tdb@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    /** `$combien` articles tous en rupture. */
    private function articlesEnRupture(int $combien): void
    {
        for ($i = 1; $i <= $combien; $i++) {
            $produit = Produit::create([
                'entreprise_id' => $this->entreprise->id,
                'reference'     => 'ART-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'nom'           => 'Article ' . $i,
                'type'          => 'marchandise',
                'unite'         => 'pièce',
                'prix_achat'    => 1000,
                'prix_vente'    => 1500,
                'taux_tva'      => 18,
            ]);

            Stock::updateOrCreate(
                ['produit_id' => $produit->id, 'point_de_vente_id' => $this->site->id],
                ['quantite_disponible' => 0, 'stock_minimum' => 5, 'stock_maximum' => 100]
            );
        }
    }

    // ══════════════ Un compteur qui plafonne ══════════════

    public function test_le_compteur_d_alertes_ne_plafonne_pas_a_huit(): void
    {
        /*
         * Le tableau n'en montre que huit — c'est un tableau de bord, pas un
         * inventaire — mais le compteur lisait `count()` sur cette liste
         * tronquée. Trente articles en rupture s'annonçaient « 8 alertes
         * stock », et le chiffre ne montait jamais au-delà.
         *
         * Un indicateur qui plafonne est pire que pas d'indicateur : il
         * rassure.
         */
        $this->articlesEnRupture(12);

        foreach ([route('admin.tableau_de_bord'), route('admin.tableau_de_bord_general')] as $adresse) {
            $page = $this->get($adresse)->assertOk()->getContent();

            $this->assertStringContainsString('Alertes stock (12)', $page, $adresse);
            $this->assertStringNotContainsString('Alertes stock (8)', $page, $adresse);
        }
    }

    public function test_le_tableau_dit_qu_il_ne_montre_pas_tout(): void
    {
        $this->articlesEnRupture(12);

        $page = $this->get(route('admin.tableau_de_bord'))->assertOk()->getContent();

        // Laisser croire que la liste est complète serait la même tromperie,
        // en plus discrète.
        $this->assertStringContainsString('8 article(s) sur 12 affiché(s)', $page);
    }

    public function test_sans_troncature_rien_ne_s_excuse(): void
    {
        $this->articlesEnRupture(3);

        $page = $this->get(route('admin.tableau_de_bord'))->assertOk()->getContent();

        $this->assertStringContainsString('Alertes stock (3)', $page);
        $this->assertStringNotContainsString('affiché(s)', $page);
    }

    public function test_un_service_n_entre_pas_dans_les_alertes(): void
    {
        // Une fiche de stock existe pour tout le monde : sans le filtre, une
        // prestation de conseil traînerait en rupture pour toujours.
        $service = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV-001',
            'nom' => 'Conseil', 'type' => 'service', 'unite' => 'heure',
            'prix_achat' => 0, 'prix_vente' => 50000, 'taux_tva' => 18,
        ]);

        Stock::updateOrCreate(
            ['produit_id' => $service->id, 'point_de_vente_id' => $this->site->id],
            ['quantite_disponible' => 0, 'stock_minimum' => 5, 'stock_maximum' => 100]
        );

        $page = $this->get(route('admin.tableau_de_bord'))->assertOk()->getContent();

        $this->assertStringContainsString('Alertes stock', $page);
        $this->assertStringNotContainsString('SRV-001', $page);
    }

    // ══════════════ Une marge comptée de travers ══════════════

    public function test_la_marge_se_compte_hors_taxes_des_deux_cotes(): void
    {
        /*
         * Elle retranchait des ventes **HT** des achats **TTC** : la TVA
         * supportée à l'achat venait donc en diminution de la marge, alors
         * qu'elle est récupérable et n'est pas une charge.
         *
         * Ici : 100 000 F de ventes HT, 50 000 F d'achats HT (59 000 TTC).
         * La marge est de 50 000 F, et non de 41 000 F.
         */
        Vente::create([
            'point_de_vente_id' => $this->site->id,
            'numero_facture' => 'VTE-001', 'date_vente' => now()->toDateString(),
            'etape' => 'Facture', 'montant_ht' => 100000, 'montant_tva' => 18000,
            'montant_ttc' => 118000, 'mode_paiement' => 'Caisse', 'statut' => 'Payé',
        ]);

        $fournisseur = \App\Modules\Admin\Modeles\Fournisseur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'SOCIÉTÉ ALPHA', 'ncc' => '1122334B',
        ]);

        Achat::create([
            'point_de_vente_id' => $this->site->id,
            'fournisseur_id' => $fournisseur->id,
            'numero_facture' => 'ACH-001', 'date_achat' => now()->toDateString(),
            'etape' => 'Facture', 'montant_ht' => 50000, 'montant_tva' => 9000,
            'montant_ttc' => 59000, 'mode_paiement' => 'Caisse', 'statut' => 'Payé',
        ]);

        $page = $this->get(route('admin.tableau_de_bord_general'))->assertOk()->getContent();

        $propre = str_replace("\u{202F}", ' ', $page);

        $this->assertStringContainsString('50 000', $propre);
        // 100 000 − 59 000 : la marge d'avant, amputée de la TVA récupérable.
        $this->assertStringNotContainsString('41 000', $propre);
        $this->assertStringContainsString('Taux : 50%', $propre);
    }

    public function test_un_avoir_fait_baisser_le_chiffre_d_affaires(): void
    {
        // Ce qui avait été corrigé et qu'il ne faut pas laisser repartir : un
        // avoir annule une facture, il ne s'y ajoute pas.
        Vente::create([
            'point_de_vente_id' => $this->site->id,
            'numero_facture' => 'VTE-001', 'date_vente' => now()->toDateString(),
            'etape' => 'Facture', 'montant_ht' => 100000, 'montant_tva' => 18000,
            'montant_ttc' => 118000, 'mode_paiement' => 'Caisse', 'statut' => 'Payé',
        ]);

        Vente::create([
            'point_de_vente_id' => $this->site->id,
            'numero_facture' => 'AV-001', 'date_vente' => now()->toDateString(),
            'etape' => 'Facture', 'type_facture' => 'avoir',
            'montant_ht' => 40000, 'montant_tva' => 7200,
            'montant_ttc' => 47200, 'mode_paiement' => 'Caisse', 'statut' => 'Payé',
        ]);

        $page = $this->get(route('admin.tableau_de_bord_general'))->assertOk()->getContent();
        $propre = str_replace("\u{202F}", ' ', $page);

        // 118 000 − 47 200 = 70 800, et non 165 200.
        $this->assertStringContainsString('70 800', $propre);
        $this->assertStringNotContainsString('165 200', $propre);
    }
}
