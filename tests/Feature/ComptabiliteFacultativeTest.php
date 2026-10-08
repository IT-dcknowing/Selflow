<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La comptabilité cesse d'être offerte à tout le monde.
 *
 * Décision du propriétaire, le 02/10/2026 : « Selflow c'est vente, achat,
 * facturation, stock — maintenant la comptabilité intervient pour ceux qui le
 * veulent. » Ceux-là ont Comptaflow, qui tient les livres ; garder toute la
 * comptabilité dans Selflow ôtait l'envie d'y aller.
 *
 * Deux réglages, et un seul suffit à ouvrir un écran :
 *
 * | Qui | Où | Ce qu'il dit |
 * |---|---|---|
 * | L'entreprise | Ses paramètres | « Je veux voir mes écrans comptables » |
 * | Le superadministrateur | L'écran Attributions | « Cette entreprise y a droit » |
 *
 * Les confondre en une seule colonne aurait fait qu'une entreprise décochant
 * son réglage annulerait ce qu'on lui a accordé — ou l'inverse, qu'elle
 * s'accorderait elle-même ce qui ne lui revient pas.
 */
class ComptabiliteFacultativeTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'TEE',
            'adresse' => 'Riviera II', 'rccm' => 'CI-ABJ-2018-B-31734',
            'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => [
                'principal', 'ventes', 'achats', 'stock', 'produits',
                'tiers', 'comptabilite', 'points_de_vente', 'rapports',
            ],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-compta@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);
    }

    private function connecte(): self
    {
        $this->actingAs($this->admin)
             ->withSession(['point_de_vente_actif_id' => $this->site->id]);

        return $this;
    }

    private function menu(): string
    {
        return $this->connecte()
            ->get(route('admin.entreprise.parametres'))
            ->assertOk()
            ->getContent();
    }

    private function ouvrirLaComptabilite(): void
    {
        $this->entreprise->comptabilite_activee = true;
        $this->entreprise->save();
        $this->entreprise->refresh();

        /*
         * L'épreuve garde un seul objet `Utilisateur` d'une requête à l'autre,
         * et sa relation `entreprise` reste celle chargée au premier appel —
         * donc comptabilité fermée. Une requête réelle rebâtit l'utilisateur à
         * chaque fois ; ici, il faut le dire.
         */
        $this->admin->unsetRelation('entreprise');
    }

    /**
     * Les écrans qui ne s'ouvrent qu'à qui a demandé la comptabilité.
     *
     * Encaissements et décaissements y sont entrés le 05/10/2026, à la demande
     * du propriétaire : ce sont des écrans de règlement rattachés aux livres,
     * et non la caisse du quotidien — celle-ci passe par la vente et son mode
     * de paiement.
     */
    private const ECRANS_FERMES = [
        'admin.comptabilite.globale',
        'admin.comptabilite.creances',
        'admin.comptabilite.plan_comptable',
        'admin.comptabilite.balance',
        'admin.comptabilite.grand_livre',
        'admin.comptabilite.lettrage',
        'admin.comptabilite.libelles',
        'admin.tresorerie.codes_journaux',
        'admin.tresorerie.encaissements',
        'admin.tresorerie.decaissements',
    ];

    /**
     * Ceux qui restent.
     *
     * Lire ce qu'on a en caisse ne demande pas de tenir de livres ; et les
     * moyens de paiement sont la porte par laquelle une entreprise déclare sa
     * banque — sans eux, le masquage l'aurait privée du seul écran où le faire.
     */
    private const ECRANS_OUVERTS = [
        'admin.tresorerie.journal',
        'admin.tresorerie.moyens_paiement',
    ];

    // ══════════════ Le défaut : fermée ══════════════

    public function test_une_entreprise_neuve_n_a_pas_la_comptabilite(): void
    {
        // Selflow est vente, achat, facturation, stock. Le reste se demande.
        $this->assertFalse($this->entreprise->comptabiliteOuverte());
        $this->assertFalse((bool) $this->entreprise->comptabilite_activee);
    }

    public function test_les_ecrans_comptables_repondent_404_et_non_403(): void
    {
        /*
         * `modules:comptabilite`, juste à côté, répond 403 (Forbidden — accès
         * interdit) avec « le module n'est pas activé ». C'est juste pour un
         * module qu'on a fermé soi-même. Ici l'entreprise n'a rien fermé :
         * elle n'a jamais eu ces écrans. Lui dire « interdit » la ferait
         * chercher un droit manquant.
         */
        foreach (self::ECRANS_FERMES as $route) {
            $this->connecte()->get(route($route))->assertNotFound();
        }
    }

    public function test_le_solde_et_les_moyens_de_paiement_restent_ouverts(): void
    {
        // Une entreprise encaisse sans tenir de livres, et doit pouvoir
        // déclarer sa banque.
        foreach (self::ECRANS_OUVERTS as $route) {
            $this->connecte()->get(route($route))->assertOk();
        }
    }

    public function test_les_moyens_de_paiement_remplacent_les_codes_journaux(): void
    {
        /*
         * Le masquage des codes journaux avait fermé **le seul écran** où une
         * entreprise pouvait déclarer sa banque ou son mobile money : elle
         * pouvait encaisser, plus en ajouter un moyen. Les deux entrées ne
         * coexistent jamais — c'est la même liste, vue avec ou sans sa colonne
         * de compte.
         */
        $menu = $this->menu();
        $this->assertStringContainsString('Moyens de paiement', $menu);
        $this->assertStringNotContainsString('Codes Journaux', $menu);

        $this->ouvrirLaComptabilite();

        $menu = $this->menu();
        $this->assertStringContainsString('Codes Journaux', $menu);
        $this->assertStringNotContainsString('Moyens de paiement', $menu);
    }

    public function test_un_moyen_de_paiement_recoit_son_compte_sans_qu_on_le_demande(): void
    {
        $this->connecte()->post(route('admin.tresorerie.creer_moyen_paiement'), [
            'type' => 'Banque', 'code' => 'nsia', 'intitule' => 'NSIA Banque',
        ])->assertRedirect();

        $journal = \App\Modules\Admin\Modeles\CodeJournal::where('entreprise_id', $this->entreprise->id)
            ->where('code', 'NSIA')->first();

        $this->assertNotNull($journal);

        /*
         * Aucun compte n'a été demandé, et c'est tout l'objet de l'écran. Le
         * laisser vide ferait un journal de trésorerie sans contrepartie : le
         * jour où l'entreprise ouvrirait sa comptabilité, elle trouverait ses
         * encaissements en l'air.
         */
        $this->assertSame('521000', $journal->compte);
        $this->assertSame('Banque', $journal->type);
    }

    public function test_la_caisse_ne_se_supprime_pas(): void
    {
        $caisse = \App\Modules\Admin\Modeles\CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'type' => 'Caisse', 'code' => 'CAI',
            'intitule' => 'Caisse', 'compte' => '571000',
        ]);

        // Tout encaissement en espèces s'y range, et les règlements déjà
        // passés la portent.
        $this->connecte()
            ->delete(route('admin.tresorerie.supprimer_moyen_paiement', $caisse))
            ->assertSessionHasErrors('moyen');

        $this->assertNotNull($caisse->fresh());
    }

    public function test_un_journal_de_vente_n_est_pas_un_moyen_de_paiement(): void
    {
        \App\Modules\Admin\Modeles\CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'type' => 'Vente', 'code' => 'VTE',
            'intitule' => 'Journal des ventes', 'compte' => null,
        ]);

        // On ne règle pas une facture « au journal des ventes ».
        $this->connecte()
            ->get(route('admin.tresorerie.moyens_paiement'))
            ->assertOk()
            ->assertDontSee('Journal des ventes');
    }

    public function test_le_resultat_par_site_survit_au_masquage(): void
    {
        PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-cocody', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        // C'est un rapport d'exploitation — quel magasin gagne de l'argent —
        // et non un écran de tenue de livres. Il a rejoint Rapports au lot 37.
        $this->connecte()->get(route('admin.comptabilite.analytique'))->assertOk();
    }

    public function test_le_menu_ne_propose_pas_ce_qui_est_ferme(): void
    {
        $menu = $this->menu();

        foreach (['Plan Comptable', 'Grand livre', 'Balance de contrôle',
                  'Lettrage', 'Codes Journaux', 'Créances &amp; règlements'] as $entree) {
            $this->assertStringNotContainsString($entree, $menu);
        }
    }

    public function test_la_section_s_appelle_tresorerie_quand_il_n_y_a_plus_que_la_caisse(): void
    {
        // Appeler « Comptabilité » une section qui ne porte que des écrans de
        // caisse promettrait des livres qu'on ne tient pas.
        $this->assertStringContainsString('<span>Trésorerie</span>', $this->menu());
    }

    // ══════════════ L'entreprise l'ouvre ══════════════

    public function test_la_case_des_parametres_ouvre_les_ecrans_par_defaut(): void
    {
        $this->ouvrirLaComptabilite();

        // Par défaut, seuls les écrans du socle de base s'ouvrent :
        // Codes journaux, Créances, Plan comptable, Configuration
        $ecransParDefaut = [
            'admin.tresorerie.codes_journaux',
            'admin.comptabilite.creances',
            'admin.comptabilite.plan_comptable',
            'admin.comptabilite.configuration',
        ];

        foreach ($ecransParDefaut as $route) {
            $this->connecte()->get(route($route))->assertOk();
        }

        // Le reste est réservé au superadministrateur (404 tant que non attribué)
        $ecransSuperadmin = [
            'admin.tresorerie.encaissements',
            'admin.tresorerie.decaissements',
            'admin.comptabilite.globale',
            'admin.comptabilite.grand_livre',
            'admin.comptabilite.lettrage',
            'admin.comptabilite.balance',
        ];

        foreach ($ecransSuperadmin as $route) {
            $this->connecte()->get(route($route))->assertNotFound();
        }
    }

    public function test_les_ecrans_avances_s_ouvrent_quand_le_superadmin_les_attribue(): void
    {
        $this->ouvrirLaComptabilite();

        $this->entreprise->forceFill([
            'attributions' => [
                'encaissements', 'decaissements', 'comptabilite_globale',
                'grand_livre', 'lettrage', 'balance',
            ],
        ])->save();
        $this->admin->unsetRelation('entreprise');

        $ecransAttribues = [
            'admin.tresorerie.encaissements',
            'admin.tresorerie.decaissements',
            'admin.comptabilite.globale',
            'admin.comptabilite.grand_livre',
            'admin.comptabilite.lettrage',
            'admin.comptabilite.balance',
        ];

        foreach ($ecransAttribues as $route) {
            $this->connecte()->get(route($route))->assertOk();
        }
    }

    public function test_la_balance_ne_s_ouvre_pas_avec_la_comptabilite(): void
    {
        // Propriétaire, 08/10/2026 : la balance se tient dans Comptaflow. La
        // comptabilité ouverte ne la montre pas ; le superadministrateur
        // l'accorde à part.
        $this->ouvrirLaComptabilite();

        $this->connecte()->get(route('admin.comptabilite.balance'))->assertNotFound();
        $this->assertStringNotContainsString('Balance de contrôle', $this->menu());

        $this->entreprise->forceFill(['attributions' => ['balance']])->save();
        $this->admin->unsetRelation('entreprise');

        $this->connecte()->get(route('admin.comptabilite.balance'))->assertOk();
        $this->assertStringContainsString('Balance de contrôle', $this->menu());
    }

    public function test_le_menu_reprend_ses_entrees_une_fois_ouverte(): void
    {
        $this->ouvrirLaComptabilite();
        $menu = $this->menu();

        // Éléments du socle par défaut
        $this->assertStringContainsString('Plan Comptable', $menu);
        $this->assertStringContainsString('Codes Journaux', $menu);
        $this->assertStringContainsString('Créances &amp; règlements', $menu);
        $this->assertStringContainsString('<span>Comptabilité</span>', $menu);

        // Les éléments réservés au superadmin ne doivent PAS être présents par défaut
        $this->assertStringNotContainsString('Grand livre', $menu);
        $this->assertStringNotContainsString('Lettrage', $menu);
        $this->assertStringNotContainsString('Opération &amp; écriture globale', $menu);

        // Dès que le superadmin accorde le grand livre, il paraît dans le menu
        $this->entreprise->forceFill(['attributions' => ['grand_livre']])->save();
        $this->admin->unsetRelation('entreprise');

        $menuAvecGrandLivre = $this->menu();
        $this->assertStringContainsString('Grand livre', $menuAvecGrandLivre);
    }

    public function test_la_case_se_coche_et_se_decoche_depuis_les_parametres(): void
    {
        $base = [
            'nom' => $this->entreprise->nom,
            'ncc' => $this->entreprise->ncc,
        ];

        $this->connecte()->put(route('admin.entreprise.parametres.enregistrer'),
            $base + ['comptabilite_activee' => '1']);
        $this->assertTrue((bool) $this->entreprise->fresh()->comptabilite_activee);

        /*
         * Le décochage est le cas qui se perd : une case non cochée ne
         * s'envoie pas. Un champ caché la fait toujours poster, et le
         * contrôleur lit `has()` plutôt que `filled()` — sans quoi le réglage
         * serait resté ouvert à jamais.
         */
        $this->connecte()->put(route('admin.entreprise.parametres.enregistrer'),
            $base + ['comptabilite_activee' => '0']);
        $this->assertFalse((bool) $this->entreprise->fresh()->comptabilite_activee);
    }

    public function test_les_ecritures_continuent_d_etre_produites_quand_c_est_masque(): void
    {
        /*
         * C'est ce qui permet d'ouvrir la comptabilité six mois plus tard et
         * d'avoir les livres complets — et c'est ce que Comptaflow reçoit.
         * Rien dans le masquage ne doit toucher à la production des écritures.
         */
        $this->assertFalse($this->entreprise->comptabiliteOuverte());

        $source = file_get_contents(
            app_path('Modules/Authentification/Middleware/VerifierComptabiliteOuverte.php')
        );

        $this->assertStringNotContainsString('Ecriture', $source);
        $this->assertStringNotContainsString('ComptabiliteService', $source);
    }

    // ══════════════ Le superadministrateur attribue ══════════════

    private function superadmin(): Utilisateur
    {
        $super = Utilisateur::create([
            'nom' => 'Agnimel', 'prenom' => 'Abraham',
            'email' => 'super-attrib@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
        ]);

        $super->habilitations = \App\Modules\Authentification\Regles\Habilitations::PLATEFORME;
        $super->save();

        return $super;
    }

    public function test_l_attribution_ouvre_les_ecrans_sans_la_case_de_l_entreprise(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('superadmin.attributions.basculer', $this->entreprise), [
                'attribution' => 'comptabilite',
                'accorder'    => '1',
            ])->assertRedirect();

        $this->entreprise->refresh();

        // La case de l'entreprise n'a pas bougé — et pourtant l'écran s'ouvre.
        $this->assertFalse((bool) $this->entreprise->comptabilite_activee);
        $this->assertTrue($this->entreprise->comptabiliteOuverte());

        $this->connecte()->get(route('admin.comptabilite.plan_comptable'))->assertOk();
    }

    public function test_l_attribution_survit_au_decochage_de_l_entreprise(): void
    {
        $this->entreprise->attributions = ['comptabilite'];
        $this->entreprise->comptabilite_activee = true;
        $this->entreprise->save();

        // L'entreprise décoche sa case : ce que le superadministrateur a
        // accordé ne doit pas partir avec.
        $this->connecte()->put(route('admin.entreprise.parametres.enregistrer'), [
            'nom' => $this->entreprise->nom,
            'ncc' => $this->entreprise->ncc,
            'comptabilite_activee' => '0',
        ]);

        $fraiche = $this->entreprise->fresh();
        $this->assertFalse((bool) $fraiche->comptabilite_activee);
        $this->assertTrue($fraiche->aAttribution('comptabilite'));
        $this->assertTrue($fraiche->comptabiliteOuverte());
    }

    public function test_une_entreprise_ne_peut_pas_s_attribuer_elle_meme(): void
    {
        /*
         * `attributions` n'est pas assignable en masse — le défaut exact de la
         * clé de liaison Comptaflow, corrigé au lot 15 : une entreprise qui
         * postait la clé d'une autre déversait ses écritures dans ses livres.
         */
        $this->connecte()->put(route('admin.entreprise.parametres.enregistrer'), [
            'nom' => $this->entreprise->nom,
            'ncc' => $this->entreprise->ncc,
            'attributions' => ['comptabilite'],
        ]);

        $this->assertFalse($this->entreprise->fresh()->aAttribution('comptabilite'));
    }

    public function test_l_attribution_se_retire(): void
    {
        $this->entreprise->attributions = ['comptabilite'];
        $this->entreprise->save();

        $this->actingAs($this->superadmin())
            ->post(route('superadmin.attributions.basculer', $this->entreprise), [
                'attribution' => 'comptabilite',
                'accorder'    => '0',
            ])->assertRedirect();

        $this->assertFalse($this->entreprise->fresh()->comptabiliteOuverte());
    }

    public function test_une_attribution_inconnue_est_refusee(): void
    {
        // La liste est fermée : sans cela, n'importe quelle chaîne entrerait
        // en base et ouvrirait quelque chose qu'on ne saurait plus nommer.
        $this->actingAs($this->superadmin())
            ->post(route('superadmin.attributions.basculer', $this->entreprise), [
                'attribution' => 'tout-ouvrir',
                'accorder'    => '1',
            ])->assertSessionHasErrors('attribution');
    }

    public function test_l_ecran_des_attributions_n_est_pas_celui_d_un_admin(): void
    {
        $this->connecte()->get(route('superadmin.attributions.index'))->assertForbidden();
    }

    public function test_le_superadministrateur_voit_l_ecran(): void
    {
        $this->actingAs($this->superadmin())
            ->get(route('superadmin.attributions.index'))
            ->assertOk()
            ->assertSee('Attributions');
    }
}
