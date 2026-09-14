<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneDemande;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\DiagnosticFneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La chaîne qui va d'un refus de la DGI à une phrase qu'on peut lire.
 *
 * Elle a quatre maillons, et chacun se vérifie ici :
 *
 * 1. **Le rejet est consigné.** Il ne l'était pas : le message détaillé
 *    assemblé par `FneService::messageRejet()` finissait dans un fichier de
 *    log. On ne diagnostique pas ce qu'on n'a pas gardé.
 * 2. **Le rejet ouvre une demande de relevé**, et dix rejets identiques n'en
 *    ouvrent qu'une : une soirée de saisie remplirait sinon la file de demandes
 *    jumelles.
 * 3. **Le relevé qui arrive sert la demande**, et rien d'autre ne la ferme.
 * 4. **Le rapprochement dit ce qu'il a vu, sans rien corriger.** Le portail et
 *    la pièce sont comparés ; l'entreprise n'est pas touchée. C'est le point
 *    que la règle d'or protège : `timbre_quittance` et `bapa` changent ce qui
 *    part à la DGI, et une facture ne change pas parce qu'un fichier est
 *    arrivé dans un dossier.
 *
 * Depuis le 29/08/2026, un cinquième maillon suit les quatre : `CorrectionFneService`
 * applique **le seul nom du point de vente** et renvoie les pièces. Il vit
 * ailleurs, et s'éprouve ailleurs — `CycleFneTroisCasTest`. Les épreuves d'ici
 * qui pilotent la commande l'éteignent, pour garder leur sujet : ce que le
 * rapprochement constate, et non ce qu'on en fait ensuite.
 */
class DiagnosticRejetFneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $pointDeVente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom'               => 'DC-KNOWING CGA',
            'ncc'               => '1864699A',
            'regime_imposition' => 'RNI',
            'adresse'           => 'RIVIERA II AFRICAINE',
            'rccm'              => 'CI-ABJ-2026-B-12345',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes'],
        ]);

        // Le nom est écrit sans accent côté Selflow ; le portail l'accentue.
        // C'est le rejet observé en pratique sur `pointOfSale`.
        $this->pointDeVente = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom'           => 'FACTURATION SIEGE',
            'ville'         => 'Abidjan',
            'commune'       => 'Cocody',
            'responsable'   => 'Ali Hassan',
            'statut'        => 'Ouvert',
        ]);
    }

    public function test_un_rejet_est_consigne_et_ouvre_une_demande_de_releve(): void
    {
        $vente = $this->uneVente('FA-0042');

        $rejet = FneRejet::consigner($vente, $this->refusDeLaPlateforme());

        $this->assertNotNull($rejet);
        $this->assertSame('vente', $rejet->piece_type);
        $this->assertSame('FA-0042', $rejet->numero_piece);
        $this->assertSame('1864699A', $rejet->login);
        $this->assertSame(['pointOfSale'], $rejet->nomsDesChamps());
        $this->assertTrue($rejet->estOuvert());

        $demande = PortailFneDemande::sole();

        $this->assertSame('1864699A', $demande->login);
        $this->assertSame($rejet->id, $demande->rejet_id);
        $this->assertStringContainsString('FA-0042', $demande->motif);
        $this->assertSame(PortailFneDemande::STATUT_EN_ATTENTE, $demande->statut);
    }

    public function test_plusieurs_rejets_de_suite_n_ouvrent_qu_une_demande(): void
    {
        foreach (['FA-0042', 'FA-0043', 'FA-0044'] as $numero) {
            FneRejet::consigner($this->uneVente($numero), $this->refusDeLaPlateforme());
        }

        $this->assertSame(3, FneRejet::count());
        $this->assertSame(1, PortailFneDemande::count());
    }

    public function test_une_normalisation_reussie_ne_consigne_rien(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0045'), ['success' => true]);

        $this->assertNull($rejet);
        $this->assertSame(0, FneRejet::count());
        $this->assertSame(0, PortailFneDemande::count());
    }

    public function test_le_releve_qui_arrive_sert_la_demande(): void
    {
        FneRejet::consigner($this->uneVente('FA-0042'), $this->refusDeLaPlateforme());

        $import = $this->unReleve();

        $demande = PortailFneDemande::sole();

        $this->assertSame(PortailFneDemande::STATUT_SERVIE, $demande->statut);
        $this->assertSame($import->id, $demande->import_id);
        $this->assertNotNull($demande->servie_at);
    }

    public function test_le_rapprochement_nomme_le_point_de_vente_declare(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refusDeLaPlateforme());

        $this->unReleve();

        $diagnostic = app(DiagnosticFneService::class)->diagnostiquer($rejet);

        $this->assertSame('21/08/2026', $diagnostic['releve']['date']);

        $champ = $diagnostic['champs'][0];

        $this->assertSame('pointOfSale', $champ['champ']);
        $this->assertSame('ecart', $champ['verdict']);
        $this->assertSame('FACTURATION SIEGE', $champ['envoye']);
        $this->assertContains('FACTURATION SIÈGE', $champ['portail']);

        // Le point fermé au portail n'est pas proposé comme candidat.
        $this->assertNotContains('FACTURATION FERMEE', $champ['portail']);

        // La phrase doit nommer les deux valeurs : c'est tout l'intérêt.
        $this->assertStringContainsString('FACTURATION SIEGE', $champ['explication']);
        $this->assertStringContainsString('FACTURATION SIÈGE', $champ['explication']);
    }

    public function test_un_champ_que_le_portail_n_affiche_pas_est_dit_hors_portee(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), [
            'success' => false,
            'message' => 'La normalisation DGI a échoué (HTTP 400).',
            'errors'  => ['api_error' => json_encode([
                'errors' => ['clientNcc' => ['required' => 'Client NCC is required']],
            ])],
        ]);

        $this->unReleve();

        $diagnostic = app(DiagnosticFneService::class)->diagnostiquer($rejet);

        $this->assertSame('hors_portee', $diagnostic['champs'][0]['verdict']);
    }

    public function test_sans_releve_le_diagnostic_le_dit_au_lieu_de_conclure(): void
    {
        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refusDeLaPlateforme());

        $diagnostic = app(DiagnosticFneService::class)->diagnostiquer($rejet);

        $this->assertNull($diagnostic['releve']);
        $this->assertSame('sans_releve', $diagnostic['champs'][0]['verdict']);
        $this->assertStringContainsString('Aucun relevé', $diagnostic['conclusion']);
    }

    public function test_le_rapprochement_ne_touche_pas_a_l_entreprise(): void
    {
        $this->entreprise->update([
            'timbre_quittance'     => false,
            'sticker_solde_alerte' => 5,
        ]);

        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refusDeLaPlateforme());

        $this->unReleve();

        $diagnostic = app(DiagnosticFneService::class)->diagnostiquer($rejet);

        $this->entreprise->refresh();

        // Le portail dit l'inverse sur les deux ; l'entreprise n'a pas bougé.
        $this->assertFalse((bool) $this->entreprise->timbre_quittance);
        $this->assertSame(5, (int) $this->entreprise->sticker_solde_alerte);

        // L'écart est montré, il n'est pas appliqué.
        $this->assertArrayHasKey('timbre_quittance', $diagnostic['ecarts_fiche']);
        $this->assertArrayHasKey('sticker_solde_alerte', $diagnostic['ecarts_fiche']);
    }

    public function test_la_commande_rend_la_file_des_logins_attendus(): void
    {
        FneRejet::consigner($this->uneVente('FA-0042'), $this->refusDeLaPlateforme());

        $this->artisan('portail-fne:demandes --json')
            ->expectsOutput('["1864699A"]')
            ->assertExitCode(0);
    }

    public function test_la_commande_de_diagnostic_ne_ferme_pas_un_rejet_sans_releve(): void
    {
        // Correction automatique éteinte : le sujet est ce que la commande
        // constate, pas ce qu'elle applique ensuite. Allumée, elle renommerait
        // le point de vente et effacerait le constat qu'on vient vérifier.
        // La correction a ses propres épreuves — `CycleFneTroisCasTest`.
        config(['selflow.portail_fne.correction_auto' => false]);

        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refusDeLaPlateforme());

        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $this->assertSame(FneRejet::STATUT_OUVERT, $rejet->refresh()->statut);

        $this->unReleve();

        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $rejet->refresh();

        $this->assertSame(FneRejet::STATUT_DIAGNOSTIQUE, $rejet->statut);
        $this->assertSame('ecart', $rejet->diagnostic['champs'][0]['verdict']);
    }

    public function test_un_releve_plus_frais_rafraichit_un_diagnostic_deja_pose(): void
    {
        // Même raison : sans cela, le premier passage corrigerait l'écart et il
        // n'y aurait plus de constat à rafraîchir au second.
        config(['selflow.portail_fne.correction_auto' => false]);

        $rejet = FneRejet::consigner($this->uneVente('FA-0042'), $this->refusDeLaPlateforme());

        $this->unReleve();
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $premier = $rejet->refresh()->diagnostic;
        $this->assertSame('21/08/2026', $premier['releve']['date']);

        // Repasser sans rien de neuf ne réécrit rien : le constat décrit déjà le
        // dernier état connu du portail.
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);
        $this->assertSame($premier['releve']['fiche_id'], $rejet->refresh()->diagnostic['releve']['fiche_id']);

        // Un relevé plus récent, où le point de vente est enfin écrit comme
        // dans Selflow : le diagnostic doit changer d'avis.
        $this->unReleve('2026-08-25', ['FACTURATION SIEGE']);
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $second = $rejet->refresh()->diagnostic;

        $this->assertSame('25/08/2026', $second['releve']['date']);
        $this->assertNotSame($premier['releve']['fiche_id'], $second['releve']['fiche_id']);
        $this->assertSame('concordant', $second['champs'][0]['verdict']);
    }

    public function test_une_demande_qui_traine_est_signalee(): void
    {
        FneRejet::consigner($this->uneVente('FA-0042'), $this->refusDeLaPlateforme());

        $demande = PortailFneDemande::sole();

        // Fraîche, elle attend simplement.
        $this->assertFalse($demande->estEnRetard());

        $demande->forceFill(['created_at' => now()->subHours(30)])->save();

        $this->assertTrue($demande->refresh()->estEnRetard());
        $this->assertSame(1, PortailFneDemande::enRetard()->count());

        $this->artisan('fne:diagnostiquer-rejets')
            ->expectsOutputToContain('sans réponse depuis plus de')
            ->assertExitCode(0);
    }

    public function test_la_demande_est_abandonnee_quand_plus_aucun_rejet_ne_la_justifie(): void
    {
        $premiere = $this->uneVente('FA-0042');
        $seconde  = $this->uneVente('FA-0043');

        FneRejet::consigner($premiere, $this->refusDeLaPlateforme());
        FneRejet::consigner($seconde, $this->refusDeLaPlateforme());

        // Deux rejets, une seule demande : en refermer un ne l'éteint pas.
        FneRejet::resoudre($premiere);
        $this->assertSame(PortailFneDemande::STATUT_EN_ATTENTE, PortailFneDemande::sole()->statut);

        FneRejet::resoudre($seconde);
        $this->assertSame(PortailFneDemande::STATUT_ABANDONNEE, PortailFneDemande::sole()->statut);
    }

    /**
     * Le refus tel que la plateforme le rend : le détail vit dans le corps
     * brut de la réponse, que `FneService` range sous `errors.api_error`.
     *
     * @return array<string, mixed>
     */
    private function refusDeLaPlateforme(): array
    {
        return [
            'success' => false,
            'message' => 'La normalisation DGI a échoué (HTTP 400) : Invalid point of sale.',
            'errors'  => ['api_error' => json_encode([
                'message' => 'Invalid point of sale',
                'errors'  => ['pointOfSale' => ['invalid' => 'Point of sale is invalid']],
            ])],
        ];
    }

    private function uneVente(string $numero): Vente
    {
        return Vente::create([
            'point_de_vente_id' => $this->pointDeVente->id,
            'numero_facture'    => $numero,
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 100000,
            'montant_tva'       => 18000,
            'montant_ttc'       => 118000,
            'etape'             => 'Facture',
        ]);
    }

    /**
     * Un relevé du portail déjà rangé : une fiche et deux points, dont un fermé.
     */
    /**
     * @param  array<int, string>  $noms
     */
    private function unReleve(string $date = '2026-08-21', array $noms = ['FACTURATION SIÈGE', 'FACTURATION FERMEE']): PortailFneImport
    {
        $import = PortailFneImport::create([
            'entreprise_id'     => $this->entreprise->id,
            'login'             => '1864699A',
            'date_scraping'     => $date,
            'type'              => PortailFneImport::TYPE_FICHE,
            'fichier_nom'       => '1864699A_20260821.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
        ]);

        PortailFneFiche::create([
            'import_id'            => $import->id,
            'entreprise_id'        => $this->entreprise->id,
            'login'                => '1864699A',
            'date_scraping'        => $date,
            'timbre_quittance'     => true,
            'sticker_solde_alerte' => 5000,
        ]);

        foreach ($noms as $nom) {
            PortailFnePointFacturation::create([
                'import_id'        => $import->id,
                'entreprise_id'    => $this->entreprise->id,
                'login'            => '1864699A',
                'date_scraping'    => $date,
                'nom'              => $nom,
                'outil'            => 'Application FNE',
                'statut'           => $nom === 'FACTURATION FERMEE' ? '0' : '1',
                'etablissement_id' => '42200613-f402-40a8-bd4d-a778bb5b96f0',
            ]);
        }

        // La demande en attente est servie par l'arrivée du relevé — ici à la
        // main, puisque le fichier ne passe pas par l'import dans ce test.
        PortailFneDemande::where('login', '1864699A')
            ->where('statut', PortailFneDemande::STATUT_EN_ATTENTE)
            ->get()
            ->each(fn (PortailFneDemande $d) => $d->servir($import));

        return $import;
    }
}
