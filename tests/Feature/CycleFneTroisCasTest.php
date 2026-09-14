<?php

namespace Tests\Feature;

use App\Jobs\NormaliserFactureFne;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneDemande;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFnePointFacturation;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le cycle complet, joué sur les trois situations qu'une facture rencontre.
 *
 * Les autres épreuves vérifient les maillons un par un — la classification du
 * refus, le rangement d'un relevé, le rapprochement. Celle-ci les enchaîne
 * comme la production les enchaîne, en partant du seul geste réel : la facture
 * part à la DGI. Rien n'est simulé en dehors du réseau lui-même et du portail,
 * qui dépose ses deux fichiers dans le dossier convenu comme le scraper le fait.
 *
 * | Cas | Ce que la DGI fait | Ce qui doit se produire |
 * |---|---|---|
 * | 1 | elle certifie | la pièce est normalisée, la file du scraper reste vide |
 * | 2 | elle refuse la pièce | relevé demandé, écart nommé, nom corrigé, pièces renvoyées |
 * | 3 | elle ne répond pas | on rejoue, et on ne réveille pas le scraper |
 *
 * **Le cas 2 se referme seul depuis le 29/08/2026**, sur demande du propriétaire
 * du projet. Ce qui se corrige est un **nom** — celui du point de vente, dont le
 * portail est la source de vérité. Ce qui ne se corrigera jamais est ce qui
 * change le **contenu** d'une facture : `timbre_quittance`, `bapa`,
 * `sticker_solde_alerte` sont montrés et jamais appliqués, et une épreuve d'ici
 * le vérifie. La règle d'or n'est pas levée, elle est bornée.
 */
class CycleFneTroisCasTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $pointDeVente;
    private Client $client;
    private Produit $article;
    private string $dossier;

    /** Ce que la plateforme répondra au prochain envoi. */
    private $reponseDeLaDgi;

    private const LOGIN = '1864699A';

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom'               => 'DC-KNOWING CGA',
            'ncc'               => self::LOGIN,
            'regime_imposition' => 'RNI',
            'adresse'           => 'RIVIERA II AFRICAINE',
            'rccm'              => 'CI-ABJ-2026-B-12345',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats'],
        ]);

        // Sans clé, `FneService` refuse avant tout appel : ce serait un
        // quatrième cas, celui où rien ne part. Il est couvert ailleurs.
        FneCredential::create([
            'entreprise_id' => $this->entreprise->id,
            'cle_test'      => 'fne_test_cle_de_simulation',
            'statut'        => 'test',
        ]);

        // Le nom est écrit sans accent dans Selflow ; le portail l'accentue.
        // C'est l'écart réellement observé sur `pointOfSale`.
        $this->pointDeVente = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom'           => 'FACTURATION SIEGE',
            'ville'         => 'Abidjan',
            'commune'       => 'Cocody',
            'responsable'   => 'Ali Hassan',
            'statut'        => 'Ouvert',
        ]);

        $this->client = Client::create([
            'entreprise_id' => $this->entreprise->id,
            'nom'           => 'Entreprise Konan BTP',
        ]);

        $this->article = Produit::create([
            'entreprise_id' => $this->entreprise->id,
            'reference'     => 'CIM-001',
            'nom'           => 'Ciment CPJ 45',
            'type'          => 'service',
            'unite'         => 'sac',
            'prix_achat'    => 5000,
            'prix_vente'    => 6500,
            'taux_tva'      => 18,
        ]);

        $this->dossier = storage_path('framework/testing/cycle-fne-' . uniqid());
        mkdir($this->dossier, 0777, true);
        config(['selflow.portail_fne.dossier_import' => $this->dossier]);

        // Un seul stub, posé une fois. `Http::fake()` appelé deux fois ne
        // remplace pas le premier : il l'empile, et c'est le premier posé qui
        // répond. Un scénario qui change d'avis en cours de route — la
        // plateforme tombe puis revient — recevait donc toujours la première
        // réponse, et l'épreuve mesurait le harnais au lieu du code.
        Http::fake(fn () => ($this->reponseDeLaDgi)());
    }

    /**
     * Ce que la DGI répondra au prochain envoi.
     *
     * @param  array<string, mixed>|string  $corps
     */
    private function laDgiRepond(array|string $corps, int $code): void
    {
        $this->reponseDeLaDgi = fn () => Http::response($corps, $code);
    }

    private function laDgiNeRepondPas(string $erreur): void
    {
        $this->reponseDeLaDgi = fn () => throw new ConnectionException($erreur);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier . '/*') ?: [] as $fichier) {
            unlink($fichier);
        }
        @rmdir($this->dossier);

        parent::tearDown();
    }

    /* ═══════════════ Cas 1 — la DGI certifie ═══════════════ */

    public function test_cas_1_la_piece_conforme_est_certifiee_et_le_scraper_reste_au_repos(): void
    {
        $this->laDgiRepond($this->certification(), 200);

        $vente = $this->uneFacture('FA-0042');

        (new NormaliserFactureFne($vente))->handle();

        $vente->refresh();

        $this->assertTrue((bool) $vente->normalise);
        $this->assertSame('9606123456789', $vente->numero_fne);
        $this->assertSame('token-dgi-abcdef', $vente->signature_dgi);
        $this->assertSame('token-dgi-abcdef', $vente->qr_code_data);

        // Ce qui est parti porte bien le nom du point de vente : c'est ce champ
        // que le cas 2 verra refusé.
        Http::assertSent(fn ($requete) => $requete['pointOfSale'] === 'FACTURATION SIEGE');

        // Rien à consigner, rien à relever, rien à demander au scraper.
        $this->assertSame(0, FneRejet::count());
        $this->assertSame(0, PortailFneDemande::count());

        // La file que le scraper interroge avant d'ouvrir son navigateur.
        $this->artisan('portail-fne:demandes --json')
            ->expectsOutput('[]')
            ->assertExitCode(0);
    }

    /* ═══════════════ Cas 2 — la DGI refuse la pièce ═══════════════ */

    public function test_cas_2_le_refus_se_corrige_et_la_piece_repart_toute_seule(): void
    {
        /* — 1. La DGI examine et refuse : le point de vente n'est pas déclaré ainsi. */

        $this->laDgiRepond($this->refusPointDeVente(), 400);

        $vente = $this->uneFacture('FA-0043');

        (new NormaliserFactureFne($vente))->handle();

        $this->assertFalse((bool) $vente->refresh()->normalise);

        $rejet = FneRejet::sole();

        $this->assertSame(FneRejet::CAUSE_DGI, $rejet->cause);
        $this->assertSame(FneRejet::STATUT_OUVERT, $rejet->statut);
        $this->assertSame(['pointOfSale'], $rejet->nomsDesChamps());

        /* — 2. Une demande de relevé s'ouvre, et le scraper la voit. */

        $demande = PortailFneDemande::sole();

        $this->assertSame(self::LOGIN, $demande->login);
        $this->assertSame(PortailFneDemande::STATUT_EN_ATTENTE, $demande->statut);
        $this->assertStringContainsString('FA-0043', $demande->motif);

        $this->artisan('portail-fne:demandes --json')
            ->expectsOutput('["' . self::LOGIN . '"]')
            ->assertExitCode(0);

        /* — 3. Le scraper dépose ses deux fichiers, `portail-fne:importer` range. */

        $this->deposerLeReleve('20260829', ['FACTURATION SIÈGE']);

        $this->artisan('portail-fne:importer')->assertExitCode(0);

        $this->assertSame(1, PortailFneFiche::count());
        $this->assertSame(1, PortailFnePointFacturation::count());

        // Le relevé arrivé sert la demande — et lui seul la ferme.
        $this->assertSame(PortailFneDemande::STATUT_SERVIE, $demande->refresh()->statut);

        /* — 4. Le passage horaire rapproche, corrige et renvoie, sans personne. */

        $this->laDgiRepond($this->certification(), 200);

        $this->artisan('fne:diagnostiquer-rejets')
            ->expectsOutputToContain('renommé')
            ->assertExitCode(0);

        // Le point d'origine n'est jamais renommé : le point déclaré au portail
        // est créé, et la pièce lui est rattachée (règle posée en 2e50585).
        $this->assertSame('FACTURATION SIEGE', $this->pointDeVente->refresh()->nom);
        $this->assertSame('FACTURATION SIÈGE', $vente->refresh()->pointDeVente->nom);

        // Et la pièce est repartie avec, jusqu'à la certification.
        $vente->refresh();

        $this->assertTrue((bool) $vente->normalise);
        $this->assertSame('9606123456789', $vente->numero_fne);

        Http::assertSent(fn ($requete) => $requete['pointOfSale'] === 'FACTURATION SIÈGE');

        /* — 5. Le cycle se referme : plus de rejet ouvert, plus rien en file. */

        $this->assertSame(FneRejet::STATUT_RESOLU, $rejet->refresh()->statut);
        $this->assertSame(0, PortailFneDemande::where('statut', PortailFneDemande::STATUT_EN_ATTENTE)->count());

        $this->artisan('portail-fne:demandes --json')
            ->expectsOutput('[]')
            ->assertExitCode(0);
    }

    public function test_cas_2_toutes_les_pieces_du_point_de_vente_repartent(): void
    {
        // Une soirée de saisie : trois pièces refusées pour un seul nom mal
        // orthographié. Ne renvoyer que celle du rejet traité laisserait les
        // deux autres refusées — et le rapprochement suivant les dirait
        // « concordantes », puisque la valeur envoyée se relit sur le point de
        // vente, qui vient d'être corrigé.
        $this->laDgiRepond($this->refusPointDeVente(), 400);

        foreach (['FA-0050', 'FA-0051', 'FA-0052'] as $numero) {
            (new NormaliserFactureFne($this->uneFacture($numero)))->handle();
        }

        $this->assertSame(3, FneRejet::count());
        $this->assertSame(1, PortailFneDemande::count());

        $this->deposerLeReleve('20260829', ['FACTURATION SIÈGE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);

        $this->laDgiRepond($this->certification(), 200);

        $this->artisan('fne:diagnostiquer-rejets')
            ->expectsOutputToContain('3 pièce(s) renvoyée(s)')
            ->assertExitCode(0);

        $this->assertSame(3, Vente::where('normalise', true)->count());
        $this->assertSame(3, FneRejet::where('statut', FneRejet::STATUT_RESOLU)->count());
    }

    public function test_cas_2_plusieurs_points_declares_la_machine_s_abstient(): void
    {
        // Le portail déclare deux points actifs. Lequel a établi la pièce ? La
        // machine ne le sait pas, et choisir à la place de qui l'a saisie
        // renommerait un site sur une supposition.
        $this->laDgiRepond($this->refusPointDeVente(), 400);

        (new NormaliserFactureFne($this->uneFacture('FA-0053')))->handle();

        $this->deposerLeReleve('20260829', ['FACTURATION SIÈGE', 'FACTURATION ANNEXE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        // Rien n'a été renommé, rien n'est reparti : le constat est montré, la
        // décision reste à l'utilisateur.
        $this->assertSame('FACTURATION SIEGE', $this->pointDeVente->refresh()->nom);
        $this->assertFalse((bool) Vente::sole()->normalise);

        $champ = FneRejet::sole()->diagnostic['champs'][0];

        $this->assertSame('ecart', $champ['verdict']);
        $this->assertContains('FACTURATION SIÈGE', $champ['portail']);
        $this->assertContains('FACTURATION ANNEXE', $champ['portail']);
    }

    public function test_cas_2_les_trois_champs_fiscaux_ne_sont_jamais_appliques(): void
    {
        // La correction automatique porte sur un libellé. Ces trois-là commandent
        // ce que la facture contient — le timbre de quittance, le bordereau
        // d'achat, le seuil d'alerte des stickers. Le portail dit l'inverse de
        // Selflow sur les trois ; l'entreprise ne doit pas bouger d'un pouce.
        $this->entreprise->update([
            'timbre_quittance'     => false,
            'bapa'                 => false,
            'sticker_solde_alerte' => 5,
        ]);

        $this->laDgiRepond($this->refusPointDeVente(), 400);

        (new NormaliserFactureFne($this->uneFacture('FA-0054')))->handle();

        $this->deposerLeReleve('20260829', ['FACTURATION SIÈGE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);

        $this->laDgiRepond($this->certification(), 200);
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        // Le point de vente, oui — celui du portail est créé à côté de l'ancien.
        $this->assertSame('FACTURATION SIEGE', $this->pointDeVente->refresh()->nom);
        $this->assertTrue(PointDeVente::where('nom', 'FACTURATION SIÈGE')->exists());

        // Les trois autres, non. Le relevé les affirme, l'entreprise les ignore.
        $this->entreprise->refresh();

        $this->assertFalse((bool) $this->entreprise->timbre_quittance);
        $this->assertFalse((bool) $this->entreprise->bapa);
        $this->assertSame(5, (int) $this->entreprise->sticker_solde_alerte);
    }

    public function test_cas_2_l_interrupteur_eteint_la_correction_automatique(): void
    {
        config(['selflow.portail_fne.correction_auto' => false]);

        $this->laDgiRepond($this->refusPointDeVente(), 400);

        (new NormaliserFactureFne($this->uneFacture('FA-0055')))->handle();

        $this->deposerLeReleve('20260829', ['FACTURATION SIÈGE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        // Le constat est posé, et il s'arrête là.
        $this->assertSame('FACTURATION SIEGE', $this->pointDeVente->refresh()->nom);
        $this->assertFalse((bool) Vente::sole()->normalise);
        $this->assertSame('ecart', FneRejet::sole()->diagnostic['champs'][0]['verdict']);
    }

    public function test_cas_2_une_entreprise_qui_certifie_a_la_main_est_corrigee_sans_etre_renvoyee(): void
    {
        // Elle a décroché la normalisation automatique : elle vérifie ses pièces
        // avant de les certifier, et une pièce certifiée ne se reprend pas.
        // Corriger le nom pour elle est un service ; l'envoyer à sa place
        // passerait outre un choix qu'elle a fait exprès.
        $this->entreprise->update(['normalisation_auto_factures' => false]);

        $this->laDgiRepond($this->refusPointDeVente(), 400);

        (new NormaliserFactureFne($this->uneFacture('FA-0056')))->handle();

        $this->deposerLeReleve('20260829', ['FACTURATION SIÈGE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);

        $this->laDgiRepond($this->certification(), 200);

        $this->artisan('fne:diagnostiquer-rejets')
            ->expectsOutputToContain('en attente')
            ->assertExitCode(0);

        // La pièce est rattachée au point du portail, et attend qu'on la renvoie.
        $this->assertSame('FACTURATION SIEGE', $this->pointDeVente->refresh()->nom);
        $this->assertSame('FACTURATION SIÈGE', Vente::sole()->pointDeVente->nom);
        $this->assertFalse((bool) Vente::sole()->normalise);
        $this->assertSame(FneRejet::STATUT_OUVERT, FneRejet::sole()->statut);
    }

    public function test_cas_2_un_second_refus_sur_le_meme_champ_ne_renomme_pas_deux_fois(): void
    {
        // La DGI refuse, on corrige, elle refuse encore — pour une autre raison
        // qu'elle range sous le même champ. Sans garde-fou, la machine
        // renommerait à chaque passage et la pièce repartirait sans fin.
        $this->laDgiRepond($this->refusPointDeVente(), 400);

        (new NormaliserFactureFne($this->uneFacture('FA-0057')))->handle();

        $this->deposerLeReleve('20260829', ['FACTURATION SIÈGE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);

        // La plateforme refuse toujours : le renvoi automatique consigne un
        // second rejet, sur le même champ.
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $this->assertSame('FACTURATION SIEGE', $this->pointDeVente->refresh()->nom);
        $this->assertSame(1, PointDeVente::where('nom', 'FACTURATION SIÈGE')->count());
        $this->assertGreaterThan(1, FneRejet::count());

        // Le passage suivant ne renomme plus rien : le nom est déjà celui du
        // portail, et le rapprochement le dit concordant.
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $this->assertSame('FACTURATION SIEGE', $this->pointDeVente->refresh()->nom);
        $this->assertSame(1, PointDeVente::where('nom', 'FACTURATION SIÈGE')->count());

        $dernier = FneRejet::orderByDesc('id')->first();

        $this->assertSame('concordant', $dernier->diagnostic['champs'][0]['verdict']);
    }

    public function test_cas_2_un_releve_plus_frais_rafraichit_le_constat(): void
    {
        // Correction éteinte : cette épreuve porte sur la fraîcheur du constat,
        // pas sur ce qu'on en fait. Allumée, elle renommerait au premier
        // passage et il n'y aurait plus d'écart à rafraîchir.
        config(['selflow.portail_fne.correction_auto' => false]);

        $this->laDgiRepond($this->refusPointDeVente(), 400);

        (new NormaliserFactureFne($this->uneFacture('FA-0044')))->handle();

        $this->deposerLeReleve('20260829', ['FACTURATION SIÈGE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $rejet = FneRejet::sole();
        $this->assertSame('ecart', $rejet->diagnostic['champs'][0]['verdict']);

        // L'entreprise fait corriger son point de facturation au portail : le
        // relevé du lendemain l'écrit comme Selflow.
        $this->deposerLeReleve('20260830', ['FACTURATION SIEGE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $rejet->refresh();

        $this->assertSame('concordant', $rejet->diagnostic['champs'][0]['verdict']);
        $this->assertStringContainsString('ailleurs', $rejet->diagnostic['conclusion']);
    }

    public function test_cas_2_les_points_tiennent_meme_quand_seule_la_fiche_a_bouge(): void
    {
        // Même raison qu'au-dessus : le sujet est ce que le rapprochement lit,
        // pas ce qu'il applique.
        config(['selflow.portail_fne.correction_auto' => false]);

        $this->laDgiRepond($this->refusPointDeVente(), 400);

        (new NormaliserFactureFne($this->uneFacture('FA-0049')))->handle();

        $this->deposerLeReleve('20260829', ['FACTURATION SIÈGE']);
        $this->artisan('portail-fne:importer')->assertExitCode(0);

        // Le lendemain, le portail n'a changé que son solde d'alerte. Le
        // tableur des points est identique, donc pas réécrit : sa dernière date
        // reste celle de la veille, tandis que la fiche avance d'un jour.
        $this->deposerLeReleve('20260830', ['FACTURATION SIÈGE'], 3000);
        $this->artisan('portail-fne:importer')->assertExitCode(0);

        $this->assertSame(2, PortailFneFiche::count());
        $this->assertSame(1, PortailFnePointFacturation::count());

        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $champ = FneRejet::sole()->diagnostic['champs'][0];

        // Le portail déclare bien un point de facturation. L'annoncer muet
        // ferait chercher une déclaration manquante là où il n'en manque aucune.
        $this->assertSame('ecart', $champ['verdict']);
        $this->assertSame(['FACTURATION SIÈGE'], $champ['portail']);

        // Et la date citée est celle des points, non celle de la fiche : c'est
        // elle qui dit de quand date ce qu'on montre.
        $this->assertStringContainsString('29/08/2026', $champ['explication']);
    }

    /* ═══════════════ Cas 3 — la DGI ne répond pas ═══════════════ */

    public function test_cas_3_une_panne_de_la_plateforme_est_rejouee_sans_rien_consigner(): void
    {
        $this->laDgiRepond('Service Unavailable', 503);

        $vente = $this->uneFacture('FA-0045');

        // Première tentative : le job relance, pour que la file rejoue.
        try {
            (new NormaliserFactureFne($vente))->handle();
            $this->fail('Une plateforme injoignable doit relancer le job, pas le clore.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('injoignable', $e->getMessage());
        }

        // Rien n'est consigné tant qu'il reste des tentatives : une coupure ne
        // vaut pas trois refus.
        $this->assertSame(0, FneRejet::count());
        $this->assertSame(0, PortailFneDemande::count());
        $this->assertFalse((bool) $vente->refresh()->normalise);
    }

    public function test_cas_3_les_tentatives_epuisees_consignent_un_rejet_qui_ne_reveille_pas_le_scraper(): void
    {
        $this->laDgiRepond('Bad Gateway', 502);

        $vente = $this->uneFacture('FA-0046');

        $this->jouerLaDerniereTentative($vente);

        $rejet = FneRejet::sole();

        // La pièce n'a jamais été examinée : il n'y a rien à relever au portail.
        $this->assertSame(FneRejet::CAUSE_RESEAU, $rejet->cause);
        $this->assertTrue($rejet->estReseau());
        $this->assertSame(FneRejet::STATUT_OUVERT, $rejet->statut);

        // Le point qui compte : la file du scraper reste vide.
        $this->assertSame(0, PortailFneDemande::count());

        $this->artisan('portail-fne:demandes --json')
            ->expectsOutput('[]')
            ->assertExitCode(0);

        // Et le rapprochement n'a rien à rapprocher : sans relevé, le rejet
        // reste ouvert au lieu de sortir de la file sur une comparaison qui
        // n'a pas eu lieu.
        $this->artisan('fne:diagnostiquer-rejets')->assertExitCode(0);

        $this->assertSame(FneRejet::STATUT_OUVERT, $rejet->refresh()->statut);
    }

    public function test_cas_3_une_coupure_reseau_suit_la_meme_regle_qu_une_panne(): void
    {
        $this->laDgiNeRepondPas('cURL error 28: Operation timed out after 10001 milliseconds');

        $vente = $this->uneFacture('FA-0047');

        $this->jouerLaDerniereTentative($vente);

        $rejet = FneRejet::sole();

        $this->assertSame(FneRejet::CAUSE_RESEAU, $rejet->cause);
        $this->assertStringContainsString("Exception lors de l'appel API FNE", $rejet->message);
        $this->assertSame(0, PortailFneDemande::count());
    }

    public function test_cas_3_la_reprise_apres_retablissement_certifie_la_meme_piece(): void
    {
        $this->laDgiRepond('Service Unavailable', 503);

        $vente = $this->uneFacture('FA-0048');

        $this->jouerLaDerniereTentative($vente);

        $this->assertSame(1, FneRejet::count());

        // La plateforme revient : la pièce n'avait rien de fautif, elle passe
        // telle quelle, sans qu'aucun relevé n'ait été nécessaire.
        $this->laDgiRepond($this->certification(), 200);

        (new NormaliserFactureFne($vente->fresh()))->handle();

        $this->assertTrue((bool) $vente->refresh()->normalise);
        $this->assertSame(FneRejet::STATUT_RESOLU, FneRejet::sole()->statut);
        $this->assertSame(0, PortailFneDemande::count());
    }

    /**
     * Le cas 3 tel que l'utilisateur le rencontre : par le bouton.
     *
     * Le bouton « Normaliser » travaille en synchrone, et `SyncJob::attempts()`
     * rend toujours 1 : la condition « il me reste des tentatives » du job etait
     * donc vraie pour toujours, l'exception remontait jusqu'a `SyncQueue` qui la
     * relançait, et l'ecran rendait une **erreur 500** — sans consigner le
     * moindre rejet. Les epreuves d'au-dessus ne le voyaient pas : elles jouent
     * le job a la main, jamais le chemin du bouton.
     */
    public function test_cas_3_le_bouton_dit_l_injoignable_au_lieu_de_rendre_une_erreur_500(): void
    {
        $this->laDgiNeRepondPas('cURL error 28: Operation timed out after 10001 milliseconds');

        $vente = $this->uneFacture('FA-0049');

        $this->actingAs($this->unAdmin())
            ->post(route('admin.ventes.normaliser', $vente))
            ->assertRedirect();

        $this->assertStringContainsString('injoignable', session('erreur'));

        // Et le bouton de reprise est la : rien ne rejoue un rejet « reseau »
        // a notre place, ni file derriere le bouton, ni tache planifiee.
        $this->assertSame(
            route('admin.ventes.normaliser', $vente),
            session('erreur_action')[0]['url']
        );

        // Ce que le 500 empechait : le rejet est consigne, et il l'est comme un
        // incident de transport — la file du scraper reste vide.
        $rejet = FneRejet::sole();
        $this->assertSame(FneRejet::CAUSE_RESEAU, $rejet->cause);
        $this->assertSame(FneRejet::STATUT_OUVERT, $rejet->statut);
        $this->assertSame(0, PortailFneDemande::count());
        $this->assertFalse((bool) $vente->refresh()->normalise);
    }

    /**
     * Le meme bouton, cote achats — ou le message partait en vert quoi qu'il
     * arrive, y compris sur une piece restee non normalisee.
     */
    public function test_cas_3_le_bouton_des_achats_ne_dit_plus_succes_quand_la_plateforme_ne_repond_pas(): void
    {
        $this->laDgiNeRepondPas('cURL error 28: Operation timed out after 10001 milliseconds');

        $achat = $this->unBordereau('BA-0001');

        $this->actingAs($this->unAdmin())
            ->post(route('admin.achats.normaliser', $achat))
            ->assertRedirect();

        $this->assertNull(session('succes'));
        $this->assertStringContainsString('injoignable', session('erreur'));

        $this->assertSame(FneRejet::CAUSE_RESEAU, FneRejet::sole()->cause);
        $this->assertSame(0, PortailFneDemande::count());
        $this->assertFalse((bool) $achat->refresh()->normalise);
    }

    /* ═══════════════ Les rouages de la simulation ═══════════════ */

    /**
     * Joue la tentative où le job n'a plus de recours : il consigne.
     *
     * `attempts()` lit le message de la file, absent quand on appelle `handle()`
     * à la main. On le fournit, plutôt que de lancer une vraie file pour
     * observer un comportement qui tient en un entier.
     */
    private function jouerLaDerniereTentative(Vente $vente): void
    {
        $job = new NormaliserFactureFne($vente);

        $messageDeFile = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $messageDeFile->shouldReceive('attempts')->andReturn(3);

        $job->setJob($messageDeFile);
        $job->handle();
    }

    private function unAdmin(): Utilisateur
    {
        return Utilisateur::create([
            'nom'               => 'Yao',
            'prenom'            => 'Adjoua',
            'email'             => 'adjoua@dcknowing.ci',
            'password'          => bcrypt('secret-de-test'),
            'role'              => 'admin',
            'entreprise_id'     => $this->entreprise->id,
            'point_de_vente_id' => $this->pointDeVente->id,
        ]);
    }

    private function unBordereau(string $numero): Achat
    {
        $fournisseur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id,
            'nom'           => 'Carriere du Banco',
        ]);

        $achat = Achat::create([
            'point_de_vente_id' => $this->pointDeVente->id,
            'fournisseur_id'    => $fournisseur->id,
            'numero_facture'    => $numero,
            'date_achat'        => now(),
            'mode_paiement'     => 'Espèces',
            'type_facture'      => 'bapa',
            'etape'             => 'Facture',
            'montant_ht'        => 40000,
            'montant_tva'       => 0,
            'montant_ttc'       => 40000,
        ]);

        AchatDetail::create([
            'achat_id'      => $achat->id,
            'produit_id'    => $this->article->id,
            'quantite'      => 8,
            'prix_unitaire' => 5000,
            'montant_tva'   => 0,
            'montant_ttc'   => 40000,
        ]);

        return $achat->fresh();
    }

    private function uneFacture(string $numero): Vente
    {
        $vente = Vente::create([
            'point_de_vente_id' => $this->pointDeVente->id,
            'client_id'         => $this->client->id,
            'numero_facture'    => $numero,
            'date_vente'        => now()->toDateString(),
            'mode_paiement'     => 'Espèces',
            'montant_ht'        => 65000,
            'montant_tva'       => 11700,
            'montant_ttc'       => 76700,
            'etape'             => 'Facture',
        ]);

        VenteDetail::create([
            'vente_id'      => $vente->id,
            'produit_id'    => $this->article->id,
            'quantite'      => 10,
            'unite'         => 'sac',
            'prix_unitaire' => 6500,
            'montant_tva'   => 11700,
            'montant_ttc'   => 76700,
        ]);

        return $vente->fresh();
    }

    /**
     * La réponse de la plateforme quand elle certifie.
     *
     * @return array<string, mixed>
     */
    private function certification(): array
    {
        return [
            'reference'       => '9606123456789',
            'token'           => 'token-dgi-abcdef',
            'invoice_id'      => 'b1f0a7c2-1111-4c3a-9d21-9f0c7a5e4d10',
            'document_url'    => 'https://portail.dgi.gouv.ci/facture/9606123456789.pdf',
            'balance_sticker' => 4990,
        ];
    }

    /**
     * Le refus tel que la plateforme le rend : un 400 dont le corps nomme le
     * champ fautif.
     *
     * @return array<string, mixed>
     */
    private function refusPointDeVente(): array
    {
        return [
            'message' => 'Invalid point of sale',
            'errors'  => [
                'pointOfSale' => [
                    'invalid' => 'Le nom du point de vente doit être déclaré à '
                        . "l'identique sur votre espace FNE.",
                ],
            ],
        ];
    }

    /**
     * Ce que le scraper dépose : deux fichiers, à la nomenclature convenue.
     *
     * @param  array<int, string>  $points
     * @param  int  $soldeAlerte  De quoi faire bouger la fiche sans toucher aux points.
     */
    private function deposerLeReleve(string $date, array $points, int $soldeAlerte = 5000): void
    {
        file_put_contents(
            $this->dossier . DIRECTORY_SEPARATOR . self::LOGIN . '_' . $date . '.json',
            json_encode([
                'Email'                                               => 'it.dcknowing@gmail.com',
                'Téléphone'                                           => '2722421443',
                'Adresse'                                             => '8XVQ+29Q',
                'Commune'                                             => 'COCODY',
                'Quartier'                                            => 'RIVIERA II AFRICAINE',
                'Référence Cadastrale'                                => '*',
                'IDU'                                                 => '*',
                "Propriétaire du local professionnel de l'entreprise" => null,
                "Sticker : solde d'alerte"                            => (string) $soldeAlerte,
                'Références bancaires'                                => null,
                'Timbre de quittance'                                 => true,
                "Bordereau d'achat de produits agricoles"             => true,
                'Pied de page des factures'                           => null,
                'Factures autres mentions'                            => null,
            ], JSON_UNESCAPED_UNICODE)
        );

        $lignes = [[
            'Nom', 'Outil', 'ID du terminal', 'Statut', 'Raison de statut',
            "ID de l'établissement", 'Créé à', 'Mise à jour à',
        ]];

        foreach ($points as $nom) {
            $lignes[] = [
                $nom, 'Application FNE', '', '1', '',
                '42200613-f402-40a8-bd4d-a778bb5b96f0',
                '2026-07-30T10:38:40.726Z', '2026-07-30T10:38:40.726Z',
            ];
        }

        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->getActiveSheet()->fromArray($lignes, null, 'A1');

        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))
            ->save($this->dossier . DIRECTORY_SEPARATOR . self::LOGIN . '_' . $date . '.xlsx');

        $classeur->disconnectWorksheets();
    }
}
