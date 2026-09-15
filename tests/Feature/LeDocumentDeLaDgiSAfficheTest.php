<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La facture reçue se lit sans passer par le bouton « Exporter » du portail.
 *
 * Demandé par le propriétaire du projet le 08/09/2026, PDF réel à l'appui —
 * `1431650A26000000588_2026-09-04.pdf`, téléchargé à la main sur le portail de
 * la DGI faute de pouvoir le lire dans Selflow.
 *
 * ## Ce qui existait, et pourquoi ça ne suffisait pas
 *
 * | Bouton | Ce qu'il ouvrait |
 * |---|---|
 * | 👁 / ⬇ | la page de vérification de la DGI : bonne pour authentifier, pas pour lire |
 * | **Voir** | la pièce **reconstruite** par Selflow depuis le relevé |
 *
 * Aucun des deux ne rendait **le** document : celui que le fournisseur a établi
 * et que la plateforme a certifié. Il fallait aller l'exporter du portail.
 *
 * ## Ce que ces épreuves fixent
 *
 * | Situation | Ce qui doit se produire |
 * |---|---|
 * | le document est là | il est servi, en PDF, à l'écran (`inline`) |
 * | le document manque | on retombe sur la reconstruction, jamais sur un 404 |
 * | la colonne pointe un fichier disparu | idem : rien ne casse |
 * | `../` dans le nom | le chemin reste dans le dossier prévu |
 * | facture d'une autre entreprise | 404 — une pièce fiscale ne se lit pas de travers |
 * | le PDF arrive après le relevé | l'import le rattache quand même |
 * | le PDF n'a jamais été rapporté | la colonne reste nulle, sans erreur |
 */
class LeDocumentDeLaDgiSAfficheTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCE = '1431650A26000000588';

    protected function setUp(): void
    {
        parent::setUp();

        // Chaque épreuve part d'un dossier de dépôt vide : un PDF laissé par la
        // précédente ferait passer une assertion pour de mauvaises raisons.
        $dossier = $this->dossierPdf();

        if (is_dir($dossier)) {
            foreach (glob($dossier . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $reste) {
                @unlink($reste);
            }
        }
    }

    /* ------------------------------- L'affichage ------------------------------ */

    public function test_le_document_de_la_dgi_s_affiche_dans_le_navigateur(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise);
        $this->deposerLePdf(self::REFERENCE);
        $facture->update(['fichier_pdf' => self::REFERENCE . '.pdf']);

        $reponse = $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues.pdf', $facture))
            ->assertOk();

        $this->assertSame('application/pdf', $reponse->headers->get('content-type'));

        // `inline` et non `attachment` : la demande est de **voir**, pas de
        // télécharger. Un fichier qui se dépose dans les téléchargements oblige
        // à l'ouvrir depuis l'explorateur — c'est ce qu'on venait de supprimer.
        $this->assertStringContainsString(
            'inline',
            (string) $reponse->headers->get('content-disposition')
        );
    }

    public function test_sans_document_on_retombe_sur_la_reconstruction(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise);

        // 404 serait la réponse facile, et la mauvaise : l'utilisateur veut lire
        // sa facture, et Selflow sait la lui montrer même sans le document.
        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues.pdf', $facture))
            ->assertRedirect(route('admin.achats.factures_recues.imprimer', $facture));
    }

    public function test_un_fichier_disparu_du_disque_ne_casse_rien(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        // La colonne pointe, le fichier n'est plus là : sauvegarde restaurée à
        // moitié, dossier de dépôt vidé, poste changé.
        $facture = $this->uneFactureRecue($entreprise, ['fichier_pdf' => 'disparu.pdf']);

        $this->assertFalse($facture->pdfDisponible());
        $this->assertNull($facture->cheminDuPdf());

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues.pdf', $facture))
            ->assertRedirect(route('admin.achats.factures_recues.imprimer', $facture));
    }

    public function test_le_nom_du_fichier_ne_sort_pas_du_dossier_prevu(): void
    {
        [, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise, [
            'fichier_pdf' => '../../../../.env',
        ]);

        // La colonne est écrite par l'import, mais elle est en base : une valeur
        // forgée ne doit pas faire servir n'importe quel fichier du disque.
        $this->assertNull($facture->cheminDuPdf());
    }

    public function test_la_facture_d_une_autre_entreprise_ne_se_lit_pas(): void
    {
        [$utilisateur] = $this->uneEntrepriseAvecUtilisateur();
        [, $autre]     = $this->uneEntrepriseAvecUtilisateur('9999999Z', 'AUTRE SARL');

        $facture = $this->uneFactureRecue($autre);
        $this->deposerLePdf(self::REFERENCE);
        $facture->update(['fichier_pdf' => self::REFERENCE . '.pdf']);

        // Une pièce fiscale lue par le mauvais client ne se répare pas.
        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures_recues.pdf', $facture))
            ->assertNotFound();
    }

    /* -------------------------------- L'écran --------------------------------- */

    public function test_l_ecran_des_achats_mene_au_document_quand_il_est_la(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise);
        $this->deposerLePdf(self::REFERENCE);
        $facture->update(['fichier_pdf' => self::REFERENCE . '.pdf']);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures'))
            ->assertOk()
            ->assertSee(route('admin.achats.factures_recues.pdf', $facture), false);
    }

    public function test_l_ecran_des_achats_mene_a_la_reconstruction_sinon(): void
    {
        [$utilisateur, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise);

        $this->actingAs($utilisateur)
            ->get(route('admin.achats.factures'))
            ->assertOk()
            ->assertSee(route('admin.achats.factures_recues.imprimer', $facture), false)
            ->assertDontSee(route('admin.achats.factures_recues.pdf', $facture), false);
    }

    /* -------------------------------- L'import -------------------------------- */

    public function test_un_document_arrive_apres_le_releve_est_rattache(): void
    {
        [, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise);
        $this->assertNull($facture->fichier_pdf);

        // Le relevé et le document ne voyagent pas ensemble : le JSON est réécrit
        // à chaque passage, le PDF déposé une seule fois, plus tard. L'empreinte
        // du contenu ne bouge donc pas — et c'est le chemin « inchangé » qui est
        // pris, celui qui court-circuitait le rangement.
        $this->deposerLePdf(self::REFERENCE);
        $this->deposerLeReleve($entreprise->ncc, '20260908');

        app(ImportFacturesRecuesService::class)->importerDossier();
        app(ImportFacturesRecuesService::class)->importerDossier();

        $this->assertSame(self::REFERENCE . '.pdf', $facture->fresh()->fichier_pdf);
    }

    public function test_sans_document_la_colonne_reste_nulle_sans_erreur(): void
    {
        [, $entreprise] = $this->uneEntrepriseAvecUtilisateur();

        $facture = $this->uneFactureRecue($entreprise);
        $this->deposerLeReleve($entreprise->ncc, '20260908');

        $rapport = app(ImportFacturesRecuesService::class)->importerDossier();

        $this->assertSame(0, $rapport['erreurs']);
        $this->assertNull($facture->fresh()->fichier_pdf);
    }

    /* -------------------------------- Le décor -------------------------------- */

    private function dossierPdf(): string
    {
        return rtrim((string) config('selflow.portail_fne.dossier_import'), "/\\")
            . DIRECTORY_SEPARATOR . 'achats' . DIRECTORY_SEPARATOR . 'pdf';
    }

    /** Un PDF minimal mais valide, déposé là où le scraper dépose. */
    private function deposerLePdf(string $reference): string
    {
        $dossier = $this->dossierPdf();

        if (!is_dir($dossier)) {
            mkdir($dossier, 0777, true);
        }

        $chemin = $dossier . DIRECTORY_SEPARATOR . $reference . '.pdf';
        file_put_contents($chemin, "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        return $chemin;
    }

    /** Un relevé portant la pièce, du même moule que celui du scraper. */
    private function deposerLeReleve(string $login, string $date): string
    {
        $dossier = rtrim((string) config('selflow.portail_fne.dossier_import'), "/\\")
            . DIRECTORY_SEPARATOR . 'achats';

        if (!is_dir($dossier)) {
            mkdir($dossier, 0777, true);
        }

        $chemin = $dossier . DIRECTORY_SEPARATOR . "{$login}_{$date}.json";

        file_put_contents($chemin, json_encode([
            'login'    => $login,
            'source'   => '/ws/invoices?listing=received',
            'periode'  => ['du' => '2024-01-01', 'au' => '2026-09-08'],
            'factures' => [[
                'reference'        => self::REFERENCE,
                'id'               => 'abc',
                'token'            => '01a06bf8-8e1a-7000-8650-7ae311524dfc',
                'type'             => 'invoice',
                'subtype'          => 'normal',
                'date'             => '2026-09-04T10:00:00.000Z',
                'totalBeforeTaxes' => 244000,
                'totalAfterTaxes'  => 244000,
                'totalDue'         => 244000,
                'company'          => ['ncc' => '1431650A', 'name' => "CENTRE IVOIRIEN D'ARCHIVAGE NUMERIQUE"],
                'items'            => [],
            ]],
        ], JSON_UNESCAPED_UNICODE));

        return $chemin;
    }

    /** @return array{0: Utilisateur, 1: Entreprise, 2: PointDeVente} */
    private function uneEntrepriseAvecUtilisateur(string $ncc = '1864699A', string $nom = 'DC-KNOWING CGA'): array
    {
        $entreprise = Entreprise::create([
            'nom'               => $nom,
            'ncc'               => $ncc,
            'regime_imposition' => 'RNI',
            'adresse'           => 'RIVIERA II AFRICAINE',
            'rccm'              => 'CI-ABJ-2026-B-' . random_int(10000, 99999),
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats', 'comptabilite'],
        ]);

        $pdv = PointDeVente::create([
            'entreprise_id' => $entreprise->id,
            'nom'           => 'FACTURATION SIEGE',
            'ville'         => 'Abidjan',
            'commune'       => 'Cocody',
        ]);

        $utilisateur = Utilisateur::create([
            'nom'               => 'Yao',
            'prenom'            => 'Adjoua',
            'email'             => 'test' . random_int(1000, 999999) . '@exemple.ci',
            'password'          => bcrypt('secret-de-test'),
            'role'              => 'admin',
            'entreprise_id'     => $entreprise->id,
            'point_de_vente_id' => $pdv->id,
        ]);

        return [$utilisateur, $entreprise, $pdv];
    }

    /** @param array<string, mixed> $attributs */
    private function uneFactureRecue(Entreprise $entreprise, array $attributs = []): PortailFneFactureRecue
    {
        $import = PortailFneImport::create([
            'entreprise_id'     => $entreprise->id,
            'login'             => $entreprise->ncc,
            'date_scraping'     => '2026-09-04',
            'type'              => ImportFacturesRecuesService::TYPE,
            'fichier_nom'       => $entreprise->ncc . '_20260904.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
            'dernier_releve_le' => '2026-09-04',
        ]);

        return PortailFneFactureRecue::create(array_merge([
            'import_id'            => $import->id,
            'entreprise_id'        => $entreprise->id,
            'login'                => $entreprise->ncc,
            'date_scraping'        => '2026-09-04',
            'reference'            => self::REFERENCE,
            'token'                => '01a06bf8-8e1a-7000-8650-7ae311524dfc',
            'type'                 => 'invoice',
            'subtype'              => 'normal',
            'date_facture'         => '2026-09-04 10:00:00',
            'emetteur_ncc'         => '1431650A',
            'emetteur_nom'         => "CENTRE IVOIRIEN D'ARCHIVAGE NUMERIQUE",
            'montant_ht'           => 244000,
            'montant_tva'          => 0,
            'montant_ttc'          => 244000,
            'net_a_payer'          => 244000,
            'statut_rapprochement' => PortailFneFactureRecue::A_RAPPROCHER,
        ], $attributs));
    }
}
