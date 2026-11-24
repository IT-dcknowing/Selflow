<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\BrouillonVente;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** 24/11/2026 : brouillons de vente, identité des entreprises (support), retour robot. */
class BrouillonsIdentiteRetoursTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;
    private PointDeVente $site;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e = Entreprise::create(['nom' => 'Boutique Test', 'email' => 'contact@boutique.ci', 'modules_actifs' => ['principal', 'ventes', 'tiers', 'produits']]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->e->id, 'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $this->admin = Utilisateur::create(['nom' => 'K', 'prenom' => 'A', 'email' => 'patron@boutique.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'statut' => 'actif',
            'entreprise_id' => $this->e->id, 'point_de_vente_id' => $this->site->id]);
    }

    public function test_un_brouillon_se_met_de_cote_puis_devient_une_facture(): void
    {
        $service = Produit::create(['entreprise_id' => $this->e->id, 'reference' => 'S1', 'nom' => 'Conseil', 'type' => 'service', 'unite' => 'u',
            'prix_achat' => 0, 'prix_vente' => 10000, 'taux_tva' => 0]);
        $client = Client::create(['entreprise_id' => $this->e->id, 'nom' => 'Société Kouamé']);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->post(route('admin.ventes.brouillons.enregistrer'), [
                'client_id' => $client->id, 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Crédit',
                'panier_json' => json_encode([$service->id => ['nom' => 'Conseil', 'prix' => 10000, 'qte' => 2, 'isVirtual' => false]]),
            ])->assertRedirect(route('admin.ventes.nouvelle'));

        $brouillon = BrouillonVente::sole();
        $this->assertSame('Société Kouamé', $brouillon->libelle);
        $this->assertEqualsWithDelta(20000, $brouillon->total, 0.01);
        $this->assertSame(0, Vente::count(), 'Un brouillon n\'est pas une facture.');

        $this->get(route('admin.ventes.brouillons'))->assertOk()->assertSee('Société Kouamé')->assertSee('Passer en facture');
        $this->get(route('admin.ventes.factures'))->assertOk()->assertSee('Brouillons');
        $this->get(route('admin.ventes.nouvelle', ['brouillon' => $brouillon->id]))->assertOk()->assertSee('Brouillon rouvert');

        $this->post(route('admin.ventes.enregistrer'), [
            'client_id' => $client->id, 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Crédit', 'etape' => 'Facture',
            'brouillon_id' => $brouillon->id, 'articles' => [['produit_id' => $service->id, 'quantite' => 2, 'unite' => 'u']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Vente::count());
        $this->assertSame(0, BrouillonVente::count(), 'Le brouillon facturé disparaît des brouillons.');
    }

    public function test_le_support_corrige_le_nom_et_les_adresses(): void
    {
        $support = Utilisateur::create(['nom' => 'S', 'prenom' => 'U', 'email' => 'support@dck.ci', 'password' => bcrypt('x'), 'role' => 'superadmin', 'statut' => 'actif']);
        $support->forceFill(['habilitations' => ['identite_entreprises']])->save();

        $this->actingAs($support)->get(route('superadmin.identites.index'))->assertOk()->assertSee('Boutique Test');
        $this->post(route('superadmin.identites.enregistrer', $this->e), [
            'nom' => 'Boutique Test SARL', 'email' => 'info@boutique.ci', 'responsable_email' => 'directeur@boutique.ci',
        ])->assertSessionHas('succes');

        $this->assertSame('Boutique Test SARL', $this->e->fresh()->nom);
        $this->assertSame('info@boutique.ci', $this->e->fresh()->email);
        $this->assertSame('directeur@boutique.ci', $this->admin->fresh()->email, 'Le responsable se connecte avec sa nouvelle adresse.');

        $this->get(route('superadmin.entreprises'))->assertForbidden();
    }

    public function test_le_retour_robot_liste_les_acces_refuses(): void
    {
        $sa = Utilisateur::create(['nom' => 'S', 'prenom' => 'A', 'email' => 'sa@dck.ci', 'password' => bcrypt('x'), 'role' => 'superadmin', 'statut' => 'actif']);
        $sa->forceFill(['habilitations' => ['tableau_de_bord_superadmin']])->save();
        \App\Modules\Admin\Modeles\FneCredential::create(['entreprise_id' => $this->e->id, 'ncc_associe' => '8888888Z']);
        $dossier = base_path('SCRAPER-PORTAIL-FNE/etat-connexion');
        @mkdir($dossier, 0775, true);
        file_put_contents($dossier . '/8888888Z.json', json_encode(['quand' => now()->toIso8601String(), 'statut' => 'identifiants', 'message' => 'accès refusé', 'portail' => 'https://x']));
        try {
            $this->actingAs($sa)->get(route('superadmin.robot.retours'))->assertOk()
                ->assertSee('Accès refusé')->assertSee('Boutique Test')->assertSee('compte FNE');
        } finally {
            @unlink($dossier . '/8888888Z.json');
        }
    }
}
