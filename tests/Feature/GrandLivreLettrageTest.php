<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Lettrage;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Services\GrandLivreService;
use App\Modules\Admin\Services\LettrageService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grand livre et lettrage.
 *
 * La logique est reprise de **Comptaflow**, pour que les deux applications
 * disent la même chose du même exercice : plage de comptes, soldes initiaux,
 * solde progressif, colonne de lettrage.
 *
 * **Une différence de structure, et elle compte.** Chez Comptaflow, une écriture
 * porte **un** compte ; une opération en compte donc plusieurs. Dans Selflow,
 * une écriture porte **les deux** comptes sur la même ligne — elle produit par
 * conséquent deux lignes de grand livre.
 */
class GrandLivreLettrageTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Boutique du carrefour',
            'regime_imposition' => 'RNI', 'adresse' => 'Abidjan',
            'rccm' => 'CI-ABJ-2026-B-1', 'ncc' => '2601234A',
            'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'comptabilite'],
            // Cette entreprise tient ses livres dans Selflow : depuis le
            // lot 39, cela se demande. Sans la case, les ecrans comptables
            // repondent 404 (Not Found -- introuvable).
            'comptabilite_activee' => true,
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        foreach ([['411000', 'Clients'], ['701000', 'Ventes de marchandises'], ['571000', 'Caisse']] as [$numero, $libelle]) {
            PlanComptable::create([
                'entreprise_id' => $this->entreprise->id,
                'numero' => $numero, 'libelle' => $libelle,
            ]);
        }
    }

    /**
     * Une écriture : un compte au débit, un au crédit, sur la même ligne.
     */
    private function ecriture(string $date, string $debit, string $credit, float $montant, string $piece = 'FAC-001'): EcritureComptable
    {
        $operation = Operation::creer(
            $this->entreprise->id, $this->site->id, $date, 'test', 'OD', $piece, 'Opération de test'
        );

        return EcritureComptable::create([
            'operation_id'       => $operation->id,
            'entreprise_id'      => $this->entreprise->id,
            'point_de_vente_id'  => $this->site->id,
            'date_ecriture'      => $date,
            'libelle'            => 'Opération de test',
            'reference_document' => $piece,
            'code_journal'       => 'OD',
            'compte_debit'       => $debit,
            'compte_credit'      => $credit,
            'debit'              => $montant,
            'credit'             => $montant,
        ]);
    }

    private function compteDuLivre(array $livre, string $compte): ?array
    {
        foreach ($livre as $ligne) {
            if ($ligne['compte'] === $compte) {
                return $ligne;
            }
        }

        return null;
    }

    // ══════════════════════ Le grand livre ══════════════════════

    public function test_une_ecriture_produit_deux_lignes_de_grand_livre(): void
    {
        // C'est la difference de structure avec Comptaflow : une ecriture de
        // Selflow porte les deux comptes, elle apparait donc sur les deux.
        $this->ecriture('2026-03-10', '411000', '701000', 100000);

        $livre = GrandLivreService::etablir($this->entreprise->id);

        $this->assertCount(1, $this->compteDuLivre($livre, '411000')['lignes']);
        $this->assertCount(1, $this->compteDuLivre($livre, '701000')['lignes']);

        $this->assertSame(100000.0, $this->compteDuLivre($livre, '411000')['lignes'][0]['debit']);
        $this->assertSame(100000.0, $this->compteDuLivre($livre, '701000')['lignes'][0]['credit']);
    }

    public function test_la_contrepartie_figure_sur_chaque_ligne(): void
    {
        // C'est ce qui permet de comprendre une operation sans la rouvrir.
        $this->ecriture('2026-03-10', '411000', '701000', 100000);

        $livre = GrandLivreService::etablir($this->entreprise->id);

        $this->assertSame('701000', $this->compteDuLivre($livre, '411000')['lignes'][0]['contrepartie']);
        $this->assertSame('411000', $this->compteDuLivre($livre, '701000')['lignes'][0]['contrepartie']);
    }

    public function test_le_solde_initial_reprend_ce_qui_precede_la_periode(): void
    {
        // Sans lui, le grand livre d'un mois de mars donnerait le solde **de
        // mars** : un client qui doit 500 000 depuis janvier et n'a rien bouge
        // en mars apparaitrait a zero.
        $this->ecriture('2026-01-15', '411000', '701000', 500000, 'FAC-JAN');
        $this->ecriture('2026-03-10', '411000', '701000', 100000, 'FAC-MAR');

        $livre = GrandLivreService::etablir($this->entreprise->id, null, null, '2026-03-01', '2026-03-31');
        $clients = $this->compteDuLivre($livre, '411000');

        $this->assertSame(500000.0, $clients['solde_initial']);
        $this->assertCount(1, $clients['lignes'], 'Seule la pièce de mars figure aux mouvements.');
        $this->assertSame(600000.0, $clients['solde_final']);
    }

    public function test_le_premier_jour_de_la_periode_n_est_pas_compte_deux_fois(): void
    {
        // Inclure le premier jour dans le report le compterait au report **et**
        // aux mouvements.
        $this->ecriture('2026-03-01', '411000', '701000', 100000);

        $clients = $this->compteDuLivre(
            GrandLivreService::etablir($this->entreprise->id, null, null, '2026-03-01', '2026-03-31'),
            '411000'
        );

        $this->assertSame(0.0, $clients['solde_initial']);
        $this->assertSame(100000.0, $clients['solde_final']);
    }

    public function test_le_solde_progresse_ligne_a_ligne(): void
    {
        // C'est le solde qu'on suit du doigt sur un grand livre imprime.
        $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-1');
        $this->ecriture('2026-03-05', '571000', '411000', 40000, 'REG-1');
        $this->ecriture('2026-03-10', '411000', '701000', 25000, 'FAC-2');

        $clients = $this->compteDuLivre(GrandLivreService::etablir($this->entreprise->id), '411000');

        $this->assertSame([100000.0, 60000.0, 85000.0], array_column($clients['lignes'], 'solde'));
    }

    public function test_la_plage_de_comptes_se_lit_dans_les_deux_sens(): void
    {
        // Un utilisateur qui tape « de 701000 a 411000 » veut la meme chose.
        $this->ecriture('2026-03-01', '411000', '701000', 100000);

        $ordre  = GrandLivreService::etablir($this->entreprise->id, '411000', '701000');
        $envers = GrandLivreService::etablir($this->entreprise->id, '701000', '411000');

        $this->assertSame(array_column($ordre, 'compte'), array_column($envers, 'compte'));
    }

    public function test_un_compte_sans_mouvement_mais_avec_report_figure_quand_meme(): void
    {
        $this->ecriture('2026-01-15', '411000', '701000', 500000);

        $clients = $this->compteDuLivre(
            GrandLivreService::etablir($this->entreprise->id, null, null, '2026-03-01', '2026-03-31'),
            '411000'
        );

        $this->assertNotNull($clients);
        $this->assertSame([], $clients['lignes']);
        $this->assertSame(500000.0, $clients['solde_final']);
    }

    // ══════════════════════ Le lettrage ══════════════════════

    public function test_lettrer_une_facture_et_son_reglement(): void
    {
        $facture = $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-1');
        $reglement = $this->ecriture('2026-03-20', '571000', '411000', 100000, 'REG-1');

        $lettrage = LettrageService::lettrer($this->entreprise->id, '411000', [$facture->id, $reglement->id]);

        $this->assertSame('A', $lettrage->code);
        $this->assertSame($lettrage->id, $facture->fresh()->lettrage_id);
        $this->assertSame($lettrage->id, $reglement->fresh()->lettrage_id);
    }

    public function test_les_codes_suivent_la_convention_comptable(): void
    {
        // A, B, … Z, puis AA. C'est ce qu'un comptable s'attend a lire.
        $codes = [];

        foreach (range(1, 3) as $rang) {
            $a = $this->ecriture('2026-03-01', '411000', '701000', 1000 * $rang, "FAC-{$rang}");
            $b = $this->ecriture('2026-03-20', '571000', '411000', 1000 * $rang, "REG-{$rang}");
            $codes[] = LettrageService::lettrer($this->entreprise->id, '411000', [$a->id, $b->id])->code;
        }

        $this->assertSame(['A', 'B', 'C'], $codes);
    }

    public function test_un_lettrage_desequilibre_est_refuse(): void
    {
        // Lettrer une facture de 100 000 avec un acompte de 40 000 dirait que
        // la creance est eteinte alors qu'il reste 60 000 dus.
        $facture = $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-1');
        $acompte = $this->ecriture('2026-03-20', '571000', '411000', 40000, 'REG-1');

        $this->expectException(\InvalidArgumentException::class);

        LettrageService::lettrer($this->entreprise->id, '411000', [$facture->id, $acompte->id]);
    }

    public function test_une_seule_ecriture_ne_se_lettre_pas(): void
    {
        $facture = $this->ecriture('2026-03-01', '411000', '701000', 100000);

        $this->expectException(\InvalidArgumentException::class);

        LettrageService::lettrer($this->entreprise->id, '411000', [$facture->id]);
    }

    public function test_une_ecriture_deja_lettree_ne_se_relettre_pas(): void
    {
        // La relettrer laisserait la premiere pièce marquee soldee sans l'etre.
        $a = $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-1');
        $b = $this->ecriture('2026-03-20', '571000', '411000', 100000, 'REG-1');
        $c = $this->ecriture('2026-03-21', '571000', '411000', 100000, 'REG-2');

        LettrageService::lettrer($this->entreprise->id, '411000', [$a->id, $b->id]);

        $this->expectException(\InvalidArgumentException::class);

        LettrageService::lettrer($this->entreprise->id, '411000', [$a->id, $c->id]);
    }

    public function test_une_ecriture_d_une_autre_entreprise_n_entre_pas_dans_un_lettrage(): void
    {
        // Sans ce controle, il suffirait de poster l'identifiant d'une ecriture
        // du voisin pour la marquer soldee dans **sa** comptabilite.
        $voisine = Entreprise::create(['nom' => 'Coopérative du Bandama']);
        $siteVoisin = PointDeVente::create([
            'entreprise_id' => $voisine->id, 'nom' => 'Dépôt',
            'ville' => 'Bouaké', 'commune' => 'Bouaké',
        ]);

        $operation = Operation::creer($voisine->id, $siteVoisin->id, '2026-03-01', 'test', 'OD', 'FAC-V', 'Chez le voisin');
        $etrangere = EcritureComptable::create([
            'operation_id' => $operation->id, 'entreprise_id' => $voisine->id,
            'point_de_vente_id' => $siteVoisin->id, 'date_ecriture' => '2026-03-01',
            'libelle' => 'Chez le voisin', 'reference_document' => 'FAC-V', 'code_journal' => 'OD',
            'compte_debit' => '411000', 'compte_credit' => '701000',
            'debit' => 100000, 'credit' => 100000,
        ]);

        $sienne = $this->ecriture('2026-03-01', '571000', '411000', 100000);

        try {
            LettrageService::lettrer($this->entreprise->id, '411000', [$sienne->id, $etrangere->id]);
            $this->fail('Une écriture étrangère ne doit pas entrer dans un lettrage.');
        } catch (\InvalidArgumentException) {
            // Attendu : la requete ne ramene que l'ecriture de notre
            // entreprise, et un lettrage d'une seule ecriture est refuse.
        }

        $this->assertNull($etrangere->fresh()->lettrage_id);
    }

    public function test_delettrer_rouvre_les_ecritures(): void
    {
        $a = $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-1');
        $b = $this->ecriture('2026-03-20', '571000', '411000', 100000, 'REG-1');

        $lettrage = LettrageService::lettrer($this->entreprise->id, '411000', [$a->id, $b->id]);

        $this->assertSame(2, LettrageService::delettrer($lettrage));
        $this->assertNull($a->fresh()->lettrage_id);
        $this->assertSame(0, Lettrage::count());
    }

    public function test_un_code_n_est_jamais_reattribue(): void
    {
        // Un code recycle designerait deux rapprochements differents dans
        // l'histoire du compte, et le grand livre d'un exercice clos
        // deviendrait faux.
        $a = $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-1');
        $b = $this->ecriture('2026-03-20', '571000', '411000', 100000, 'REG-1');

        LettrageService::delettrer(LettrageService::lettrer($this->entreprise->id, '411000', [$a->id, $b->id]));

        $c = $this->ecriture('2026-04-01', '411000', '701000', 50000, 'FAC-2');
        $d = $this->ecriture('2026-04-20', '571000', '411000', 50000, 'REG-2');

        $this->assertSame('A', LettrageService::lettrer($this->entreprise->id, '411000', [$c->id, $d->id])->code,
            'Aucun lettrage ne subsiste : le compteur repart de A, ce qui est correct.');
    }

    public function test_le_reste_du_ignore_ce_qui_est_lettre(): void
    {
        // C'est la reponse a « que me doit-on encore ? ».
        $a = $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-1');
        $b = $this->ecriture('2026-03-20', '571000', '411000', 100000, 'REG-1');
        $this->ecriture('2026-04-01', '411000', '701000', 75000, 'FAC-2');

        // Avant lettrage : 100 000 + 75 000 dus, moins 100 000 encaisses.
        $this->assertSame(75000.0, LettrageService::resteDu($this->entreprise->id, '411000'));

        LettrageService::lettrer($this->entreprise->id, '411000', [$a->id, $b->id]);

        $this->assertSame(75000.0, LettrageService::resteDu($this->entreprise->id, '411000'));
    }

    public function test_le_lettrage_apparait_au_grand_livre(): void
    {
        // C'est ce qui dit d'un coup d'oeil ce qui est solde.
        $a = $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-1');
        $b = $this->ecriture('2026-03-20', '571000', '411000', 100000, 'REG-1');

        LettrageService::lettrer($this->entreprise->id, '411000', [$a->id, $b->id]);

        $clients = $this->compteDuLivre(GrandLivreService::etablir($this->entreprise->id), '411000');

        $this->assertSame(['A', 'A'], array_column($clients['lignes'], 'lettrage'));
    }

    // ══════════════ Le lettrage automatique au règlement ══════════════

    public function test_un_reglement_qui_solde_lettre_de_lui_meme(): void
    {
        // Demander le rapprochement en seconde manipulation reviendrait a ne
        // jamais l'obtenir, et le compte client redeviendrait illisible en
        // trois mois.
        $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-7');
        $this->ecriture('2026-03-20', '571000', '411000', 100000, 'FAC-7');

        $lettrage = LettrageService::lettrerLaPiece($this->entreprise->id, '411000', 'FAC-7');

        $this->assertNotNull($lettrage);
        $this->assertSame(0.0, LettrageService::resteDu($this->entreprise->id, '411000'));
    }

    public function test_un_reglement_partiel_ne_lettre_rien(): void
    {
        // C'est exactement le cas que `lettrer()` refuse, et il le refuse pour
        // de bonnes raisons : il resterait 60 000 dus.
        $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-8');
        $this->ecriture('2026-03-20', '571000', '411000', 40000, 'FAC-8');

        $this->assertNull(LettrageService::lettrerLaPiece($this->entreprise->id, '411000', 'FAC-8'));
        $this->assertSame(60000.0, LettrageService::resteDu($this->entreprise->id, '411000'));
    }

    public function test_une_facture_seule_ne_lettre_rien(): void
    {
        $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-9');

        $this->assertNull(LettrageService::lettrerLaPiece($this->entreprise->id, '411000', 'FAC-9'));
    }

    public function test_le_lettrage_automatique_ne_reprend_pas_ce_qui_est_deja_lettre(): void
    {
        $a = $this->ecriture('2026-03-01', '411000', '701000', 100000, 'FAC-10');
        $b = $this->ecriture('2026-03-20', '571000', '411000', 100000, 'FAC-10');

        $premier = LettrageService::lettrerLaPiece($this->entreprise->id, '411000', 'FAC-10');

        $this->assertNotNull($premier);
        $this->assertNull(LettrageService::lettrerLaPiece($this->entreprise->id, '411000', 'FAC-10'));
        $this->assertSame(1, Lettrage::count());
    }

    // ══════════════════════ Les écrans ══════════════════════

    public function test_l_ecran_du_grand_livre_repond(): void
    {
        $this->ecriture('2026-03-01', '411000', '701000', 100000);

        $this->actingAs($this->admin)
            ->get(route('admin.comptabilite.grand_livre'))
            ->assertOk()
            ->assertSee('411000');
    }

    public function test_l_ecran_de_lettrage_repond(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.comptabilite.lettrage', ['compte' => '411000']))
            ->assertOk()
            ->assertSee('Reste dû sur ce compte');
    }

    public function test_les_ecrans_sont_fermes_aux_visiteurs(): void
    {
        $this->get(route('admin.comptabilite.grand_livre'))->assertRedirect();
        $this->get(route('admin.comptabilite.lettrage'))->assertRedirect();
        $this->post(route('admin.comptabilite.lettrer'), [])->assertRedirect();
    }

    public function test_on_ne_defait_pas_le_lettrage_d_une_autre_entreprise(): void
    {
        // **404 et non 403.** Repondre « acces refuse » sur la piece d'un
        // autre et « introuvable » sur une piece inexistante distingue les
        // deux : les identifiants etant sequentiels, compter les 403
        // donnait le volume de toute la plateforme. Ce qui n'est pas a
        // vous n'existe pas pour vous.
        $voisine = Entreprise::create(['nom' => 'Coopérative du Bandama']);
        $etranger = Lettrage::create([
            'entreprise_id' => $voisine->id, 'code' => 'A',
            'date_lettrage' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.comptabilite.delettrer', $etranger))
            ->assertNotFound();

        $this->assertNotNull($etranger->fresh());
    }
}
