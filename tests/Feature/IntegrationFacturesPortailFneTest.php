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

    private function facture(string $reference, array $brut, array $lignes, string $secteur = 'Commerce', array $champs = []): PortailFneFactureRecue
    {
        $ht = collect($lignes)->sum(fn ($l) => $l['quantity'] * $l['amount']);
        $tva = round($ht * 0.18, 2);

        $import = PortailFneImport::create([
            'entreprise_id' => $this->entreprise->id, 'login' => '1864699A', 'date_scraping' => now()->toDateString(),
            'type' => ImportFacturesEmisesService::TYPE, 'fichier_nom' => 'ventes.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)), 'statut' => PortailFneImport::STATUT_IMPORTE,
        ]);

        $f = PortailFneFactureRecue::create(array_merge([
            'import_id' => $import->id, 'date_scraping' => now()->toDateString(),
            'entreprise_id' => $this->entreprise->id, 'login' => '1864699A', 'reference' => $reference,
            'fne_id' => 'id-' . $reference, 'token' => 'tok-' . $reference, 'type' => 'invoice', 'subtype' => 'normal',
            'liste_portail' => 'issued', 'date_facture' => '2026-10-16 00:42:10',
            'montant_ht' => $ht, 'montant_tva' => $tva, 'montant_ttc' => $ht + $tva, 'net_a_payer' => $ht + $tva,
            'timbre_fiscal' => 0, 'autres_taxes' => 0, 'moyen_paiement' => $brut['paymentMethod'] ?? 'cash',
            'statut_rapprochement' => PortailFneFactureRecue::A_RAPPROCHER,
            'contenu_brut' => array_merge(['clientPointOfSale' => 'PDV-marcory', 'clientCompanyName' => 'Client de passage', 'clientNcc' => '',
                'paymentMethod' => 'cash', 'company' => ['sector' => $secteur]], $brut),
        ], $champs));

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

        // 25/11/2026 : la ligne entre au catalogue, en service, avec son compte et son prix.
        $article = $vente->details->first()->produit;
        $this->assertNotNull($article, 'L\'article du portail est créé au catalogue.');
        $this->assertSame('Mission de conseil fiscal', $article->nom);
        $this->assertSame('service', $article->type);
        $this->assertSame('706000', $article->compte_vente);
        $this->assertEquals(100, (float) $article->prix_vente);
        $this->assertSame('Crédit', $vente->statut, 'Un paiement différé reste à régler.');
        $this->assertSame('SPLENDID GROUP', $vente->client?->nom, 'Le client nommé par la facture est créé.');
        $this->assertSame('2101234B', $vente->client?->ncc);
        $this->assertTrue(EcritureComptable::where('reference_document', $vente->numero_facture)->where('compte_credit', '706000')->exists(),
            'Le chiffre d’affaires d’une prestation va en 706000.');
    }

    public function test_une_marchandise_du_portail_entre_au_catalogue_comme_stockable(): void
    {
        $vente = $this->service->integrer($this->facture('REF-CAT-1', [], [['reference' => 'HUI-5L', 'description' => 'Huile Dinor 5 L', 'quantity' => 2, 'amount' => 7500]]),
            $this->entreprise, Auth::id());

        $article = $vente->details->first()->produit;
        $this->assertSame('HUI-5L', $article->reference);
        $this->assertSame('marchandise', $article->type);
        $this->assertEquals(7500, (float) $article->prix_vente);
        $this->assertNotEmpty($article->compte_vente);

        // La pièce suivante retrouve l'article au lieu d'en créer un second.
        $this->service->integrer($this->facture('REF-CAT-2', [], [['reference' => 'HUI-5L', 'description' => 'Huile Dinor 5 L', 'quantity' => 1, 'amount' => 7500]]),
            $this->entreprise, Auth::id());
        $this->assertSame(1, Produit::where('entreprise_id', $this->entreprise->id)->where('reference', 'HUI-5L')->count());
    }

    public function test_un_client_sans_ncc_va_sous_client_divers_avec_son_nom_garde(): void
    {
        $vente = $this->service->integrer($this->facture('REF-B2C-1', ['clientCompanyName' => 'KOUAME ALAIN', 'clientNcc' => ''],
            [['description' => 'Sucre', 'quantity' => 1, 'amount' => 1000]]), $this->entreprise, Auth::id());

        $this->assertSame(\App\Modules\Admin\Modeles\Client::NUMERO_DIVERS, $vente->client->numero_tiers);
        $this->assertSame('Client divers', $vente->client->nom);
        $this->assertSame('KOUAME ALAIN', $vente->fresh()->nom_client_origine);
        $this->assertFalse(\App\Modules\Admin\Modeles\Client::where('entreprise_id', $this->entreprise->id)->where('nom', 'KOUAME ALAIN')->exists());
    }

    public function test_l_article_cree_prend_la_categorie_le_secteur_et_le_domaine_du_referentiel(): void
    {
        $this->seed(\Database\Seeders\ReferentielSeeder::class);
        \App\Modules\Admin\Services\ClassementParReferentielService::oublier();

        $vente = $this->service->integrer($this->facture('REF-REF-1', [], [['description' => 'Riz parfumé sac 25 kg', 'quantity' => 2, 'amount' => 16000]]),
            $this->entreprise, Auth::id());

        $article = $vente->details->first()->produit->fresh();
        $this->assertNotNull($article->categorie_id, 'L\'article est rangé dans une catégorie du référentiel.');
        $categorie = \App\Modules\Admin\Modeles\Categorie::find($article->categorie_id);
        $this->assertNotNull($categorie->domaine_configuration);
        $this->assertNotNull($categorie->metier_configuration);

        $e = $this->entreprise->fresh();
        $this->assertTrue($e->profils()->exists(), 'Le secteur est inscrit à l\'entreprise.');
        $this->assertContains($categorie->domaine_configuration, (array) $e->domaines_souscrits);
    }

    public function test_un_article_que_le_referentiel_ne_connait_pas_reste_sans_categorie(): void
    {
        $this->seed(\Database\Seeders\ReferentielSeeder::class);
        \App\Modules\Admin\Services\ClassementParReferentielService::oublier();

        $vente = $this->service->integrer($this->facture('REF-REF-2', [], [['description' => 'Xyzzy quux', 'quantity' => 1, 'amount' => 500]]),
            $this->entreprise, Auth::id());

        $this->assertNull($vente->details->first()->produit->fresh()->categorie_id);
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

    public function test_un_client_qui_porte_un_ncc_est_cree_meme_appele_de_passage(): void
    {
        $f = $this->facture('REF-NCC', ['clientCompanyName' => 'Client de passage', 'clientNcc' => '9901234Z'],
            [['description' => 'Conseil', 'quantity' => 1, 'amount' => 1000]]);

        $vente = $this->service->integrer($f, $this->entreprise, Auth::id());

        $this->assertNotNull($vente->client, 'Un client identifié par son NCC n’est pas un passage.');
        $this->assertSame('9901234Z', $vente->client->ncc);
    }

    public function test_un_avoir_emis_devient_l_avoir_de_sa_facture(): void
    {
        $vente = $this->service->integrer($this->facture('FAC-1', [], [['description' => 'Conseil', 'quantity' => 1, 'amount' => 10000]]), $this->entreprise, Auth::id());
        $avoirPortail = $this->facture('AVO-1', ['parentReference' => 'FAC-1'], [['description' => 'Conseil', 'quantity' => 1, 'amount' => 10000]], 'Commerce', ['subtype' => 'refund']);

        $avoir = $this->service->integrer($avoirPortail, $this->entreprise, Auth::id());

        $this->assertSame('avoir', $avoir->type_facture);
        $this->assertSame($vente->id, $avoir->parent_id);
        $this->assertTrue((bool) $avoir->normalise);
        $this->assertSame('AVO-1', $avoir->numero_fne);
    }

    public function test_un_avoir_sans_sa_facture_dans_selflow_attend(): void
    {
        $avoirPortail = $this->facture('AVO-2', ['parentReference' => 'FAC-INCONNUE'], [['description' => 'X', 'quantity' => 1, 'amount' => 100]], 'Commerce', ['subtype' => 'refund']);

        $this->expectException(\DomainException::class);
        $this->service->integrer($avoirPortail, $this->entreprise, Auth::id());
    }

    public function test_une_facture_recue_devient_un_achat_et_son_ecriture_n_est_pas_doublee(): void
    {
        $sucre = Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'SUC', 'nom' => 'Sucre',
            'type' => 'marchandise', 'unite' => 'u', 'prix_achat' => 100, 'prix_vente' => 200, 'taux_tva' => 18]);
        $recue = $this->facture('B000123X26000000001', ['paymentMethod' => 'deferred'], [['reference' => 'SUC', 'description' => 'Sucre', 'quantity' => 5, 'amount' => 100]],
            'Commerce', ['liste_portail' => 'received', 'emetteur_ncc' => '2201234K', 'emetteur_nom' => 'GROSSISTE SA']);

        // Le relevé l'avait déjà passée en écriture.
        \App\Modules\Admin\Services\EcritureFactureRecueService::synchroniser($recue->fresh());

        $achat = $this->service->integrer($recue->fresh('lignes'), $this->entreprise, Auth::id(), ['point_de_vente_id' => $this->marcory->id]);

        $this->assertSame('B000123X26000000001', $achat->numero_facture_fournisseur);
        $this->assertSame('GROSSISTE SA', $achat->fournisseur?->nom);
        $this->assertSame('Crédit', $achat->statut);
        $this->assertSame($achat->id, $recue->fresh()->achat_id);
        $this->assertNull($recue->fresh()->operation_id, 'L’écriture du relevé est contre-passée : l’achat porte la charge.');
        $this->assertSame(1, PropositionStock::where('reference', $achat->numero_facture)->where('sens', 'entree')->count());

        $controleur = app(PropositionStockControleur::class);
        $controleur->valider(PropositionStock::where('reference', $achat->numero_facture)->first());
        $this->assertEqualsWithDelta(5, StockService::disponible($sucre, $this->marcory->id), 0.001);
    }

    public function test_un_avoir_recu_devient_l_avoir_de_son_achat(): void
    {
        $recue = $this->facture('B000123X26000000002', ['paymentMethod' => 'deferred'], [['description' => 'Prestation', 'quantity' => 1, 'amount' => 5000]],
            'Commerce', ['liste_portail' => 'received', 'emetteur_ncc' => '2201234K', 'emetteur_nom' => 'GROSSISTE SA']);
        $achat = $this->service->integrer($recue, $this->entreprise, Auth::id(), ['point_de_vente_id' => $this->marcory->id]);

        $avoirRecu = $this->facture('B000123X26000000003', ['parentReference' => 'B000123X26000000002'], [['description' => 'Prestation', 'quantity' => 1, 'amount' => 5000]],
            'Commerce', ['liste_portail' => 'received', 'subtype' => 'refund', 'emetteur_ncc' => '2201234K', 'emetteur_nom' => 'GROSSISTE SA']);
        $avoir = $this->service->integrer($avoirRecu, $this->entreprise, Auth::id());

        $this->assertSame('avoir', $avoir->type_facture);
        $this->assertSame($achat->id, $avoir->parent_id);
        $this->assertSame($achat->fournisseur_id, $avoir->fournisseur_id);
    }

    public function test_une_facture_emise_par_notre_ncc_reste_proposee(): void
    {
        // Sur une pièce émise, l'émetteur est notre propre NCC : elle ne doit
        // pas être prise pour notre BAPA (page vide du 23/11/2026).
        $this->facture('1864699A26000000150', [], [['description' => 'Conseil', 'quantity' => 1, 'amount' => 1000]], 'Commerce',
            ['emetteur_ncc' => '1864699A', 'emetteur_nom' => 'DC-KNOWING CGA']);

        $this->assertCount(1, $this->service->enAttente($this->entreprise));
    }

    public function test_un_client_connu_sous_le_meme_ncc_est_signale_et_integre_par_le_bouton_des_reconnus(): void
    {
        \App\Modules\Admin\Modeles\Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'SOTRA', 'ncc' => '0101010 A']);

        $connu = $this->facture('1864699A26000000201', ['clientNcc' => '0101010A', 'clientCompanyName' => 'SOTRA'],
            [['description' => 'Conseil', 'quantity' => 1, 'amount' => 1000]]);
        $inconnu = $this->facture('1864699A26000000202', ['clientNcc' => '0999999Z', 'clientCompanyName' => 'Autre'],
            [['description' => 'Conseil', 'quantity' => 1, 'amount' => 1000]]);

        $this->assertTrue($this->service->proposer($connu, $this->entreprise)['reconnu_ncc']);
        $this->assertFalse($this->service->proposer($inconnu, $this->entreprise)['reconnu_ncc']);

        view()->share('errors', new \Illuminate\Support\ViewErrorBag());
        $controleur = app(\App\Modules\Admin\Controleurs\IntegrationPortailFneControleur::class);
        $this->assertStringContainsString('Client reconnu par NCC', $controleur->index()->render());

        $requete = \Illuminate\Http\Request::create('/', 'POST', ['reconnus' => true]);
        $requete->headers->set('Accept', 'application/json');
        $controleur->integrerPretes($requete);

        $this->assertSame(PortailFneFactureRecue::RAPPROCHEE, $connu->fresh()->statut_rapprochement);
        $this->assertSame(PortailFneFactureRecue::A_RAPPROCHER, $inconnu->fresh()->statut_rapprochement, 'Seuls les reconnus partent.');
    }

    public function test_chaque_depot_du_robot_notifie_une_fois_meme_identique(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        $import = PortailFneImport::create([
            'entreprise_id' => $this->entreprise->id, 'login' => '1864699A', 'date_scraping' => now()->toDateString(),
            'type' => ImportFacturesEmisesService::TYPE, 'fichier_nom' => 'ventes.json',
            'fichier_empreinte' => 'x', 'statut' => PortailFneImport::STATUT_IMPORTE,
        ]);
        $rapport = fn (int $mtime) => ['details' => [['import_id' => $import->id, 'statut' => 'inchange', 'lignes' => 3,
            'chemin' => '/tmp/ventes.json', 'depose_le' => $mtime]]];
        $admin = Auth::user();

        \App\Modules\Admin\Services\PortailFneNotificationService::notifier($rapport(1000), 'Ventes');
        \App\Modules\Admin\Services\PortailFneNotificationService::notifier($rapport(1000), 'Ventes');
        \Illuminate\Support\Facades\Notification::assertSentToTimes($admin, \App\Notifications\PortailFneRecuperationNotification::class, 1);

        \App\Modules\Admin\Services\PortailFneNotificationService::notifier($rapport(2000), 'Ventes');
        \Illuminate\Support\Facades\Notification::assertSentToTimes($admin, \App\Notifications\PortailFneRecuperationNotification::class, 2);
    }

    public function test_le_panneau_regroupe_les_lignes_par_categorie_avec_leurs_comptes(): void
    {
        $boissons = \App\Modules\Admin\Modeles\Categorie::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Boissons', 'prefixe' => 'BOI']);
        Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'DEPB-SUC-001', 'nom' => 'Casier sucrerie 30 cl', 'categorie_id' => $boissons->id,
            'type' => 'marchandise', 'unite' => 'casier', 'prix_achat' => 30000, 'prix_vente' => 40000, 'taux_tva' => 18]);
        $this->facture('1864699A26000000501', [], [
            ['reference' => 'DEPB-SUC-001', 'description' => 'Casier sucrerie 30 cl', 'quantity' => 2, 'amount' => 40000],
            ['description' => 'Livraison spéciale', 'quantity' => 1, 'amount' => 5000],
        ]);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());

        $html = app(\App\Modules\Admin\Controleurs\IntegrationPortailFneControleur::class)->index()->render();
        $this->assertStringContainsString("Groupes d'articles", $html);
        $this->assertStringContainsString('pièce(s) en attente', $html);
        $this->assertStringContainsString('Boissons', $html);
        $this->assertStringContainsString('Lignes libres', $html);
        $this->assertStringContainsString('701000', $html);
    }
}
