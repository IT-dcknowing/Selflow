<?php

namespace Tests\Feature;

use App\Modules\Admin\Controleurs\PropositionStockControleur;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportFacturesEmisesService;
use App\Modules\Admin\Modeles\PortailFneFactureRecueLigne;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\PropositionStock;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\IntegrationFacturePortailService;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Les factures émises sur le portail FNE deviennent des ventes normalisées
 * (23/11/2026, demande explicite du propriétaire — exception n° 3).
 */
class IntegrationFacturesPortailFneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $marcory;
    private PointDeVente $siege;
    private IntegrationFacturePortailService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2018-B-31734', 'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'ventes', 'stock', 'comptabilite'],
        ]);
        $this->siege = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège DC-Knowing', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->marcory = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory']);

        $admin = Utilisateur::create([
            'nom' => 'Keyman', 'prenom' => 'Constant', 'email' => 'ck@exemple.ci', 'password' => bcrypt('secret-de-test'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->siege->id,
        ]);
        Auth::login($admin);

        $this->service = app(IntegrationFacturePortailService::class);
    }

    private function facture(string $reference, array $brut, array $lignes, string $secteur = 'Commerce'): PortailFneFactureRecue
    {
        $ht = collect($lignes)->sum(fn ($l) => $l['quantity'] * $l['amount']);
        $tva = round($ht * 0.18, 2);

        $import = PortailFneImport::create([
            'entreprise_id' => $this->entreprise->id, 'login' => '1864699A', 'date_scraping' => now()->toDateString(),
            'type' => ImportFacturesEmisesService::TYPE, 'fichier_nom' => 'ventes.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)), 'statut' => PortailFneImport::STATUT_IMPORTE,
        ]);

        $f = PortailFneFactureRecue::create([
            'import_id' => $import->id, 'date_scraping' => now()->toDateString(),
            'entreprise_id' => $this->entreprise->id, 'login' => '1864699A', 'reference' => $reference,
            'fne_id' => 'id-' . $reference, 'token' => 'tok-' . $reference, 'type' => 'invoice', 'subtype' => 'normal',
            'liste_portail' => 'issued', 'date_facture' => '2026-10-16 00:42:10',
            'montant_ht' => $ht, 'montant_tva' => $tva, 'montant_ttc' => $ht + $tva, 'net_a_payer' => $ht + $tva,
            'timbre_fiscal' => 0, 'autres_taxes' => 0, 'moyen_paiement' => $brut['paymentMethod'] ?? 'cash',
            'statut_rapprochement' => PortailFneFactureRecue::A_RAPPROCHER,
            'contenu_brut' => array_merge(['clientPointOfSale' => 'PDV-marcory', 'clientCompanyName' => 'Client de passage', 'clientNcc' => '',
                'paymentMethod' => 'cash', 'company' => ['sector' => $secteur]], $brut),
        ]);

        foreach ($lignes as $l) {
            PortailFneFactureRecueLigne::create([
                'facture_recue_id' => $f->id, 'reference_article' => $l['reference'] ?? null, 'designation' => $l['description'],
                'quantite' => $l['quantity'], 'unite' => 'u', 'prix_unitaire' => $l['amount'], 'remise' => 0,
                'taxes' => [['shortName' => 'TVA', 'amount' => 18]],
            ]);
        }

        return $f->fresh('lignes');
    }

    public function test_la_facture_devient_une_vente_normalisee_au_point_de_vente_de_la_facture(): void
    {
        $sucre = Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'DEPB-SUC-001', 'nom' => 'Casier sucrerie 30 cl',
            'type' => 'marchandise', 'unite' => 'casier', 'prix_achat' => 30000, 'prix_vente' => 40000, 'taux_tva' => 18]);

        $f = $this->facture('1864699A26000000122', [], [['reference' => 'DEPB-SUC-001', 'description' => 'Casier sucrerie 30 cl', 'quantity' => 1, 'amount' => 40000]]);

        $vente = $this->service->integrer($f, $this->entreprise, Auth::id());

        $this->assertTrue((bool) $vente->normalise, 'Une pièce certifiée par la DGI est normalisée.');
        $this->assertSame('1864699A26000000122', $vente->numero_fne);
        $this->assertSame($this->marcory->id, $vente->point_de_vente_id, 'Le point de vente vient de la facture.');
        $this->assertEqualsWithDelta(47200, (float) $vente->montant_ttc, 0.01);
        $this->assertSame('Payé', $vente->statut);
        $this->assertSame($sucre->id, $vente->details->first()->produit_id);
        $this->assertTrue(EcritureComptable::where('reference_document', $vente->numero_facture)->exists(), 'La facture passe en comptabilité.');

        $this->assertSame(1, PropositionStock::where('vente_id', $vente->id)->where('statut', 'proposee')->count());
        $this->assertSame(0, MouvementStock::count(), 'Le stock ne bouge pas sans validation.');
        $this->assertSame(PortailFneFactureRecue::RAPPROCHEE, $f->fresh()->statut_rapprochement);
    }

    public function test_une_facture_deja_normalisee_par_selflow_n_est_jamais_reprise(): void
    {
        $f = $this->facture('1864699A26000000101', [], [['description' => 'Conseil', 'quantity' => 1, 'amount' => 1000]]);
        Vente::create(['point_de_vente_id' => $this->siege->id, 'numero_facture' => 'VTE-150926-001', 'numero_fne' => '1864699A26000000101',
            'date_vente' => '2026-09-15', 'mode_paiement' => 'Espèces', 'montant_ht' => 1000, 'montant_tva' => 180, 'montant_ttc' => 1180,
            'etape' => 'Facture', 'statut' => 'Payé', 'normalise' => true]);

        $this->assertTrue($this->service->enAttente($this->entreprise)->isEmpty());
        $this->assertSame(PortailFneFactureRecue::RAPPROCHEE, $f->fresh()->statut_rapprochement);

        $this->expectException(\DomainException::class);
        $this->service->integrer($f->fresh('lignes'), $this->entreprise, Auth::id());
    }

    public function test_une_ligne_hors_catalogue_d_une_entreprise_de_services_va_en_706(): void
    {
        $f = $this->facture('1864699A26000000090', ['clientCompanyName' => 'SPLENDID GROUP', 'clientNcc' => '2101234B', 'paymentMethod' => 'deferred'],
            [['description' => 'Mission de conseil fiscal', 'quantity' => 1, 'amount' => 100]], 'Services');

        $vente = $this->service->integrer($f, $this->entreprise, Auth::id());

        $this->assertSame('706000', $vente->details->first()->compte_vente);
        $this->assertSame('Crédit', $vente->statut, 'Un paiement différé reste à régler.');
        $this->assertSame('SPLENDID GROUP', $vente->client?->nom, 'Le client nommé par la facture est créé.');
        $this->assertSame('2101234B', $vente->client?->ncc);
        $this->assertTrue(EcritureComptable::where('reference_document', $vente->numero_facture)->where('compte_credit', '706000')->exists(),
            'Le chiffre d’affaires d’une prestation va en 706000.');
    }

    public function test_valider_une_proposition_sort_le_stock_et_refuser_ne_bouge_rien(): void
    {
        $sucre = Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'SUC', 'nom' => 'Sucre',
            'type' => 'marchandise', 'unite' => 'u', 'prix_achat' => 100, 'prix_vente' => 200, 'taux_tva' => 18]);
        StockService::entree($sucre, $this->marcory->id, 10, MouvementStock::RECEPTION, ['cout_unitaire' => 100]);

        $v1 = $this->service->integrer($this->facture('REF-1', [], [['reference' => 'SUC', 'description' => 'Sucre', 'quantity' => 3, 'amount' => 200]]), $this->entreprise, Auth::id());
        $v2 = $this->service->integrer($this->facture('REF-2', [], [['reference' => 'SUC', 'description' => 'Sucre', 'quantity' => 2, 'amount' => 200]]), $this->entreprise, Auth::id());

        $controleur = app(PropositionStockControleur::class);
        $controleur->valider(PropositionStock::where('vente_id', $v1->id)->first());
        $controleur->refuser(request(), PropositionStock::where('vente_id', $v2->id)->first());

        $this->assertEqualsWithDelta(7, StockService::disponible($sucre, $this->marcory->id), 0.001);
        $this->assertSame('validee', PropositionStock::where('vente_id', $v1->id)->value('statut'));
        $this->assertSame('refusee', PropositionStock::where('vente_id', $v2->id)->value('statut'));
    }

    public function test_les_deux_pages_s_affichent(): void
    {
        $this->facture('1864699A26000000122', [], [['description' => 'Casier sucrerie 30 cl', 'quantity' => 1, 'amount' => 40000]]);

        // Sans passer par le web, `$errors` n'est pas partagé par le middleware.
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());

        $html = app(\App\Modules\Admin\Controleurs\IntegrationPortailFneControleur::class)->index()->render();
        $this->assertStringContainsString('1864699A26000000122', $html);
        $this->assertStringContainsString('PDV-marcory', $html);
        $this->assertStringContainsString('Intégrer comme vente normalisée', $html);

        $html = app(PropositionStockControleur::class)->index()->render();
        $this->assertStringContainsString('Proposition entrée/sortie', $html);
    }
}
