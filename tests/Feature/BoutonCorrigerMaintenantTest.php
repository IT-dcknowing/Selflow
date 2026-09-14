<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le bouton « Lancer la correction » du pop-up, et le choix qu'il propose.
 *
 * `RejetFneControleur::corrigerMaintenant()` enchaîne trois gestes en un clic :
 * ranger ce que le scraper a déposé, rapprocher le rejet du relevé, appliquer
 * la correction. Aucune épreuve ne le couvrait — seul `appliquer`, le geste
 * unitaire de l'écran des rejets, l'était.
 *
 * | Situation | Ce que le bouton doit rendre |
 * |---|---|
 * | un seul nom déclaré au portail | `succes` : renommé, la pièce repart |
 * | plusieurs noms déclarés | le pop-up **propose les noms**, rien n'est touché |
 * | un nom cliqué dans le pop-up | `succes` : ce nom-là est appliqué |
 * | un rang qui ne désigne rien | `avertissement`, aucun renommage |
 * | aucun relevé rangé | `avertissement` : le relevé n'est pas arrivé |
 * | rejet réseau | `erreur` : la DGI n'a rien refusé |
 * | rejet d'une autre entreprise | 404 |
 *
 * La deuxième ligne est celle observée en production le 31/08/2026 : le portail
 * de l'entreprise déclare « FACTURATION SIEGE » **et** « FACTURATION TEST 2 ».
 * L'automatisme s'abstient — il ne choisit pas le point de vente à la place de
 * qui a établi la pièce —, mais s'abstenir n'est pas se taire : le pop-up
 * aligne les noms déclarés, et **c'est un humain qui tranche**. La règle tient,
 * et l'utilisateur n'est plus laissé devant rien.
 *
 * Ce qui revient du navigateur est un **rang** dans la liste, jamais un nom :
 * le clic désigne une valeur que le rapprochement a écrite, il n'en introduit
 * aucune.
 */
class BoutonCorrigerMaintenantTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = '1864699A';

    private Entreprise $mienne;
    private PointDeVente $monMagasin;
    private Utilisateur $moi;
    private Client $client;
    private string $dossier;

    /** Ce que la plateforme répondra au renvoi. */
    private $reponseDeLaDgi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mienne = $this->uneEntreprise('DC-KNOWING CGA', 'CI-ABJ-2026-B-01111', self::LOGIN);

        FneCredential::create([
            'entreprise_id' => $this->mienne->id,
            'cle_test'      => 'fne_test_cle',
            'statut'        => 'test',
        ]);

        $this->monMagasin = PointDeVente::create([
            'entreprise_id' => $this->mienne->id,
            'nom'           => 'FACTURATION SIEGES', // le S de trop, comme en production
            'ville'         => 'Abidjan',
            'commune'       => 'Cocody',
        ]);

        $this->client = Client::create(['entreprise_id' => $this->mienne->id, 'nom' => 'Client X']);

        $this->moi = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Adjoua', 'email' => 'adjoua@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->mienne->id,
            'point_de_vente_id' => $this->monMagasin->id,
        ]);

        $this->actingAs($this->moi)
            ->withSession(['point_de_vente_actif_id' => $this->monMagasin->id]);

        // Le bouton appelle `portail-fne:importer` : il lui faut un dossier à
        // lui, sans quoi l'épreuve range les relevés réels du poste.
        $this->dossier = storage_path('framework/testing/corriger-' . uniqid());
        mkdir($this->dossier, 0777, true);
        config(['selflow.portail_fne.dossier_import' => $this->dossier]);

        $this->reponseDeLaDgi = fn () => Http::response([
            'reference' => '9606123456789', 'token' => 'tok',
            'invoice_id' => 'b1f0a7c2-1111-4c3a-9d21-9f0c7a5e4d10',
            'document_url' => 'x', 'balance_sticker' => 4990,
        ], 200);

        Http::fake(fn () => ($this->reponseDeLaDgi)());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        @rmdir($this->dossier);

        parent::tearDown();
    }

    public function test_un_seul_nom_declare_le_bouton_cree_le_point_et_renvoie_la_piece(): void
    {
        $vente = $this->uneVente('FA-0042');
        $rejet = FneRejet::consigner($vente, $this->refus());

        $this->unReleve(['FACTURATION SIEGE']);

        $this->post(route('admin.fne.rejets.corriger_maintenant', $rejet))
            ->assertRedirect()
            ->assertSessionHas('succes');

        // Le point de vente d'origine n'est JAMAIS renommé...
        $this->assertSame('FACTURATION SIEGES', $this->monMagasin->refresh()->nom);

        // ...un nouveau point a été créé d'après le portail...
        $this->assertTrue(PointDeVente::where('entreprise_id', $this->mienne->id)->where('nom', 'FACTURATION SIEGE')->exists());

        // ...et la pièce lui a été rattachée et repartie dans la foulée.
        $this->assertSame('FACTURATION SIEGE', $vente->refresh()->pointDeVente->nom);
        $this->assertTrue((bool) $vente->normalise);
    }

    public function test_deux_noms_declares_le_pop_up_propose_les_deux_sans_rien_toucher(): void
    {
        $vente = $this->uneVente('FA-0042');
        $rejet = FneRejet::consigner($vente, $this->refus());

        // Le cas de production : le portail déclare deux points de facturation.
        $this->unReleve(['FACTURATION SIEGE', 'FACTURATION TEST 2']);

        $this->post(route('admin.fne.rejets.corriger_maintenant', $rejet))
            ->assertRedirect()
            ->assertSessionHas('avertissement');

        $this->assertStringContainsString('ne choisit pas à votre place', session('avertissement'));

        // Le pop-up porte un bouton Activer par nom déclaré, sans lien 'Voir les rejets'.
        $actions = session('avertissement_action');

        $this->assertSame(
            ['FACTURATION SIEGE', 'FACTURATION TEST 2'],
            array_column($actions, 'label')
        );
        $this->assertSame('Activer', $actions[0]['action_label']);
        $this->assertSame('Activer', $actions[1]['action_label']);
        $this->assertSame('post', $actions[0]['method']);
        $this->assertStringContainsString('/corriger-avec/0', $actions[0]['url']);
        $this->assertStringContainsString('/corriger-avec/1', $actions[1]['url']);

        // Rien n'a bougé tant que personne n'a cliqué : ni le nom, ni la pièce.
        $this->assertSame('FACTURATION SIEGES', $this->monMagasin->refresh()->nom);
        $this->assertFalse((bool) $vente->refresh()->normalise);

        // Le rapprochement, lui, a bien eu lieu : le rejet porte son constat.
        $rejet->refresh();
        $this->assertSame(FneRejet::STATUT_DIAGNOSTIQUE, $rejet->statut);
        $this->assertSame(
            ['FACTURATION SIEGE', 'FACTURATION TEST 2'],
            $rejet->diagnostic['champs'][0]['portail']
        );
    }

    public function test_le_nom_choisi_dans_le_pop_up_est_applique_et_la_piece_repart(): void
    {
        $vente = $this->uneVente('FA-0042');
        $rejet = FneRejet::consigner($vente, $this->refus());

        $this->unReleve(['FACTURATION SIEGE', 'FACTURATION TEST 2']);
        $this->post(route('admin.fne.rejets.corriger_maintenant', $rejet));

        // L'humain tranche : le premier de la liste.
        $this->post(route('admin.fne.rejets.corriger_avec', ['rejet' => $rejet, 'rang' => 0]))
            ->assertRedirect()
            ->assertSessionHas('succes');

        // Le point d'origine n'a pas été renommé
        $this->assertSame('FACTURATION SIEGES', $this->monMagasin->refresh()->nom);
        // Le nouveau point a été créé et la vente rattachée
        $this->assertSame('FACTURATION SIEGE', $vente->refresh()->pointDeVente->nom);
        $this->assertTrue((bool) $vente->normalise);
    }

    public function test_l_autre_nom_du_pop_up_mene_a_l_autre_point(): void
    {
        $vente = $this->uneVente('FA-0042');
        $rejet = FneRejet::consigner($vente, $this->refus());

        $this->unReleve(['FACTURATION SIEGE', 'FACTURATION TEST 2']);
        $this->post(route('admin.fne.rejets.corriger_maintenant', $rejet));

        $this->post(route('admin.fne.rejets.corriger_avec', ['rejet' => $rejet, 'rang' => 1]))
            ->assertSessionHas('succes');

        // Le point d'origine n'a pas été renommé
        $this->assertSame('FACTURATION SIEGES', $this->monMagasin->refresh()->nom);
        // Le second point a été créé et la vente rattachée
        $this->assertSame('FACTURATION TEST 2', $vente->refresh()->pointDeVente->nom);
        $this->assertTrue((bool) $vente->normalise);
    }

    /**
     * Un rang qui ne désigne rien ne renomme rien.
     *
     * C'est ce qui borne le geste : ce qui revient du navigateur **désigne**
     * une valeur écrite par le rapprochement, il ne peut pas en introduire une.
     */
    public function test_un_rang_qui_ne_designe_aucun_nom_ne_renomme_rien(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refus());

        $this->unReleve(['FACTURATION SIEGE', 'FACTURATION TEST 2']);
        $this->post(route('admin.fne.rejets.corriger_maintenant', $rejet));

        $this->post(route('admin.fne.rejets.corriger_avec', ['rejet' => $rejet, 'rang' => 7]))
            ->assertRedirect()
            ->assertSessionHas('avertissement');

        $this->assertSame('FACTURATION SIEGES', $this->monMagasin->refresh()->nom);
    }

    public function test_le_choix_sur_le_rejet_d_une_autre_entreprise_n_existe_pas(): void
    {
        $sienne = $this->uneEntreprise('Quincaillerie rivale', 'CI-ABJ-2026-B-03333', '8888888Y');

        $sonMagasin = PointDeVente::create([
            'entreprise_id' => $sienne->id, 'nom' => 'Magasin rival',
            'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);

        $saVente = Vente::create([
            'point_de_vente_id' => $sonMagasin->id,
            'numero_facture'    => 'FA-RIVALE-002',
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 100000, 'montant_tva' => 18000, 'montant_ttc' => 118000,
            'etape'             => 'Facture',
        ]);

        $sonRejet = FneRejet::consigner($saVente, $this->refus());

        $this->post(route('admin.fne.rejets.corriger_avec', ['rejet' => $sonRejet, 'rang' => 0]))
            ->assertNotFound();
    }

    /**
     * Deux relevés le même jour : le second doit rafraîchir le constat.
     *
     * Le 31/08/2026, un point renommé au portail à 20 h 44 est arrivé dans un
     * second relevé du 31/08. La fraîcheur se jugeait sur la **date** du jeu de
     * points : elle n'avait pas bougé, le rapprochement s'est cru à jour, et
     * l'écran a continué d'affirmer « le portail confirme les valeurs
     * envoyées » sur un constat périmé. Une date n'identifie pas un relevé.
     */
    public function test_un_second_releve_du_meme_jour_rafraichit_le_constat(): void
    {
        // La correction reste à l'arrêt : ce qui s'éprouve ici est le constat,
        // pas ce qu'on en fait.
        config(['selflow.portail_fne.correction_auto' => false]);

        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refus());

        // Premier relevé : le portail déclare le nom que la pièce porte.
        $this->unReleve(['FACTURATION SIEGES']);
        $this->artisan('fne:diagnostiquer-rejets', ['--rejet' => $rejet->id])->assertExitCode(0);

        $this->assertSame('concordant', $rejet->refresh()->diagnostic['champs'][0]['verdict']);

        // Second relevé, le même jour, **sans fiche neuve** : c'est ce qui se
        // produit quand un point est renommé au portail — le tableur change,
        // la fiche non. C'est donc la fraîcheur des points, et elle seule, qui
        // doit faire refaire le constat.
        $this->unReleve(['FACTURATION SIEGE'], avecFiche: false);
        $this->artisan('fne:diagnostiquer-rejets', ['--rejet' => $rejet->id])->assertExitCode(0);

        $champ = $rejet->refresh()->diagnostic['champs'][0];

        $this->assertSame('ecart', $champ['verdict']);
        $this->assertSame(['FACTURATION SIEGE'], $champ['portail']);
    }

    /**
     * Le rapprochement dit « concordant » là où la DGI a refusé ce champ.
     *
     * Les deux ne peuvent pas avoir raison : le message de la plateforme fait
     * foi, donc le relevé est en retard. Le bouton va chercher un relevé neuf
     * plutôt que de conclure « la cause est ailleurs » — mais il ne le promet
     * que s'il a pu le lancer. Scraper éteint, il ne promet rien.
     */
    public function test_scraper_eteint_le_bouton_ne_promet_pas_de_releve(): void
    {
        config(['selflow.portail_fne.scraper.actif' => false]);

        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refus());

        // Le portail déclare exactement ce que la pièce a envoyé.
        $this->unReleve(['FACTURATION SIEGES']);

        $this->post(route('admin.fne.rejets.corriger_maintenant', $rejet))
            ->assertRedirect()
            ->assertSessionHas('avertissement');

        $this->assertStringContainsString(
            'aucune correction automatique applicable',
            session('avertissement')
        );
        $this->assertStringNotContainsString("relève vient d'être lancée", session('avertissement'));
    }

    public function test_sans_releve_le_bouton_dit_que_le_portail_n_a_pas_repondu(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refus());

        $this->post(route('admin.fne.rejets.corriger_maintenant', $rejet))
            ->assertRedirect()
            ->assertSessionHas('avertissement');

        $this->assertStringContainsString("n'est pas encore arrivé", session('avertissement'));
        $this->assertSame('FACTURATION SIEGES', $this->monMagasin->refresh()->nom);
    }

    public function test_un_rejet_reseau_n_a_rien_a_corriger(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), [
            'success' => false,
            'message' => 'La plateforme FNE est injoignable (connexion impossible).',
            'errors'  => [],
        ]);
        $rejet->update(['cause' => FneRejet::CAUSE_RESEAU]);

        $this->unReleve(['FACTURATION SIEGE']);

        $this->post(route('admin.fne.rejets.corriger_maintenant', $rejet))
            ->assertRedirect()
            ->assertSessionHas('erreur');

        $this->assertSame('FACTURATION SIEGES', $this->monMagasin->refresh()->nom);
    }

    public function test_le_rejet_d_une_autre_entreprise_n_existe_pas(): void
    {
        $sienne = $this->uneEntreprise('Quincaillerie rivale', 'CI-ABJ-2026-B-02222', '9999999Z');

        $sonMagasin = PointDeVente::create([
            'entreprise_id' => $sienne->id,
            'nom' => 'Magasin rival', 'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);

        $saVente = Vente::create([
            'point_de_vente_id' => $sonMagasin->id,
            'numero_facture'    => 'FA-RIVALE-001',
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 100000, 'montant_tva' => 18000, 'montant_ttc' => 118000,
            'etape'             => 'Facture',
        ]);

        $sonRejet = FneRejet::consigner($saVente, $this->refus());

        $this->post(route('admin.fne.rejets.corriger_maintenant', $sonRejet))->assertNotFound();
    }

    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function refus(): array
    {
        return [
            'success' => false,
            'message' => 'La normalisation DGI a échoué (HTTP 400) : Invalid point of sale.',
            'errors'  => ['api_error' => json_encode([
                'errors' => ['pointOfSale' => ['invalid' => 'Point of sale is invalid']],
            ])],
        ];
    }

    private function uneEntreprise(string $nom, string $rccm, string $ncc): Entreprise
    {
        return Entreprise::create([
            'nom' => $nom, 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => $rccm, 'ncc' => $ncc, 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'comptabilite', 'points_de_vente'],
        ]);
    }

    private function uneVente(string $numero): Vente
    {
        return Vente::create([
            'point_de_vente_id' => $this->monMagasin->id,
            'client_id'         => $this->client->id,
            'numero_facture'    => $numero,
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 100000, 'montant_tva' => 18000, 'montant_ttc' => 118000,
            'etape'             => 'Facture',
        ]);
    }

    /** @param  array<int, string>  $noms */
    private function unReleve(array $noms, bool $avecFiche = true): PortailFneImport
    {
        $import = PortailFneImport::create([
            'entreprise_id'     => $this->mienne->id,
            'login'             => self::LOGIN,
            'date_scraping'     => '2026-08-31',
            'type'              => $avecFiche ? PortailFneImport::TYPE_FICHE : PortailFneImport::TYPE_POINTS,
            'fichier_nom'       => self::LOGIN . ($avecFiche ? '_20260831.json' : '_20260831.xlsx'),
            'fichier_empreinte' => hash('sha256', uniqid('', true)),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
        ]);

        // Sans fiche : c'est le cas ordinaire d'un second passage. Le tableur
        // et le JSON sont deux fichiers, et l'import n'ecrit que ce qui a
        // change — une fiche identique a la precedente n'est pas reecrite. Un
        // point renomme au portail fait donc bouger les points, et eux seuls.
        if ($avecFiche) {
            PortailFneFiche::create([
                'import_id'            => $import->id,
                'entreprise_id'        => $this->mienne->id,
                'login'                => self::LOGIN,
                'date_scraping'        => '2026-08-31',
                'timbre_quittance'     => true,
                'sticker_solde_alerte' => 5000,
            ]);
        }

        foreach ($noms as $nom) {
            PortailFnePointFacturation::create([
                'import_id'        => $import->id,
                'entreprise_id'    => $this->mienne->id,
                'login'            => self::LOGIN,
                'date_scraping'    => '2026-08-31',
                'nom'              => $nom,
                'outil'            => 'Application FNE',
                'statut'           => '1',
                'etablissement_id' => '42200613-f402-40a8-bd4d-a778bb5b96f0',
            ]);
        }

        return $import;
    }
}
