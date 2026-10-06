<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Comptabilité éteinte : ce qui disparaît des écrans courants — section 3.
 *
 * Le serveur pose les comptes que l'utilisateur ne voit plus (5.2) : 411000
 * pour un client, 401000 pour un fournisseur, la famille sinon 701000 / 601000
 * pour un article. Et ce qui n'est plus demandé n'est plus accepté.
 */
class ComptabiliteEteinteEcransTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;

    /** Ce qu'aucun écran ne doit plus montrer quand la comptabilité est éteinte. */
    private const MARQUES_COMPTABLES = [
        'Compte comptable général',
        'Compte général',
        'Nature de la vente (Compte de vente)',
        "Nature de l'achat (Compte d'achat)",
        'Compte de vente personnalisé',
        'name="compte_comptable"',
        'name="compte_vente"',
        'name="compte_achat"',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-KNOWING CGA', 'regime_imposition' => 'TEE', 'adresse' => 'Riviera II',
            'rccm' => 'CI-ABJ-2018-B-31734', 'ncc' => '1864699A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite', 'points_de_vente', 'rapports'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'PDV-marcory', 'ville' => 'Abidjan', 'commune' => 'Marcory']);
        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-eteinte@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function ouvrirLaComptabilite(): void
    {
        $this->entreprise->forceFill(['comptabilite_activee' => true])->save();
        $this->admin->refresh();
    }

    // ══════════════ 3.1 — les fiches de tiers ══════════════

    public function test_un_client_recoit_411000_et_un_compte_poste_a_la_main_est_ignore(): void
    {
        $this->post(route('admin.clients.creer'), [
            'nom' => 'Chocolaterie Baoulé', 'type_facturation' => 'B2C', 'compte_comptable' => '471000',
        ])->assertSessionHasNoErrors();

        $this->assertSame('411000', Client::where('nom', 'Chocolaterie Baoulé')->value('compte_comptable'));
    }

    public function test_un_fournisseur_recoit_401000_sans_qu_on_le_demande(): void
    {
        $this->post(route('admin.fournisseurs.creer'), [
            'nom' => 'Planteurs réunis', 'type_facturation' => 'B2C',
        ])->assertSessionHasNoErrors();

        $fournisseur = Fournisseur::where('nom', 'Planteurs réunis')->first();
        $this->assertSame('401000', $fournisseur->compte_comptable);
        // 3.2 — le numéro de tiers, lui, reste : il sert au rapprochement.
        $this->assertNotEmpty($fournisseur->numero_tiers);
    }

    public function test_comptabilite_ouverte_le_compte_choisi_l_emporte(): void
    {
        $this->ouvrirLaComptabilite();
        \App\Modules\Admin\Modeles\PlanComptable::create(['entreprise_id' => $this->entreprise->id, 'numero' => '411100', 'libelle' => 'Clients export']);

        $this->post(route('admin.clients.creer'), [
            'nom' => 'Client export', 'type_facturation' => 'B2C', 'compte_comptable' => '411100',
        ])->assertSessionHasNoErrors();

        $this->assertSame('411100', Client::where('nom', 'Client export')->value('compte_comptable'));

        $this->get(route('admin.clients.index'))->assertSee('Compte comptable général collective');
    }

    // ══════════════ 3.3 — le catalogue ══════════════

    public function test_un_article_neuf_prend_le_compte_de_sa_famille(): void
    {
        $famille = Categorie::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Boissons', 'prefixe' => 'BOI',
            'compte_vente' => '701200', 'compte_achat' => '601200',
        ]);

        $this->post(route('admin.produits.creer'), [
            'nom' => 'Eau minérale', 'type' => 'service', 'categorie_id' => (string) $famille->id,
            'prix_vente' => 500, 'taux_tva' => 18,
            'compte_vente' => '999999', 'compte_achat' => '999999',
        ])->assertSessionHasNoErrors();

        $produit = Produit::where('nom', 'Eau minérale')->first();
        $this->assertSame('701200', $produit->compte_vente);
        $this->assertSame('601200', $produit->compte_achat);
    }

    // ══════════════ 3.6 — aucun élément comptable ne subsiste ══════════════

    public function test_aucun_ecran_courant_ne_montre_de_compte(): void
    {
        $produit = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'EAU-1', 'nom' => 'Eau',
            'type' => 'service', 'prix_achat' => 0, 'prix_vente' => 500, 'taux_tva' => 18,
        ]);
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Client A', 'compte_comptable' => '411000', 'numero_tiers' => 'C00001']);
        Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Fournisseur A', 'compte_comptable' => '401000', 'numero_tiers' => 'F00001']);

        foreach ([
            route('admin.clients.index'),
            route('admin.fournisseurs.index'),
            route('admin.produits.index'),
            route('admin.produits.fiche', $produit),
        ] as $ecran) {
            $page = $this->get($ecran)->assertOk()->getContent();

            foreach (self::MARQUES_COMPTABLES as $marque) {
                $this->assertStringNotContainsString($marque, $page, "« {$marque} » subsiste sur {$ecran}");
            }
        }
    }
}
