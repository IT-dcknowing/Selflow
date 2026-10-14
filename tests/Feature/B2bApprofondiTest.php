<?php

namespace Tests\Feature;

use App\Mail\B2bEvenement;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\B2bNegotiation;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Le B2B approfondi (lot 75) : deux entreprises inscrites, d'un bout à
 * l'autre — demande de prix, réponse, contre-proposition, commande,
 * confirmation selon le stock, livraison facturée, réception.
 *
 * Le client : un restaurant qui achète du riz. Le fournisseur : un grossiste
 * qui en a 30 sacs en stock.
 */
class B2bApprofondiTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $restaurant;
    private Entreprise $grossiste;
    private Utilisateur $acheteur;
    private Utilisateur $vendeur;
    private PointDeVente $siteRestaurant;
    private PointDeVente $entrepot;
    private Produit $rizChezLeClient;
    private Produit $rizChezLeFournisseur;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();

        $modules = ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'points_de_vente'];
        $this->restaurant = Entreprise::create(['nom' => 'Restaurant Le Baobab', 'ncc' => '2601111R', 'regime_imposition' => 'RNI',
            'adresse' => 'Cocody', 'rccm' => 'CI-ABJ-1', 'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Commerce'], 'modules_actifs' => $modules]);
        $this->grossiste = Entreprise::create(['nom' => 'Grossiste du Port', 'ncc' => '2602222G', 'regime_imposition' => 'RNI',
            'adresse' => 'Treichville', 'rccm' => 'CI-ABJ-2', 'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Commerce'], 'modules_actifs' => $modules]);

        $this->siteRestaurant = PointDeVente::create(['entreprise_id' => $this->restaurant->id, 'nom' => 'Salle', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->entrepot = PointDeVente::create(['entreprise_id' => $this->grossiste->id, 'nom' => 'Entrepôt', 'ville' => 'Abidjan', 'commune' => 'Treichville']);

        $this->acheteur = Utilisateur::create(['nom' => 'Koné', 'prenom' => 'Awa', 'email' => 'awa@baobab.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $this->restaurant->id, 'point_de_vente_id' => $this->siteRestaurant->id]);
        $this->vendeur = Utilisateur::create(['nom' => 'Diallo', 'prenom' => 'Moussa', 'email' => 'moussa@grossiste.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $this->grossiste->id, 'point_de_vente_id' => $this->entrepot->id]);

        Fournisseur::create(['entreprise_id' => $this->restaurant->id, 'nom' => 'Grossiste du Port', 'ncc' => '2602222G']);

        $this->rizChezLeClient = Produit::create(['entreprise_id' => $this->restaurant->id, 'reference' => 'RIZ-25', 'nom' => 'Riz 25 kg',
            'type' => 'marchandise', 'unite' => 'sac', 'prix_achat' => 15000, 'prix_vente' => 0, 'taux_tva' => 18]);
        $this->rizChezLeFournisseur = Produit::create(['entreprise_id' => $this->grossiste->id, 'reference' => 'RIZ-PARF-25', 'nom' => 'Riz 25 kg',
            'type' => 'marchandise', 'unite' => 'sac', 'prix_achat' => 12000, 'prix_vente' => 16000, 'taux_tva' => 18]);
        StockService::entree($this->rizChezLeFournisseur, $this->entrepot->id, 30, MouvementStock::RECEPTION, ['cout_unitaire' => 12000]);
    }

    private function enClient(): static
    {
        return $this->actingAs($this->acheteur)->withSession(['point_de_vente_actif_id' => $this->siteRestaurant->id]);
    }

    private function enFournisseur(): static
    {
        return $this->actingAs($this->vendeur)->withSession(['point_de_vente_actif_id' => $this->entrepot->id]);
    }

    private function demande(string $type = 'rfq', float $prix = 15000, float $quantite = 40): B2bNegotiation
    {
        $this->enClient()->post(route('admin.b2b.rfq.creer'), [
            'fournisseur_id' => Fournisseur::where('entreprise_id', $this->restaurant->id)->value('id'),
            'type_demande' => $type, 'message' => 'Livraison avant vendredi.',
            'articles' => [['produit_id' => $this->rizChezLeClient->id, 'quantite' => $quantite, 'prix_unitaire' => $prix]],
        ])->assertSessionHasNoErrors();

        return B2bNegotiation::latest('id')->firstOrFail();
    }

    // ── 70a : la conversation ────────────────────────────────────────

    public function test_la_demande_arrive_chez_le_fournisseur_avec_un_courriel_et_un_compteur(): void
    {
        $neg = $this->demande();

        $this->assertSame('RFQ', $neg->statut);
        $this->assertSame($this->restaurant->id, $neg->entreprise_client_id);
        Mail::assertSent(B2bEvenement::class, fn ($m) => $m->hasTo('moussa@grossiste.ci'));
        $this->assertSame(1, B2bNegotiation::nonLuesPour($this->grossiste->id, 'fournisseur')->count());

        // Ouvrir la négociation la marque comme lue.
        $this->enFournisseur()->get(route('admin.b2b.fournisseur.voir', $neg))->assertOk()
            ->assertSee('Livraison avant vendredi.')->assertSee('Demande de prix reçue');
        $this->assertSame(0, B2bNegotiation::nonLuesPour($this->grossiste->id, 'fournisseur')->count());
    }

    public function test_proposition_contre_proposition_et_messages(): void
    {
        $neg = $this->demande();

        $this->enFournisseur()->post(route('admin.b2b.fournisseur.proposer', $neg), ['prix' => [17000], 'message' => 'Prix du marché.'])
            ->assertSessionHas('succes');
        $this->assertSame('Negociation_Fournisseur', $neg->fresh()->statut);
        $this->assertSame(1, B2bNegotiation::nonLuesPour($this->restaurant->id, 'client')->count());

        $this->enClient()->post(route('admin.b2b.client.proposer', $neg), ['prix' => [16000], 'quantites' => [40], 'message' => 'À 16 000, je prends.']);
        $neg->refresh();
        $this->assertSame('Negociation_Client', $neg->statut);
        $this->assertEquals(16000, $neg->produits_demandes[0]['prix_propose']);

        $this->enFournisseur()->post(route('admin.b2b.fournisseur.message', $neg), ['message' => "D'accord pour 16 000."]);
        $fil = collect($neg->fresh()->historique_discussions)->pluck('message')->join("\n");
        $this->assertStringContainsString('17 000 F → 16 000 F', $fil);
        $this->assertStringContainsString("D'accord pour 16 000.", $fil);
    }

    // ── 70b-70c : commander, confirmer, livrer, réceptionner ─────────

    public function test_le_parcours_complet_de_la_demande_a_la_reception(): void
    {
        $neg = $this->demande(prix: 0);

        // Sans prix, on ne commande pas : on attend la proposition.
        $this->enClient()->post(route('admin.b2b.client.commander', $neg))->assertSessionHas('erreur');

        $this->enFournisseur()->post(route('admin.b2b.fournisseur.proposer', $neg), ['prix' => [16000]]);
        $this->enClient()->post(route('admin.b2b.client.commander', $neg))->assertSessionHas('succes');

        $neg->refresh();
        $this->assertSame('Commande', $neg->statut);
        $bcf = Achat::where('b2b_negotiation_id', $neg->id)->firstOrFail();
        $this->assertSame('Bon de commande', $bcf->etape);
        $this->assertStringStartsWith('BCF-', $bcf->numero_facture);

        // Le grossiste n'a que 30 sacs : il rattache sa référence et confirme 30.
        $this->enFournisseur()->get(route('admin.b2b.fournisseur.voir', $neg))->assertOk()->assertSee('Confirmer selon votre stock');
        $this->enFournisseur()->post(route('admin.b2b.fournisseur.confirmer', $neg), [
            'point_de_vente_id' => $this->entrepot->id,
            'produits' => [$this->rizChezLeFournisseur->id], 'quantites' => [30],
        ])->assertSessionHas('succes');

        $neg->refresh();
        $this->assertSame('Confirme', $neg->statut);
        $this->assertSame('Confirmée — en attente de livraison', $neg->libelleStatut('client'));
        $bc = Vente::where('b2b_negotiation_id', $neg->id)->where('etape', 'Bon de commande')->firstOrFail();
        $this->assertSame($this->entrepot->id, $bc->point_de_vente_id);
        $this->assertEqualsWithDelta(30 * 16000 * 1.18, (float) $bc->montant_ttc, 0.01);
        $this->assertEquals(30, (float) $bcf->fresh()->details->first()->quantite, 'La commande du client suit la confirmation.');

        // Livrer et facturer : le stock sort chez le grossiste.
        $this->enFournisseur()->post(route('admin.b2b.fournisseur.livrer', $neg))->assertSessionHas('succes');
        $neg->refresh();
        $this->assertSame('Livre', $neg->statut);
        $facture = Vente::where('b2b_negotiation_id', $neg->id)->where('etape', 'Facture')->firstOrFail();
        $this->assertSame(0.0, $this->rizChezLeFournisseur->fresh()->stockActuel($this->entrepot->id));
        $this->assertSame('En attente B2B', $bcf->fresh()->statut);

        // Le restaurant réceptionne : le riz entre, la facture d'achat naît.
        $this->enClient()->post(route('admin.b2b.client.receptionner', $neg))->assertSessionHas('succes');
        $this->assertSame('Termine', $neg->fresh()->statut);
        $achat = $bcf->fresh();
        $this->assertSame('Facture', $achat->etape);
        $this->assertSame($facture->numero_facture, $achat->numero_facture_fournisseur);
        $this->assertSame(30.0, $this->rizChezLeClient->fresh()->stockActuel($this->siteRestaurant->id));
        $this->assertEqualsWithDelta((float) $facture->montant_ttc, (float) $achat->montant_ttc, 0.01);
    }

    public function test_la_commande_directe_cree_le_bon_de_commande_du_client(): void
    {
        $neg = $this->demande('commande', 15500, 10);

        $this->assertSame('Commande', $neg->statut);
        $this->assertSame('commande', $neg->type_demande);
        $this->assertSame(1, Achat::where('b2b_negotiation_id', $neg->id)->where('etape', 'Bon de commande')->count());
        $this->enFournisseur()->get(route('admin.b2b.negociations.fournisseur', ['tab' => 'commandes']))
            ->assertOk()->assertSee('Commande reçue — à confirmer');
    }

    public function test_un_refus_clot_les_pieces_et_previent_l_autre(): void
    {
        $neg = $this->demande('commande', 15500, 10);
        Mail::fake();

        $this->enFournisseur()->post(route('admin.b2b.fournisseur.refuser', $neg), ['motif' => 'Rupture chez nous.'])->assertSessionHas('succes');

        $this->assertSame('Refuse', $neg->fresh()->statut);
        $this->assertTrue((bool) Achat::where('b2b_negotiation_id', $neg->id)->value('archived'));
        Mail::assertSent(B2bEvenement::class, fn ($m) => $m->hasTo('awa@baobab.ci') && str_contains($m->texte, 'Rupture chez nous.'));
    }

    // ── Les portes ───────────────────────────────────────────────────

    public function test_chacun_ne_voit_que_son_cote_et_ses_negociations(): void
    {
        $neg = $this->demande();

        $this->enClient()->get(route('admin.b2b.fournisseur.voir', $neg))->assertNotFound();
        $this->enFournisseur()->post(route('admin.b2b.client.commander', $neg))->assertNotFound();

        $intrus = Entreprise::create(['nom' => 'Intrus', 'modules_actifs' => ['principal', 'ventes', 'achats']]);
        $autre = Utilisateur::create(['nom' => 'X', 'prenom' => 'Y', 'email' => 'x@intrus.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'entreprise_id' => $intrus->id]);
        $this->actingAs($autre)->get(route('admin.b2b.client.voir', $neg))->assertNotFound();
    }

    public function test_le_bc_d_un_vendeur_pour_un_client_inscrit_arrive_chez_le_client_confirme(): void
    {
        // Le lien était posé à l'envers : le vendeur figurait comme client.
        $client = Client::create(['entreprise_id' => $this->grossiste->id, 'nom' => 'Restaurant Le Baobab', 'ncc' => '2601111R']);

        $this->enFournisseur()->post(route('admin.ventes.enregistrer'), [
            'client_id' => $client->id, 'mode_paiement' => 'Crédit', 'etape' => 'Bon de commande',
            'articles' => [['produit_id' => $this->rizChezLeFournisseur->id, 'quantite' => 5, 'unite' => 'sac']],
        ])->assertSessionHasNoErrors();

        $neg = B2bNegotiation::firstOrFail();
        $this->assertSame($this->restaurant->id, $neg->entreprise_client_id);
        $this->assertSame($this->grossiste->id, $neg->entreprise_fournisseur_id);
        $this->assertSame('Confirme', $neg->statut);
        $this->assertSame(1, Achat::where('b2b_negotiation_id', $neg->id)->count(), 'Le client a son bon de commande.');

        // Le grossiste livre depuis la page B2B : sa commande se facture.
        $this->enFournisseur()->post(route('admin.b2b.fournisseur.livrer', $neg))->assertSessionHas('succes');
        $this->assertSame('Livre', $neg->fresh()->statut);
    }

    public function test_facturer_depuis_l_ecran_des_ventes_previent_aussi_le_client(): void
    {
        $neg = $this->demande('commande', 15500, 10);
        $this->enFournisseur()->post(route('admin.b2b.fournisseur.confirmer', $neg), [
            'point_de_vente_id' => $this->entrepot->id, 'produits' => [$this->rizChezLeFournisseur->id], 'quantites' => [10],
        ])->assertSessionHas('succes');
        $bc = Vente::where('b2b_negotiation_id', $neg->id)->where('etape', 'Bon de commande')->firstOrFail();

        // Le grossiste facture sa commande par le chemin habituel des ventes.
        \App\Modules\Admin\Services\FacturationCommandeService::etablir(
            $bc, \App\Modules\Admin\Services\FacturationCommandeService::quantitesDeLaCommande($bc), ['mode' => 'Crédit']
        );
        \App\Modules\Admin\Services\FacturationCommandeService::clore($bc, Vente::where('etape', 'Facture')->latest('id')->firstOrFail());

        $this->assertSame('Livre', $neg->fresh()->statut);
        $this->assertSame('En attente B2B', Achat::where('b2b_negotiation_id', $neg->id)->value('statut'));
    }
}
