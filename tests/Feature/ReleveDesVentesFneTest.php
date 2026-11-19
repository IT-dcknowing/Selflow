<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\EcritureFactureRecueService;
use App\Modules\Admin\Services\ImportFacturesEmisesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleveDesVentesFneTest extends TestCase
{
    use RefreshDatabase;

    private const NCC = '1864699A';

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('framework/testing/ventes-fne-' . uniqid());
        mkdir($this->dossier, 0777, true);

        Entreprise::create([
            'nom' => 'ENTREPRISE VENTES FNE TEST',
            'regime_imposition' => 'RNI',
            'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'ncc' => self::NCC,
            'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        @rmdir($this->dossier);

        parent::tearDown();
    }

    public function test_les_factures_emises_sont_importees_sans_creer_de_vente_ni_ecriture(): void
    {
        $reference = 'FNE-VENTE-EXTERNE-001';
        $payload = [
            'login' => self::NCC,
            'source' => '/ws/invoices?subtype=normal&listing=issued',
            'periode' => ['du' => '2026-01-01', 'au' => '2026-11-19'],
            'factures' => [[
                'reference' => $reference,
                'id' => 'fne-vente-externe-001',
                'token' => 'token-vente-externe-001',
                'type' => 'invoice',
                'subtype' => 'normal',
                'listing_source' => 'issued',
                'nature_document' => 'vente',
                'date' => '2026-11-18T10:00:00Z',
                'totalBeforeTaxes' => 10000,
                'totalTaxes' => 1800,
                'totalAfterTaxes' => 11800,
                'totalDue' => 11800,
                'company' => ['ncc' => self::NCC, 'name' => 'ENTREPRISE VENTES FNE TEST'],
                'clientCompany' => ['ncc' => '9999999X', 'name' => 'CLIENT EXTERNE'],
            ]],
        ];

        file_put_contents(
            $this->dossier . '/' . self::NCC . '_20261119.json',
            json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $rapport = app(ImportFacturesEmisesService::class)->importerDossier($this->dossier, self::NCC);

        $this->assertSame(1, $rapport['importes']);
        $this->assertDatabaseHas('portail_fne_imports', [
            'login' => self::NCC,
            'type' => ImportFacturesEmisesService::TYPE,
        ]);
        $this->assertDatabaseHas('portail_fne_factures_recues', [
            'login' => self::NCC,
            'reference' => $reference,
            'liste_portail' => 'issued',
            'subtype' => 'normal',
        ]);

        EcritureFactureRecueService::pourEntreprise(
            Entreprise::where('ncc', self::NCC)->value('id')
        );

        $this->assertSame(0, Vente::where('numero_fne', $reference)->count());
        $this->assertSame(0, EcritureComptable::where('reference_document', $reference)->count());

        // Le même fichier ne crée pas une seconde pièce lors d'une actualisation.
        $second = app(ImportFacturesEmisesService::class)->importerDossier($this->dossier, self::NCC);
        $this->assertSame(1, $second['ignores']);
        $this->assertSame(1, PortailFneFactureRecue::where('reference', $reference)->count());
    }
}
