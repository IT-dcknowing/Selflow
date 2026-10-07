<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneRejet;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Lot 42 — la caisse en un geste.
 *
 * Une vente au comptant de deux articles, payée au montant exact, coûtait une
 * dizaine de clics, dont cinq **après** que la vente était faite :
 *
 * 1. **La validation renvoyait à la liste des factures.** Il fallait y
 *    retrouver sa ligne parmi douze colonnes, ouvrir le reçu, revenir,
 *    rouvrir la caisse. Et le panier n'était plus vidé : seule la page de la
 *    facture le faisait, et on ne l'ouvrait plus — la vente suivante
 *    repartait avec les articles de la précédente.
 *
 * 2. **Le montant reçu se recopiait à chaque vente**, faute de quoi une
 *    alerte bloquait la validation. Le serveur savait pourtant déjà qu'un
 *    champ vide vaut le montant exact.
 *
 * 3. **Le client se choisissait dans une liste sans recherche**, et un client
 *    nouveau obligeait à passer par Tiers → Clients puis à revenir.
 *
 * 4. **La recherche d'article ne lisait que le nom**, et Entrée n'ajoutait
 *    rien — elle soumettait la vente dès que le panier n'était plus vide.
 */
class CaisseEnUnGesteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $magasin;
    private Produit $article;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom' => 'Boutique Yopougon', 'regime_imposition' => 'RNI',
            'adresse' => 'Yopougon, Abidjan', 'rccm' => 'CI-ABJ-2026-B-04242',
            'ncc' => '2604242B', 'gerant_fonction' => 'Gérante',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin Selmer', 'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Akissi', 'email' => 'akissi-caisse@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        // Un service : la caisse ne bute sur aucun stock, et ce n'est pas ce
        // qu'on éprouve ici.
        $this->article = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'RIZ-0042',
            'nom' => 'Livraison à domicile', 'type' => 'service', 'unite' => 'course',
            'prix_achat' => 0, 'prix_vente' => 5000, 'taux_tva' => 0,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    /** Une vente de 10 000 F, telle que la caisse l'envoie. */
    private function vendre(array $ajouts = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('admin.ventes.enregistrer'), array_merge([
            'etape'         => 'Facture',
            'type_piece'    => 'facture',
            'mode_paiement' => 'Caisse',
            'articles'      => [[
                'produit_id' => $this->article->id,
                'quantite'   => 2,
                'unite'      => 'course',
            ]],
        ], $ajouts));
    }

    private function facture(array $ajouts = []): Vente
    {
        return Vente::create(array_merge([
            'point_de_vente_id' => $this->magasin->id,
            'utilisateur_id'    => $this->admin->id,
            'numero_facture'    => 'VTE-061026-001',
            'date_vente'        => now()->toDateString(),
            'etape'             => 'Facture',
            'type_piece'        => Vente::TYPE_FACTURE,
            'montant_ht'        => 10000,
            'montant_tva'       => 0,
            'montant_ttc'       => 10000,
            'montant_recu'      => 15000,
            'mode_paiement'     => 'Caisse',
            'statut'            => 'Payé',
        ], $ajouts));
    }

    // ══════════════ 1. Ce qui suit la validation ══════════════

    public function test_une_facture_mene_a_l_ecran_de_fin_de_vente(): void
    {
        $reponse = $this->vendre(['montant_paye' => 15000]);

        $vente = Vente::latest('id')->firstOrFail();
        $reponse->assertRedirect(route('admin.ventes.enregistree', $vente));
    }

    public function test_un_devis_retourne_toujours_a_la_liste(): void
    {
        // Un devis ne se remet pas au comptoir : ni monnaie, ni reçu.
        $this->vendre(['etape' => 'Devis'])
            ->assertRedirect(route('admin.ventes.factures', ['etape' => 'Devis']));
    }

    public function test_l_ecran_annonce_ce_qu_on_rend(): void
    {
        $vente = $this->facture();

        $this->get(route('admin.ventes.enregistree', $vente))
            ->assertOk()
            ->assertSee('VTE-061026-001')
            ->assertSee('À rendre')
            ->assertSee('5 000 F');
    }

    public function test_une_avance_annonce_ce_qui_reste_du(): void
    {
        $vente = $this->facture(['montant_recu' => 6000, 'statut' => 'Avance']);

        $this->get(route('admin.ventes.enregistree', $vente))
            ->assertOk()
            ->assertSee('Reste dû')
            ->assertSee('4 000 F')
            ->assertDontSee('À rendre');
    }

    public function test_l_ecran_vide_le_panier_de_la_vente_faite(): void
    {
        $page = $this->get(route('admin.ventes.enregistree', $this->facture()))->assertOk()->getContent();

        $this->assertStringContainsString("localStorage.removeItem('selflow_vente_panier')", $page);
    }

    public function test_l_ecran_mene_au_recu_et_a_la_vente_suivante(): void
    {
        $vente = $this->facture();

        $this->get(route('admin.ventes.enregistree', $vente))
            ->assertOk()
            ->assertSee(route('admin.ventes.ticket', $vente), false)
            ->assertSee(route('admin.ventes.nouvelle'), false)
            ->assertSee('id="btnNouvelleVente"', false);
    }

    public function test_un_devis_n_a_pas_d_ecran_de_fin_de_vente(): void
    {
        $devis = $this->facture(['etape' => 'Devis', 'statut' => 'Brouillon']);

        $this->get(route('admin.ventes.enregistree', $devis))
            ->assertRedirect(route('admin.ventes.imprimer', $devis));
    }

    public function test_la_piece_d_une_autre_entreprise_est_introuvable(): void
    {
        $vente = $this->facture();

        // Une entreprise complète, avec son magasin : sans quoi le contrôle
        // d'inscription la renverrait ailleurs avant que l'appartenance de la
        // pièce soit seulement regardée.
        $autre = Entreprise::create([
            'nom' => 'Concurrent', 'regime_imposition' => 'RNI',
            'adresse' => 'Treichville', 'rccm' => 'CI-ABJ-2026-B-09999',
            'ncc' => '2609999C', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes'],
        ]);
        $sonMagasin = PointDeVente::create([
            'entreprise_id' => $autre->id,
            'nom' => 'Magasin Treichville', 'ville' => 'Abidjan', 'commune' => 'Treichville',
        ]);
        $intrus = Utilisateur::create([
            'nom' => 'Intrus', 'prenom' => 'Paul', 'email' => 'intrus-caisse@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $autre->id,
            'point_de_vente_id' => $sonMagasin->id,
        ]);

        $this->actingAs($intrus)->withSession(['point_de_vente_actif_id' => $sonMagasin->id]);
        $this->get(route('admin.ventes.enregistree', $vente))->assertNotFound();
        $this->getJson(route('admin.ventes.etat_dgi', $vente))->assertNotFound();
    }

    // ══════════════ 1 bis. L'état DGI que l'écran attend ══════════════

    public function test_une_piece_certifiee_se_dit_certifiee(): void
    {
        $vente = $this->facture(['normalise' => true, 'numero_fne' => '2604242B26000000042']);

        $this->getJson(route('admin.ventes.etat_dgi', $vente))->assertOk()->assertJson(['etat' => 'certifiee']);
    }

    public function test_une_piece_deposee_dans_la_file_est_en_cours(): void
    {
        $this->entreprise->update(['normalisation_auto_factures' => true]);

        $this->getJson(route('admin.ventes.etat_dgi', $this->facture()))->assertJson(['etat' => 'en_cours']);
    }

    public function test_sans_normalisation_automatique_la_piece_attend(): void
    {
        // Rien ne partira seul : l'écran propose alors « Normaliser maintenant ».
        $this->entreprise->update(['normalisation_auto_factures' => false]);
        $vente = $this->facture();

        $this->getJson(route('admin.ventes.etat_dgi', $vente))->assertJson(['etat' => 'en_attente']);
        $this->get(route('admin.ventes.enregistree', $vente))
            ->assertSee(route('admin.ventes.normaliser', $vente), false)
            ->assertSee('Normaliser maintenant');
    }

    public function test_un_refus_de_la_dgi_n_est_pas_une_coupure(): void
    {
        $refusee = $this->facture();
        $this->rejet($refusee, FneRejet::CAUSE_DGI);

        $coupee = $this->facture(['numero_facture' => 'VTE-061026-002']);
        $this->rejet($coupee, FneRejet::CAUSE_RESEAU);

        // La liste confond les deux sous « Rejetée ». Dire à un caissier
        // qu'une pièce est refusée quand la plateforme était injoignable lui
        // ferait chercher une faute qui n'existe pas.
        $this->getJson(route('admin.ventes.etat_dgi', $refusee))->assertJson(['etat' => 'rejetee']);
        $this->getJson(route('admin.ventes.etat_dgi', $coupee))->assertJson(['etat' => 'injoignable']);
    }

    private function rejet(Vente $vente, string $cause): void
    {
        FneRejet::create([
            'entreprise_id' => $this->entreprise->id,
            'piece_type'    => 'vente',
            'piece_id'      => $vente->id,
            'numero_piece'  => $vente->numero_facture,
            'login'         => $this->entreprise->ncc,
            'message'       => 'Refus de test',
            'cause'         => $cause,
            'statut'        => FneRejet::STATUT_OUVERT,
        ]);
    }

    public function test_sans_le_droit_aux_factures_aucun_lien_ne_mene_a_un_refus(): void
    {
        // Le reçu et la normalisation demandent `factures_vente`. Un caissier
        // qui n'a que la caisse ne doit pas se voir tendre une page 403
        // (Forbidden — accès interdit).
        $caissier = Utilisateur::create([
            'nom' => 'Koné', 'prenom' => 'Ali', 'email' => 'ali-caisse@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'caissier',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
            'habilitations' => ['nouvelle_vente'],
        ]);
        $vente = $this->facture();

        $this->actingAs($caissier)
            ->get(route('caissier.ventes.enregistree', $vente))
            ->assertOk()
            ->assertSee(route('caissier.ventes.nouvelle'), false)
            ->assertDontSee(route('caissier.ventes.ticket', $vente), false)
            ->assertDontSee(route('caissier.ventes.normaliser', $vente), false);
    }

    // ══════════════ 2. Le montant reçu ══════════════

    public function test_un_montant_vide_vaut_le_montant_exact(): void
    {
        // L'écran s'appuie désormais sur ce comportement du serveur : un champ
        // laissé vide encaisse le net à payer, et la vente est payée.
        $this->vendre();

        $vente = Vente::latest('id')->firstOrFail();
        $this->assertSame('Payé', $vente->statut);
        $this->assertSame(10000.0, (float) $vente->montant_recu);
        $this->assertSame(0.0, $vente->monnaieRendue());
    }

    public function test_la_caisse_n_exige_plus_de_recopier_le_total(): void
    {
        $page = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Le montant payé est obligatoire', $page);
        $this->assertStringContainsString('Laissez vide si le client paie le montant exact', $page);
        $this->assertStringNotContainsString('montantInput.required    = true', $page);
    }

    // ══════════════ 3. Le client ══════════════

    public function test_le_client_se_cherche_au_lieu_de_defiler(): void
    {
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Adjoua Kouassi', 'telephone' => '0707070707']);

        $page = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<select name="client_id"', $page);
        $this->assertStringContainsString('id="clientRecherche"', $page);
        $this->assertStringContainsString('name="client_id" id="clientIdInput"', $page);
        // Le téléphone voyage avec le nom : on cherche aussi par lui.
        $this->assertStringContainsString('0707070707', $page);
        // Telle que `@json` l'écrit dans le script : barres obliques échappées.
        $this->assertStringContainsString(json_encode(route('admin.ventes.client_rapide')), $page);
    }

    public function test_un_client_se_cree_depuis_la_caisse(): void
    {
        $reponse = $this->postJson(route('admin.ventes.client_rapide'), [
            'nom' => 'Ibrahim Traoré', 'type_facturation' => 'B2C', 'telephone' => '0505050505',
        ])->assertCreated();

        $client = Client::findOrFail($reponse->json('client.id'));
        $this->assertSame($this->entreprise->id, $client->entreprise_id);
        $this->assertSame('Ibrahim Traoré', $client->nom);
        $this->assertSame('0505050505', $client->telephone);
        // Le compte général n'est pas demandé : le serveur pose le collectif.
        $this->assertSame('411000', $client->compte_comptable);
        $this->assertNotEmpty($client->numero_tiers);
        $this->assertNull($client->ncc);
    }

    public function test_une_entreprise_cliente_exige_son_ncc(): void
    {
        $this->postJson(route('admin.ventes.client_rapide'), [
            'nom' => 'SARL Bâtir', 'type_facturation' => 'B2B',
        ])->assertStatus(422)->assertJsonValidationErrors('ncc');

        $this->postJson(route('admin.ventes.client_rapide'), [
            'nom' => 'SARL Bâtir', 'type_facturation' => 'B2B', 'ncc' => ' 1234 567a ',
        ])->assertCreated();

        $this->assertSame('1234567A', Client::where('nom', 'SARL Bâtir')->value('ncc'));
    }

    public function test_un_caissier_cree_un_client_avec_la_seule_caisse(): void
    {
        $caissier = Utilisateur::create([
            'nom' => 'Koné', 'prenom' => 'Ali', 'email' => 'ali-client@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'caissier',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
            'habilitations' => ['nouvelle_vente'],
        ]);

        $this->actingAs($caissier)
            ->postJson(route('caissier.ventes.client_rapide'), ['nom' => 'Mariam Diallo', 'type_facturation' => 'B2C'])
            ->assertCreated();

        $this->assertDatabaseHas('clients', ['nom' => 'Mariam Diallo', 'entreprise_id' => $this->entreprise->id]);

        // Et sa caisse lui envoie la fiche à sa propre adresse, non à celle de
        // l'administration, que son rôle ne lui ouvre pas.
        $page = $this->get(route('caissier.ventes.nouvelle'))->assertOk()->getContent();
        $this->assertStringContainsString(json_encode(route('caissier.ventes.client_rapide')), $page);
    }

    // ══════════════ 4. La recherche d'article ══════════════

    public function test_la_reference_de_l_article_se_cherche_aussi(): void
    {
        $page = $this->get(route('admin.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringContainsString('data-ref="RIZ-0042"', $page);
        // Entrée ajoute l'article au lieu de soumettre la vente.
        $this->assertStringContainsString("addEventListener('keydown'", $page);
        $this->assertStringContainsString('Entrée pour ajouter', $page);
    }
}
