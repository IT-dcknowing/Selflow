<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFactureRecue;
use App\Modules\Admin\Modeles\PortailFneFactureRecueLigne;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\EcritureFactureRecueService;
use App\Modules\Admin\Services\ImportFacturesRecuesService;
use App\Modules\Admin\Services\RapprochementAutomatiqueService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La facture fournisseur reçue de la DGI passe en écriture — une fois.
 *
 * Décision du propriétaire du 06/10/2026, qui renverse la règle du 05/10 :
 *
 * 1. les achats récupérés du portail passent en écriture, comme les ventes ;
 * 2. les BAPA passent en écriture — par leur achat, et jamais une seconde fois
 *    quand le relevé les ramène ;
 * 3. les achats saisis passent en écriture — et l'écran prévient qu'une
 *    facture normalisée ne se saisit pas.
 */
class FactureRecuePasseEnEcritureTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'RNI',
            'adresse' => 'Riviera II', 'rccm' => 'CI-ABJ-2018-B-31734',
            'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'achats', 'tiers'],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-ecriture@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);
    }

    private function uneFactureRecue(array $ajouts = []): PortailFneFactureRecue
    {
        $import = PortailFneImport::create([
            'entreprise_id'     => $this->entreprise->id,
            'login'             => '1864699A',
            'date_scraping'     => '2026-10-06',
            'type'              => ImportFacturesRecuesService::TYPE,
            'fichier_nom'       => 'releve.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)),
            'statut'            => PortailFneImport::STATUT_IMPORTE,
        ]);

        return PortailFneFactureRecue::create(array_merge([
            'import_id'            => $import->id,
            'entreprise_id'        => $this->entreprise->id,
            'point_de_vente_id'    => $this->site->id,
            'login'                => '1864699A',
            'date_scraping'        => '2026-10-06',
            'reference'            => 'B0000001X26000000042',
            'emetteur_nom'         => 'SOCIÉTÉ ALPHA',
            'emetteur_ncc'         => '1122334B',
            'date_facture'         => '2026-10-05',
            'montant_ht'           => 10000,
            'montant_tva'          => 1800,
            'montant_ttc'          => 11800,
            'timbre_fiscal'        => 100,
            'net_a_payer'          => 11900,
            'statut_rapprochement' => PortailFneFactureRecue::A_RAPPROCHER,
        ], $ajouts));
    }

    /** @return array{debit: float, credit: float} */
    private function soldes(string $reference): array
    {
        $lignes = EcritureComptable::where('reference_document', $reference)->get();

        return ['debit' => round((float) $lignes->sum('debit'), 2), 'credit' => round((float) $lignes->sum('credit'), 2)];
    }

    private function montantSur(string $reference, string $compte, string $sens): float
    {
        return round((float) EcritureComptable::where('reference_document', $reference)
            ->where($sens === 'debit' ? 'compte_debit' : 'compte_credit', $compte)
            ->sum($sens), 2);
    }

    // ══════════════ Règle 1 : la facture reçue passe ══════════════

    public function test_une_facture_recue_sans_achat_passe_au_journal_des_achats(): void
    {
        $facture = $this->uneFactureRecue();

        EcritureFactureRecueService::pourEntreprise($this->entreprise->id);

        $facture->refresh();
        $this->assertNotNull($facture->operation_id);

        $operation = Operation::find($facture->operation_id);
        $this->assertTrue($operation->est_equilibree);
        $this->assertSame(EcritureFactureRecueService::TYPE_OPERATION, $operation->type_operation);

        $ref = $facture->reference;
        $this->assertSame(10000.0, $this->montantSur($ref, '601000', 'debit'));
        $this->assertSame(1800.0, $this->montantSur($ref, '445200', 'debit'));
        $this->assertSame(100.0, $this->montantSur($ref, '646200', 'debit'));
        $this->assertSame(11900.0, $this->montantSur($ref, '401000', 'credit'));
    }

    public function test_le_fournisseur_est_retrouve_par_son_ncc_et_cree_s_il_manque(): void
    {
        $facture = $this->uneFactureRecue();

        EcritureFactureRecueService::synchroniser($facture);

        // Créé d'après ce que la DGI a certifié, et non le fournisseur divers :
        // Comptaflow doit voir chaque fournisseur sous son propre numéro.
        $fournisseur = Fournisseur::where('entreprise_id', $this->entreprise->id)->where('ncc', '1122334B')->first();
        $this->assertNotNull($fournisseur);
        $this->assertSame('SOCIÉTÉ ALPHA', $fournisseur->nom);

        $ligneTiers = EcritureComptable::where('reference_document', $facture->reference)
            ->where('compte_credit', '401000')->first();
        $this->assertSame($fournisseur->numero_tiers, $ligneTiers->compte_tiers);
        $this->assertNotSame(Fournisseur::NUMERO_DIVERS, $ligneTiers->compte_tiers);
    }

    public function test_le_compte_parametre_de_l_article_est_pris(): void
    {
        Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'GAS-12',
            'nom' => 'Gasoil', 'type' => 'marchandise', 'prix_achat' => 700, 'prix_vente' => 800,
            'taux_tva' => 18, 'compte_achat' => '605300',
        ]);

        $facture = $this->uneFactureRecue();
        PortailFneFactureRecueLigne::create([
            'facture_recue_id' => $facture->id, 'reference_article' => 'GAS-12',
            'designation' => 'Gasoil', 'quantite' => 10, 'prix_unitaire' => 1000,
        ]);

        EcritureFactureRecueService::synchroniser($facture->load('lignes'));

        $this->assertSame(10000.0, $this->montantSur($facture->reference, '605300', 'debit'));
        // La TVA suit la même ventilation que les achats saisis : 60x en 4452.
        $this->assertSame(1800.0, $this->montantSur($facture->reference, '445200', 'debit'));
        $this->assertSame(0.0, $this->montantSur($facture->reference, '601000', 'debit'));
    }

    public function test_relancer_ne_passe_rien_deux_fois(): void
    {
        $facture = $this->uneFactureRecue();

        EcritureFactureRecueService::pourEntreprise($this->entreprise->id);
        EcritureFactureRecueService::pourEntreprise($this->entreprise->id);
        $this->artisan('selflow:ecritures-factures-recues')->assertSuccessful();

        $this->assertSame(1, Operation::where('reference_document', $facture->reference)->count());
        $this->assertSame(['debit' => 11900.0, 'credit' => 11900.0], $this->soldes($facture->reference));
    }

    public function test_un_avoir_recu_inverse_les_sens(): void
    {
        $facture = $this->uneFactureRecue(['subtype' => 'refund', 'reference' => 'A0000001X26000000007']);

        EcritureFactureRecueService::synchroniser($facture);

        $this->assertSame(11900.0, $this->montantSur($facture->reference, '401000', 'debit'));
        $this->assertSame(10000.0, $this->montantSur($facture->reference, '601000', 'credit'));
    }

    public function test_une_proforma_et_une_facture_ecartee_ne_passent_pas(): void
    {
        $proforma = $this->uneFactureRecue(['subtype' => 'proforma', 'reference' => 'P1']);
        $ecartee  = $this->uneFactureRecue(['reference' => 'E1', 'statut_rapprochement' => PortailFneFactureRecue::ECARTEE]);

        EcritureFactureRecueService::pourEntreprise($this->entreprise->id);

        $this->assertNull($proforma->refresh()->operation_id);
        $this->assertNull($ecartee->refresh()->operation_id);
        $this->assertSame(0, EcritureComptable::whereIn('reference_document', ['P1', 'E1'])->count());
    }

    // ══════════════ Ne jamais compter deux fois ══════════════

    public function test_rattachee_a_un_achat_c_est_l_achat_qui_porte_et_la_facture_se_contre_passe(): void
    {
        // La facture arrive d'abord, seule : elle passe.
        $facture = $this->uneFactureRecue(['timbre_fiscal' => 0, 'net_a_payer' => 11800]);
        EcritureFactureRecueService::pourEntreprise($this->entreprise->id);
        $this->assertNotNull($facture->refresh()->operation_id);

        // Puis l'achat est saisi : le rapprochement le reconnaît, et la
        // facture se contre-passe — sans quoi la charge compterait deux fois.
        $fournisseur = Fournisseur::where('ncc', '1122334B')->first();
        Achat::create([
            'point_de_vente_id' => $this->site->id, 'fournisseur_id' => $fournisseur->id,
            'numero_facture' => 'ACH-0001', 'date_achat' => '2026-10-05', 'etape' => 'Facture',
            'montant_ht' => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
            'mode_paiement' => 'Caisse', 'statut' => 'Payé',
        ]);

        $facture->refresh();
        $this->assertNotNull($facture->achat_id);
        $this->assertNull($facture->operation_id);

        // La pièce de la DGI revient à zéro : passée puis contre-passée.
        $this->assertSame(0.0, round($this->montantSur($facture->reference, '601000', 'debit')
            - $this->montantSur($facture->reference, '601000', 'credit'), 2));
        $this->assertSame(1, Operation::where('reference_document', $facture->reference)
            ->where('type_operation', EcritureFactureRecueService::TYPE_ANNULATION)->count());
    }

    public function test_rattachee_d_avance_elle_ne_passe_jamais(): void
    {
        $fournisseur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'SOCIÉTÉ ALPHA', 'ncc' => '1122334B']);
        Achat::create([
            'point_de_vente_id' => $this->site->id, 'fournisseur_id' => $fournisseur->id,
            'numero_facture' => 'ACH-0002', 'date_achat' => '2026-10-05', 'etape' => 'Facture',
            'montant_ht' => 10000, 'montant_tva' => 1800, 'montant_ttc' => 11800,
            'mode_paiement' => 'Caisse', 'statut' => 'Payé',
        ]);
        $facture = $this->uneFactureRecue(['timbre_fiscal' => 0, 'net_a_payer' => 11800]);

        RapprochementAutomatiqueService::pourEntreprise($this->entreprise->id);
        EcritureFactureRecueService::pourEntreprise($this->entreprise->id);

        $this->assertNotNull($facture->refresh()->achat_id);
        $this->assertSame(0, Operation::where('reference_document', $facture->reference)->count());
    }

    public function test_notre_propre_bapa_ramene_par_le_releve_ne_passe_pas(): void
    {
        // Émis par nous : l'émetteur porte notre NCC.
        $parNcc = $this->uneFactureRecue(['reference' => 'BAPA-1', 'subtype' => 'purchase_slip', 'emetteur_ncc' => '1864699A']);

        // Ou sa référence est le numéro FNE d'un de nos achats.
        $fournisseur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Planteur', 'type_facturation' => 'B2C']);
        $achat = Achat::create([
            'point_de_vente_id' => $this->site->id, 'fournisseur_id' => $fournisseur->id,
            'numero_facture' => 'BAPA-0002', 'date_achat' => '2026-09-01', 'etape' => 'Facture',
            'type_facture' => 'bapa', 'montant_ht' => 50000, 'montant_tva' => 0, 'montant_ttc' => 50000,
            'mode_paiement' => 'Caisse', 'statut' => 'Payé',
        ]);
        $achat->forceFill(['numero_fne' => 'BAPA-FNE-2'])->saveQuietly();
        $parReference = $this->uneFactureRecue(['reference' => 'BAPA-FNE-2', 'subtype' => 'purchase_slip', 'emetteur_ncc' => '9999999Z', 'net_a_payer' => 50000, 'montant_tva' => 0, 'timbre_fiscal' => 0]);

        EcritureFactureRecueService::pourEntreprise($this->entreprise->id);

        $this->assertNull($parNcc->refresh()->operation_id);
        $this->assertNull($parReference->refresh()->operation_id);
        $this->assertSame(0, Operation::whereIn('reference_document', ['BAPA-1', 'BAPA-FNE-2'])->count());
    }

    public function test_ecarter_contre_passe_et_reintegrer_repasse(): void
    {
        $facture = $this->uneFactureRecue();
        EcritureFactureRecueService::synchroniser($facture);

        $this->actingAs($this->admin)
            ->post(route('admin.achats.factures_recues.ecarter', $facture))
            ->assertRedirect();

        $this->assertNull($facture->refresh()->operation_id);
        $this->assertSame(['debit' => 23800.0, 'credit' => 23800.0], $this->soldes($facture->reference));

        $this->actingAs($this->admin)
            ->post(route('admin.achats.factures_recues.reintegrer', $facture))
            ->assertRedirect();

        $this->assertNotNull($facture->refresh()->operation_id);
    }

    public function test_le_releve_suivant_ne_defait_pas_un_ecartement(): void
    {
        $facture = $this->uneFactureRecue(['statut_rapprochement' => PortailFneFactureRecue::ECARTEE]);

        // Le relevé redépose la même pièce.
        $methode = new \ReflectionMethod(ImportFacturesRecuesService::class, 'rangerLesFactures');
        $methode->invoke(app(ImportFacturesRecuesService::class), $facture->import, [[
            'reference' => $facture->reference, 'company' => ['ncc' => '1122334B', 'name' => 'SOCIÉTÉ ALPHA'],
            'totalBeforeTaxes' => 10000, 'totalTaxes' => 1800, 'totalAfterTaxes' => 11800, 'items' => [],
        ]]);

        $this->assertSame(PortailFneFactureRecue::ECARTEE, $facture->refresh()->statut_rapprochement);
    }

    // ══════════════ Règle 3 : l'écran prévient ══════════════

    public function test_l_ecran_de_saisie_previent_qu_une_facture_normalisee_ne_se_saisit_pas(): void
    {
        $this->entreprise->forceFill(['comptabilite_activee' => true])->save();
        $this->admin->unsetRelation('entreprise');

        $this->actingAs($this->admin)
            ->get(route('admin.achats.nouveau'))
            ->assertOk()
            ->assertSee('Tout achat enregistré ici passe en écriture comptable.')
            ->assertSee('ne les saisissez pas ici', false);
    }

    public function test_comptabilite_fermee_l_ecran_de_saisie_ne_parle_pas_d_ecriture(): void
    {
        // Propriétaire, 08/10/2026 : la note ne paraît que comptabilité ouverte.
        $this->entreprise->forceFill(['comptabilite_activee' => false])->save();
        $this->admin->unsetRelation('entreprise');

        $this->actingAs($this->admin)
            ->get(route('admin.achats.nouveau'))
            ->assertOk()
            ->assertDontSee('Tout achat enregistré ici passe en écriture comptable.');
    }
}
