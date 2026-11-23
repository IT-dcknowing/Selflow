<?php

namespace Tests\Feature;

use App\Modules\Admin\Controleurs\EspaceSurMesureControleur;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\EspaceSurMesure;
use App\Modules\Admin\Modeles\Intervenant;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\DeversementHistoriqueService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lot 154 (23/11/2026) : pages retirées par entreprise, prestataires et
 * facture en attente, export des entreprises, référencement, adresse créée
 * par l'API de cPanel, file Comptaflow.
 */
class SurMesurePagesPrestatairesTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $salon;
    private PointDeVente $site;
    private Utilisateur $gerant;
    private Utilisateur $superadmin;
    private EspaceSurMesure $espace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salon = Entreprise::create(['nom' => 'FOR MEN', 'modules_actifs' => ['principal', 'ventes', 'tiers', 'produits']]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->salon->id, 'nom' => 'Salon Cocody', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->gerant = Utilisateur::create(['nom' => 'Bamba', 'prenom' => 'Ali', 'email' => 'ali@formen.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $this->salon->id, 'point_de_vente_id' => $this->site->id]);
        $this->superadmin = Utilisateur::create(['nom' => 'S', 'prenom' => 'A', 'email' => 'sa@x.ci', 'password' => bcrypt('x'), 'role' => 'superadmin', 'statut' => 'actif']);
        $this->superadmin->forceFill(['habilitations' => ['gestion_entreprises']])->save();
        $this->espace = EspaceSurMesure::create(['entreprise_id' => $this->salon->id, 'actif' => false, 'sous_domaine' => 'formen',
            'modules' => ['prestataires', 'facture_attente']]);
    }

    public function test_une_page_retiree_disparait_du_menu_et_ne_s_ouvre_plus_meme_pour_l_administrateur(): void
    {
        $this->actingAs($this->gerant)->get(route('admin.clients.index'))->assertOk();

        Auth::login($this->superadmin);
        $toutes = collect(\App\Modules\Authentification\Regles\Habilitations::CATALOGUE)->flatMap(fn ($g) => collect($g)->flatMap(fn ($d) => array_keys($d)));
        app(EspaceSurMesureControleur::class)->enregistrer(Request::create('/', 'POST', [
            'couleur_principale' => '#111111', 'couleur_secondaire' => '#111111', 'modele_accueil' => 'horizon', 'modele_connexion' => 'panneau',
            'sous_domaine' => 'formen', 'modules' => ['prestataires', 'facture_attente'], 'pages_formulaire' => '1', 'pages_gardees' => $toutes->reject(fn ($c) => $c === 'tiers_clients')->values()->all(),
        ]), $this->salon);
        $this->assertSame(['tiers_clients'], $this->espace->fresh()->pages_retirees);

        $gerant = $this->gerant->fresh();
        $this->assertFalse($gerant->aHabilitation('tiers_clients'));
        $this->assertTrue($gerant->aHabilitation('nouvelle_vente'));
        $this->actingAs($gerant)->get(route('admin.clients.index'))->assertForbidden();
        $this->actingAs($gerant)->get(route('admin.intervenants.index'))->assertOk()->assertDontSee(route('admin.clients.index'), false);
    }

    public function test_chaque_ligne_dit_qui_a_realise_la_prestation(): void
    {
        $moussa = Intervenant::create(['entreprise_id' => $this->salon->id, 'nom' => 'Moussa', 'actif' => true]);
        $autre = Entreprise::create(['nom' => 'Autre']);
        $etranger = Intervenant::create(['entreprise_id' => $autre->id, 'nom' => 'Pas d\'ici', 'actif' => true]);
        $coupe = Produit::create(['entreprise_id' => $this->salon->id, 'reference' => 'CP-01', 'nom' => 'Coupe dégradé', 'type' => 'service',
            'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 3000, 'taux_tva' => 0]);
        $client = Client::create(['entreprise_id' => $this->salon->id, 'nom' => 'Kouamé Yao']);

        $this->actingAs($this->gerant)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->post(route('admin.ventes.enregistrer'), [
                'client_id' => $client->id, 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Crédit', 'etape' => 'Facture',
                'articles' => [
                    ['produit_id' => $coupe->id, 'quantite' => 1, 'unite' => 'u', 'intervenant_id' => $moussa->id],
                    ['produit_id' => $coupe->id, 'quantite' => 1, 'unite' => 'u', 'intervenant_id' => $etranger->id],
                ],
            ])->assertSessionHasNoErrors();

        $vente = Vente::latest('id')->firstOrFail();
        $this->assertSame([$moussa->id, null], $vente->details()->orderBy('id')->pluck('intervenant_id')->map(fn ($v) => $v === null ? null : (int) $v)->all(),
            'Un prestataire d\'une autre entreprise n\'est jamais écrit.');

        $this->get(route('admin.intervenants.index'))->assertOk()->assertSee('Moussa')->assertSee('Coupe dégradé')->assertSee('Kouamé Yao');
        $this->get(route('admin.ventes.nouvelle'))->assertOk()->assertSee('Réalisé par')->assertSee('En attente');
        $this->get(route('admin.ventes.factures'))->assertOk()->assertSee('En attente de règlement')->assertSee('Encaisser');
    }

    public function test_l_export_des_entreprises_en_pdf_et_en_excel(): void
    {
        $this->actingAs($this->superadmin);
        $pdf = $this->get(route('superadmin.entreprises.exporter', ['format' => 'pdf']));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $xlsx = $this->get(route('superadmin.entreprises.exporter', ['format' => 'excel']));
        $xlsx->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $xlsx->headers->get('Content-Disposition'));
        $this->get(route('superadmin.entreprises'))->assertOk()->assertSee(route('superadmin.entreprises.exporter', ['format' => 'pdf']), false);
    }

    public function test_le_referencement_de_la_page_d_accueil(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('<link rel="canonical"', false)
            ->assertSee('application/ld+json', false)
            ->assertSee('SoftwareApplication', false)
            ->assertSee('og:title', false);
        $this->get('/sitemap.xml')->assertOk()->assertSee('<urlset', false)->assertSee('/guide', false);
    }

    public function test_l_adresse_se_cree_sur_le_serveur_par_l_api_de_cpanel(): void
    {
        config(['selflow.cpanel' => ['hote' => 'serveur.exemple.ci', 'utilisateur' => 'cpuser', 'jeton' => 'JETON', 'dossier_selflow' => 'public_html/Selflow/public']]);
        Http::fake(['https://serveur.exemple.ci:2083/execute/SubDomain/addsubdomain*' => Http::response(['status' => 1, 'errors' => null])]);

        $this->actingAs($this->superadmin)->post(route('superadmin.sur_mesure.serveur', [$this->salon, 'sous_domaine']))
            ->assertSessionHas('success');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'domain=formen') && str_contains($r->url(), 'rootdomain=dc-knowing.com')
            && $r->hasHeader('Authorization', 'cpanel cpuser:JETON'));
    }

    public function test_la_file_comptaflow_bloquee_se_voit_et_un_envoi_abandonne_passe_en_echec(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subMinutes(20)->timestamp, 'created_at' => now()->subMinutes(20)->timestamp]);
        $this->assertTrue(DeversementHistoriqueService::etatDeLaFile()['bloquee']);

        $operation = \App\Modules\Admin\Modeles\Operation::forceCreate(['entreprise_id' => $this->salon->id, 'date_operation' => now()->toDateString(),
            'type_operation' => 'OD', 'code_journal' => 'OD', 'numero_saisie' => 'OD-1']);
        $ligne = EcritureComptable::withoutGlobalScopes()->forceCreate([
            'entreprise_id' => $this->salon->id, 'operation_id' => $operation->id, 'date_ecriture' => now()->toDateString(), 'libelle' => 'Test', 'reference_document' => 'TEST-1', 'code_journal' => 'OD',
            'compte_debit' => '411000', 'debit' => 100, 'credit' => 0, 'comptaflow_sync_status' => 'sending',
        ]);
        (new \App\Jobs\DeverserOperationComptaflow($operation->id))->failed(new \RuntimeException('Timeout'));
        $ligne->refresh();
        $this->assertSame('failed', $ligne->comptaflow_sync_status);
        $this->assertStringContainsString('Timeout', $ligne->comptaflow_sync_error);
    }

    public function test_tout_integrer_tourne_en_arriere_plan(): void
    {
        $arrierePlan = app(\App\Modules\Admin\Services\IntegrationPortailEnArrierePlan::class);
        $etat = $arrierePlan->demander($this->salon, $this->gerant->id, false);
        $this->assertSame('en_attente', $etat['etat']);
        $this->assertSame(0, $etat['restantes']);

        $arrierePlan->traiterLesDemandes(5);
        $this->assertSame('termine', \App\Modules\Admin\Services\IntegrationPortailEnArrierePlan::etat($this->salon->id)['etat']);

        $this->actingAs($this->gerant)->get(route('admin.ventes.portail_fne.index', ['page' => 9]))
            ->assertRedirect(route('admin.ventes.portail_fne.index'));
    }
}
