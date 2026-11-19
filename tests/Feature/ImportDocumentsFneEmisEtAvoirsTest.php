<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\ImportAvoirsService;
use App\Modules\Admin\Services\ImportFacturesEmisesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les relevés de ventes émises et d'avoirs fournisseurs sont des registres
 * documentaires : ils conservent les pièces déjà certifiées par la FNE.
 * Aucun de ces imports ne crée de vente, d'achat ni ne déclenche une
 * normalisation.
 */
class ImportDocumentsFneEmisEtAvoirsTest extends TestCase
{
    use RefreshDatabase;

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('framework/testing/fne-emis-' . uniqid());
        mkdir($this->dossier, 0777, true);
        config(['selflow.portail_fne.dossier_import' => $this->dossier]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            if (is_file($fichier)) unlink($fichier);
        }
        @rmdir($this->dossier . '/ventes');
        @rmdir($this->dossier . '/avoirs');
        @rmdir($this->dossier);

        parent::tearDown();
    }

    public function test_les_factures_de_vente_fne_sont_archivees_sans_creer_ni_normaliser_une_vente(): void
    {
        $entreprise = $this->uneEntreprise('1864699A');
        $dossier = $this->dossier . '/ventes';
        mkdir($dossier, 0777, true);

        $this->poser($dossier, '1864699A_20261119.json', [
            'login' => '1864699A',
            'source' => '/ws/invoices?listing=issued',
            'periode' => ['du' => '2026-01-01', 'au' => '2026-11-19'],
            'factures' => [$this->unePiece([
                'reference' => 'FNE-VENTE-001',
                'listing_source' => 'issued',
                'nature_document' => 'vente',
                'clientNcc' => '0000001X',
            ])],
        ]);

        $rapport = app(ImportFacturesEmisesService::class)->importerDossier($dossier, '1864699A');

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(0, $rapport['erreurs']);

        $piece = PortailFneFactureRecue::sole();
        $this->assertSame($entreprise->id, $piece->entreprise_id);
        $this->assertSame('issued', $piece->liste_portail);
        $this->assertSame('normal', $piece->subtype);
        $this->assertSame('FNE-VENTE-001', $piece->reference);

        // Le relevé constate les ventes certifiées, il ne les ressaisit pas.
        $this->assertSame(0, Vente::count());
        $this->assertSame(0, Achat::count());
    }

    public function test_les_avoirs_fournisseurs_sont_recuperes_sans_ecriture_ni_normalisation_et_sans_doublon(): void
    {
        $entreprise = $this->uneEntreprise('1864699A');
        $dossier = $this->dossier . '/avoirs';
        mkdir($dossier, 0777, true);

        $enveloppe = [
            'login' => '1864699A',
            'source' => '/ws/invoices?subtype=refund&listing=received',
            'periode' => ['du' => '2026-01-01', 'au' => '2026-11-19'],
            'factures' => [$this->unePiece([
                'reference' => 'FNE-AVOIR-FOURN-001',
                'listing_source' => 'received',
                'nature_avoir' => 'achat',
                'subtype' => 'refund',
                'parentReference' => 'FNE-ACHAT-ORIG-001',
                'company' => [
                    'id' => 'fournisseur-1',
                    'name' => 'FOURNISSEUR SARL',
                    'ncc' => '0000001X',
                ],
            ])],
        ];

        $this->poser($dossier, '1864699A_20261119.json', $enveloppe);

        $service = app(ImportAvoirsService::class);
        $premier = $service->importerDossier($dossier, '1864699A');

        $this->assertSame(1, $premier['importes']);
        $this->assertSame(0, $premier['erreurs']);

        $piece = PortailFneFactureRecue::sole();
        $this->assertSame($entreprise->id, $piece->entreprise_id);
        $this->assertSame('received', $piece->liste_portail);
        $this->assertSame('refund', $piece->subtype);
        $this->assertSame('FNE-AVOIR-FOURN-001', $piece->reference);
        $this->assertSame('0000001X', $piece->emetteur_ncc);
        $this->assertNull($piece->achat_id);

        // Un avoir reçu est déjà certifié par la FNE. Le relever ne crée
        // aucune pièce locale et n'écrit dans aucun journal comptable.
        $this->assertSame(0, Vente::count());
        $this->assertSame(0, Achat::count());

        $this->poser($dossier, '1864699A_20261120.json', $enveloppe);
        $second = $service->importerDossier($dossier, '1864699A');

        $this->assertSame(0, $second['importes']);
        $this->assertSame(1, PortailFneFactureRecue::where('reference', 'FNE-AVOIR-FOURN-001')->count());
    }

    private function uneEntreprise(string $ncc): Entreprise
    {
        return Entreprise::create([
            'nom' => 'DC-KNOWING CGA',
            'ncc' => $ncc,
            'regime_imposition' => 'RNI',
            'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats'],
        ]);
    }

    private function unePiece(array $remplacements = []): array
    {
        return array_replace([
            'id' => 'fne-id-001',
            'token' => 'fne-token-001',
            'reference' => 'FNE-PIECE-001',
            'type' => 'invoice',
            'subtype' => 'normal',
            'date' => '2026-11-19T09:30:00.000Z',
            'paymentMethod' => 'cash',
            'totalBeforeTaxes' => 10000,
            'totalDiscounted' => 0,
            'totalTaxes' => 1800,
            'fiscalStamp' => 0,
            'totalCustomTaxes' => 0,
            'totalAfterTaxes' => 11800,
            'totalDue' => 11800,
            'status' => 'paid',
            'rne' => false,
            'items' => [],
            'company' => [
                'id' => 'fournisseur-1',
                'name' => 'FOURNISSEUR SARL',
                'ncc' => '0000001X',
            ],
        ], $remplacements);
    }

    private function poser(string $dossier, string $nom, array $contenu): void
    {
        file_put_contents(
            $dossier . DIRECTORY_SEPARATOR . $nom,
            json_encode($contenu, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
}
