<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que la passerelle entrante laissait lire.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Le défaut
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Quatre points d'entrée n'avaient qu'un secret partagé — le même pour toutes
 * les entreprises, détenu par le serveur, et qui **ne dit pas qui appelle**.
 *
 * **Simulation d'attaque.** Le secret fuit : ancien salarié, journal de
 * requêtes, `.env` recopié sur un poste de développement. Ce qu'il ouvrait :
 *
 *  - `list-companies` rendait **toutes les entreprises de la plateforme**,
 *    avec leur adresse, leur NCC, leur RCCM, le nom de leur gérant et
 *    **l'adresse électronique de leur administrateur** — l'annuaire complet
 *    des clients, et de quoi monter un hameçonnage crédible contre le compte
 *    le plus puissant de chaque entreprise ;
 *  - `company-info` rendait la fiche de n'importe laquelle ;
 *  - `tier-info` rendait le téléphone, l'adresse et le NCC de n'importe quel
 *    client de n'importe quelle entreprise — son carnet d'adresses commercial.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui le ferme
 * ─────────────────────────────────────────────────────────────────────────
 *
 * La clé du dossier, elle, désigne une entreprise et une seule. Présentée en
 * en-tête `X-Company-Key`, elle borne la réponse à cette entreprise-là ; une
 * clé qui en désigne une autre reçoit 403 (Forbidden — accès interdit), et une
 * clé inconnue 401 (Unauthorized — non authentifié).
 *
 * **La tolérance de transition est fermée.** Elle laissait passer un appel
 * sans clé sur le seul secret, le temps que Comptaflow envoie l'en-tête. Il
 * l'envoie désormais sur `company-info` et `tier-info` : un secret volé ne
 * suffit plus à lire la fiche d'une entreprise ni son carnet d'adresses.
 *
 * `list-companies` seul reste accessible sans clé, et c'est voulu : il sert à
 * rapprocher un dossier qui n'est pas encore lié, et ne rend qu'un nom et un
 * identifiant.
 */
class PasserelleEntranteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $victime;
    private Entreprise $assaillante;

    protected function setUp(): void
    {
        parent::setUp();

        config(['selflow.comptaflow_api_secret' => 'secret-serveur-de-test']);

        $this->victime = Entreprise::create([
            'nom' => 'Quincaillerie du Plateau', 'ncc' => '2601234A',
            'rccm' => 'CI-ABJ-2026-B-00042', 'comptaflow_sync_status' => 'active',
            'comptaflow_company_id' => 7,
        ]);
        $this->victime->comptaflow_sync_key = 'cptf_live_de_la_victime';
        $this->victime->save();

        Utilisateur::create([
            'nom' => 'Konan', 'prenom' => 'Yao', 'email' => 'patron@quincaillerie.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->victime->id,
        ]);

        Client::create([
            'entreprise_id' => $this->victime->id, 'nom' => 'Grand Client BTP',
            'numero_tiers' => '410001', 'telephone' => '0102030405',
            'ncc' => '2609876B', 'compte_comptable' => '411000',
        ]);

        $this->assaillante = Entreprise::create([
            'nom' => 'Voisine SARL', 'comptaflow_sync_status' => 'active',
            'comptaflow_company_id' => 8,
        ]);
        $this->assaillante->comptaflow_sync_key = 'cptf_live_de_l_assaillante';
        $this->assaillante->save();
    }

    /** @param array<string, mixed> $corps */
    private function appel(string $route, array $corps, ?string $cle = null)
    {
        $entetes = $cle ? ['X-Company-Key' => $cle] : [];

        return $this->withHeaders($entetes)->postJson(route($route), array_merge(
            ['secret' => 'secret-serveur-de-test'], $corps
        ));
    }

    // ── L'annuaire de la plateforme ──────────────────────────────────

    public function test_la_liste_ne_rend_plus_l_annuaire_de_la_plateforme(): void
    {
        $reponse = $this->appel('api.external.list-companies', [])->assertOk();

        $corps = $reponse->getContent();

        // Ce qui en sortait : de quoi écrire à l'administrateur de chaque
        // entreprise en se faisant passer pour nous.
        $this->assertStringNotContainsString('patron@quincaillerie.ci', $corps);
        $this->assertStringNotContainsString('2601234A', $corps);
        $this->assertStringNotContainsString('CI-ABJ-2026-B-00042', $corps);

        // Ce qui reste : de quoi rapprocher un dossier, et rien de plus.
        $this->assertStringContainsString('Quincaillerie du Plateau', $corps);
    }

    public function test_une_cle_borne_la_liste_a_son_propre_dossier(): void
    {
        $reponse = $this->appel('api.external.list-companies', [], 'cptf_live_de_l_assaillante')
            ->assertOk();

        $noms = collect($reponse->json('companies'))->pluck('nom')->all();

        $this->assertSame(['Voisine SARL'], $noms);
    }

    // ── La fiche d'une entreprise ────────────────────────────────────

    public function test_la_cle_d_une_entreprise_n_ouvre_pas_la_fiche_d_une_autre(): void
    {
        $this->appel(
            'api.external.company-info',
            ['selflow_company_id' => $this->victime->id],
            'cptf_live_de_l_assaillante'
        )->assertForbidden();
    }

    public function test_la_cle_ouvre_sa_propre_fiche(): void
    {
        $this->appel(
            'api.external.company-info',
            ['selflow_company_id' => $this->victime->id],
            'cptf_live_de_la_victime'
        )->assertOk()->assertJsonPath('company.ncc', '2601234A');
    }

    // ── Le carnet d'adresses commercial ──────────────────────────────

    public function test_la_cle_d_une_entreprise_n_ouvre_pas_les_tiers_d_une_autre(): void
    {
        $this->appel(
            'api.external.tier-info',
            ['selflow_company_id' => $this->victime->id, 'numero_de_tiers' => '410001'],
            'cptf_live_de_l_assaillante'
        )->assertForbidden();
    }

    public function test_la_cle_ouvre_ses_propres_tiers(): void
    {
        $this->appel(
            'api.external.tier-info',
            ['selflow_company_id' => $this->victime->id, 'numero_de_tiers' => '410001'],
            'cptf_live_de_la_victime'
        )->assertOk()->assertJsonPath('tier.telephone', '0102030405');
    }

    // ── Ce que vaut une clé ──────────────────────────────────────────

    public function test_une_cle_inventee_est_refusee_en_401(): void
    {
        // 401, et non 403 : l'appelant n'est pas reconnu. Confondre les deux
        // rendrait le journal illisible — on ne saurait plus distinguer un
        // déploiement mal configuré d'une tentative de lecture croisée.
        $this->appel(
            'api.external.company-info',
            ['selflow_company_id' => $this->victime->id],
            'cptf_live_inventee'
        )->assertUnauthorized();
    }

    public function test_une_cle_revoquee_est_refusee_en_le_disant(): void
    {
        $this->victime->update(['comptaflow_revoquee_le' => now()]);

        $this->appel(
            'api.external.company-info',
            ['selflow_company_id' => $this->victime->id],
            'cptf_live_de_la_victime'
        )->assertUnauthorized()->assertJsonFragment(['success' => false]);
    }

    public function test_un_secret_invalide_refuse_tout_meme_avec_une_bonne_cle(): void
    {
        $this->withHeaders(['X-Company-Key' => 'cptf_live_de_la_victime'])
            ->postJson(route('api.external.company-info'), [
                'secret' => 'pas-le-bon-secret',
                'selflow_company_id' => $this->victime->id,
            ])->assertUnauthorized();
    }

    // ── La porte fermée ──────────────────────────────────────────────

    public function test_sans_cle_le_refus_est_401(): void
    {
        // Le secret seul suffisait. Il ne suffit plus : il dit que l'appel
        // vient de Comptaflow, pas de quelle entreprise.
        $this->appel('api.external.company-info', ['selflow_company_id' => $this->victime->id])
            ->assertUnauthorized();
    }

    public function test_sans_cle_le_carnet_d_adresses_reste_ferme(): void
    {
        $this->appel('api.external.tier-info', [
            'selflow_company_id' => $this->victime->id,
            'numero_de_tiers'    => '411001',
        ])->assertUnauthorized();
    }

    public function test_list_companies_reste_accessible_sans_cle(): void
    {
        // Rapprocher un dossier qui n'est pas lié : il n'a pas de clé à
        // présenter. Fermer cette route-là aurait cassé l'écran de liaison
        // du superadministrateur de Comptaflow.
        $this->appel('api.external.list-companies', [])->assertOk();
    }
}
