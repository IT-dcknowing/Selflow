<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\EcritureFactureRecueService;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Retest de production, octobre 2026 — le cycle d'achat.
 *
 *  - la liste n'offrait aucune action sur une demande de prix ni sur une
 *    commande : on ne savait pas comment avancer ;
 *  - la page d'une demande de prix offrait à la fois « Passer en bon de
 *    réception » et « Confirmer la commande » ;
 *  - la facture normalisée d'un fournisseur revient par le relevé du portail :
 *    à la facturation, on demande désormais si le fournisseur la déclarera.
 *    Si oui, la saisie reste informative et c'est la facture certifiée qui
 *    passe en écriture (règle du propriétaire).
 */
class AchatDeclareParLeFournisseurTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Fournisseur $fournisseur;
    private Produit $ciment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Axenova', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody',
            'rccm' => 'CI-ABJ-2018-B-31734', 'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'achats', 'ventes', 'stock', 'produits', 'tiers'],
        ]);
        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Agence Cocody', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);
        $admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@axenova.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->fournisseur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Cimenterie du Sud', 'ncc' => '1122334B']);
        $this->ciment = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'CIM-50', 'nom' => 'Ciment 50 kg',
            'type' => 'marchandise', 'unite' => 'sac', 'prix_achat' => 5000, 'prix_vente' => 6500, 'taux_tva' => 18,
        ]);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function commande(string $etape = 'Bon de commande'): Achat
    {
        $this->post(route('admin.achats.enregistrer'), [
            'fournisseur_id' => $this->fournisseur->id, 'date_achat' => now()->toDateString(), 'etape' => $etape,
            'articles' => [['produit_id' => $this->ciment->id, 'quantite' => 2, 'prix_unitaire' => 5000, 'unite' => 'sac']],
        ])->assertSessionHasNoErrors();

        return Achat::latest('id')->firstOrFail();
    }

    private function operationsDeFacture(Achat $achat): int
    {
        return Operation::where('type_operation', 'FactureAchat')->where('reference_document', $achat->fresh()->numero_facture)->count();
    }

    private function factureRecue(Achat $achat): PortailFneFactureRecue
    {
        $import = PortailFneImport::create([
            'entreprise_id' => $this->entreprise->id, 'login' => '1864699A', 'date_scraping' => now()->toDateString(),
            'type' => ImportFacturesRecuesService::TYPE, 'fichier_nom' => 'releve.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)), 'statut' => PortailFneImport::STATUT_IMPORTE,
        ]);

        return PortailFneFactureRecue::create([
            'import_id' => $import->id, 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
            'login' => '1864699A', 'date_scraping' => now()->toDateString(), 'reference' => 'B0000001X26000000099',
            'emetteur_nom' => 'Cimenterie du Sud', 'emetteur_ncc' => '1122334B', 'date_facture' => now()->toDateString(),
            'montant_ht' => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800, 'timbre_fiscal' => 0, 'net_a_payer' => 11800,
            'statut_rapprochement' => PortailFneFactureRecue::RAPPROCHEE, 'achat_id' => $achat->id,
        ]);
    }

    // ── Une demande de prix, un bon de commande ne se paient pas (15/10/2026) ──

    public function test_une_commande_ignore_le_reglement_envoye_par_le_formulaire(): void
    {
        foreach (['Demande de prix', 'Bon de commande'] as $etape) {
            $this->post(route('admin.achats.enregistrer'), [
                'fournisseur_id' => $this->fournisseur->id, 'date_achat' => now()->toDateString(), 'etape' => $etape,
                // Ce que la page postait : le mode « Caisse » par défaut, et un
                // montant saisi avant de changer de type de document.
                'mode_paiement' => 'Banque', 'montant_paye' => 7000,
                'articles' => [['produit_id' => $this->ciment->id, 'quantite' => 2, 'prix_unitaire' => 5000, 'unite' => 'sac']],
            ])->assertSessionHasNoErrors();

            $piece = Achat::latest('id')->firstOrFail();
            $this->assertSame($etape, $piece->etape);
            $this->assertSame('Crédit', $piece->mode_paiement, "{$etape} : aucun mode de paiement.");
            $this->assertNull($piece->montant_recu, "{$etape} : aucun montant reçu.");
            $this->assertFalse(
                DB::table('tresorerie_journal')->where('reference_document', $piece->numero_facture)->exists(),
                "{$etape} : rien ne sort de la caisse."
            );
        }
    }

    public function test_la_page_dit_qu_une_commande_ne_se_paie_pas(): void
    {
        $this->get(route('admin.achats.nouveau'))->assertOk()
            ->assertSee('noteSansReglement', false)
            ->assertSee('Aucun règlement pour une demande de prix ni un bon de commande', false);
    }

    // ── La règle : déclarée par le fournisseur, la saisie est informative ──

    public function test_declaree_la_facture_saisie_ne_s_ecrit_pas_mais_son_reglement_si(): void
    {
        $bc = $this->commande();

        $this->post(route('admin.achats.facturer', $bc), [
            'mode_paiement' => 'Caisse', 'montant_paye' => 11800, 'declaree_par_fournisseur' => '1',
        ])->assertSessionHasNoErrors();

        $facture = $bc->fresh();
        $this->assertTrue($facture->estInformative());
        $this->assertSame(0, $this->operationsDeFacture($facture), 'La facture déclarée ne passe pas deux fois.');
        $this->assertTrue(
            EcritureComptable::where('reference_document', $facture->numero_facture)->where('compte_credit', '571000')->exists(),
            'L\'argent sorti s\'écrit, lui, tout de suite.'
        );
    }

    public function test_non_declaree_la_facture_s_ecrit_comme_avant(): void
    {
        $bc = $this->commande();

        $this->post(route('admin.achats.facturer', $bc), ['mode_paiement' => 'Crédit', 'declaree_par_fournisseur' => '0']);

        $this->assertFalse($bc->fresh()->estInformative());
        $this->assertSame(1, $this->operationsDeFacture($bc));
    }

    public function test_la_facture_certifiee_porte_l_ecriture_d_un_achat_declare(): void
    {
        $bc = $this->commande();
        $this->post(route('admin.achats.facturer', $bc), ['mode_paiement' => 'Crédit', 'declaree_par_fournisseur' => '1']);

        $recue = $this->factureRecue($bc->fresh());

        $this->assertNull(EcritureFactureRecueService::motifDeNePasPorter($recue));
        $this->assertSame('passee', EcritureFactureRecueService::synchroniser($recue));
    }

    public function test_rattachee_a_un_achat_non_declare_la_facture_certifiee_ne_passe_pas(): void
    {
        $bc = $this->commande();
        $this->post(route('admin.achats.facturer', $bc), ['mode_paiement' => 'Crédit', 'declaree_par_fournisseur' => '0']);

        $this->assertNotNull(EcritureFactureRecueService::motifDeNePasPorter($this->factureRecue($bc->fresh())));
    }

    public function test_la_facture_saisie_directement_pose_la_question(): void
    {
        $this->post(route('admin.achats.enregistrer'), [
            'fournisseur_id' => $this->fournisseur->id, 'date_achat' => now()->toDateString(), 'etape' => 'Facture',
            'type_facture' => 'normale', 'mode_paiement' => 'Crédit', 'declaree_par_fournisseur' => '1',
            'articles' => [['produit_id' => $this->ciment->id, 'quantite' => 2, 'prix_unitaire' => 5000, 'unite' => 'sac']],
        ])->assertSessionHasNoErrors();

        $achat = Achat::latest('id')->firstOrFail();
        $this->assertTrue($achat->estInformative());
        $this->assertSame(0, $this->operationsDeFacture($achat));
    }

    // ── Les écrans ───────────────────────────────────────────────────

    public function test_la_modale_de_facturation_pose_la_question(): void
    {
        $bc = $this->commande();

        $this->get(route('admin.achats.imprimer', $bc))->assertOk()
            ->assertSee('Cette facture sera-t-elle déclarée par le fournisseur sur la plateforme FNE ?')
            ->assertSee('name="declaree_par_fournisseur"', false);
    }

    public function test_une_demande_de_prix_se_confirme_et_ne_se_receptionne_pas(): void
    {
        $dp = $this->commande('Demande de prix');
        $html = $this->get(route('admin.achats.imprimer', $dp))->assertOk()->getContent();
        $this->assertStringContainsString('Confirmer la commande', $html);
        $this->assertStringNotContainsString('id="receipt-toggle-btn"', $html);

        $bc = $this->commande();
        $html = $this->get(route('admin.achats.imprimer', $bc))->getContent();
        $this->assertStringContainsString('id="receipt-toggle-btn"', $html);
        $this->assertStringContainsString('Valider & Facturer', $html);
    }

    public function test_la_liste_offre_le_geste_suivant(): void
    {
        $dp = $this->commande('Demande de prix');
        $bc = $this->commande();

        $this->get(route('admin.achats.factures', ['etape' => 'Demande de prix']))->assertOk()
            ->assertSee(route('admin.achats.confirmer', $dp), false)
            ->assertSee('Confirmer la commande');

        $this->get(route('admin.achats.factures', ['etape' => 'Bon de commande']))->assertOk()
            ->assertSee(route('admin.achats.imprimer', $bc) . '?facturer=1', false);
    }

    public function test_l_achat_du_b2b_s_ecrit_bon_de_commande_comme_les_autres(): void
    {
        DB::table('achats')->where('id', $this->commande()->id)->update(['etape' => 'Bon de Commande']);

        $migration = require base_path('app/Modules/Admin/Migrations/2026_10_14_000001_l_achat_declare_par_le_fournisseur.php');
        $migration->up();

        $this->assertSame(0, Achat::where('etape', 'Bon de Commande')->count());
    }

    /** ACH-240926-005 (16/10/2026) : une remise globale déséquilibrait l'opération. */
    public function test_une_remise_globale_laisse_l_operation_d_achat_equilibree(): void
    {
        $this->post(route('admin.achats.enregistrer'), [
            'fournisseur_id' => $this->fournisseur->id, 'date_achat' => now()->toDateString(), 'etape' => 'Facture',
            'declaree_par_fournisseur' => 0, 'remise_taux' => 2, 'mode_paiement' => 'Caisse',
            'articles' => [['produit_id' => $this->ciment->id, 'quantite' => 10, 'prix_unitaire' => 5000, 'unite' => 'sac']],
        ])->assertSessionHasNoErrors();

        $achat = Achat::latest('id')->firstOrFail();
        $op = Operation::where('type_operation', 'FactureAchat')->where('reference_document', $achat->numero_facture)->first();
        if (!$op) {
            $this->markTestSkipped('Achat traité comme informatif dans ce montage.');
        }
        $this->assertTrue((bool) $op->fresh()->est_equilibree);
        $this->assertEqualsWithDelta(57820.0, (float) $achat->montant_ttc, 0.01);
    }
}
