<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneFactureRecueLigne;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce qu'un relevé de factures reçues devient une fois rangé.
 *
 * Les enregistrements employés ici sont ceux du **vrai** portail, relevés le
 * 27/08/2026 : mêmes noms de champs, mêmes types, même forme de `items[]`. Un
 * test bâti sur une forme inventée ne vérifierait que l'imagination de celui
 * qui l'a écrit.
 */
class ImportFacturesRecuesTest extends TestCase
{
    use RefreshDatabase;

    private string $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dossier = storage_path('framework/testing/achats-fne-' . uniqid());
        mkdir($this->dossier, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        @rmdir($this->dossier);

        parent::tearDown();
    }

    public function test_une_facture_recue_est_rangee_avec_son_emetteur_et_ses_lignes(): void
    {
        $entreprise = $this->uneEntreprise(['ncc' => '1864699 A']);

        $this->poser('1864699A_20260827.json', $this->enveloppe([$this->uneFacture()]));

        $rapport = app(ImportFacturesRecuesService::class)->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(0, $rapport['erreurs']);

        $facture = PortailFneFactureRecue::sole();

        $this->assertSame($entreprise->id, $facture->entreprise_id);
        $this->assertSame('B0000001X26000000042', $facture->reference);
        $this->assertSame('01a04306-e47e-7000-8275-49aa4b9318e3', $facture->token);
        $this->assertSame('invoice', $facture->type);
        $this->assertSame('normal', $facture->subtype);
        $this->assertFalse($facture->est_rne);
        $this->assertSame('27/08/2026', $facture->date_facture->format('d/m/Y'));

        // L'émetteur vient de `company` : c'est le fournisseur, et son NCC est
        // la clé du rapprochement.
        $this->assertSame('0000001X', $facture->emetteur_ncc);
        $this->assertSame('FOURNISSEUR SARL', $facture->emetteur_nom);

        // Les montants sont des nombres, pas des libellés formatés.
        $this->assertSame('10000.00', $facture->montant_ht);
        $this->assertSame('1800.00', $facture->montant_tva);
        $this->assertSame('11800.00', $facture->montant_ttc);

        $ligne = PortailFneFactureRecueLigne::sole();

        $this->assertSame('Cartouches encre', $ligne->designation);
        $this->assertSame('2.000', $ligne->quantite);
        $this->assertSame('5000.00', $ligne->prix_unitaire);
        $this->assertSame('1800.00', $ligne->montant_tva);
        $this->assertSame(10000.0, $ligne->montantHt());
    }

    public function test_un_second_releve_met_a_jour_la_facture_au_lieu_de_la_dupliquer(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);
        $service = app(ImportFacturesRecuesService::class);

        $this->poser('1864699A_20260827.json', $this->enveloppe([$this->uneFacture()]));
        $service->importerDossier($this->dossier);

        // Le lendemain, la même facture — et une seconde qui vient d'arriver.
        // Une facture est un fait : elle ne s'enregistre pas deux fois.
        $this->poser('1864699A_20260828.json', $this->enveloppe([
            $this->uneFacture(['status' => 'paid', 'updatedAt' => '2026-08-28T09:00:00.000Z']),
            $this->uneFacture([
                'reference' => 'B0000001X26000000043',
                'id'        => 'aaaaaaaa-0000-4000-8000-000000000002',
            ]),
        ]));

        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(2, PortailFneFactureRecue::count());
        $this->assertSame('paid', PortailFneFactureRecue::where('reference', 'B0000001X26000000042')->sole()->statut_portail);

        // Les lignes sont refaites, pas empilées.
        $this->assertSame(2, PortailFneFactureRecueLigne::count());
    }

    public function test_un_releve_identique_ne_cree_aucune_ligne_supplementaire(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);
        $service = app(ImportFacturesRecuesService::class);

        $this->poser('1864699A_20260827.json', $this->enveloppe([$this->uneFacture()]));
        $service->importerDossier($this->dossier);

        // Même contenu, écrit autrement : période élargie d'un jour, clés dans
        // un autre ordre. Le fichier diffère, ce que le portail dit non.
        $this->poser('1864699A_20260828.json', json_encode([
            'periode'  => ['du' => '2023-01-01', 'au' => '2026-08-28'],
            'factures' => [$this->uneFacture()],
            'login'    => '1864699A',
        ], JSON_UNESCAPED_UNICODE));

        $rapport = $service->importerDossier($this->dossier);

        $this->assertSame(0, $rapport['importes']);
        $this->assertSame(1, $rapport['inchanges']);

        // Pas une ligne de plus, nulle part.
        $this->assertSame(1, PortailFneImport::count());
        $this->assertSame(1, PortailFneFactureRecue::count());

        $import = PortailFneImport::sole();

        $this->assertSame('27/08/2026', $import->date_scraping->format('d/m/Y'));
        $this->assertSame('28/08/2026', $import->dernier_releve_le->format('d/m/Y'));
        $this->assertSame(2, $import->releves);
    }

    public function test_un_releve_vide_est_une_reponse_et_non_une_erreur(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $this->poser('1864699A_20260827.json', $this->enveloppe([]));

        $rapport = app(ImportFacturesRecuesService::class)->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['importes']);
        $this->assertSame(0, $rapport['erreurs']);
        $this->assertSame(0, PortailFneFactureRecue::count());
    }

    public function test_un_fichier_sans_cle_factures_est_refuse(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        // Une page d'erreur ou un relevé tronqué ne doit pas se lire comme
        // « aucune facture reçue » : le silence serait pris pour un succès.
        $this->poser('1864699A_20260827.json', json_encode(['erreur' => 'session expirée']));

        $rapport = app(ImportFacturesRecuesService::class)->importerDossier($this->dossier);

        $this->assertSame(1, $rapport['erreurs']);
        $this->assertSame(PortailFneImport::STATUT_ERREUR, PortailFneImport::sole()->statut);
    }

    public function test_une_piece_sans_numero_fne_est_ecartee_et_signalee(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $this->poser('1864699A_20260827.json', $this->enveloppe([
            $this->uneFacture(),
            $this->uneFacture(['reference' => '']),
        ]));

        app(ImportFacturesRecuesService::class)->importerDossier($this->dossier);

        // Sans référence, une pièce n'a pas d'identité : impossible de la
        // reconnaître au relevé suivant ni de détecter un doublon de saisie.
        $this->assertSame(1, PortailFneFactureRecue::count());
    }

    public function test_le_bordereau_d_achat_ne_porte_pas_de_tva_deductible(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $this->poser('1864699A_20260827.json', $this->enveloppe([
            $this->uneFacture(['subtype' => 'purchase_slip', 'totalTaxes' => 0]),
        ]));

        app(ImportFacturesRecuesService::class)->importerDossier($this->dossier);

        $facture = PortailFneFactureRecue::sole();

        $this->assertFalse($facture->tvaDeductible());
        $this->assertSame("Bordereau d'achat", $facture->libelleDuSousType());
    }

    public function test_un_emetteur_sans_ncc_rend_la_facture_orpheline(): void
    {
        $this->uneEntreprise(['ncc' => '1864699A']);

        $facture = $this->uneFacture();
        $facture['company']['ncc'] = '';

        $this->poser('1864699A_20260827.json', $this->enveloppe([$facture]));

        app(ImportFacturesRecuesService::class)->importerDossier($this->dossier);

        // Sans NCC, aucun fournisseur ne peut être retrouvé : le dire tout de
        // suite vaut mieux que de présenter la pièce comme « à rapprocher ».
        $this->assertSame(
            PortailFneFactureRecue::ORPHELINE,
            PortailFneFactureRecue::sole()->statut_rapprochement
        );
    }

    public function test_le_fournisseur_est_retrouve_par_son_ncc_et_non_par_son_nom(): void
    {
        $entreprise = $this->uneEntreprise(['ncc' => '1864699A']);

        // Le NCC s'écrit avec un espace côté Selflow, sans espace au portail —
        // et la raison sociale ne s'écrit pas pareil des deux côtés non plus.
        $fournisseur = Fournisseur::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'Fournisseur S.A.R.L.',
            'ncc'           => '0000001 X',
        ]);

        $this->poser('1864699A_20260827.json', $this->enveloppe([$this->uneFacture()]));

        app(ImportFacturesRecuesService::class)->importerDossier($this->dossier);

        $this->assertSame($fournisseur->id, PortailFneFactureRecue::sole()->fournisseurProbable()?->id);
    }

    public function test_le_releve_ne_cree_aucun_achat(): void
    {
        $entreprise = $this->uneEntreprise(['ncc' => '1864699A']);

        Fournisseur::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'FOURNISSEUR SARL',
            'ncc'           => '0000001X',
        ]);

        $this->poser('1864699A_20260827.json', $this->enveloppe([$this->uneFacture()]));

        app(ImportFacturesRecuesService::class)->importerDossier($this->dossier);

        // La règle d'or : un constat, pas une décision. Créer l'achat tout seul
        // produirait des écritures comptables parce qu'un fichier est arrivé
        // dans un dossier — et doublonnerait une saisie probablement déjà faite.
        $this->assertSame(0, \App\Modules\Admin\Modeles\Achat::count());
        $this->assertNull(PortailFneFactureRecue::sole()->achat_id);
    }

    /* -------------------------------------------------------------------- */

    /**
     * Un enregistrement de l'API du portail, à la forme relevée le 27/08/2026.
     *
     * @param  array<string, mixed>  $remplacements
     * @return array<string, mixed>
     */
    private function uneFacture(array $remplacements = []): array
    {
        return array_replace([
            'id'               => 'aaaaaaaa-0000-4000-8000-000000000001',
            'parentId'         => null,
            'token'            => '01a04306-e47e-7000-8275-49aa4b9318e3',
            'reference'        => 'B0000001X26000000042',
            'type'             => 'invoice',
            'subtype'          => 'normal',
            'date'             => '2026-08-27T11:42:00.277Z',
            'paymentMethod'    => 'cash',
            'amount'           => 10000,
            'vatAmount'        => 1800,
            'fiscalStamp'      => 0,
            'discount'         => 0,
            'totalBeforeTaxes' => 10000,
            'totalDiscounted'  => 0,
            'totalTaxes'       => 1800,
            'totalAfterTaxes'  => 11800,
            'totalCustomTaxes' => 0,
            'totalDue'         => 11800,
            'clientNcc'        => '1864699A',
            'clientCompanyName' => 'DC-KNOWING CGA',
            'status'           => 'pending',
            'template'         => 'B2B',
            'isRne'            => false,
            'rne'              => '',
            'foreignCurrency'  => '',
            'foreignCurrencyRate' => 0,
            'createdAt'        => '2026-08-27T11:42:00.277Z',
            'updatedAt'        => '2026-08-27T11:42:00.334Z',
            'items'            => [[
                'id'              => 'bbbbbbbb-0000-4000-8000-000000000001',
                'quantity'        => 2,
                'reference'       => 'ENC-005',
                'description'     => 'Cartouches encre',
                'amount'          => 5000,
                'discount'        => 0,
                'measurementUnit' => 'Unité',
                'taxes'           => [['name' => 'TVA', 'amount' => 1800]],
                'customTaxes'     => [],
            ]],
            'customTaxes'      => [],
            'company'          => [
                'id'   => 'cccccccc-0000-4000-8000-000000000001',
                'name' => 'FOURNISSEUR SARL',
                'ncc'  => '0000001X',
                'rccm' => 'CI-ABJ-2020-B-11111',
            ],
        ], $remplacements);
    }

    /** @param  array<int, array<string, mixed>>  $factures */
    private function enveloppe(array $factures): string
    {
        return json_encode([
            'login'    => '1864699A',
            'source'   => '/ws/invoices?listing=received',
            'periode'  => ['du' => '2024-01-01', 'au' => '2026-08-27'],
            'factures' => $factures,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** @param  array<string, mixed>  $attributs */
    private function uneEntreprise(array $attributs = []): Entreprise
    {
        return Entreprise::create(array_merge([
            'nom'               => 'DC-KNOWING CGA',
            'regime_imposition' => 'RNI',
            'adresse'           => 'RIVIERA II AFRICAINE',
            'rccm'              => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats'],
        ], $attributs));
    }

    private function poser(string $nom, string $contenu): void
    {
        file_put_contents($this->dossier . DIRECTORY_SEPARATOR . $nom, $contenu);
    }
}
