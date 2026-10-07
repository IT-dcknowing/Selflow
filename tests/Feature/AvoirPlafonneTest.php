<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Un avoir ne peut pas dépasser la facture (section 8 du plan).
 *
 * Réponse validée par le propriétaire le 02/10/2026 : rendre plus que ce qui a
 * été facturé rendrait la TVA collectée négative sur la pièce. Le plafond
 * existait en quantité sur l'avoir partiel ; il ne voyait ni une ligne ajoutée
 * à l'avoir, ni un prix relevé à la main, et l'avoir total ne regardait pas du
 * tout les avoirs déjà établis.
 */
class AvoirPlafonneTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom' => 'Librairie du lycée', 'regime_imposition' => 'RNI', 'adresse' => 'Bouaké',
            'rccm' => 'CI-BKE-2026-B-00002', 'ncc' => '2601235B', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);
        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Librairie', 'ville' => 'Bouaké', 'commune' => 'Bouaké',
        ]);
        $this->admin = Utilisateur::create([
            'nom' => 'Koffi', 'prenom' => 'Ama', 'email' => 'ama-avoir@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Lycée moderne']);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    /** Une facture : cent manuels à 1 000 F HT, TVA 18 %, soit 118 000 F TTC. */
    private function facture(string $numero = 'VTE-2026-0001'): Vente
    {
        $vente = Vente::create([
            'point_de_vente_id' => $this->site->id, 'client_id' => $this->client->id,
            'utilisateur_id' => $this->admin->id, 'numero_facture' => $numero,
            'date_vente' => now()->toDateString(), 'mode_paiement' => 'Crédit',
            'montant_ht' => 100000, 'montant_tva' => 18000, 'montant_ttc' => 118000,
            'statut' => 'Non payé', 'etape' => 'Facture',
        ]);

        VenteDetail::create([
            'vente_id' => $vente->id, 'libelle_virtuel' => 'Manuel de mathématiques',
            'quantite' => 100, 'unite' => 'pcs', 'prix_unitaire' => 1000,
            'montant_tva' => 18000, 'montant_ttc' => 118000,
        ]);

        return $vente->fresh('details');
    }

    private function avoirPartiel(Vente $vente, array $items)
    {
        return $this->post(route('admin.ventes.avoir.creer_nouveau'), [
            'parent_id' => $vente->uuid, 'raison' => 'Retour', 'items' => $items,
        ]);
    }

    private function surLaLigne(Vente $vente, float $quantite, float $prix = 1000): array
    {
        return [$vente->details->first()->id => ['quantite' => $quantite, 'prix_unitaire' => $prix, 'stock_action' => 'none']];
    }

    private function avoirs(Vente $vente)
    {
        return Vente::where('parent_id', $vente->id)->where('type_facture', 'avoir');
    }

    // ── 8.1 — Le plafond, au serveur ─────────────────────────────────

    public function test_un_prix_releve_a_la_main_ne_fait_pas_depasser_la_facture(): void
    {
        $vente = $this->facture();

        // Toute la quantité, au double du prix : le plafond en quantité
        // laissait passer 236 000 F d'avoir sur une facture de 118 000 F.
        $this->avoirPartiel($vente, $this->surLaLigne($vente, 100, 2000))->assertSessionHasErrors('items');

        $this->assertSame(0, $this->avoirs($vente)->count(), 'Le refus défait tout.');
    }

    public function test_une_ligne_ajoutee_ne_fait_pas_depasser_la_facture(): void
    {
        $vente = $this->facture();

        $items = $this->surLaLigne($vente, 100) + ['n1' => [
            'est_nouveau' => 1, 'libelle_virtuel' => 'Cahier', 'quantite' => 10,
            'prix_unitaire' => 500, 'taux_tva' => 18, 'stock_action' => 'none',
        ]];

        $this->avoirPartiel($vente, $items)->assertSessionHasErrors('items');
        $this->assertSame(0, $this->avoirs($vente)->count());
    }

    public function test_des_avoirs_successifs_s_arretent_au_total(): void
    {
        $vente = $this->facture();

        $this->avoirPartiel($vente, $this->surLaLigne($vente, 60))->assertSessionHasNoErrors();
        $this->avoirPartiel($vente, $this->surLaLigne($vente, 40))->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(118000, (float) $this->avoirs($vente)->sum('montant_ttc'), 0.01);
        $this->assertSame(0.0, $vente->fresh()->resteAAvoirer());
    }

    public function test_l_avoir_total_refuse_une_facture_deja_avoiree(): void
    {
        $vente = $this->facture();
        $this->avoirPartiel($vente, $this->surLaLigne($vente, 50))->assertSessionHasNoErrors();

        $this->post(route('admin.ventes.avoir', $vente), ['raison' => 'Annulation'])
            ->assertSessionHasErrors('raison');

        $this->assertSame(1, $this->avoirs($vente)->count());
    }

    public function test_un_avoir_d_une_autre_periode_compte_aussi(): void
    {
        $vente = $this->facture();
        $this->avoirPartiel($vente, $this->surLaLigne($vente, 60))->assertSessionHasNoErrors();
        $this->avoirs($vente)->update(['date_vente' => now()->subYear()->toDateString()]);

        // La période affichée ne montre plus le premier avoir ; il a pourtant
        // bien rendu 70 800 F.
        $this->withSession(['active_periode_debut' => now()->startOfMonth()->toDateString(),
                            'active_periode_fin' => now()->endOfMonth()->toDateString()]);

        $this->avoirPartiel($vente, $this->surLaLigne($vente, 60))->assertSessionHasErrors('items');
    }

    // ── 8.2 / 8.3 — La liste des factures d'origine ──────────────────

    public function test_une_facture_entierement_avoiree_sort_de_la_liste(): void
    {
        $soldee = $this->facture('VTE-2026-0001');
        $this->avoirPartiel($soldee, $this->surLaLigne($soldee, 100))->assertSessionHasNoErrors();
        $this->facture('VTE-2026-0002');

        $corps = $this->get(route('admin.ventes.factures', ['type' => 'avoir']))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="' . $soldee->uuid . '"', $corps);
        $this->assertStringContainsString('VTE-2026-0002', $corps);

        $recherche = $this->getJson(route('admin.ventes.factures.rechercher', ['q' => 'VTE-2026']))->assertOk()->json();
        $this->assertSame(['VTE-2026-0002'], array_map(fn ($r) => explode(' ', $r['text'])[0], $recherche));
    }

    public function test_une_facture_partiellement_avoiree_annonce_son_reste(): void
    {
        $vente = $this->facture();
        $this->avoirPartiel($vente, $this->surLaLigne($vente, 25))->assertSessionHasNoErrors();

        $this->get(route('admin.ventes.factures', ['type' => 'avoir']))
            ->assertOk()
            ->assertSee('reste 88 500 F sur 118 000 F');

        $this->getJson(route('admin.ventes.factures.details', $vente))
            ->assertOk()
            ->assertJson(['deja_avoire' => 29500, 'reste_a_avoirer' => 88500]);
    }
}
