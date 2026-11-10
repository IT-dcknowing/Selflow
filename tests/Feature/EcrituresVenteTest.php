<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Services\ComptabiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La forme des écritures d'une vente.
 *
 * Trois défauts vivaient ici sans que rien ne les signale, parce qu'aucune
 * épreuve ne regardait la forme de l'écriture — seulement son équilibre :
 *
 *  1. **La vente comptant ne passait pas par le 411.** Une seule opération
 *     « caisse contre produits » était écrite. Le compte du client ne bougeait
 *     donc jamais sur ses achats au comptant, le numéro de tiers n'était
 *     transmis à Comptaflow sur aucune de ces ventes — l'écriture y retombait
 *     sur le seul compte collectif — et le journal des ventes ne contenait pas
 *     les ventes comptant, alors que c'est lui qui justifie le chiffre
 *     d'affaires en cas de contrôle.
 *
 *  2. **Le droit de timbre de quittance n'entrait dans aucune écriture.** Le
 *     client le payait — `net_a_payer` le comptait, la facture l'imprimait, la
 *     plateforme le certifiait — mais la caisse était débitée de moins que ce
 *     qui y était réellement entré, et la dette envers l'État n'apparaissait
 *     nulle part.
 *
 *  3. **Toute la TVA collectée partait en 4431.** SYSCOHADA distingue la
 *     marchandise (4431), la prestation de services (4432) et les travaux
 *     (4433), et la déclaration reprend cette distinction.
 */
class EcrituresVenteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Client $client;
    private Produit $riz;
    private Produit $maindoeuvre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom'              => 'Quincaillerie du Plateau',
            'timbre_quittance' => true,
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Plateau',
        ]);

        foreach ([['VTE', 'Ventes', 'Vente'], ['CAI', 'Caisse', 'Trésorerie']] as [$code, $intitule, $type]) {
            CodeJournal::create([
                'entreprise_id' => $this->entreprise->id,
                'code' => $code, 'intitule' => $intitule, 'type' => $type,
            ]);
        }

        $this->client = Client::create([
            'entreprise_id'    => $this->entreprise->id,
            'nom'              => 'Konan Yao',
            'compte_comptable' => '411000',
            'numero_tiers'     => '410007',
        ]);

        $marchandises = Categorie::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Marchandises', 'prefixe' => 'MAR',
            'compte_vente' => '701000',
        ]);

        $services = Categorie::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Prestations', 'prefixe' => 'SER',
            'compte_vente' => '706000',
        ]);

        $this->riz = Produit::create([
            'entreprise_id' => $this->entreprise->id,
            'reference' => 'RIZ-25', 'nom' => 'Riz parfumé 25 kg', 'type' => 'marchandise',
            'prix_achat' => 12000, 'prix_vente' => 20000, 'categorie_id' => $marchandises->id,
        ]);

        $this->maindoeuvre = Produit::create([
            'entreprise_id' => $this->entreprise->id,
            'reference' => 'MO-H', 'nom' => 'Main-d\'œuvre horaire', 'type' => 'service',
            'prix_achat' => 0, 'prix_vente' => 10000, 'categorie_id' => $services->id,
        ]);
    }

    /**
     * Une vente et ses lignes. Les montants sont posés tels qu'ils le seraient
     * à la saisie ; le service ne les recalcule pas.
     *
     * @param  array<int, array{produit: Produit, quantite: float, prix: float, tva: float}>  $lignes
     */
    private function vente(array $lignes, array $attributs = []): Vente
    {
        $ht  = 0.0;
        $tva = 0.0;
        foreach ($lignes as $l) {
            $ht  += $l['quantite'] * $l['prix'];
            $tva += $l['tva'];
        }

        $vente = Vente::create(array_merge([
            'point_de_vente_id' => $this->site->id,
            'client_id'         => $this->client->id,
            'numero_facture'    => 'FAC-' . uniqid(),
            'date_vente'        => '2026-08-21',
            'mode_paiement'     => 'especes',
            'montant_ht'        => $ht,
            'montant_tva'       => $tva,
            'montant_ttc'       => $ht + $tva,
            'statut'            => 'payee',
            'type_facture'      => 'normale',
            'etape'             => 'Facture',
        ], $attributs));

        foreach ($lignes as $l) {
            VenteDetail::create([
                'vente_id'      => $vente->id,
                'produit_id'    => $l['produit']->id,
                'quantite'      => $l['quantite'],
                'prix_unitaire' => $l['prix'],
                'montant_tva'   => $l['tva'],
                'montant_ttc'   => $l['quantite'] * $l['prix'] + $l['tva'],
            ]);
        }

        return $vente->fresh();
    }

    /** @return \Illuminate\Support\Collection<int, EcritureComptable> */
    private function ecrituresDe(Operation $operation)
    {
        return EcritureComptable::where('operation_id', $operation->id)->get();
    }

    private function operations(Vente $vente)
    {
        return Operation::where('entreprise_id', $this->entreprise->id)
            ->where('reference_document', $vente->numero_facture)
            ->orderBy('id')
            ->get();
    }

    // ── 1. Le passage par le compte client ───────────────────────────

    public function test_une_vente_comptant_passe_par_le_compte_client(): void
    {
        // C'est le defaut principal : une vente comptant n'ecrivait qu'une
        // operation « caisse contre produits », sans jamais toucher le 411.
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 2, 'prix' => 20000, 'tva' => 7200],
        ]);

        ComptabiliteService::genererEcrituresVente($vente, $vente->net_a_payer, 'especes');

        $operations = $this->operations($vente);

        $this->assertCount(2, $operations, 'Une facturation, puis un règlement.');
        $this->assertSame('FactureVente', $operations[0]->type_operation);
        $this->assertSame('ReglementVente', $operations[1]->type_operation);

        $debitClient = $this->ecrituresDe($operations[0])
            ->firstWhere('compte_debit', '411000');

        $this->assertNotNull($debitClient, 'La facturation doit débiter le compte client.');
        $this->assertSame('410007', $debitClient->compte_tiers,
            'Sans le numéro de tiers, Comptaflow retombe sur le seul compte collectif.');
    }

    public function test_le_reglement_comptant_solde_le_compte_client(): void
    {
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ]);

        ComptabiliteService::genererEcrituresVente($vente, $vente->net_a_payer, 'especes');

        $solde = EcritureComptable::where('entreprise_id', $this->entreprise->id)
            ->where('reference_document', $vente->numero_facture)
            ->get()
            ->reduce(function ($porte, $e) {
                if ($e->compte_debit === '411000')  $porte += (float) $e->debit;
                if ($e->compte_credit === '411000') $porte -= (float) $e->credit;
                return $porte;
            }, 0.0);

        $this->assertEqualsWithDelta(0, $solde, 0.01,
            'Une vente réglée intégralement ne doit rien laisser au compte du client.');
    }

    public function test_la_facturation_est_au_journal_des_ventes(): void
    {
        // La vente comptant partait au journal de caisse : le journal des
        // ventes, celui qui justifie le chiffre d'affaires, l'ignorait.
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ]);

        ComptabiliteService::genererEcrituresVente($vente, $vente->net_a_payer, 'especes');

        $facturation = $this->operations($vente)[0];

        $this->assertSame('VTE', $facturation->code_journal);
        foreach ($this->ecrituresDe($facturation) as $ecriture) {
            $this->assertSame('VTE', $ecriture->code_journal);
        }
    }

    public function test_la_date_de_facture_est_reprise_dans_les_operations_comptables(): void
    {
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ], ['date_vente' => '2026-08-21']);

        ComptabiliteService::genererEcrituresVente(
            $vente,
            $vente->net_a_payer,
            'especes',
            $vente->date_vente->toDateString()
        );

        $facturation = $this->operations($vente)->first();
        $this->assertSame('2026-08-21', $facturation->date_operation->toDateString());
        $this->assertSame('2026-08-21', $this->ecrituresDe($facturation)->first()->date_ecriture->toDateString());
    }

    public function test_une_vente_a_credit_n_ecrit_aucun_reglement(): void
    {
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ], ['statut' => 'impayee']);

        ComptabiliteService::genererEcrituresVente($vente, 0, 'especes');

        $operations = $this->operations($vente);

        $this->assertCount(1, $operations);
        $this->assertSame('FactureVente', $operations[0]->type_operation);
    }

    public function test_un_acompte_ecrit_un_reglement_partiel(): void
    {
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ], ['statut' => 'partiellement_payee']);

        ComptabiliteService::genererEcrituresVente($vente, 10000, 'especes');

        $operations = $this->operations($vente);

        $this->assertCount(2, $operations);
        $this->assertSame('Acompte à la facturation', $operations[1]->libelle_general);

        $creditClient = $this->ecrituresDe($operations[1])->firstWhere('compte_credit', '411000');
        $this->assertEqualsWithDelta(10000, (float) $creditClient->credit, 0.01);
    }

    // ── 2. Le droit de timbre de quittance ───────────────────────────

    public function test_le_timbre_de_quittance_est_porte_au_compte_de_l_etat(): void
    {
        // 23 600 F encaisses en especes : tranche 5 001 – 100 000, soit 100 F
        // de droit (article 873 du CGI).
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ]);

        $this->assertEqualsWithDelta(100, $vente->timbre_quittance, 0.01,
            'Le barème doit retenir 100 F sur cette tranche.');

        ComptabiliteService::genererEcrituresVente($vente, $vente->net_a_payer, 'especes');

        $timbre = $this->ecrituresDe($this->operations($vente)[0])
            ->firstWhere('compte_credit', '447800');

        $this->assertNotNull($timbre, 'Le timbre encaissé du client est une dette envers l\'État.');
        $this->assertEqualsWithDelta(100, (float) $timbre->credit, 0.01);
    }

    public function test_le_compte_client_est_debite_du_net_a_payer_timbre_compris(): void
    {
        // Le defaut se lisait ici : le client reglait 23 700 F et la
        // comptabilite n'en constatait que 23 600.
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ], ['montant_autres_taxes' => 500]);

        ComptabiliteService::genererEcrituresVente($vente, $vente->net_a_payer, 'especes');

        $debitClient = $this->ecrituresDe($this->operations($vente)[0])
            ->firstWhere('compte_debit', '411000');

        // 23 600 TTC + 500 de taxes parafiscales + 100 de timbre.
        $this->assertEqualsWithDelta(24200, (float) $debitClient->debit, 0.01);
        $this->assertEqualsWithDelta($vente->net_a_payer, (float) $debitClient->debit, 0.01);
    }

    public function test_sans_option_de_timbre_aucune_ligne_n_est_ecrite(): void
    {
        $this->entreprise->update(['timbre_quittance' => false]);

        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ]);

        ComptabiliteService::genererEcrituresVente($vente->fresh(), 23600, 'especes');

        $this->assertNull(
            $this->ecrituresDe($this->operations($vente)[0])->firstWhere('compte_credit', '447800')
        );
    }

    public function test_un_reglement_par_banque_n_est_pas_timbre(): void
    {
        // Le timbre frappe la quittance ; un virement laisse sa propre trace.
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ], ['mode_paiement' => 'banque : SGCI']);

        ComptabiliteService::genererEcrituresVente($vente, 23600, 'banque : SGCI');

        $this->assertNull(
            $this->ecrituresDe($this->operations($vente)[0])->firstWhere('compte_credit', '447800')
        );
    }

    // ── 3. La ventilation de la TVA collectée ────────────────────────

    public function test_la_tva_sur_marchandises_va_au_4431(): void
    {
        $vente = $this->vente([
            ['produit' => $this->riz, 'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
        ]);

        ComptabiliteService::genererEcrituresVente($vente, $vente->net_a_payer, 'especes');

        $tva = $this->ecrituresDe($this->operations($vente)[0])->firstWhere('compte_credit', '443100');

        $this->assertNotNull($tva);
        $this->assertEqualsWithDelta(3600, (float) $tva->credit, 0.01);
    }

    public function test_la_tva_sur_prestations_va_au_4432(): void
    {
        $vente = $this->vente([
            ['produit' => $this->maindoeuvre, 'quantite' => 3, 'prix' => 10000, 'tva' => 5400],
        ]);

        ComptabiliteService::genererEcrituresVente($vente, $vente->net_a_payer, 'especes');

        $ecritures = $this->ecrituresDe($this->operations($vente)[0]);

        $this->assertNotNull($ecritures->firstWhere('compte_credit', '443200'));
        $this->assertNull($ecritures->firstWhere('compte_credit', '443100'),
            'Une facture de service ne doit rien porter au compte des ventes de marchandises.');
    }

    public function test_une_vente_mixte_repartit_sa_tva_sur_les_deux_comptes(): void
    {
        // Le cas du garage : des pieces et de la main-d'œuvre sur la meme
        // facture. Tout verser en 4431 faussait la declaration.
        $vente = $this->vente([
            ['produit' => $this->riz,         'quantite' => 1, 'prix' => 20000, 'tva' => 3600],
            ['produit' => $this->maindoeuvre, 'quantite' => 2, 'prix' => 10000, 'tva' => 3600],
        ]);

        ComptabiliteService::genererEcrituresVente($vente, $vente->net_a_payer, 'especes');

        $ecritures = $this->ecrituresDe($this->operations($vente)[0]);

        $this->assertEqualsWithDelta(3600, (float) $ecritures->firstWhere('compte_credit', '443100')->credit, 0.01);
        $this->assertEqualsWithDelta(3600, (float) $ecritures->firstWhere('compte_credit', '443200')->credit, 0.01);
    }

    public function test_la_tva_ventilee_egale_toujours_celle_de_la_piece(): void
    {
        // La somme des lignes peut s'ecarter du total annonce — arrondis,
        // remise globale au prorata. Un ecart de deux francs suffit a
        // desequilibrer l'operation : l'ecart est reporte, jamais ignore.
        $vente = $this->vente([
            ['produit' => $this->riz,         'quantite' => 1, 'prix' => 20000, 'tva' => 1200.33],
            ['produit' => $this->maindoeuvre, 'quantite' => 1, 'prix' => 10000, 'tva' => 600.34],
        ], ['montant_tva' => 1800.70]);

        ComptabiliteService::genererEcrituresVente($vente->fresh(), $vente->fresh()->net_a_payer, 'especes');

        $totalTva = $this->ecrituresDe($this->operations($vente)[0])
            ->whereIn('compte_credit', ['443100', '443200', '443300'])
            ->sum(fn ($e) => (float) $e->credit);

        $this->assertEqualsWithDelta(1800.70, $totalTva, 0.001,
            'C\'est le montant de la pièce qui fait foi : c\'est lui que la plateforme certifie.');
    }

    // ── L'équilibre, qui doit tenir dans tous les cas ────────────────

    public function test_chaque_operation_reste_equilibree(): void
    {
        $vente = $this->vente([
            ['produit' => $this->riz,         'quantite' => 3, 'prix' => 20000, 'tva' => 10800],
            ['produit' => $this->maindoeuvre, 'quantite' => 2, 'prix' => 10000, 'tva' => 3600],
        ], ['montant_autres_taxes' => 1250]);

        ComptabiliteService::genererEcrituresVente($vente, $vente->net_a_payer, 'especes');

        foreach ($this->operations($vente) as $operation) {
            $ecritures = $this->ecrituresDe($operation);
            $this->assertEqualsWithDelta(
                $ecritures->sum(fn ($e) => (float) $e->debit),
                $ecritures->sum(fn ($e) => (float) $e->credit),
                0.01,
                "L'opération {$operation->type_operation} doit être équilibrée."
            );
        }
    }
}
