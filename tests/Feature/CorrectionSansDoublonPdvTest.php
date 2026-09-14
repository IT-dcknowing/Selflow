<?php

namespace Tests\Feature;

use App\Jobs\NormaliserFactureFne;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La correction ne duplique pas un point de vente qui existe déjà.
 *
 * Le portail déclare « FACTURATION SIEGE ». L'entreprise a bien ce point de
 * vente dans Selflow — mais la facture a été saisie sous un point mal nommé
 * (« TESTR »). Renommer « TESTR » en « FACTURATION SIEGE » poserait un second
 * point homonyme à côté du vrai : c'est précisément ce qui, en produisant, a
 * laissé trois « FACTURATION SIEGE » dans des villes différentes.
 *
 * La correction rattache donc la pièce au point **qui existe déjà**, et laisse
 * « TESTR » tel quel. Un seul point par nom, comme le portail n'en déclare
 * qu'un.
 */
class CorrectionSansDoublonPdvTest extends TestCase
{
    use RefreshDatabase;

    private const LOGIN = '1864699A';

    private Entreprise $entreprise;
    private PointDeVente $pointExistant;
    private PointDeVente $pointMalNomme;
    private Client $client;
    private Produit $article;
    private string $dossier;
    private $reponseDeLaDgi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'ncc' => self::LOGIN, 'regime_imposition' => 'RNI',
            'adresse' => 'Abidjan', 'rccm' => 'CI-ABJ-2026-B-1', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'], 'modules_actifs' => ['principal', 'ventes'],
        ]);

        FneCredential::create(['entreprise_id' => $this->entreprise->id, 'cle_test' => 'fne_test_cle', 'statut' => 'test']);

        // Le point réellement déclaré au portail, déjà présent dans Selflow.
        $this->pointExistant = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'FACTURATION SIEGE',
            'ville' => 'Abidjan', 'commune' => 'Cocody', 'statut' => 'Ouvert',
        ]);

        // Le point mal nommé, sous lequel la facture a été saisie par erreur.
        $this->pointMalNomme = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'TESTR',
            'ville' => 'Abidjan', 'commune' => 'Cocody', 'statut' => 'Ouvert',
        ]);

        $this->client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Client X']);
        $this->article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'A1', 'nom' => 'Article',
            'type' => 'service', 'unite' => 'u', 'prix_achat' => 5000, 'prix_vente' => 6500, 'taux_tva' => 18,
        ]);

        $this->dossier = storage_path('framework/testing/dedup-' . uniqid());
        mkdir($this->dossier, 0777, true);
        config(['selflow.portail_fne.dossier_import' => $this->dossier]);

        Http::fake(fn () => ($this->reponseDeLaDgi)());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dossier);
        parent::tearDown();
    }

    public function test_la_piece_est_rattachee_au_point_existant_sans_le_dupliquer(): void
    {
        // 1. La facture, sous le mauvais point, est refusée par la DGI.
        $this->reponseDeLaDgi = fn () => Http::response([
            'message' => 'Invalid point of sale',
            'errors'  => ['pointOfSale' => ['invalid' => 'Nom non déclaré']],
        ], 400);

        $vente = $this->uneFacture('FA-0001');
        (new NormaliserFactureFne($vente))->handle();

        $this->assertFalse((bool) $vente->refresh()->normalise);
        $rejet = FneRejet::sole();

        // 2. Le relevé déclare « FACTURATION SIEGE » — exactement le point qui
        // existe déjà dans Selflow.
        $this->deposerLeReleve('20260830', ['FACTURATION SIEGE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);

        // 3. La DGI certifiera au renvoi ; le rapprochement corrige.
        $this->reponseDeLaDgi = fn () => Http::response([
            'reference' => '9606123456789', 'token' => 'tok',
            'invoice_id' => 'b1f0a7c2-1111-4c3a-9d21-9f0c7a5e4d10',
            'document_url' => 'x', 'balance_sticker' => 4990,
        ], 200);

        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        // 4. Aucun doublon : toujours un seul « FACTURATION SIEGE ».
        $this->assertSame(1, PointDeVente::where('entreprise_id', $this->entreprise->id)
            ->where('nom', 'FACTURATION SIEGE')->count());

        // Le point mal nommé n'a pas été renommé.
        $this->assertSame('TESTR', $this->pointMalNomme->refresh()->nom);

        // La facture a été rattachée au point existant, et certifiée.
        $vente->refresh();
        $this->assertSame($this->pointExistant->id, $vente->point_de_vente_id);
        $this->assertTrue((bool) $vente->normalise);
        $this->assertSame(FneRejet::STATUT_RESOLU, $rejet->refresh()->statut);
    }

    private function uneFacture(string $numero): Vente
    {
        $vente = Vente::create([
            'point_de_vente_id' => $this->pointMalNomme->id, 'client_id' => $this->client->id,
            'numero_facture' => $numero, 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Espèces', 'montant_ht' => 65000, 'montant_tva' => 11700,
            'montant_ttc' => 76700, 'etape' => 'Facture',
        ]);
        VenteDetail::create([
            'vente_id' => $vente->id, 'produit_id' => $this->article->id, 'quantite' => 10,
            'unite' => 'u', 'prix_unitaire' => 6500, 'montant_tva' => 11700, 'montant_ttc' => 76700,
        ]);

        return $vente->fresh();
    }

    private function deposerLeReleve(string $date, array $points): void
    {
        file_put_contents(
            $this->dossier . DIRECTORY_SEPARATOR . self::LOGIN . '_' . $date . '.json',
            json_encode([
                'Email' => 'it@x.com', 'Téléphone' => '2722421443', 'Adresse' => '8XVQ+29Q',
                'Commune' => 'COCODY', 'Quartier' => 'RIV', 'Référence Cadastrale' => '*', 'IDU' => '*',
                "Propriétaire du local professionnel de l'entreprise" => null,
                "Sticker : solde d'alerte" => '5000', 'Références bancaires' => null,
                'Timbre de quittance' => true, "Bordereau d'achat de produits agricoles" => true,
                'Pied de page des factures' => null, 'Factures autres mentions' => null,
            ], JSON_UNESCAPED_UNICODE)
        );

        $lignes = [['Nom', 'Outil', 'ID du terminal', 'Statut', 'Raison de statut', "ID de l'établissement", 'Créé à', 'Mise à jour à']];
        foreach ($points as $nom) {
            $lignes[] = [$nom, 'Application FNE', '', '1', '', '42200613-f402-40a8-bd4d-a778bb5b96f0', '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z'];
        }

        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->getActiveSheet()->fromArray($lignes, null, 'A1');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save($this->dossier . DIRECTORY_SEPARATOR . self::LOGIN . '_' . $date . '.xlsx');
        $classeur->disconnectWorksheets();
    }
}
