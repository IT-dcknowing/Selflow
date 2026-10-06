<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Services\NumerotationTiersService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le numéro de tiers, et ce qu'il ne faut pas confondre avec lui.
 *
 * | Notion | Colonne | Exemple |
 * |---|---|---|
 * | Compte général de rattachement | `compte_comptable` | `411000` |
 * | Numéro de tiers | `numero_tiers` | `410001`, `41KON1` |
 *
 * Le compte général est celui du plan comptable, et son intitulé — « Clients »
 * — ne change jamais. Le numéro de tiers désigne un client précis.
 *
 * **La numérotation automatique démarrait à `411000`** : le premier client de
 * chaque entreprise portait, comme numéro de tiers, le numéro du compte
 * collectif. Les deux notions se rejoignaient en base, et le relevé de ce
 * client remontait le solde de tous les autres.
 *
 * **La règle vient de Comptaflow**, qui retrouve un tiers par égalité de
 * chaîne sur `numero_de_tiers`. Une convention différente d'un côté et de
 * l'autre, et plus aucun tiers n'est reconnu : chaque écriture déversée
 * retombe sur son compte collectif, sans que rien ne le signale. Le préfixe
 * tient donc sur **deux** caractères, et le numéro fait six caractères en
 * tout — comme `companies.tier_digits` là-bas.
 */
class NumerotationTiersTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Quincaillerie du Bandama',
            'modules_actifs' => ['principal', 'tiers', 'comptabilite'],
        ]);

        foreach ([['411000', 'Clients'], ['401000', 'Fournisseurs']] as [$numero, $libelle]) {
            PlanComptable::create([
                'entreprise_id' => null, 'numero' => $numero, 'libelle' => $libelle,
            ]);
        }

        $this->admin = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Adjoua', 'email' => 'adjoua-tiers@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
        ]);

        $this->actingAs($this->admin);
    }

    /**
     * Changer la convention, et faire oublier l'ancienne au compte connecté.
     *
     * `actingAs` garde une même instance d'utilisateur pour toute la durée du
     * test : sa relation `entreprise` reste celle chargée au premier appel. En
     * production chaque requête repart d'une lecture neuve ; ici il faut le
     * dire.
     */
    private function convention(string $convention): void
    {
        $this->entreprise->update(['numerotation_tiers' => $convention]);
        $this->admin->unsetRelation('entreprise');
    }

    private function creerClient(array $champs = [])
    {
        return $this->post(route('admin.clients.creer'), array_merge([
            'nom'                => 'Kouassi Koné',
            'type_facturation'   => 'B2C',
            'compte_comptable'   => '411000',
            'auto_numero_tiers'  => '1',
        ], $champs));
    }

    // ══════════════ Le premier client ne porte plus le collectif ══════════════

    public function test_le_premier_client_ne_porte_pas_le_numero_du_compte_collectif(): void
    {
        // C'était le défaut : la séquence démarrait à 411000, et le relevé de
        // ce client remontait le solde de tous les autres.
        $this->creerClient();

        $client = Client::where('entreprise_id', $this->entreprise->id)->firstOrFail();

        $this->assertSame('410001', $client->numero_tiers);
        $this->assertSame('411000', $client->compte_comptable);
        $this->assertNotSame($client->compte_comptable, $client->numero_tiers);
    }

    public function test_le_premier_fournisseur_non_plus(): void
    {
        $this->post(route('admin.fournisseurs.creer'), [
            'nom' => 'CDCI Distribution', 'type_facturation' => 'B2C',
            'compte_comptable' => '401000', 'auto_numero_tiers' => '1',
        ]);

        $fournisseur = Fournisseur::where('entreprise_id', $this->entreprise->id)->firstOrFail();

        $this->assertSame('400001', $fournisseur->numero_tiers);
        $this->assertNotSame($fournisseur->compte_comptable, $fournisseur->numero_tiers);
    }

    public function test_la_sequence_avance(): void
    {
        $this->creerClient(['nom' => 'Premier client']);
        $this->creerClient(['nom' => 'Second client']);

        $this->assertSame(
            ['410001', '410002'],
            Client::where('entreprise_id', $this->entreprise->id)
                ->orderBy('numero_tiers')->pluck('numero_tiers')->all()
        );
    }

    // ══════════════ La convention par le nom ══════════════

    public function test_la_convention_par_le_nom_tire_le_radical_du_nom(): void
    {
        $this->convention(NumerotationTiersService::ALPHANUMERIQUE);

        $this->creerClient(['nom' => 'Kouassi Koné']);

        $this->assertSame(
            '41KOU1',
            Client::where('entreprise_id', $this->entreprise->id)->value('numero_tiers')
        );
    }

    public function test_deux_homonymes_recoivent_des_numeros_distincts(): void
    {
        $this->convention(NumerotationTiersService::ALPHANUMERIQUE);

        $this->creerClient(['nom' => 'Koné']);
        $this->creerClient(['nom' => 'Koné']);

        $numeros = Client::where('entreprise_id', $this->entreprise->id)
            ->orderBy('id')->pluck('numero_tiers')->all();

        $this->assertSame(['41KON1', '41KON2'], $numeros);
    }

    public function test_un_nom_sans_lettre_retombe_sur_la_sequence(): void
    {
        // « 123 » ne laisse aucun radical : sans ce repli, le numéro se
        // réduirait à sa racine — c'est-à-dire au compte collectif.
        $this->convention(NumerotationTiersService::ALPHANUMERIQUE);

        $this->creerClient(['nom' => '123']);

        $numero = Client::where('entreprise_id', $this->entreprise->id)->value('numero_tiers');

        $this->assertSame('410001', $numero);
        $this->assertNotSame('41', $numero);
    }

    public function test_les_deux_conventions_cohabitent(): void
    {
        // Une entreprise qui change d'avis en cours de route ne doit pas voir
        // sa séquence repartir de 001 et heurter un numéro déjà pris.
        $this->creerClient(['nom' => 'Premier client']);      // 410001

        $this->convention(NumerotationTiersService::ALPHANUMERIQUE);
        $this->creerClient(['nom' => 'Koné']);                // 41KON1

        $this->convention(NumerotationTiersService::NUMERIQUE);
        $this->creerClient(['nom' => 'Troisième client']);    // 410002

        $this->assertSame(
            ['410001', '41KON1', '410002'],
            Client::where('entreprise_id', $this->entreprise->id)->orderBy('id')->pluck('numero_tiers')->all()
        );
    }

    // ══════════════ Le numéro ne se saisit plus ══════════════

    public function test_un_numero_envoye_par_le_formulaire_est_ignore(): void
    {
        // Le système numérote seul, comme Comptaflow. Un champ ajouté à la
        // requête — par un formulaire forgé, ou par un ancien gabarit resté en
        // cache — ne doit pas passer devant la convention de l'entreprise.
        $this->creerClient(['numero_tiers' => '411000']);

        $this->assertSame(
            '410001',
            Client::where('entreprise_id', $this->entreprise->id)->value('numero_tiers')
        );
    }

    public function test_le_numero_ne_se_modifie_pas_davantage(): void
    {
        // Changer le numéro d'un tiers déjà déversé le rendrait introuvable
        // chez Comptaflow, et ses écritures futures retomberaient sur le
        // collectif tandis que les anciennes resteraient sur l'ancien numéro.
        $this->creerClient(['nom' => 'Kouassi Koné']);
        $client = Client::where('entreprise_id', $this->entreprise->id)->firstOrFail();

        $this->put(route('admin.clients.modifier', $client), [
            'nom' => 'Kouassi Koné', 'type_facturation' => 'B2C',
            'compte_comptable' => '411000', 'numero_tiers' => '419999',
        ]);

        $this->assertSame('410001', $client->fresh()->numero_tiers);
    }

    // ══════════════ L'import filtre, comme Comptaflow ══════════════

    private function importerClients(array $lignes)
    {
        $contenu = '';
        foreach ($lignes as $ligne) {
            $contenu .= implode(';', array_map(fn ($v) => '"' . $v . '"', $ligne)) . "\r\n";
        }
        $chemin = tempnam(sys_get_temp_dir(), 'tiers') . '.csv';
        file_put_contents($chemin, $contenu);

        return $this->post(route('admin.import.importer', ['type' => 'clients']), [
            'fichier' => new \Illuminate\Http\UploadedFile($chemin, 'clients.csv', 'text/csv', null, true),
        ]);
    }

    public function test_l_import_ne_lit_plus_le_numero_de_tiers(): void
    {
        // Il gardait un numéro fourni, s'il était conforme. Depuis le
        // chantier 11.2 (plan du 02/10/2026), le numéro de tiers est
        // automatique : la colonne a quitté le modèle, et un fichier qui la
        // porterait encore ne l'impose plus.
        $this->importerClients([
            ['nom', 'type_facturation', 'compte_comptable', 'numero_tiers'],
            ['Société ABC', 'B2C', '411000', '410042'],
        ]);

        $this->assertSame('410001', Client::where('nom', 'Société ABC')->value('numero_tiers'));
    }

    public function test_l_import_renumerote_un_tiers_qui_vaut_le_collectif(): void
    {
        // Un fichier vient de partout — d'un autre logiciel, d'un tableur
        // retouché à la main — et rien n'y garantit la convention.
        $this->importerClients([
            ['nom', 'type_facturation', 'compte_comptable', 'numero_tiers'],
            ['Société ABC', 'B2C', '411000', '411000'],
        ]);

        $this->assertSame('410001', Client::where('nom', 'Société ABC')->value('numero_tiers'));
    }

    public function test_l_import_renumerote_un_prefixe_qui_ne_correspond_pas(): void
    {
        // Un tiers 40… sur un client ferait partir l'écriture sur le collectif
        // fournisseurs.
        $this->importerClients([
            ['nom', 'type_facturation', 'compte_comptable', 'numero_tiers'],
            ['Société ABC', 'B2C', '411000', '400001'],
        ]);

        $this->assertSame('410001', Client::where('nom', 'Société ABC')->value('numero_tiers'));
    }

    public function test_l_import_renumerote_une_longueur_incorrecte(): void
    {
        // Comptaflow cherche par égalité de chaîne : « 4100010 » ne vaut pas
        // « 410001 ».
        $this->importerClients([
            ['nom', 'type_facturation', 'compte_comptable', 'numero_tiers'],
            ['Société ABC', 'B2C', '411000', '4100010'],
        ]);

        $this->assertSame('410001', Client::where('nom', 'Société ABC')->value('numero_tiers'));
    }

    public function test_l_import_ne_reprend_pas_un_numero_deja_pris(): void
    {
        $this->creerClient(['nom' => 'Client existant']);   // 410001

        $this->importerClients([
            ['nom', 'type_facturation', 'compte_comptable', 'numero_tiers'],
            ['Société ABC', 'B2C', '411000', '410001'],
        ]);

        $this->assertSame('410002', Client::where('nom', 'Société ABC')->value('numero_tiers'));
    }

    // ══════════════ Le service, isolément ══════════════

    public function test_la_coherence_se_juge_sur_la_racine(): void
    {
        $this->assertTrue(NumerotationTiersService::estCoherent('410001', '411000'));
        $this->assertTrue(NumerotationTiersService::estCoherent('41KON1', '411000'));
        $this->assertFalse(NumerotationTiersService::estCoherent('400001', '411000'));
        $this->assertFalse(NumerotationTiersService::estCoherent(null, '411000'));
    }

    public function test_le_service_reconnait_le_compte_collectif(): void
    {
        $this->assertTrue(NumerotationTiersService::estLeCompteCollectif('411000', '411000'));
        $this->assertFalse(NumerotationTiersService::estLeCompteCollectif('410001', '411000'));
    }

    // ══════════════ Le client de passage ══════════════

    public function test_le_client_divers_porte_son_propre_numero_et_son_rattachement(): void
    {
        // Une vente sans client nommé laissait `compte_tiers` vide : tout se
        // rangeait sur le collectif 411000, et le grand livre ne distinguait
        // plus les ventes de comptoir des créances d'un client identifié.
        $divers = Client::divers($this->entreprise);

        $this->assertSame('410000', $divers->numero_tiers);
        $this->assertSame('411000', $divers->compte_comptable);
        $this->assertSame('Client divers', $divers->nom);
    }

    public function test_le_fournisseur_divers_est_son_pendant(): void
    {
        $divers = Fournisseur::divers($this->entreprise);

        $this->assertSame('400000', $divers->numero_tiers);
        $this->assertSame('401000', $divers->compte_comptable);
    }

    public function test_une_seule_fiche_divers_par_entreprise(): void
    {
        // Une fiche par ticket de caisse ferait gonfler le plan de tiers d'une
        // ligne par vente de comptoir.
        Client::divers($this->entreprise);
        Client::divers($this->entreprise);
        Client::divers($this->entreprise);

        $this->assertSame(1, Client::where('entreprise_id', $this->entreprise->id)
            ->where('numero_tiers', Client::NUMERO_DIVERS)->count());
    }

    public function test_chaque_entreprise_a_son_propre_client_divers(): void
    {
        $autre = Entreprise::create(['nom' => 'Quincaillerie rivale']);

        $ici     = Client::divers($this->entreprise);
        $ailleurs = Client::divers($autre);

        $this->assertNotSame($ici->id, $ailleurs->id);
        $this->assertSame($this->entreprise->id, $ici->entreprise_id);
        $this->assertSame($autre->id, $ailleurs->entreprise_id);
    }

    public function test_le_client_divers_ne_gene_pas_la_sequence(): void
    {
        // Son numéro n'est pas numérique : il ne doit pas être lu comme un
        // rang, ni faire sauter la séquence.
        Client::divers($this->entreprise);

        $this->creerClient(['nom' => 'Kouassi Koné']);

        $this->assertSame(
            '410001',
            Client::where('entreprise_id', $this->entreprise->id)
                ->where('numero_tiers', '!=', Client::NUMERO_DIVERS)->value('numero_tiers')
        );
    }

    // ══════════════ Cloisonnement ══════════════

    public function test_deux_entreprises_peuvent_porter_le_meme_numero_de_tiers(): void
    {
        // Le plan de tiers est propre à chaque entreprise : la séquence de
        // l'une ne doit pas dépendre de ce que l'autre a créé.
        $autre = Entreprise::create(['nom' => 'Quincaillerie rivale']);
        Client::create([
            'entreprise_id' => $autre->id, 'nom' => 'Client de la rivale',
            'compte_comptable' => '411000', 'numero_tiers' => '410001',
        ]);

        $this->creerClient();

        $this->assertSame(
            '410001',
            Client::where('entreprise_id', $this->entreprise->id)->value('numero_tiers')
        );
    }
}
