<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PlanComptable;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comptabilité éteinte : ce qui disparaît des écrans courants (section 3 du
 * plan de correction).
 *
 * Le lot 39 avait fermé les écrans comptables ; les fiches de tous les jours
 * continuaient pourtant d'exiger un « compte comptable général collectif »
 * — un menu déroulant obligatoire, sur un sujet que l'entreprise avait choisi
 * de ne pas tenir. Ce qui n'est plus demandé ne doit plus être accepté : un
 * compte posté à la main par-dessus le formulaire ne doit pas s'écrire.
 */
class ComptabiliteEteinteEcransTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Quincaillerie du rond-point', 'regime_imposition' => 'TEE',
            'ncc' => '1864699A',
            'modules_actifs' => [
                'principal', 'ventes', 'achats', 'stock', 'produits',
                'tiers', 'comptabilite', 'points_de_vente', 'rapports',
            ],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'PDV-adjame', 'ville' => 'Abidjan', 'commune' => 'Adjamé',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Awa', 'email' => 'awa-tiers@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        foreach (['411000' => 'Clients', '401000' => 'Fournisseurs', '411100' => 'Clients — groupe'] as $numero => $libelle) {
            PlanComptable::create(['numero' => $numero, 'libelle' => $libelle, 'type' => 'tiers']);
        }
    }

    private function connecte(): self
    {
        $this->actingAs($this->admin)
             ->withSession(['point_de_vente_actif_id' => $this->site->id]);

        return $this;
    }

    private function ouvrirLaComptabilite(): void
    {
        $this->entreprise->comptabilite_activee = true;
        $this->entreprise->save();
        $this->admin->unsetRelation('entreprise');
    }

    // ── 3.1 — Le compte collectif ────────────────────────────────────

    public function test_comptabilite_fermee_le_client_se_cree_sans_compte(): void
    {
        $this->connecte()
            ->post(route('admin.clients.creer'), ['nom' => 'Koné Ibrahim', 'type_facturation' => 'B2C'])
            ->assertSessionHasNoErrors();

        $client = Client::where('nom', 'Koné Ibrahim')->firstOrFail();
        $this->assertSame('411000', $client->compte_comptable);
    }

    public function test_comptabilite_fermee_un_compte_poste_a_la_main_ne_s_ecrit_pas(): void
    {
        $this->connecte()
            ->post(route('admin.clients.creer'), [
                'nom' => 'Traoré Moussa', 'type_facturation' => 'B2C',
                'compte_comptable' => '411100',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('411000', Client::where('nom', 'Traoré Moussa')->value('compte_comptable'));
    }

    public function test_comptabilite_fermee_la_modification_ne_touche_pas_au_compte(): void
    {
        $client = Client::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Bamba Sita',
            'compte_comptable' => '411100',
        ]);

        $this->connecte()
            ->put(route('admin.clients.modifier', $client), [
                'nom' => 'Bamba Sita', 'type_facturation' => 'B2C',
                'compte_comptable' => '411000',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('411100', $client->fresh()->compte_comptable);
    }

    public function test_comptabilite_ouverte_le_compte_choisi_est_retenu(): void
    {
        $this->ouvrirLaComptabilite();

        $this->connecte()
            ->post(route('admin.clients.creer'), [
                'nom' => 'Ouattara Ali', 'type_facturation' => 'B2C',
                'compte_comptable' => '411100',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('411100', Client::where('nom', 'Ouattara Ali')->value('compte_comptable'));
    }

    public function test_comptabilite_ouverte_le_compte_reste_obligatoire(): void
    {
        $this->ouvrirLaComptabilite();

        $this->connecte()
            ->post(route('admin.clients.creer'), ['nom' => 'Sans compte', 'type_facturation' => 'B2C'])
            ->assertSessionHasErrors('compte_comptable');
    }

    public function test_comptabilite_fermee_le_fournisseur_recoit_le_collectif_fournisseurs(): void
    {
        $this->connecte()
            ->post(route('admin.fournisseurs.creer'), [
                'nom' => 'SOCOCE', 'type_facturation' => 'B2C', 'compte_comptable' => '411000',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('401000', Fournisseur::where('nom', 'SOCOCE')->value('compte_comptable'));
    }

    public function test_les_ecrans_tiers_ne_montrent_aucun_compte_quand_c_est_ferme(): void
    {
        foreach (['admin.clients.index', 'admin.fournisseurs.index'] as $ecran) {
            $this->connecte()->get(route($ecran))
                ->assertOk()
                ->assertDontSee('Compte comptable général collective')
                ->assertDontSee('Compte général');
        }

        $this->ouvrirLaComptabilite();

        foreach (['admin.clients.index', 'admin.fournisseurs.index'] as $ecran) {
            $this->connecte()->get(route($ecran))
                ->assertOk()
                ->assertSee('Compte comptable général collective');
        }
    }

    // ── 3.2 — Le numéro de tiers, automatique partout ────────────────

    /**
     * L'API mobile, le parcours B2B et le tiers d'un BAPA créaient des fiches
     * sans numéro : leurs écritures retombaient en vrac sur le collectif
     * chez Comptaflow.
     */
    public function test_une_fiche_creee_hors_de_l_ecran_recoit_son_numero(): void
    {
        $client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Venu par l\'API']);
        $fournisseur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Vendeur BAPA']);

        $this->assertSame('411000', $client->compte_comptable);
        $this->assertSame('401000', $fournisseur->compte_comptable);
        $this->assertMatchesRegularExpression('/^41\w{4}$/', (string) $client->numero_tiers);
        $this->assertMatchesRegularExpression('/^40\w{4}$/', (string) $fournisseur->numero_tiers);
    }

    public function test_un_numero_deja_pose_n_est_pas_remplace(): void
    {
        $client = Client::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Venu de Comptaflow',
            'numero_tiers' => '41KON7', 'source' => 'comptaflow',
        ]);

        $this->assertSame('41KON7', $client->numero_tiers);
    }

    public function test_le_numero_de_tiers_ne_se_saisit_pas(): void
    {
        $this->connecte()
            ->post(route('admin.clients.creer'), [
                'nom' => 'Diallo Fanta', 'type_facturation' => 'B2C', 'numero_tiers' => '419999',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotSame('419999', Client::where('nom', 'Diallo Fanta')->value('numero_tiers'));
    }
}
