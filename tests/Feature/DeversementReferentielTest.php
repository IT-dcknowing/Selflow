<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Services\DeversementReferentielService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le sens de la passerelle.
 *
 * **Selflow déverse ; Comptaflow reçoit.** Chaque entreprise verse son
 * référentiel dans Comptaflow comme on y verserait un fichier d'import, et
 * Comptaflow ne l'accepte que si la liaison existe.
 *
 * Le code faisait l'inverse. `synchroniserDepuisComptaflow()` appelait
 * Comptaflow, **recevait** son plan comptable, ses codes journaux et ses
 * tiers, les recopiait dans Selflow — puis **supprimait** toute ligne Selflow
 * marquée `source = comptaflow` absente de la réponse. Une entreprise dont le
 * comptable n'avait pas encore rempli son plan se retrouvait dépouillée du
 * sien, sans que rien ne le lui dise.
 */
class DeversementReferentielTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'selflow.comptaflow_api_url'    => 'https://comptaflow.test',
            'selflow.comptaflow_api_secret' => 'secret-de-test',
        ]);

        $this->entreprise = Entreprise::create([
            'nom'                    => 'Quincaillerie du Plateau',
            'comptaflow_sync_status' => 'active',
        ]);

        // La clé ne passe plus par l'affectation en masse : elle n'est pas
        // `$fillable`, précisément pour qu'aucune requête ne puisse l'y
        // glisser. Elle se pose comme le service la pose.
        $this->entreprise->comptaflow_sync_key = 'cle-de-liaison';
        $this->entreprise->save();

        foreach ([['411000', 'Clients'], ['401000', 'Fournisseurs'], ['701000', 'Ventes de marchandises']] as [$numero, $libelle]) {
            PlanComptable::create([
                'entreprise_id' => $this->entreprise->id,
                'numero' => $numero, 'libelle' => $libelle,
            ]);
        }

        CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'VTE', 'intitule' => 'Ventes', 'type' => 'Vente',
        ]);

        CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'MTN', 'intitule' => 'MTN Mobile Money', 'type' => 'Banque',
            'compte' => '521500',
        ]);

        Client::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Konan Yao', 'numero_tiers' => '410007',
            'compte_comptable' => '411000', 'telephone' => '+225 07 00 00 00',
        ]);

        Fournisseur::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Grossiste du Nord', 'numero_tiers' => '400003',
            'compte_comptable' => '401000',
        ]);

    }

    /**
     * Faire répondre Comptaflow.
     *
     * Les doublures ne se déclarent pas dans `setUp()` : `Http::fake()` empile
     * ses motifs et retient **le premier qui correspond**. Un attrape-tout
     * posé au montage masquerait donc toute doublure plus précise déclarée
     * ensuite dans un test, qui passerait alors sans rien éprouver.
     */
    private function comptaflowRepond(array $doublures = []): void
    {
        Http::fake($doublures + [
            '*/link-company' => Http::response(['success' => true, 'company_id' => 42], 200),
            '*'              => Http::response(['success' => true], 200),
        ]);
    }

    /** Le corps du dernier appel au déversement du référentiel. */
    private function corpsDuDeversement(): array
    {
        $corps = [];

        Http::assertSent(function ($requete) use (&$corps) {
            if (str_contains($requete->url(), '/referentiel/deverser')) {
                $corps = $requete->data();
                return true;
            }
            return false;
        });

        return $corps;
    }

    // ── Le sens ──────────────────────────────────────────────────────

    public function test_le_referentiel_part_vers_comptaflow(): void
    {
        $this->comptaflowRepond();

        $resultat = DeversementReferentielService::deverser($this->entreprise);

        $this->assertTrue($resultat['success'], $resultat['message']);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/api/external/referentiel/deverser'));
    }

    public function test_rien_n_est_recopie_depuis_comptaflow(): void
    {
        // Comptaflow renvoie un plan comptable : il doit rester sans effet.
        // C'est exactement ce que l'ancien code absorbait — et il effaçait le
        // plan de l'entreprise dans la foulée.
        $this->comptaflowRepond([
            '*/link-company' => Http::response([
                'success'        => true,
                'company_id'     => 42,
                'plan_comptable' => [['numero_de_compte' => '999999', 'intitule' => 'Compte de Comptaflow']],
                'codes_journaux' => [['code_journal' => 'ZZZ', 'intitule' => 'Journal de Comptaflow', 'type' => 'Autre']],
                'tiers'          => [['numero_de_tiers' => '419999', 'intitule' => 'Tiers de Comptaflow', 'type_de_tiers' => 'client']],
            ], 200),
        ]);

        DeversementReferentielService::deverser($this->entreprise);

        $this->assertDatabaseMissing('plan_comptable', ['numero' => '999999']);
        $this->assertDatabaseMissing('codes_journaux', ['code' => 'ZZZ']);
        $this->assertDatabaseMissing('clients', ['numero_tiers' => '419999']);
    }

    public function test_le_plan_de_l_entreprise_n_est_jamais_supprime(): void
    {
        // Le pire cas de l'ancien sens : Comptaflow ne renvoyait rien, et
        // toute ligne Selflow absente de cette réponse était effacée.
        $this->comptaflowRepond();

        DeversementReferentielService::deverser($this->entreprise);

        $this->assertSame(3, PlanComptable::where('entreprise_id', $this->entreprise->id)->count());
        $this->assertSame(2, CodeJournal::where('entreprise_id', $this->entreprise->id)->count());
        $this->assertSame(1, Client::where('entreprise_id', $this->entreprise->id)->count());
    }

    // ── Ce qui est envoyé ────────────────────────────────────────────

    public function test_le_plan_comptable_part_sous_les_colonnes_de_l_import(): void
    {
        $this->comptaflowRepond();

        DeversementReferentielService::deverser($this->entreprise);

        $comptes = $this->corpsDuDeversement()['plan_comptable'];

        $this->assertCount(3, $comptes);
        $this->assertSame('401000', $comptes[0]['numero_de_compte']);
        $this->assertSame('Fournisseurs', $comptes[0]['intitule']);
    }

    public function test_les_journaux_partent_avec_le_vocabulaire_de_comptaflow(): void
    {
        // Selflow dit « Vente », Comptaflow dit « Ventes ». La traduction se
        // faisait a l'entree, dans l'ancien sens ; elle se fait a la sortie.
        $this->comptaflowRepond();

        DeversementReferentielService::deverser($this->entreprise);

        $journaux = collect($this->corpsDuDeversement()['codes_journaux'])
            ->keyBy('code_journal');

        $this->assertSame('Ventes', $journaux['VTE']['type']);
        $this->assertSame('Trésorerie', $journaux['MTN']['type']);
        $this->assertSame('521500', $journaux['MTN']['compte_numero']);
    }

    public function test_les_tiers_partent_avec_leur_compte_de_rattachement(): void
    {
        // `plan_tiers.compte_general` est NOT NULL chez Comptaflow, avec une
        // clé étrangère : sans lui, l'insertion échoue sur une violation
        // d'intégrité.
        $this->comptaflowRepond();

        DeversementReferentielService::deverser($this->entreprise);

        $tiers = collect($this->corpsDuDeversement()['tiers'])->keyBy('numero_de_tiers');

        $this->assertSame('411000', $tiers['410007']['compte_general']);
        $this->assertSame('client', $tiers['410007']['type_de_tiers']);
        $this->assertSame('401000', $tiers['400003']['compte_general']);
        $this->assertSame('fournisseur', $tiers['400003']['type_de_tiers']);
    }

    public function test_ce_qui_ne_tient_pas_dans_les_colonnes_voyage_a_cote(): void
    {
        // Seules les colonnes de l'import passent la logique de Comptaflow ;
        // le reste se déverse et se consulte, sans contrôle, parce que rien
        // de comptable n'en dépend.
        $this->comptaflowRepond();

        DeversementReferentielService::deverser($this->entreprise);

        $tiers = collect($this->corpsDuDeversement()['tiers'])->keyBy('numero_de_tiers');

        $this->assertSame('+225 07 00 00 00', $tiers['410007']['informations']['telephone']);
        $this->assertArrayNotHasKey('email', $tiers['410007']['informations'],
            'Un champ vide ne part pas : il écraserait ce que Comptaflow détient peut-être.');
    }

    public function test_un_compte_archive_ne_part_pas(): void
    {
        // Le déverser le ferait réapparaître dans les listes de Comptaflow,
        // où plus personne ne saurait pourquoi il est là.
        $this->comptaflowRepond();

        PlanComptable::where('entreprise_id', $this->entreprise->id)
            ->where('numero', '701000')
            ->update(['archive_le' => now()]);

        DeversementReferentielService::deverser($this->entreprise);

        $numeros = collect($this->corpsDuDeversement()['plan_comptable'])->pluck('numero_de_compte');

        $this->assertNotContains('701000', $numeros);
    }

    public function test_un_tiers_sans_numero_ne_part_pas(): void
    {
        // Comptaflow le rangerait sous une chaîne vide, où il resterait
        // introuvable.
        $this->comptaflowRepond();

        Client::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Client sans numéro', 'compte_comptable' => '411000',
        ]);

        DeversementReferentielService::deverser($this->entreprise);

        foreach ($this->corpsDuDeversement()['tiers'] as $t) {
            $this->assertNotSame('', $t['numero_de_tiers']);
        }
    }

    // ── Ce qui doit refuser de partir ────────────────────────────────

    public function test_sans_cle_de_liaison_rien_ne_part(): void
    {
        // La doublure est posée exprès : sans elle, « rien n'est parti » ne
        // prouverait rien — aucun appel n'aurait été observable.
        $this->comptaflowRepond();

        // Hors affectation en masse, comme la pose : la clé n'est pas
        // `$fillable`, et `update()` l'ignorerait en silence.
        $this->entreprise->comptaflow_sync_key = null;
        $this->entreprise->save();

        $resultat = DeversementReferentielService::deverser($this->entreprise);

        $this->assertFalse($resultat['success']);
        Http::assertNothingSent();
    }

    public function test_sans_secret_partage_rien_ne_part(): void
    {
        // Un secret absent ne doit pas devenir un appel anonyme : ces routes
        // créent des entreprises chez Comptaflow.
        $this->comptaflowRepond();
        config(['selflow.comptaflow_api_secret' => null]);

        $resultat = DeversementReferentielService::deverser($this->entreprise);

        $this->assertFalse($resultat['success']);
        Http::assertNothingSent();
    }

    public function test_une_liaison_refusee_n_envoie_aucun_referentiel(): void
    {
        $this->comptaflowRepond([
            '*/link-company' => Http::response(['success' => false, 'message' => 'Clé inconnue.'], 200),
        ]);

        $resultat = DeversementReferentielService::deverser($this->entreprise);

        $this->assertFalse($resultat['success']);
        $this->assertSame('failed', $this->entreprise->fresh()->comptaflow_sync_status);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/referentiel/deverser'));
    }

    public function test_le_bilan_restitue_les_lignes_creees_et_refusees_par_comptaflow(): void
    {
        $refus = [
            'Compte « 411000 Clients » : numéro invalide.',
            'Tiers « 400003 » : compte collectif introuvable.',
        ];

        $this->comptaflowRepond([
            '*/referentiel/deverser' => Http::response([
                'success' => true,
                'comptes' => 3,
                'journaux' => 2,
                'tiers' => 2,
                'detail' => [
                    'plan_comptable' => ['recues' => 3, 'creees' => 2, 'deja_la' => 0, 'ecartees' => 1],
                    'codes_journaux' => ['recues' => 2, 'creees' => 1, 'deja_la' => 1, 'ecartees' => 0],
                    'tiers' => ['recues' => 2, 'creees' => 1, 'deja_la' => 0, 'ecartees' => 1],
                ],
                'refus' => $refus,
                'message' => '7 lignes reçues : 4 créées, 1 déjà présente, 2 écartées.',
            ], 200),
        ]);

        $resultat = DeversementReferentielService::deverser($this->entreprise);

        $this->assertTrue($resultat['success']);
        $this->assertTrue($resultat['partiel']);
        $this->assertSame(2, $resultat['bilan']['comptes_crees']);
        $this->assertSame(1, $resultat['bilan']['journaux_deja_la']);
        $this->assertSame(2, $resultat['bilan']['total_ecartes']);
        $this->assertSame($refus, $resultat['refus']);
        $this->assertSame('7 lignes reçues : 4 créées, 1 déjà présente, 2 écartées.', $resultat['message']);
    }

    public function test_un_refus_de_comptaflow_est_rapporte_tel_quel(): void
    {
        $this->comptaflowRepond([
            '*/referentiel/deverser' => Http::response(['success' => false, 'message' => 'Exercice clos.'], 200),
        ]);

        $resultat = DeversementReferentielService::deverser($this->entreprise);

        $this->assertFalse($resultat['success']);
        $this->assertStringContainsString('Exercice clos.', $resultat['message']);
    }
}
