<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Immobilisation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Services\AmortissementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les immobilisations et leur amortissement.
 *
 * **Rien n'existait.** Un camion, un four, un ordinateur achetés par
 * l'entreprise passaient en charge de l'exercice, ou ne passaient nulle part.
 *
 * - **le bilan était faux** — l'actif immobilisé, la classe 2, restait vide.
 *   Une entreprise qui possède un camion de dix millions présentait un bilan
 *   qui n'en portait pas trace ;
 * - **le résultat était faux** — un investissement passé en charge écrase le
 *   résultat de l'année où il est fait ;
 * - **la charge d'amortissement, déductible, n'était pas prise.** Une
 *   entreprise qui n'amortit pas paie l'impôt sur un bénéfice qu'elle n'a pas.
 */
class AmortissementTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $siege;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create(['nom' => 'Transports du Bandama']);
        // Les immobilisations sont derrière le garde-fou `comptabilite` depuis
        // le lot 55 : une entreprise qui amortit ses biens tient ses livres.
        $this->entreprise->comptabilite_activee = true;
        $this->entreprise->save();

        $this->siege = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Treichville',
        ]);

        CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'OD', 'intitule' => 'Opérations diverses', 'type' => 'OD',
        ]);
    }

    /**
     * Un camion de 12 000 000, amorti sur cinq ans.
     *
     * Les comptes viennent du relevé OHADA du dépôt : `245100` matériel
     * automobile, `284500` ses amortissements, `681300` la dotation.
     */
    private function camion(array $champs = []): Immobilisation
    {
        $bien = Immobilisation::create(array_merge([
            'entreprise_id'         => $this->entreprise->id,
            'point_de_vente_id'     => $this->siege->id,
            'code'                  => 'IMM-001',
            'libelle'               => 'Camion Mercedes 1113',
            'compte_immobilisation' => '245100',
            'compte_amortissement'  => '284500',
            'compte_dotation'       => '681300',
            'date_acquisition'      => '2026-01-01',
            'date_mise_en_service'  => '2026-01-01',
            'valeur_acquisition'    => 12000000,
            'valeur_residuelle'     => 0,
            'duree_mois'            => 60,
        ], $champs));

        AmortissementService::etablirLePlan($bien);

        return $bien->fresh();
    }

    private function dotation(Immobilisation $bien, int $annee): float
    {
        return (float) $bien->dotations()->where('annee', $annee)->value('dotation');
    }

    // ══════════════ Le plan ══════════════

    public function test_un_bien_mis_en_service_au_premier_janvier_s_amortit_en_annuites_egales(): void
    {
        // 12 000 000 sur 60 mois = 2 400 000 par an, cinq ans pleins.
        $camion = $this->camion();

        $this->assertSame(5, $camion->dotations()->count());

        foreach ([2026, 2027, 2028, 2029, 2030] as $annee) {
            $this->assertSame(2400000.0, $this->dotation($camion, $annee), "Annuité {$annee}");
        }
    }

    public function test_la_mise_en_service_en_cours_d_annee_donne_un_prorata(): void
    {
        // **C'est la mise en service qui declenche l'amortissement**, non
        // l'acquisition. Du 1er juillet au 31 decembre : six mois commerciaux,
        // soit 180 jours sur 360, la moitie de l'annuite.
        $camion = $this->camion(['date_mise_en_service' => '2026-07-01']);

        $this->assertSame(1200000.0, $this->dotation($camion, 2026));
        $this->assertSame(2400000.0, $this->dotation($camion, 2027));
    }

    public function test_le_plan_couvre_un_exercice_de_plus_quand_il_deborde(): void
    {
        // Cinq ans a partir de juillet 2026 se terminent en juin 2031 : six
        // exercices, dont deux partiels.
        $camion = $this->camion(['date_mise_en_service' => '2026-07-01']);

        $this->assertSame(6, $camion->dotations()->count());
        $this->assertSame(1200000.0, $this->dotation($camion, 2031));
    }

    public function test_le_cumul_des_dotations_egale_la_base_amortissable(): void
    {
        // **La derniere annuite solde le plan.** Les arrondis de chaque
        // exercice laisseraient sinon quelques francs non amortis, et le bien
        // resterait indefiniment au bilan pour ce reliquat.
        $camion = $this->camion([
            'valeur_acquisition' => 7777777,
            'date_mise_en_service' => '2026-04-17',
            'duree_mois' => 37,
        ]);

        $this->assertSame(7777777.0, round((float) $camion->dotations()->sum('dotation'), 2));
    }

    public function test_la_valeur_residuelle_ne_s_amortit_pas(): void
    {
        // Un vehicule revendu a une valeur : l'amortir en entier ferait
        // apparaitre une plus-value fictive a la cession.
        $camion = $this->camion(['valeur_residuelle' => 2000000]);

        $this->assertSame(10000000.0, $camion->baseAmortissable());
        $this->assertSame(2000000.0, $this->dotation($camion, 2026));
        $this->assertSame(10000000.0, round((float) $camion->dotations()->sum('dotation'), 2));
    }

    public function test_la_valeur_nette_du_plan_descend_jusqu_a_la_valeur_residuelle(): void
    {
        $camion = $this->camion(['valeur_residuelle' => 2000000]);

        // La relation trie deja par annee : `orderByDesc` s'ajouterait apres et
        // ne renverserait rien. C'est la derniere ligne qu'on veut.
        $this->assertSame(2000000.0, (float) $camion->dotations->last()->valeur_nette);
    }

    public function test_le_mois_commercial_vaut_trente_jours(): void
    {
        // Le 31 janvier et le 30 janvier donnent la meme chose, et fevrier se
        // compte comme les autres. C'est ce qui rend deux plans comparables.
        $le30 = $this->camion(['code' => 'IMM-30', 'date_mise_en_service' => '2026-01-30']);
        $le31 = $this->camion(['code' => 'IMM-31', 'date_mise_en_service' => '2026-01-31']);

        $this->assertSame($this->dotation($le30, 2026), $this->dotation($le31, 2026));
    }

    public function test_refaire_un_plan_deja_entame_est_refuse(): void
    {
        // Le refaire le mettrait en desaccord avec le grand livre, et le
        // desaccord ne se verrait qu'au bilan de l'annee suivante.
        $camion = $this->camion();
        AmortissementService::comptabiliser($camion->dotations()->first());

        $this->expectException(\LogicException::class);

        AmortissementService::etablirLePlan($camion->fresh());
    }

    // ══════════════ La dotation ══════════════

    public function test_la_dotation_debite_le_681_et_credite_le_28(): void
    {
        $camion = $this->camion();

        AmortissementService::comptabiliser($camion->dotations()->first());

        $this->assertSame(2400000.0,
            (float) EcritureComptable::where('compte_debit', '681300')->sum('debit'));
        $this->assertSame(2400000.0,
            (float) EcritureComptable::where('compte_credit', '284500')->sum('credit'));
    }

    public function test_la_dotation_s_ecrit_en_deux_lignes(): void
    {
        // C'est la convention du projet et celle de Comptaflow, dont le point
        // d'entree retient `compte_debit` s'il est present et ignore
        // `compte_credit` : une ligne portant les deux imputerait les deux
        // montants sur le seul compte debite.
        $camion = $this->camion();

        AmortissementService::comptabiliser($camion->dotations()->first());

        $this->assertSame(2, EcritureComptable::count());

        foreach (EcritureComptable::all() as $ecriture) {
            $this->assertTrue(
                ($ecriture->compte_debit === null) !== ($ecriture->compte_credit === null),
                'Une écriture porte un compte, jamais deux.'
            );
        }
    }

    public function test_une_dotation_ne_se_passe_pas_deux_fois(): void
    {
        // **La repasser doublerait la charge et amortirait le bien au double de
        // sa valeur** — et l'erreur ne se verrait qu'au bilan, l'annee
        // suivante.
        $camion = $this->camion();
        $ligne = $camion->dotations()->first();

        AmortissementService::comptabiliser($ligne);

        $this->expectException(\LogicException::class);

        AmortissementService::comptabiliser($ligne->fresh());
    }

    public function test_la_dotation_est_datee_de_la_cloture_de_l_exercice(): void
    {
        $camion = $this->camion();

        AmortissementService::comptabiliser($camion->dotations()->first());

        $this->assertSame('2026-12-31',
            EcritureComptable::first()->date_ecriture->toDateString());
    }

    public function test_la_cloture_passe_toutes_les_dotations_de_l_exercice(): void
    {
        $this->camion();
        $this->camion(['code' => 'IMM-002', 'libelle' => 'Groupe électrogène',
                       'compte_immobilisation' => '241100', 'compte_amortissement' => '284100',
                       'valeur_acquisition' => 3000000]);

        $passees = AmortissementService::comptabiliserLExercice($this->entreprise->id, 2026);

        $this->assertSame(2, $passees);
        $this->assertSame(4, EcritureComptable::count());
    }

    public function test_la_cloture_ne_repasse_pas_ce_qui_l_est_deja(): void
    {
        $camion = $this->camion();
        AmortissementService::comptabiliser($camion->dotations()->first());

        $this->assertSame(0, AmortissementService::comptabiliserLExercice($this->entreprise->id, 2026));
        $this->assertSame(2, EcritureComptable::count());
    }

    public function test_la_cloture_ne_touche_pas_a_une_autre_entreprise(): void
    {
        $this->camion();

        $autre = Entreprise::create(['nom' => 'Transports rivaux']);
        CodeJournal::create(['entreprise_id' => $autre->id, 'code' => 'OD',
                             'intitule' => 'Opérations diverses', 'type' => 'OD']);
        $sonSiege = PointDeVente::create(['entreprise_id' => $autre->id,
            'nom' => 'Siège rival', 'ville' => 'Abidjan', 'commune' => 'Yopougon']);

        $sonBien = Immobilisation::create([
            'entreprise_id' => $autre->id, 'point_de_vente_id' => $sonSiege->id,
            'code' => 'IMM-001', 'libelle' => 'Camion rival',
            'compte_immobilisation' => '245100', 'compte_amortissement' => '284500',
            'compte_dotation' => '681300',
            'date_acquisition' => '2026-01-01', 'date_mise_en_service' => '2026-01-01',
            'valeur_acquisition' => 5000000, 'duree_mois' => 60,
        ]);
        AmortissementService::etablirLePlan($sonBien);

        AmortissementService::comptabiliserLExercice($this->entreprise->id, 2026);

        $this->assertNull($sonBien->dotations()->first()->comptabilise_at);
        $this->assertSame(0, EcritureComptable::where('entreprise_id', $autre->id)->count());
    }

    // ══════════════ La cession ══════════════

    public function test_la_cession_solde_l_amortissement_et_sort_le_bien(): void
    {
        // Trois ans passes, puis vente le 31 decembre 2028 : 7 200 000 amortis,
        // 4 800 000 de valeur nette.
        $camion = $this->camion();

        foreach ([2026, 2027, 2028] as $annee) {
            AmortissementService::comptabiliser($camion->dotations()->where('annee', $annee)->first());
        }

        AmortissementService::ceder($camion->fresh(), '2028-12-31', 6000000);

        $this->assertSame(7200000.0,
            (float) EcritureComptable::where('compte_debit', '284500')->sum('debit'),
            'L\'amortissement cumulé est soldé.');
        $this->assertSame(4800000.0,
            (float) EcritureComptable::where('compte_debit', '810000')->sum('debit'),
            'La valeur comptable nette part en charge.');
        $this->assertSame(12000000.0,
            (float) EcritureComptable::where('compte_credit', '245100')->sum('credit'),
            'La valeur d\'acquisition sort du bilan.');
    }

    public function test_le_prix_de_cession_va_en_82_et_la_creance_en_485(): void
    {
        $camion = $this->camion();
        AmortissementService::comptabiliser($camion->dotations()->where('annee', 2026)->first());

        AmortissementService::ceder($camion->fresh(), '2026-12-31', 9000000);

        $this->assertSame(9000000.0,
            (float) EcritureComptable::where('compte_credit', '820000')->sum('credit'));
        $this->assertSame(9000000.0,
            (float) EcritureComptable::where('compte_debit', '485000')->sum('debit'));
    }

    public function test_la_plus_value_ne_s_ecrit_pas(): void
    {
        // Elle apparait d'elle-meme, comme difference entre le 82 et le 81 au
        // compte de resultat. L'inscrire doublerait le resultat de cession —
        // erreur qu'on retrouve dans beaucoup de logiciels.
        $camion = $this->camion();
        AmortissementService::comptabiliser($camion->dotations()->where('annee', 2026)->first());

        AmortissementService::ceder($camion->fresh(), '2026-12-31', 11000000);

        $produits = (float) EcritureComptable::where('compte_credit', '820000')->sum('credit');
        $charges  = (float) EcritureComptable::where('compte_debit', '810000')->sum('debit');

        // 11 000 000 encaisses contre 9 600 000 de valeur nette : 1 400 000 de
        // plus-value, qui ne figure sur aucune ligne.
        $this->assertSame(1400000.0, round($produits - $charges, 2));
        $this->assertSame(0, EcritureComptable::where('compte_credit', 'like', '77%')->count());
    }

    public function test_la_cession_est_equilibree(): void
    {
        $camion = $this->camion();
        AmortissementService::comptabiliser($camion->dotations()->where('annee', 2026)->first());

        AmortissementService::ceder($camion->fresh(), '2027-06-30', 8000000);

        $this->assertEqualsWithDelta(
            EcritureComptable::sum('debit'), EcritureComptable::sum('credit'), 0.01
        );
    }

    public function test_la_dotation_de_l_annee_de_sortie_est_due_jusqu_a_la_sortie(): void
    {
        // Le bien a servi une partie de l'annee : l'omettre gonflerait la
        // valeur nette, donc minorerait la charge et **majorerait la
        // plus-value**, sur laquelle l'entreprise serait imposee.
        $camion = $this->camion();
        AmortissementService::comptabiliser($camion->dotations()->where('annee', 2026)->first());

        // Du 1er janvier au 30 juin 2027 : six mois, la moitie de l'annuite.
        AmortissementService::ceder($camion->fresh(), '2027-06-30', 8000000);

        $this->assertSame(1200000.0, $this->dotation($camion->fresh(), 2027));
        $this->assertSame(3600000.0, $camion->fresh()->cumulAmorti());
    }

    public function test_un_rebut_est_une_cession_a_prix_nul(): void
    {
        // La valeur nette part en charge, et rien n'entre en face.
        $camion = $this->camion();
        AmortissementService::comptabiliser($camion->dotations()->where('annee', 2026)->first());

        AmortissementService::ceder($camion->fresh(), '2026-12-31', 0);

        $this->assertSame(Immobilisation::REBUTE, $camion->fresh()->statut);
        $this->assertSame(9600000.0,
            (float) EcritureComptable::where('compte_debit', '810000')->sum('debit'));
        $this->assertSame(0, EcritureComptable::where('compte_credit', '820000')->count());
    }

    public function test_un_bien_entierement_amorti_ne_laisse_aucune_valeur_nette(): void
    {
        $camion = $this->camion();

        foreach ($camion->dotations as $ligne) {
            AmortissementService::comptabiliser($ligne);
        }

        AmortissementService::ceder($camion->fresh(), '2031-03-15', 500000);

        $this->assertSame(0, EcritureComptable::where('compte_debit', '810000')->count());
        $this->assertSame(500000.0,
            (float) EcritureComptable::where('compte_credit', '820000')->sum('credit'));
    }

    public function test_les_exercices_a_venir_disparaissent_du_plan(): void
    {
        // Les laisser ferait croire a une charge future qui ne viendra pas.
        $camion = $this->camion();
        AmortissementService::comptabiliser($camion->dotations()->where('annee', 2026)->first());

        AmortissementService::ceder($camion->fresh(), '2027-06-30', 8000000);

        $this->assertSame(0, $camion->dotations()->where('annee', '>', 2027)->count());
    }

    public function test_un_bien_deja_sorti_ne_se_cede_pas_deux_fois(): void
    {
        $camion = $this->camion();
        AmortissementService::ceder($camion->fresh(), '2026-12-31', 8000000);

        $this->expectException(\LogicException::class);

        AmortissementService::ceder($camion->fresh(), '2027-01-15', 3000000);
    }

    public function test_un_bien_cede_sort_de_la_cloture_suivante(): void
    {
        $camion = $this->camion();
        AmortissementService::ceder($camion->fresh(), '2026-06-30', 8000000);

        $this->assertSame(0, AmortissementService::comptabiliserLExercice($this->entreprise->id, 2027));
    }

    // ══════════════ Ce que la fiche dit ══════════════

    public function test_la_valeur_nette_ne_compte_que_ce_qui_est_passe(): void
    {
        // Un plan etabli n'est pas une charge : tant que la dotation n'est pas
        // comptabilisee, le bilan porte encore la valeur pleine.
        $camion = $this->camion();

        $this->assertSame(12000000.0, $camion->valeurNette());

        AmortissementService::comptabiliser($camion->dotations()->first());

        $this->assertSame(9600000.0, $camion->fresh()->valeurNette());
    }

    // ══════════════ Les écrans, et ce qu'un attaquant tenterait ══════════════

    /**
     * Un administrateur de l'entreprise, connecté.
     */
    private function connecte(): \App\Modules\Authentification\Modeles\Utilisateur
    {
        $this->entreprise->update([
            'regime_imposition' => 'RNI', 'adresse' => 'Treichville, Abidjan',
            'rccm' => 'CI-ABJ-2026-B-00123', 'ncc' => '2601234A',
            'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Transport'],
            'modules_actifs' => ['principal', 'comptabilite', 'ventes'],
        ]);

        $admin = \App\Modules\Authentification\Modeles\Utilisateur::create([
            'nom' => 'Koffi', 'prenom' => 'Yao', 'email' => 'yao@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->siege->id,
        ]);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $this->siege->id]);

        return $admin;
    }

    public function test_l_ecran_enregistre_le_bien_et_etablit_son_plan(): void
    {
        $this->connecte();

        $this->post(route('admin.immobilisations.enregistrer'), [
            'code' => 'IMM-100', 'libelle' => 'Chariot élévateur',
            'compte_immobilisation' => '241100', 'compte_amortissement' => '284100',
            'compte_dotation' => '681300',
            'date_acquisition' => '2026-01-01', 'date_mise_en_service' => '2026-01-01',
            'valeur_acquisition' => 6000000, 'valeur_residuelle' => 0, 'duree_mois' => 48,
        ]);

        $bien = Immobilisation::where('code', 'IMM-100')->firstOrFail();

        $this->assertSame(4, $bien->dotations()->count());
        $this->assertSame(1500000.0, $this->dotation($bien, 2026));
    }

    public function test_une_mise_en_service_anterieure_a_l_acquisition_est_refusee(): void
    {
        // Un bien ne se met pas en service avant d'etre acquis, et le prorata
        // partirait d'une date que rien ne justifie.
        $this->connecte();

        $this->post(route('admin.immobilisations.enregistrer'), [
            'code' => 'IMM-101', 'libelle' => 'Photocopieuse',
            'compte_immobilisation' => '244100', 'compte_amortissement' => '284400',
            'compte_dotation' => '681300',
            'date_acquisition' => '2026-06-01', 'date_mise_en_service' => '2026-01-01',
            'valeur_acquisition' => 800000, 'duree_mois' => 36,
        ])->assertSessionHasErrors('date_mise_en_service');

        $this->assertSame(0, Immobilisation::where('code', 'IMM-101')->count());
    }

    public function test_une_valeur_residuelle_superieure_a_l_acquisition_est_refusee(): void
    {
        // La base deviendrait negative, et le plan n'amortirait rien tout en
        // laissant croire le contraire.
        $this->connecte();

        $this->post(route('admin.immobilisations.enregistrer'), [
            'code' => 'IMM-102', 'libelle' => 'Groupe électrogène',
            'compte_immobilisation' => '241100', 'compte_amortissement' => '284100',
            'compte_dotation' => '681300',
            'date_acquisition' => '2026-01-01', 'date_mise_en_service' => '2026-01-01',
            'valeur_acquisition' => 1000000, 'valeur_residuelle' => 5000000, 'duree_mois' => 60,
        ])->assertSessionHasErrors('valeur_residuelle');
    }

    public function test_un_code_deja_pris_est_refuse_dans_l_entreprise(): void
    {
        // C'est le code que porte l'etiquette collee sur le bien : deux biens
        // sous le meme code rendraient l'inventaire physique inexploitable.
        $this->camion();
        $this->connecte();

        $this->post(route('admin.immobilisations.enregistrer'), [
            'code' => 'IMM-001', 'libelle' => 'Autre camion',
            'compte_immobilisation' => '245100', 'compte_amortissement' => '284500',
            'compte_dotation' => '681300',
            'date_acquisition' => '2026-01-01', 'date_mise_en_service' => '2026-01-01',
            'valeur_acquisition' => 5000000, 'duree_mois' => 60,
        ])->assertSessionHasErrors('code');
    }

    public function test_le_meme_code_reste_libre_dans_une_autre_entreprise(): void
    {
        // L'unicite est par entreprise : chacune numerote son parc comme elle
        // l'entend.
        $this->camion();

        $autre = Entreprise::create(['nom' => 'Transports rivaux']);

        $this->assertNotNull(Immobilisation::create([
            'entreprise_id' => $autre->id, 'code' => 'IMM-001', 'libelle' => 'Camion rival',
            'compte_immobilisation' => '245100', 'compte_amortissement' => '284500',
            'compte_dotation' => '681300',
            'date_acquisition' => '2026-01-01', 'date_mise_en_service' => '2026-01-01',
            'valeur_acquisition' => 5000000, 'duree_mois' => 60,
        ]));
    }

    public function test_la_fiche_d_un_bien_d_une_autre_entreprise_est_fermee(): void
    {
        // **404 et non 403.** Repondre « acces refuse » sur la piece d'un
        // autre et « introuvable » sur une piece inexistante distingue les
        // deux : les identifiants etant sequentiels, compter les 403
        // donnait le volume de toute la plateforme. Ce qui n'est pas a
        // vous n'existe pas pour vous.
        // Le parc immobilise dit ce qu'un concurrent possede et ce qu'il l'a
        // paye : c'est une information commerciale.
        $this->connecte();

        $autre = Entreprise::create(['nom' => 'Transports rivaux']);
        $sonBien = Immobilisation::create([
            'entreprise_id' => $autre->id, 'code' => 'IMM-RIVAL', 'libelle' => 'Camion rival',
            'compte_immobilisation' => '245100', 'compte_amortissement' => '284500',
            'compte_dotation' => '681300',
            'date_acquisition' => '2026-01-01', 'date_mise_en_service' => '2026-01-01',
            'valeur_acquisition' => 5000000, 'duree_mois' => 60,
        ]);
        AmortissementService::etablirLePlan($sonBien);

        $this->get(route('admin.immobilisations.fiche', $sonBien))->assertNotFound();
        $this->post(route('admin.immobilisations.ceder', $sonBien), [
            'date_sortie' => '2026-12-31', 'prix_cession' => 1,
        ])->assertNotFound();
        $this->post(route('admin.immobilisations.dotation', $sonBien->dotations()->first()))
            ->assertNotFound();

        $this->assertSame(Immobilisation::EN_SERVICE, $sonBien->fresh()->statut);
        $this->assertNull($sonBien->dotations()->first()->comptabilise_at);
    }

    public function test_une_fiche_engagee_ne_se_modifie_plus(): void
    {
        // Changer la duree ou la valeur d'un bien a moitie amorti mettrait le
        // plan en desaccord avec les ecritures deja au grand livre, et le
        // desaccord ne se verrait qu'au bilan de l'annee suivante.
        $camion = $this->camion();
        AmortissementService::comptabiliser($camion->dotations()->first());
        $this->connecte();

        $this->put(route('admin.immobilisations.modifier', $camion), [
            'code' => 'IMM-001', 'libelle' => 'Camion Mercedes 1113',
            'compte_immobilisation' => '245100', 'compte_amortissement' => '284500',
            'compte_dotation' => '681300',
            'date_acquisition' => '2026-01-01', 'date_mise_en_service' => '2026-01-01',
            'valeur_acquisition' => 99000000, 'duree_mois' => 12,
        ])->assertSessionHas('erreur');

        $this->assertSame(12000000.0, $camion->fresh()->valeur_acquisition);
        $this->assertSame(5, $camion->fresh()->dotations()->count());
    }

    public function test_une_sortie_anterieure_a_la_mise_en_service_est_refusee(): void
    {
        // Elle produirait une dotation negative.
        $camion = $this->camion(['date_mise_en_service' => '2026-06-01']);
        $this->connecte();

        $this->post(route('admin.immobilisations.ceder', $camion), [
            'date_sortie' => '2026-01-15', 'prix_cession' => 1000000,
        ])->assertSessionHas('erreur');

        $this->assertSame(Immobilisation::EN_SERVICE, $camion->fresh()->statut);
    }

    public function test_l_ecran_annonce_ce_qui_reste_a_passer(): void
    {
        // **La charge que l'entreprise oublierait si personne ne cloturait.**
        $this->camion();
        $this->connecte();

        $this->get(route('admin.immobilisations.index', ['annee' => 2026]))
            ->assertOk()
            ->assertSee('restent à passer', false)
            ->assertSee('Camion Mercedes 1113');
    }

    public function test_la_cloture_par_l_ecran_passe_les_dotations(): void
    {
        $camion = $this->camion();
        $this->connecte();

        $this->post(route('admin.immobilisations.cloturer'), ['annee' => 2026])
            ->assertSessionHas('succes');

        $this->assertNotNull($camion->fresh()->dotations()->where('annee', 2026)->first()->comptabilise_at);
    }

    public function test_une_duree_nulle_n_ecrit_aucune_dotation(): void
    {
        // Un terrain ne s'amortit pas : le plan est vide, et rien ne le force.
        $terrain = Immobilisation::create([
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->siege->id,
            'code' => 'IMM-TERRAIN', 'libelle' => 'Terrain de Yopougon',
            'compte_immobilisation' => '222000', 'compte_amortissement' => '282000',
            'compte_dotation' => '681300',
            'date_acquisition' => '2026-01-01', 'date_mise_en_service' => '2026-01-01',
            'valeur_acquisition' => 25000000, 'duree_mois' => 0,
        ]);

        AmortissementService::etablirLePlan($terrain);

        $this->assertSame(0.0, round((float) $terrain->dotations()->sum('dotation'), 2));
    }

    public function test_un_terrain_mis_en_service_en_cours_d_annee_n_amortit_pas_davantage(): void
    {
        // **Le defaut ne se voyait pas au 1er janvier.** Ce jour-la, la borne de
        // fin tombe l'annee precedente et la boucle ne s'execute pas du tout.
        // Mis en service un 20 novembre, l'unique exercice du parcours
        // declenchait la regle « la derniere annuite solde le plan », qui
        // ecrivait une dotation **egale a la valeur entiere du bien** : un
        // terrain de 25 millions passait 25 millions en charge.
        $terrain = Immobilisation::create([
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->siege->id,
            'code' => 'IMM-TERRAIN-NOV', 'libelle' => 'Terrain de Bingerville',
            'compte_immobilisation' => '222000', 'compte_amortissement' => '282000',
            'compte_dotation' => '681300',
            'date_acquisition' => '2023-11-20', 'date_mise_en_service' => '2023-11-20',
            'valeur_acquisition' => 25000000, 'duree_mois' => 0,
        ]);

        AmortissementService::etablirLePlan($terrain);

        $this->assertSame(0, $terrain->dotations()->count());
        $this->assertSame(25000000.0, $terrain->fresh()->valeurNette());
    }

    public function test_un_bien_couvert_par_sa_valeur_residuelle_n_a_pas_de_plan(): void
    {
        // Il n'y a rien a etaler : la base amortissable est nulle.
        $camion = $this->camion(['code' => 'IMM-COUVERT', 'valeur_residuelle' => 12000000]);

        $this->assertSame(0, $camion->dotations()->count());
    }
}
