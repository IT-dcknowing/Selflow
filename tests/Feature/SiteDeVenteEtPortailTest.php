<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 23/11/2026, O'Super prix : un seul site (13 sacs), aucun membre affecté ;
 * la vente cherchait le stock d'un site « tout » ou d'aucun site.
 */
class SiteDeVenteEtPortailTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $e;
    private PointDeVente $site;
    private Produit $riz;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e = Entreprise::create(['nom' => "O'Super prix", 'modules_actifs' => ['principal', 'ventes', 'stock', 'produits', 'tiers']]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->e->id, 'nom' => 'O', 'ville' => 'Abidjan', 'commune' => 'Abobo']);
        $this->riz = Produit::create(['entreprise_id' => $this->e->id, 'reference' => 'RIZ-00005', 'nom' => 'GABI KC 22KG', 'type' => 'marchandise',
            'unite' => 'sac', 'prix_achat' => 8750, 'prix_vente' => 9500, 'taux_tva' => 0]);
        Stock::updateOrCreate(['produit_id' => $this->riz->id, 'point_de_vente_id' => $this->site->id], ['quantite_disponible' => 13, 'stock_minimum' => 5]);
        $this->client = Client::create(['entreprise_id' => $this->e->id, 'nom' => 'Client']);
    }

    private function vendre(Utilisateur $u, array $session = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u)->withSession($session)->post(route($u->role === 'caissier' ? 'caissier.ventes.enregistrer' : 'admin.ventes.enregistrer'), [
            'client_id' => $this->client->id, 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Espèces', 'etape' => 'Facture',
            'articles' => [['produit_id' => $this->riz->id, 'quantite' => 1, 'unite' => 'sac']],
        ]);
    }

    public function test_un_administrateur_en_affichage_tous_les_sites_vend_sur_le_seul_site(): void
    {
        $admin = Utilisateur::create(['nom' => 'T', 'prenom' => 'T', 'email' => 't@x.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'statut' => 'actif', 'entreprise_id' => $this->e->id]);

        $this->vendre($admin, ['point_de_vente_actif_id' => 'tout'])->assertSessionMissing('error');
        $this->assertSame($this->site->id, (int) Vente::latest('id')->value('point_de_vente_id'));
        $this->assertEqualsWithDelta(12, $this->riz->fresh()->stockActuel($this->site->id), 0.001);
    }

    public function test_un_caissier_sans_site_vend_sur_le_seul_site(): void
    {
        $caissier = Utilisateur::create(['nom' => 'C', 'prenom' => 'C', 'email' => 'c@x.ci', 'password' => bcrypt('x'), 'role' => 'caissier', 'statut' => 'actif',
            'entreprise_id' => $this->e->id, 'habilitations' => ['nouvelle_vente']]);

        $this->vendre($caissier)->assertSessionMissing('error');
        $this->assertSame($this->site->id, (int) Vente::latest('id')->value('point_de_vente_id'));
    }

    public function test_la_fiche_du_portail_est_reprise_des_que_le_releve_est_autorise(): void
    {
        \App\Modules\Admin\Modeles\FneCredential::create(['entreprise_id' => $this->e->id, 'ncc_associe' => '2196217J', 'telechargement_actif' => true]);
        $this->e->forceFill(['possede_compte_fne' => false])->save();
        $import = \App\Modules\Admin\Modeles\PortailFneImport::create(['entreprise_id' => $this->e->id, 'login' => '2196217J', 'date_scraping' => now()->toDateString(),
            'type' => \App\Modules\Admin\Modeles\PortailFneImport::TYPE_FICHE, 'fichier_nom' => 'f.json', 'fichier_empreinte' => 'x', 'statut' => 'importe']);
        \App\Modules\Admin\Modeles\PortailFneFiche::forceCreate(['import_id' => $import->id, 'entreprise_id' => $this->e->id, 'login' => '2196217J', 'date_scraping' => now()->toDateString(), 'telephone' => '0102030405']);

        \App\Modules\Admin\Services\SynchronisationPortailFneService::reprendre($this->e->fresh());
        $this->assertSame('0102030405', $this->e->fresh()->telephone);
    }

    public function test_les_acces_fne_incorrects_s_affichent_dans_gestion_fne(): void
    {
        $admin = Utilisateur::create(['nom' => 'T', 'prenom' => 'T', 'email' => 't2@x.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'statut' => 'actif',
            'entreprise_id' => $this->e->id, 'point_de_vente_id' => $this->site->id]);
        $this->e->forceFill(['ncc' => '9999999Z', 'modules_actifs' => ['principal', 'ventes', 'stock', 'produits', 'tiers', 'comptabilite']])->save();
        $dossier = base_path('SCRAPER-PORTAIL-FNE/etat-connexion');
        @mkdir($dossier, 0775, true);
        file_put_contents($dossier . '/9999999Z.json', json_encode(['quand' => now()->toIso8601String(), 'statut' => 'identifiants', 'message' => 'x', 'portail' => 'https://exemple']));
        try {
            $this->actingAs($admin)->get(route('admin.fne.gestion'))->assertOk()->assertSee('Accès de connexion FNE incorrects');
        } finally {
            @unlink($dossier . '/9999999Z.json');
        }
    }
}
