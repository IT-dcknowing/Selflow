<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retour du 20/10/2026 :
 * - une facture de « Fournisseur divers » n'est pas un BAPA, et ne se
 *   normalise pas ;
 * - les créances et les tiers se lisent par point de vente (tous, ou un) ;
 * - qui a le droit « Créances & règlements » trouve la page.
 */
class CreancesEtTiersParSiteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $marcory;
    private PointDeVente $plateau;
    private Produit $service;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI', 'adresse' => 'Abidjan', 'rccm' => 'CI-ABJ-2026-B-00222',
            'ncc' => '2602222A', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'produits', 'tiers', 'points_de_vente'],
            'normalisation_auto_factures' => false, 'normalisation_auto_recus' => false,
        ]);
        $this->marcory = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory']);
        $this->plateau = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-plateau', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->service = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV', 'nom' => 'Conseil', 'type' => 'service',
            'unite' => 'u', 'prix_achat' => 0, 'prix_vente' => 10000, 'taux_tva' => 18,
        ]);
        $this->admin = Utilisateur::create([
            'nom' => 'K', 'prenom' => 'L', 'email' => 'admin@dck.ci', 'password' => bcrypt('x'), 'role' => 'admin',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
        ]);
    }

    private function sur(PointDeVente $site): self
    {
        return $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id, 'point_de_vente_actif_nom' => $site->nom]);
    }

    private function venteACredit(PointDeVente $site, string $client): Vente
    {
        $this->sur($site);
        $c = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => $client]);
        $this->post(route('admin.ventes.enregistrer'), [
            'client_id' => $c->id, 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Crédit', 'etape' => 'Facture',
            'articles' => [['produit_id' => $this->service->id, 'quantite' => 1, 'prix_unitaire' => 10000, 'unite' => 'u']],
        ])->assertSessionHasNoErrors();

        return Vente::latest('id')->firstOrFail();
    }

    // ── BAPA ─────────────────────────────────────────────────────────

    public function test_une_facture_de_fournisseur_divers_n_est_pas_un_bapa_et_ne_se_normalise_pas(): void
    {
        $divers = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Fournisseur divers']);
        $achat = Achat::create([
            'point_de_vente_id' => $this->marcory->id, 'fournisseur_id' => $divers->id, 'numero_facture' => 'ACH-191026-001',
            'date_achat' => now()->toDateString(), 'etape' => 'Facture', 'type_facture' => 'normale',
            'montant_ht' => 1000, 'montant_tva' => 0, 'montant_ttc' => 1000, 'mode_paiement' => 'Crédit', 'statut' => 'Crédit',
        ]);

        $this->assertFalse($achat->estBapa());
        $this->sur($this->marcory)->post(route('admin.achats.normaliser', $achat))->assertSessionHas('erreur');
        $this->assertFalse((bool) $achat->fresh()->normalise);
    }

    public function test_la_requalification_ramene_les_bapa_non_normalises(): void
    {
        $f = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Fournisseur divers']);
        $achat = Achat::create([
            'point_de_vente_id' => $this->marcory->id, 'fournisseur_id' => $f->id, 'numero_facture' => 'ACH-GUE-201026-001',
            'date_achat' => now()->toDateString(), 'etape' => 'Facture', 'type_facture' => 'bapa',
            'montant_ht' => 1000, 'montant_tva' => 0, 'montant_ttc' => 1000, 'mode_paiement' => 'Crédit', 'statut' => 'Crédit',
        ]);

        $this->artisan('selflow:requalifier-achats', ['entreprise' => $this->entreprise->id, 'numeros' => ['ACH-GUE-201026-001']])->assertSuccessful();
        $this->assertSame('bapa', $achat->fresh()->type_facture, 'Sans --appliquer, rien ne change.');

        $this->artisan('selflow:requalifier-achats', ['entreprise' => $this->entreprise->id, 'numeros' => ['ACH-GUE-201026-001'], '--appliquer' => true])->assertSuccessful();
        $this->assertSame('normale', $achat->fresh()->type_facture);
    }

    // ── Créances par site ───────────────────────────────────────────

    public function test_les_creances_se_lisent_par_point_de_vente(): void
    {
        $this->venteACredit($this->marcory, 'Client Marcory');
        $this->venteACredit($this->plateau, 'Client Plateau');

        $this->sur($this->marcory)->get(route('admin.comptabilite.creances', ['site' => $this->marcory->id]))->assertOk()
            ->assertSee('Client Marcory')->assertDontSee('Client Plateau');
        $this->get(route('admin.comptabilite.creances', ['site' => 'tout']))->assertOk()
            ->assertSee('Client Marcory')->assertSee('Client Plateau');
    }

    public function test_le_membre_affecte_ne_voit_que_les_creances_de_son_site(): void
    {
        $this->venteACredit($this->marcory, 'Client Marcory');
        $this->venteACredit($this->plateau, 'Client Plateau');
        $responsable = Utilisateur::create([
            'nom' => 'R', 'prenom' => 'P', 'email' => 'resp@dck.ci', 'password' => bcrypt('x'), 'role' => 'responsable_pdv',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->plateau->id,
            'habilitations' => ['comptabilite_creances'],
        ]);

        $this->actingAs($responsable)->get(route('admin.comptabilite.creances', ['site' => 'tout']))->assertOk()
            ->assertSee('Client Plateau')->assertDontSee('Client Marcory');
    }

    public function test_une_facture_soldee_ne_reste_pas_en_creance_negative(): void
    {
        $vente = $this->venteACredit($this->marcory, 'Client soldé');
        // Un encaissement qui couvre la facture, taxes et timbre compris.
        \App\Modules\Admin\Modeles\TresorerieJournal::withoutEvents(fn () => \App\Modules\Admin\Modeles\TresorerieJournal::create([
            'point_de_vente_id' => $this->marcory->id, 'date_operation' => now()->toDateString(), 'type_operation' => 'Encaissement',
            'libelle' => 'Règlement', 'mode_paiement' => 'Caisse', 'montant_entree' => (float) $vente->montant_ttc + 100,
            'montant_sortie' => 0, 'solde_resultat' => 0, 'reference_document' => $vente->numero_facture,
        ]));

        // La fenêtre de la facture qui suit l'enregistrement : consommée d'abord.
        $this->sur($this->marcory)->get(route('admin.tableau_de_bord'));
        $this->get(route('admin.comptabilite.creances'))->assertOk()->assertDontSee($vente->numero_facture);
    }

    // ── Tiers par site ──────────────────────────────────────────────

    public function test_le_client_cree_sur_un_site_n_apparait_pas_dans_la_liste_d_un_autre(): void
    {
        $this->sur($this->marcory);
        $client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Né à Marcory']);
        $this->assertSame($this->marcory->id, (int) $client->point_de_vente_id);

        $this->sur($this->plateau)->get(route('admin.clients.index', ['site' => $this->plateau->id]))->assertOk()->assertDontSee('Né à Marcory');
        $this->get(route('admin.clients.index', ['site' => $this->marcory->id]))->assertOk()->assertSee('Né à Marcory');
        $this->get(route('admin.clients.index', ['site' => 'tout']))->assertOk()->assertSee('Né à Marcory');
    }

    // ── Accès à la page ─────────────────────────────────────────────

    public function test_le_droit_ouvre_la_page_meme_sans_le_module_comptabilite_et_au_caissier(): void
    {
        $this->sur($this->marcory)->get(route('admin.tableau_de_bord'))->assertOk()->assertSee('Créances &amp; règlements', false);

        $caissier = Utilisateur::create([
            'nom' => 'C', 'prenom' => 'D', 'email' => 'caisse@dck.ci', 'password' => bcrypt('x'), 'role' => 'caissier',
            'statut' => 'actif', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->marcory->id,
            'habilitations' => ['nouvelle_vente', 'comptabilite_creances'],
        ]);
        $this->actingAs($caissier)->get(route('caissier.ventes.nouvelle'))->assertOk()
            ->assertSee(route('caissier.comptabilite.creances'), false);
        $this->actingAs($caissier)->get(route('caissier.comptabilite.creances'))->assertOk()->assertSee('Soldes de départ');
    }
}
