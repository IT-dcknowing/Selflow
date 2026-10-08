<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\BalanceService;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La balance de contrôle.
 *
 * Selflow écrit les écritures, Comptaflow les exploite. Mais un client sans
 * abonnement Comptaflow n'avait **aucun moyen de vérifier ce que Selflow avait
 * écrit** : les écritures existaient en base, et nulle part un écran ne les
 * totalisait. Une erreur d'imputation ne se voyait donc jamais.
 */
class BalanceTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $magasin;
    private PointDeVente $depot;
    private Produit $riz;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Boutique du carrefour',
            'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-1', 'ncc' => '2601234A',
            'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'comptabilite'],
            // Cette entreprise tient ses livres dans Selflow : depuis le
            // lot 39, cela se demande. Sans la case, les ecrans comptables
            // repondent 404 (Not Found -- introuvable).
            'comptabilite_activee' => true,
        ]);
        // La balance s'accorde à part (08/10/2026) : la comptabilité ouverte
        // ne la montre pas.
        $this->entreprise->forceFill(['attributions' => ['balance']])->save();

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->depot = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Dépôt de Bouaké', 'ville' => 'Bouaké', 'commune' => 'Bouaké',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'OD', 'intitule' => 'Opérations diverses', 'type' => 'OD',
        ]);

        PlanComptable::create([
            'entreprise_id' => $this->entreprise->id,
            'numero' => '311000', 'libelle' => 'Marchandises — Vivres et alimentation',
        ]);

        $vivres = Categorie::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Vivres', 'prefixe' => 'VIV',
            'compte_vente' => '701000', 'compte_achat' => '601000',
            'compte_stock' => '311000', 'compte_variation' => '603100',
        ]);

        $this->riz = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'VIV-001',
            'nom' => 'Riz sac 25 kg', 'type' => 'marchandise', 'unite' => 'sac',
            'prix_achat' => 12000, 'prix_vente' => 15000,
            'categorie_id' => $vivres->id,
        ]);
    }

    private function balance(?int $siteId = null): array
    {
        return BalanceService::etablir($this->entreprise->id, null, null, $siteId);
    }

    private function ligne(array $balance, string $compte): ?array
    {
        foreach ($balance['lignes'] as $ligne) {
            if ($ligne['compte'] === $compte) {
                return $ligne;
            }
        }

        return null;
    }

    // ── Ce que la balance doit dire ──────────────────────────────────

    public function test_une_balance_vide_est_equilibree(): void
    {
        $balance = $this->balance();

        $this->assertSame([], $balance['lignes']);
        $this->assertTrue($balance['equilibree']);
    }

    public function test_une_entree_en_stock_apparait_des_deux_cotes(): void
    {
        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);

        $balance = $this->balance();

        $this->assertSame(120000.0, $this->ligne($balance, '311000')['debit']);
        $this->assertSame(120000.0, $this->ligne($balance, '603100')['credit']);
    }

    public function test_le_solde_porte_son_sens(): void
    {
        // Positif = debiteur, negatif = crediteur. Une seule colonne signee :
        // l'ecran presente ce qu'il veut, le calcul ne choisit pas pour lui.
        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);

        $balance = $this->balance();

        $this->assertSame(120000.0, $this->ligne($balance, '311000')['solde']);
        $this->assertSame(-120000.0, $this->ligne($balance, '603100')['solde']);
    }

    public function test_les_totaux_se_repondent(): void
    {
        // C'est le controle qui prime sur tous les autres : si les debits ne
        // valent pas les credits, une ecriture est incomplete.
        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);
        StockService::sortie($this->riz, $this->magasin->id, 4, MouvementStock::LIVRAISON);

        $balance = $this->balance();

        $this->assertSame($balance['total_debit'], $balance['total_credit']);
        $this->assertSame(0.0, $balance['ecart']);
        $this->assertTrue($balance['equilibree']);
    }

    public function test_un_compte_se_nomme_depuis_le_plan_de_l_entreprise(): void
    {
        // Le plan de l'entreprise fait foi : c'est celui que l'utilisateur a
        // sous les yeux.
        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);

        $this->assertSame(
            'Marchandises — Vivres et alimentation',
            $this->ligne($this->balance(), '311000')['libelle']
        );
    }

    public function test_un_compte_absent_du_plan_se_nomme_quand_meme(): void
    {
        // `603100` n'est pas au plan de cette entreprise : le dictionnaire
        // OHADA sert de repli, plutot que d'afficher un numero nu.
        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);

        $libelle = $this->ligne($this->balance(), '603100')['libelle'];

        $this->assertNotSame('', $libelle);
        $this->assertNotSame('603100', $libelle);
    }

    public function test_les_comptes_sont_ordonnes(): void
    {
        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);

        $comptes = array_column($this->balance()['lignes'], 'compte');
        $tries = $comptes;
        sort($tries, SORT_STRING);

        $this->assertSame($tries, $comptes);
    }

    // ── Le filtre par site ───────────────────────────────────────────

    public function test_la_balance_se_restreint_a_un_site(): void
    {
        // Un gerant doit pouvoir controler un magasin sans les autres.
        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);
        StockService::entree($this->riz, $this->depot->id, 5, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);

        $this->assertSame(180000.0, $this->balance()['total_debit']);
        $this->assertSame(120000.0, $this->balance($this->magasin->id)['total_debit']);
        $this->assertSame(60000.0, $this->balance($this->depot->id)['total_debit']);
    }

    // ── Le signal d'imputation ───────────────────────────────────────

    public function test_les_comptes_generiques_se_signalent(): void
    {
        // Une ligne en 701000 alors que les rayons portent leurs comptes
        // signale un article cree a la main, sans rayon.
        $lignes = [
            ['compte' => '311000', 'debit' => 100.0, 'credit' => 0.0],
            ['compte' => '701000', 'debit' => 0.0, 'credit' => 100.0],
        ];

        $this->assertSame(['701000'], BalanceService::comptesGeneriquesUtilises($lignes));
    }

    public function test_aucun_compte_generique_ne_se_signale_a_tort(): void
    {
        $lignes = [['compte' => '701200', 'debit' => 0.0, 'credit' => 100.0]];

        $this->assertSame([], BalanceService::comptesGeneriquesUtilises($lignes));
    }

    // ── L'écran ──────────────────────────────────────────────────────

    public function test_l_ecran_affiche_la_balance(): void
    {
        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);

        $this->actingAs($this->admin)
            ->get(route('admin.comptabilite.balance'))
            ->assertOk()
            ->assertSee('311000')
            ->assertSee('La balance est équilibrée');
    }

    // ── Le bordereau d'achat ne déduit aucune TVA ────────────────────

    public function test_un_bapa_ne_credite_aucune_tva_deductible(): void
    {
        // Un bordereau constate un achat aupres d'un tiers **non immatricule** :
        // il ne facture aucune TVA, et il n'y a donc rien a deduire. Le taux du
        // catalogue s'appliquait pourtant, et l'ecriture debitait un compte 445
        // sur une taxe que personne n'avait payee : ce n'est pas une
        // imprecision comptable, c'est une deduction indue.
        $this->riz->update(['taux_tva' => 18]);

        $fournisseur = \App\Modules\Admin\Modeles\Fournisseur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Planteur du village',
        ]);

        $bapa = \App\Modules\Admin\Modeles\Achat::create([
            'point_de_vente_id' => $this->magasin->id,
            'fournisseur_id'    => $fournisseur->id,
            'utilisateur_id'    => $this->admin->id,
            'numero_facture'    => 'BAPA-0001',
            'date_achat'        => now(),
            'mode_paiement'     => 'Espèces',
            'type_facture'      => 'bapa',
            'montant_ht'        => 100000,
            'montant_tva'       => 18000, // saisi a tort : ne doit pas ressortir
            'montant_ttc'       => 100000,
        ]);

        \App\Modules\Admin\Modeles\AchatDetail::create([
            'achat_id' => $bapa->id, 'produit_id' => $this->riz->id,
            'quantite' => 10, 'prix_unitaire' => 10000,
            'montant_tva' => 0, 'montant_ttc' => 100000,
        ]);

        \App\Modules\Admin\Services\ComptabiliteService::genererEcrituresAchat(
            $bapa->fresh(), 100000, 'Espèces'
        );

        $tvaDeductible = config('selflow.plan_comptable_defaut.tva_deductible');

        $this->assertNull(
            $this->ligne($this->balance(), $tvaDeductible),
            "Un bordereau d'achat ne doit produire aucune TVA déductible."
        );

        $this->assertTrue($this->balance()['equilibree']);
    }

    public function test_un_achat_ordinaire_deduit_bien_sa_tva(): void
    {
        // Le pendant indispensable : ecarter la TVA partout serait aussi faux
        // que la deduire partout.
        $this->riz->update(['taux_tva' => 18]);

        $fournisseur = \App\Modules\Admin\Modeles\Fournisseur::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Grossiste immatriculé',
        ]);

        $achat = \App\Modules\Admin\Modeles\Achat::create([
            'point_de_vente_id' => $this->magasin->id,
            'fournisseur_id'    => $fournisseur->id,
            'utilisateur_id'    => $this->admin->id,
            'numero_facture'    => 'ACH-0001',
            'date_achat'        => now(),
            'mode_paiement'     => 'Espèces',
            'type_facture'      => 'normale',
            'montant_ht'        => 100000,
            'montant_tva'       => 18000,
            'montant_ttc'       => 118000,
        ]);

        \App\Modules\Admin\Modeles\AchatDetail::create([
            'achat_id' => $achat->id, 'produit_id' => $this->riz->id,
            'quantite' => 10, 'prix_unitaire' => 10000,
            'montant_tva' => 18000, 'montant_ttc' => 118000,
        ]);

        \App\Modules\Admin\Services\ComptabiliteService::genererEcrituresAchat(
            $achat->fresh(), 118000, 'Espèces'
        );

        $tvaDeductible = config('selflow.plan_comptable_defaut.tva_deductible');

        $this->assertNotNull($this->ligne($this->balance(), $tvaDeductible));
        $this->assertSame(18000.0, $this->ligne($this->balance(), $tvaDeductible)['debit']);
    }

    public function test_l_ecran_est_ferme_aux_visiteurs(): void
    {
        $this->get(route('admin.comptabilite.balance'))->assertRedirect();
    }

    public function test_le_filtre_de_site_refuse_le_site_d_une_autre_entreprise(): void
    {
        // `?pdv_id=` dans l'URL : sans controle, la page afficherait la balance
        // du concurrent — son chiffre d'affaires, ses achats, ses marges.
        $voisine = Entreprise::create(['nom' => 'Coopérative du Bandama']);
        $siteVoisin = PointDeVente::create([
            'entreprise_id' => $voisine->id,
            'nom' => 'Dépôt voisin', 'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);

        StockService::entree($this->riz, $this->magasin->id, 10, MouvementStock::RECEPTION,
            ['cout_unitaire' => 12000]);

        // Le site etranger n'est pas retenu : on retombe sur « tous les sites »
        // de sa propre entreprise, jamais sur ceux du voisin.
        $this->actingAs($this->admin)
            ->get(route('admin.comptabilite.balance', ['pdv_id' => $siteVoisin->id]))
            ->assertOk()
            ->assertDontSee('Dépôt voisin');
    }
}
