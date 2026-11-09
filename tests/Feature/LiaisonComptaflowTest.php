<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Services\RecevoirReferentielComptaflowService;
use App\Modules\Admin\Services\LiaisonComptaflowService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La clé de liaison Comptaflow, délivrée et non saisie.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui était en place
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Un champ de texte libre dans les paramètres de l'entreprise, avec la
 * consigne « obtenir depuis Comptaflow → Configuration → Liaison Selflow ».
 * `EntrepriseControleur` ouvrait la liaison dès que la valeur changeait.
 *
 * **Simulation d'attaque, et c'est le cœur de ce lot.** Une entreprise obtient
 * la clé d'une autre — comptable partagé entre deux clients, capture d'écran,
 * ancien salarié. Elle la colle dans ses propres paramètres. La liaison
 * s'ouvre, et son référentiel puis toutes ses écritures partent **dans les
 * livres de l'autre**. Le secret partagé n'y change rien : il est détenu par
 * le serveur, part sur tous les appels, et ne dit pas qui appelle. Rien, côté
 * Selflow, ne vérifiait que la clé saisie désignait celui qui la saisissait.
 *
 * Trois autres écrans mentaient au passage :
 *
 *  - le bouton « Lancer la synchronisation test » annonçait « Synchronisation
 *    bidirectionnelle réussie ! Les écritures comptables et les statuts des
 *    factures ont été synchronisés » **sans qu'aucun appel ne parte** — et
 *    écrivait `comptaflow_sync_status = 'Actif'`, valeur qu'aucun autre code
 *    ne reconnaît : le déversement, qui attend `active`, s'arrêtait net après
 *    cette « réussite » ;
 *  - « Délier » effaçait la clé chez Selflow sans rien dire à Comptaflow, où
 *    elle continuait d'ouvrir le dossier ;
 *  - « Vérifier la liaison » écrivait la date du jour et annonçait « Liaison
 *    active » sans interroger personne.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui la remplace
 * ─────────────────────────────────────────────────────────────────────────
 *
 * L'entreprise demande, le superadministrateur valide, Comptaflow génère la
 * clé et la renvoie, Selflow la range chiffrée. Personne ne la tape, aucun
 * écran ne l'affiche entière, et c'est elle — en en-tête `X-Company-Key` —
 * qui dit à Comptaflow quelle entreprise appelle.
 */
class LiaisonComptaflowTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private Utilisateur $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['selflow.comptaflow_api_secret' => 'secret-serveur-de-test']);
        config(['selflow.comptaflow_api_url' => 'http://comptaflow.test']);

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-Knowing CGA', 'regime_imposition' => 'RNI',
            'adresse' => 'Cocody, Abidjan', 'rccm' => 'CI-ABJ-2026-B-00042',
            'ncc' => '2601234A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'comptabilite', 'tiers'],
        ]);

        $site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-liaison@dc-knowing.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);

        $this->superadmin = Utilisateur::create([
            'nom' => 'Selflow', 'prenom' => 'Support', 'email' => 'support-liaison@selflow.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin',
            'habilitations' => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME,
        ]);
    }

    /** Le minimum que le formulaire des paramètres exige. */
    private function enregistrerParametres(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.entreprise.parametres.enregistrer'), array_merge([
            'nom'             => $this->entreprise->nom,
            'gerant_fonction' => 'Gérant',
            'adresse'         => 'Cocody, Abidjan',
            'rccm'            => 'CI-ABJ-2026-B-00042',
            'ncc'             => '2601234A',
        ], $extra));
    }

    /** Ce que Comptaflow répond quand tout va bien. */
    private function comptaflowRepond(string $cle = 'cptf_live_9f3b7d2a4c81'): void
    {
        Http::fake([
            '*/api/external/companies/provision' => Http::response([
                'success' => true, 'company_id' => 42, 'sync_key' => $cle,
            ]),
            '*/api/external/link-company' => Http::response(['success' => true, 'company_id' => 42, 'plan_comptable' => [], 'codes_journaux' => [], 'tiers' => []]),
            '*/api/external/companies/revoke' => Http::response(['success' => true]),
            '*' => Http::response(['success' => true]),
        ]);
    }

    // ── La clé ne se saisit plus ─────────────────────────────────────

    public function test_le_formulaire_des_parametres_n_accepte_plus_de_cle(): void
    {
        Http::fake();

        $this->enregistrerParametres(['comptaflow_sync_key' => 'cle_collee_a_la_main'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->entreprise->fresh()->comptaflow_sync_key,
            'Le formulaire des paramètres a de nouveau écrit la clé de liaison.');
        Http::assertNothingSent();
    }

    public function test_coller_la_cle_d_une_autre_entreprise_ne_lie_a_rien(): void
    {
        // Simulation d'attaque. La victime est liée ; l'assaillant connaît sa
        // clé et la colle dans ses propres paramètres. Avant ce lot, son
        // référentiel et ses écritures partaient dans les livres de la victime.
        $victime = Entreprise::create([
            'nom' => 'Victime SARL', 'regime_imposition' => 'RSI',
            'comptaflow_company_id' => 7, 'comptaflow_sync_status' => 'active',
        ]);
        $victime->comptaflow_sync_key = 'cptf_live_de_la_victime';
        $victime->save();

        Http::fake();

        $this->enregistrerParametres(['comptaflow_sync_key' => 'cptf_live_de_la_victime'])
            ->assertSessionHasNoErrors();

        $assaillant = $this->entreprise->fresh();

        $this->assertNull($assaillant->comptaflow_sync_key);
        $this->assertNull($assaillant->comptaflow_company_id);
        $this->assertFalse($assaillant->liaisonComptaflowActive());
        Http::assertNothingSent();
    }

    public function test_enregistrer_les_parametres_ne_coupe_pas_une_liaison_active(): void
    {
        // Le statut se déduisait de la présence du champ dans la requête :
        // enregistrer les paramètres sans y toucher faisait passer une liaison
        // active à `inactive`, et les écritures cessaient de partir sans que
        // rien ne le dise.
        $this->entreprise->comptaflow_sync_key = 'cptf_live_abcd';
        $this->entreprise->comptaflow_sync_status = 'active';
        $this->entreprise->comptaflow_company_id = 42;
        $this->entreprise->save();

        Http::fake();
        $this->enregistrerParametres(['telephone' => '0102030405'])->assertSessionHasNoErrors();

        $this->assertTrue($this->entreprise->fresh()->liaisonComptaflowActive());
    }

    // ── La demande ───────────────────────────────────────────────────

    public function test_l_entreprise_demande_sans_rien_saisir(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.entreprise.comptaflow.demander'))
            ->assertSessionHasNoErrors();

        $fraiche = $this->entreprise->fresh();

        $this->assertTrue($fraiche->demandeComptaflowEnAttente());
        $this->assertNotNull($fraiche->comptaflow_demande_le);
        $this->assertSame($this->admin->id, $fraiche->comptaflow_demande_par);
        $this->assertNull($fraiche->comptaflow_sync_key);
    }

    public function test_une_seconde_demande_ne_se_superpose_pas_a_la_premiere(): void
    {
        $this->actingAs($this->admin)->post(route('admin.entreprise.comptaflow.demander'));
        $premiere = $this->entreprise->fresh()->comptaflow_demande_le;

        $this->actingAs($this->admin)->post(route('admin.entreprise.comptaflow.demander'))
            ->assertSessionHasErrors('comptaflow');

        $this->assertEquals($premiere, $this->entreprise->fresh()->comptaflow_demande_le);
    }

    public function test_l_ecran_des_parametres_ne_propose_plus_de_champ_de_cle(): void
    {
        $corps = $this->actingAs($this->admin)
            ->get(route('admin.entreprise.parametres'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="comptaflow_sync_key"', $corps);
        $this->assertStringContainsString('Demander la liaison Comptaflow', $corps);
    }

    // ── La validation ────────────────────────────────────────────────

    public function test_la_validation_fait_delivrer_la_cle_par_comptaflow(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);
        $this->comptaflowRepond();

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.liaisons.valider', $this->entreprise));

        $fraiche = $this->entreprise->fresh();

        $this->assertTrue($fraiche->liaisonComptaflowActive());
        $this->assertSame('cptf_live_9f3b7d2a4c81', $fraiche->comptaflow_sync_key);
        $this->assertSame(42, (int) $fraiche->comptaflow_company_id);
        $this->assertSame(Entreprise::DEMANDE_VALIDEE, $fraiche->comptaflow_demande_statut);
        $this->assertNotNull($fraiche->comptaflow_liee_le);

        // Le provisionnement est le seul appel qui porte le secret serveur.
        Http::assertSent(fn ($requete) => str_contains($requete->url(), '/companies/provision')
            && $requete['secret'] === 'secret-serveur-de-test'
            && $requete['entreprise']['ncc'] === '2601234A');
    }

    public function test_le_referentiel_est_recu_de_comptaflow_dans_la_foulee(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);
        $this->comptaflowRepond();

        LiaisonComptaflowService::valider($this->entreprise);

        // Selflow lit le référentiel du dossier lié. Aucun référentiel Selflow
        // n'est envoyé vers Comptaflow.
        Http::assertSent(fn ($requete) => str_contains($requete->url(), '/api/external/link-company')
            && $requete['selflow_company_id'] === $this->entreprise->id
            && !array_key_exists('clients', $requete->data())
            && !array_key_exists('fournisseurs', $requete->data()));
        Http::assertNotSent(fn ($requete) => str_contains($requete->url(), '/referentiel/deverser'));
    }

    public function test_un_provisionnement_en_echec_ne_laisse_pas_de_liaison_a_moitie_ouverte(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);

        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Dossier refusé'], 500)]);

        $resultat = LiaisonComptaflowService::valider($this->entreprise);
        $fraiche = $this->entreprise->fresh();

        $this->assertFalse($resultat['success']);
        $this->assertFalse($fraiche->liaisonComptaflowActive());
        $this->assertNull($fraiche->comptaflow_sync_key);
        // La demande reste en attente : elle se rejoue.
        $this->assertTrue($fraiche->demandeComptaflowEnAttente());
    }

    public function test_un_comptaflow_sans_le_point_d_entree_le_dit(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);

        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $resultat = LiaisonComptaflowService::valider($this->entreprise);

        // 404 (Not Found — introuvable) veut dire ici « déploiement en
        // retard », pas « clé perdue ». Le message doit envoyer au bon endroit.
        $this->assertFalse($resultat['success']);
        $this->assertStringContainsString('companies/provision', $resultat['message']);
        $this->assertTrue($this->entreprise->fresh()->demandeComptaflowEnAttente());
    }

    public function test_une_entreprise_deja_liee_n_est_pas_provisionnee_deux_fois(): void
    {
        $this->entreprise->comptaflow_sync_key = 'cptf_live_abcd';
        $this->entreprise->comptaflow_sync_status = 'active';
        $this->entreprise->comptaflow_company_id = 42;
        $this->entreprise->save();

        Http::fake();
        $resultat = LiaisonComptaflowService::valider($this->entreprise->fresh());

        $this->assertFalse($resultat['success']);
        Http::assertNothingSent();
    }

    // ── Les accès : le compte Comptaflow est le compte Selflow ───────

    public function test_l_empreinte_du_mot_de_passe_part_jamais_le_mot_de_passe(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);
        $this->comptaflowRepond();

        LiaisonComptaflowService::valider($this->entreprise);

        // Le compte est le même des deux côtés : même adresse, même mot de
        // passe. Ce qui voyage est l'empreinte `bcrypt`, telle qu'elle est en
        // base — personne, ni le superadministrateur ni le réseau ni le
        // journal, n'a jamais lu le mot de passe.
        Http::assertSent(function ($requete) {
            if (!str_contains($requete->url(), '/companies/provision')) {
                return false;
            }

            $identite = $requete['entreprise'];

            return $identite['admin_email'] === 'lewis-liaison@dc-knowing.ci'
                && str_starts_with($identite['admin_password_hash'], '$2y$')
                && !str_contains($requete->body(), 'secret-de-test');
        });
    }

    public function test_aucun_mot_de_passe_n_est_choisi_par_le_superadministrateur(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);
        $this->comptaflowRepond();

        LiaisonComptaflowService::valider($this->entreprise);

        // L'ancienne route `register-enterprise` demandait au
        // superadministrateur de choisir le mot de passe du compte d'un client,
        // et le transportait en clair.
        Http::assertSent(fn ($requete) => !str_contains($requete->url(), '/companies/provision')
            || !array_key_exists('admin_password', $requete['entreprise'] ?? []));
    }

    public function test_l_entreprise_lit_ses_acces_et_l_adresse_de_comptaflow(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);
        $this->comptaflowRepond();
        LiaisonComptaflowService::valider($this->entreprise);

        // Elle apprenait qu'elle avait un dossier comptable sans savoir où le
        // consulter, ni avec quels identifiants.
        $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))->assertOk()
            ->assertSee('lewis-liaison@dc-knowing.ci', false)
            ->assertSee(rtrim(config('selflow.comptaflow_app_url'), '/'), false)
            ->assertSee('celui de votre compte Selflow', false);
    }

    // ── L'avis d'ouverture ───────────────────────────────────────────

    public function test_le_titulaire_est_prevenu_de_l_ouverture(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->comptaflowRepond();

        LiaisonComptaflowService::valider($this->entreprise);

        // Un compte s'ouvrait chez une autre application au nom du client sans
        // qu'il en soit informé.
        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\CompteComptaflowOuvert::class,
            fn ($mail) => $mail->hasTo('lewis-liaison@dc-knowing.ci')
        );
    }

    public function test_l_avis_ne_porte_ni_mot_de_passe_ni_cle(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->comptaflowRepond();

        LiaisonComptaflowService::valider($this->entreprise);

        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\CompteComptaflowOuvert::class,
            function ($mail) {
                $corps = $mail->render();

                // On dit **lequel** est le mot de passe, jamais **quel** il
                // est : un courriel traverse des serveurs qu'on ne choisit pas
                // et dort des années dans une sauvegarde. Et la clé de liaison
                // ne concerne pas le client.
                return !str_contains($corps, 'secret-de-test')
                    && !str_contains($corps, 'cptf_live_9f3b7d2a4c81')
                    && str_contains($corps, 'lewis-liaison@dc-knowing.ci')
                    && str_contains($corps, 'celui de votre compte Selflow');
            }
        );
    }

    public function test_l_avis_porte_son_en_tete_son_corps_et_son_pied(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->comptaflowRepond();

        LiaisonComptaflowService::valider($this->entreprise);

        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\CompteComptaflowOuvert::class,
            function ($mail) {
                $corps = $mail->render();

                return str_contains($corps, 'Votre dossier comptable est ouvert')
                    && str_contains($corps, rtrim(config('selflow.comptaflow_app_url'), '/'))
                    && str_contains($corps, 'Nous ne vous demanderons jamais votre mot de passe')
                    && str_contains($corps, 'Vous n\'attendiez pas ce message');
            }
        );
    }

    public function test_une_messagerie_en_panne_ne_defait_pas_la_liaison(): void
    {
        $this->comptaflowRepond();

        // Le contraire laisserait la demande en attente alors que le dossier
        // existe déjà chez Comptaflow — et le rejeu en ouvrirait un second.
        \Illuminate\Support\Facades\Mail::shouldReceive('to')
            ->andThrow(new \RuntimeException('SMTP injoignable'));

        $resultat = LiaisonComptaflowService::valider($this->entreprise);

        $this->assertTrue($resultat['success']);
        $this->assertTrue($this->entreprise->fresh()->liaisonComptaflowActive());
    }

    // ── Le superadministrateur lie sans attendre de demande ──────────

    public function test_le_superadministrateur_peut_lier_sans_demande(): void
    {
        // Un client qui souscrit Comptaflow par téléphone n'a pas à cliquer
        // dans un écran pour que son dossier s'ouvre.
        $this->assertNull($this->entreprise->comptaflow_demande_statut);
        $this->comptaflowRepond();

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.liaisons.valider', $this->entreprise));

        $this->assertTrue($this->entreprise->fresh()->liaisonComptaflowActive());
    }

    public function test_la_liaison_directe_delivre_la_cle_comme_les_autres(): void
    {
        $this->comptaflowRepond();

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.liaisons.valider', $this->entreprise));

        // Ce n'est pas l'ancien « Lier manuellement », qui faisait coller un
        // identifiant et une clé sans que rien ne vérifie que ce dossier
        // appartenait à cette entreprise.
        $this->assertSame('cptf_live_9f3b7d2a4c81', $this->entreprise->fresh()->comptaflow_sync_key);
        Http::assertSent(fn ($requete) => str_contains($requete->url(), '/companies/provision'));
    }

    public function test_l_ecran_du_superadministrateur_propose_de_lier_une_entreprise_sans_demande(): void
    {
        $corps = $this->actingAs($this->superadmin)
            ->get(route('superadmin.liaisons.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Lier maintenant', $corps);
        $this->assertStringContainsString(rtrim(config('selflow.comptaflow_app_url'), '/'), $corps);
    }

    // ── La rotation des clés ─────────────────────────────────────────

    /** Une entreprise liée, dont la clé date. */
    private function lieeDepuis(int $jours): Entreprise
    {
        $this->entreprise->comptaflow_sync_key = 'cptf_live_ancienne';
        $this->entreprise->fill([
            'comptaflow_sync_status'    => 'active',
            'comptaflow_company_id'     => 42,
            'comptaflow_cle_indice'     => 'enne',
            'comptaflow_liee_le'        => now()->subDays($jours),
            'comptaflow_cle_tournee_le' => now()->subDays($jours),
        ])->save();

        return $this->entreprise->fresh();
    }

    public function test_le_superadministrateur_renouvelle_une_cle_quand_il_veut(): void
    {
        $entreprise = $this->lieeDepuis(2);

        Http::fake(['*/api/external/companies/rotate-key' => Http::response([
            'success' => true, 'sync_key' => 'cptf_live_toute_neuve_9f3b',
        ])]);

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.liaisons.renouveler_cle', $entreprise));

        $fraiche = $entreprise->fresh();

        // Il y a des jours où l'on n'attend pas le premier du mois : un
        // prestataire qui part, un journal retrouvé sur un poste partagé.
        $this->assertSame('cptf_live_toute_neuve_9f3b', $fraiche->comptaflow_sync_key);
        $this->assertSame('9f3b', $fraiche->comptaflow_cle_indice);
        $this->assertNotNull($fraiche->comptaflow_cle_tournee_le);
        $this->assertTrue($fraiche->liaisonComptaflowActive());
    }

    public function test_un_renouvellement_en_echec_laisse_l_ancienne_cle_en_place(): void
    {
        $entreprise = $this->lieeDepuis(40);

        Http::fake(['*' => Http::response(['success' => false], 500)]);

        $resultat = LiaisonComptaflowService::renouvelerLaCle($entreprise);
        $fraiche = $entreprise->fresh();

        // C'est le point qui garantit que rien ne casse : effacer d'abord et
        // demander ensuite couperait la liaison à la première panne réseau.
        $this->assertFalse($resultat['success']);
        $this->assertSame('cptf_live_ancienne', $fraiche->comptaflow_sync_key);
        $this->assertTrue($fraiche->liaisonComptaflowActive());
        $this->assertNotNull($fraiche->comptaflow_rotation_echouee_le);
    }

    public function test_un_comptaflow_sans_la_rotation_le_dit_sans_rien_casser(): void
    {
        $entreprise = $this->lieeDepuis(40);

        Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

        $resultat = LiaisonComptaflowService::renouvelerLaCle($entreprise);

        $this->assertFalse($resultat['success']);
        $this->assertStringContainsString('companies/rotate-key', $resultat['message']);
        $this->assertStringContainsString('continue de fonctionner', $resultat['message']);
        $this->assertTrue($entreprise->fresh()->liaisonComptaflowActive());
    }

    public function test_la_rotation_mensuelle_ne_prend_que_ce_qui_a_depasse_sa_duree(): void
    {
        $recente = $this->lieeDepuis(3);
        $this->assertCount(0, LiaisonComptaflowService::dossiersARenouveler());

        $ancienne = $this->lieeDepuis(LiaisonComptaflowService::JOURS_AVANT_ROTATION + 1);
        $this->assertCount(1, LiaisonComptaflowService::dossiersARenouveler());
    }

    public function test_une_cle_jamais_tournee_compte_depuis_sa_liaison(): void
    {
        // Une clé posée il y a six mois et jamais renouvelée est la plus en
        // retard de toutes : une comparaison sur une colonne nulle l'aurait
        // laissée tranquille.
        $this->entreprise->comptaflow_sync_key = 'cptf_live_ancienne';
        $this->entreprise->fill([
            'comptaflow_sync_status'    => 'active',
            'comptaflow_company_id'     => 42,
            'comptaflow_liee_le'        => now()->subMonths(6),
            'comptaflow_cle_tournee_le' => null,
        ])->save();

        $this->assertCount(1, LiaisonComptaflowService::dossiersARenouveler());
    }

    public function test_un_dossier_en_echec_recent_est_mis_au_repos(): void
    {
        $entreprise = $this->lieeDepuis(40);
        $entreprise->update(['comptaflow_rotation_echouee_le' => now()->subHour()]);

        // Comptaflow peut être en panne pour la journée : marteler son API ne
        // la réveillera pas.
        $this->assertCount(0, LiaisonComptaflowService::dossiersARenouveler());

        $entreprise->update(['comptaflow_rotation_echouee_le' => now()->subDay()]);
        $this->assertCount(1, LiaisonComptaflowService::dossiersARenouveler());
    }

    public function test_une_liaison_revoquee_n_est_pas_renouvelee(): void
    {
        $entreprise = $this->lieeDepuis(40);
        $entreprise->update(['comptaflow_revoquee_le' => now()]);

        $this->assertCount(0, LiaisonComptaflowService::dossiersARenouveler());
    }

    public function test_la_commande_mensuelle_renouvelle_et_rend_la_main(): void
    {
        $entreprise = $this->lieeDepuis(40);

        Http::fake(['*/api/external/companies/rotate-key' => Http::response([
            'success' => true, 'sync_key' => 'cptf_live_du_mois_abcd',
        ])]);

        $this->artisan('selflow:renouveler-cles-comptaflow')
            ->assertSuccessful();

        $this->assertSame('cptf_live_du_mois_abcd', $entreprise->fresh()->comptaflow_sync_key);
    }

    public function test_un_echec_n_empeche_pas_les_autres_dossiers(): void
    {
        $enEchec = $this->lieeDepuis(40);

        $seconde = Entreprise::create([
            'nom' => 'Seconde SARL', 'comptaflow_sync_status' => 'active',
            'comptaflow_company_id' => 43, 'comptaflow_liee_le' => now()->subDays(40),
            'comptaflow_cle_tournee_le' => now()->subDays(40),
        ]);
        $seconde->comptaflow_sync_key = 'cptf_live_seconde';
        $seconde->save();

        // La première échoue, la seconde doit passer : une entreprise dont
        // Comptaflow refuse le renouvellement ne doit pas empêcher les
        // suivantes de tourner.
        Http::fake(['*' => Http::sequence()
            ->push(['success' => false], 500)
            ->push(['success' => true, 'sync_key' => 'cptf_live_seconde_neuve'])]);

        $this->artisan('selflow:renouveler-cles-comptaflow')->assertSuccessful();

        $this->assertSame('cptf_live_ancienne', $enEchec->fresh()->comptaflow_sync_key);
        $this->assertSame('cptf_live_seconde_neuve', $seconde->fresh()->comptaflow_sync_key);
    }

    public function test_la_rotation_a_blanc_n_appelle_personne(): void
    {
        $this->lieeDepuis(40);
        Http::fake();

        $this->artisan('selflow:renouveler-cles-comptaflow --a-blanc')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('cptf_live_ancienne', $this->entreprise->fresh()->comptaflow_sync_key);
    }

    public function test_un_administrateur_ne_renouvelle_pas_la_cle(): void
    {
        $entreprise = $this->lieeDepuis(40);
        Http::fake();

        $this->actingAs($this->admin)
            ->post(route('superadmin.liaisons.renouveler_cle', $entreprise))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    // ── Le refus ─────────────────────────────────────────────────────

    public function test_le_refus_porte_un_motif_que_l_entreprise_lit(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);

        $this->actingAs($this->superadmin)->post(
            route('superadmin.liaisons.refuser', $this->entreprise),
            ['motif' => 'Le NCC déclaré ne correspond à aucune entreprise connue de la DGI.']
        )->assertSessionHasNoErrors();

        $this->assertSame(Entreprise::DEMANDE_REFUSEE, $this->entreprise->fresh()->comptaflow_demande_statut);

        $this->actingAs($this->admin)->get(route('admin.entreprise.parametres'))
            ->assertSee('ne correspond à aucune entreprise connue', false);
    }

    public function test_un_refus_muet_est_refuse(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);

        $this->actingAs($this->superadmin)
            ->post(route('superadmin.liaisons.refuser', $this->entreprise), ['motif' => ''])
            ->assertSessionHasErrors('motif');
    }

    public function test_redemander_apres_un_refus_efface_le_motif_precedent(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);
        LiaisonComptaflowService::refuser($this->entreprise, 'RCCM manquant');

        LiaisonComptaflowService::demander($this->entreprise->fresh(), $this->admin);

        $this->assertNull($this->entreprise->fresh()->comptaflow_refus_motif);
    }

    // ── La révocation ────────────────────────────────────────────────

    public function test_delier_revoque_la_cle_chez_comptaflow(): void
    {
        $this->entreprise->comptaflow_sync_key = 'cptf_live_abcd';
        $this->entreprise->comptaflow_sync_status = 'active';
        $this->entreprise->comptaflow_company_id = 42;
        $this->entreprise->save();

        $this->comptaflowRepond();

        $this->actingAs($this->superadmin)
            ->delete(route('superadmin.liaisons.delierEntreprise', $this->entreprise));

        $fraiche = $this->entreprise->fresh();

        $this->assertNull($fraiche->comptaflow_sync_key);
        $this->assertNull($fraiche->comptaflow_company_id);
        $this->assertNotNull($fraiche->comptaflow_revoquee_le);

        // Une clé oubliée chez l'autre est une clé qui marche : la révocation
        // doit être dite à Comptaflow, en portant la clé qu'on révoque.
        Http::assertSent(fn ($requete) => str_contains($requete->url(), '/companies/revoke')
            && $requete->hasHeader('X-Company-Key', 'cptf_live_abcd'));
    }

    // ── Ce que la clé authentifie ────────────────────────────────────

    public function test_le_deversement_porte_la_cle_en_entete(): void
    {
        $this->entreprise->comptaflow_sync_key = 'cptf_live_abcd';
        $this->entreprise->comptaflow_sync_status = 'active';
        $this->entreprise->comptaflow_company_id = 42;
        $this->entreprise->save();

        $this->comptaflowRepond();

        RecevoirReferentielComptaflowService::synchroniser($this->entreprise->fresh());

        // Le secret partagé est le même pour toutes les entreprises : il ne
        // dit pas laquelle appelle. La clé du dossier, si.
        Http::assertSent(fn ($requete) => str_contains($requete->url(), '/api/external/link-company')
            && $requete['selflow_sync_key'] === 'cptf_live_abcd'
            && !array_key_exists('plan_comptable', $requete->data())
            && !array_key_exists('codes_journaux', $requete->data())
            && !array_key_exists('tiers', $requete->data()));
    }

    public function test_une_liaison_deja_ouverte_n_est_pas_rouverte_a_chaque_deversement(): void
    {
        $this->entreprise->comptaflow_sync_key = 'cptf_live_abcd';
        $this->entreprise->comptaflow_sync_status = 'active';
        $this->entreprise->comptaflow_company_id = 42;
        $this->entreprise->save();

        $this->comptaflowRepond();

        RecevoirReferentielComptaflowService::synchroniser($this->entreprise->fresh());

        Http::assertSent(fn ($requete) => str_contains($requete->url(), '/link-company'));
    }

    // ── Ce que la base et les écrans laissent voir ───────────────────

    public function test_la_cle_est_chiffree_en_base(): void
    {
        $this->entreprise->comptaflow_sync_key = 'cptf_live_9f3b7d2a4c81';
        $this->entreprise->save();

        $brut = DB::table('entreprises')->where('id', $this->entreprise->id)->value('comptaflow_sync_key');

        // Une sauvegarde égarée, ou un accès en lecture à la table, livrait
        // toutes les clés en clair — donc l'écriture dans les livres de
        // chaque entreprise.
        $this->assertNotSame('cptf_live_9f3b7d2a4c81', $brut);
        $this->assertSame('cptf_live_9f3b7d2a4c81', Crypt::decryptString($brut));
        $this->assertSame('cptf_live_9f3b7d2a4c81', $this->entreprise->fresh()->comptaflow_sync_key);
    }

    public function test_aucun_ecran_n_affiche_la_cle_entiere(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);
        $this->comptaflowRepond();
        LiaisonComptaflowService::valider($this->entreprise);

        $parametres = $this->actingAs($this->admin)
            ->get(route('admin.entreprise.parametres'))->assertOk()->getContent();
        $liaisons = $this->actingAs($this->superadmin)
            ->get(route('superadmin.liaisons.index'))->assertOk()->getContent();

        foreach ([$parametres, $liaisons] as $corps) {
            $this->assertStringNotContainsString('cptf_live_9f3b7d2a4c81', $corps);
        }

        // Quatre caractères : de quoi reconnaître la clé, pas de quoi s'en
        // servir.
        $this->assertStringContainsString('••••4c81', $liaisons);
    }

    // ── Qui a le droit ───────────────────────────────────────────────

    public function test_un_administrateur_d_entreprise_ne_valide_pas_sa_propre_demande(): void
    {
        LiaisonComptaflowService::demander($this->entreprise, $this->admin);
        Http::fake();

        $this->actingAs($this->admin)
            ->post(route('superadmin.liaisons.valider', $this->entreprise))
            ->assertForbidden();

        $this->assertFalse($this->entreprise->fresh()->liaisonComptaflowActive());
        Http::assertNothingSent();
    }

    public function test_un_administrateur_ne_delie_pas_une_entreprise(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->delete(route('superadmin.liaisons.delierEntreprise', $this->entreprise))
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
