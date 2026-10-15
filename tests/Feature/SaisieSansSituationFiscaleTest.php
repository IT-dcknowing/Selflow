<?php

namespace Tests\Feature;

use App\Jobs\NormaliserFactureFne;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Retour de production du 15/10/2026 : on doit pouvoir tenir son stock,
 * acheter, vendre et facturer sans aucune information fiscale. Le blocage
 * vit à la normalisation, qui dit ce qui manque (Remplir / Fermer).
 */
class SaisieSansSituationFiscaleTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $magasin;
    private Produit $article;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // Ni NCC, ni régime, ni RCCM, ni adresse, ni domaine.
        $this->entreprise = Entreprise::create([
            'nom'            => 'Boutique sans papiers',
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'fne', 'comptabilite'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Koné', 'prenom' => 'Awa', 'email' => 'awa-sans-ncc@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV-001',
            'nom' => 'Prestation', 'type' => 'service', 'unite' => 'unité',
            'prix_achat' => 0, 'prix_vente' => 10000, 'taux_tva' => 18,
        ]);

        $this->client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Client A']);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    private function facturer()
    {
        return $this->post(route('admin.ventes.enregistrer'), [
            'etape'         => 'Facture',
            'type_piece'    => Vente::TYPE_FACTURE,
            'client_id'     => $this->client->id,
            'mode_paiement' => 'Espèces',
            'articles'      => [[
                'produit_id' => $this->article->id,
                'quantite'   => 1,
                'unite'      => 'unité',
            ]],
        ]);
    }

    public function test_la_caisse_et_les_achats_s_ouvrent_sans_situation_fiscale(): void
    {
        $this->assertFalse($this->entreprise->estInscriptionComplete());

        $this->get(route('admin.ventes.nouvelle'))->assertOk();
        $this->get(route('admin.achats.nouveau'))->assertOk();
    }

    public function test_la_facture_s_enregistre_et_reste_a_normaliser(): void
    {
        $this->facturer()->assertSessionDoesntHaveErrors()
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'informations fiscales à compléter'));

        $this->assertSame(1, Vente::count());
        $this->assertFalse((bool) Vente::first()->normalise);
        Queue::assertNotPushed(NormaliserFactureFne::class);
    }

    public function test_normaliser_dit_ce_qui_manque_avec_remplir_et_fermer(): void
    {
        $this->facturer();
        $vente = Vente::first();

        $this->from(route('admin.ventes.factures'))
            ->post(route('admin.ventes.normaliser', $vente))
            ->assertRedirect(route('admin.ventes.factures'))
            ->assertSessionHas('normalisation_impossible', function (array $manquants) {
                $cles = array_column($manquants, 'cle');
                return in_array('ncc', $cles, true)
                    && in_array('regime_imposition', $cles, true)
                    && in_array('acces_fne', $cles, true)
                    && !in_array('point_de_vente', $cles, true);
            });

        Queue::assertNotPushed(NormaliserFactureFne::class);

        $page = $this->withSession([
            'normalisation_impossible'       => $this->entreprise->elementsNormalisationManquants(),
            'normalisation_impossible_piece' => $vente->numero_facture,
        ])->get(route('admin.ventes.factures'))->assertOk()->getContent();

        $this->assertStringContainsString('Informations fiscales non renseignées', $page);
        $this->assertStringContainsString('btn-remplir-normalisation-impossible', $page);
        $this->assertStringContainsString('btn-fermer-normalisation-impossible', $page);
    }

    public function test_situation_fiscale_complete_la_facture_part(): void
    {
        $this->entreprise->update(['ncc' => '2603210A', 'regime_imposition' => 'RNI']);
        FneCredential::create(['entreprise_id' => $this->entreprise->id, 'cle_test' => 'fne_test_cle', 'statut' => 'test']);

        $this->assertTrue($this->entreprise->fresh()->peutNormaliser());

        $this->facturer()->assertSessionDoesntHaveErrors();
        Queue::assertPushed(NormaliserFactureFne::class);
    }

    public function test_le_ncc_pose_plus_tard_paraît_sur_les_factures_deja_faites(): void
    {
        $this->facturer();
        $vente = Vente::first();

        $this->assertStringNotContainsString('2603210A', $this->get(route('admin.ventes.imprimer', $vente))->getContent());

        $this->entreprise->update(['ncc' => '2603210A']);

        $this->get(route('admin.ventes.imprimer', $vente))->assertOk()->assertSee('2603210A');
    }

    public function test_sans_point_de_vente_la_saisie_reste_fermee(): void
    {
        $this->magasin->delete();

        $this->get(route('admin.ventes.nouvelle'))->assertRedirect(route('admin.tableau_de_bord'));
    }

    public function test_la_normalisation_par_lot_dit_ce_qui_manque(): void
    {
        $this->facturer();

        $this->postJson(route('admin.fne.batch_normaliser'), ['ids' => [Vente::first()->id], 'flux' => 'ventes'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['manquants' => [['cle', 'libelle', 'ou']]]);
    }
}
