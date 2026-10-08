<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ce qui rend un devis opposable.
 *
 * Le devis existait comme étape de la vente, et ne touchait déjà ni le stock ni
 * la comptabilité — tout cela était juste. **Mais il n'engageait personne**, et
 * quatre manques l'expliquaient :
 *
 * - **aucune date de validité.** Un devis de janvier restait présentable en
 *   décembre, aux prix de janvier, et le client qui l'acceptait avait raison de
 *   le faire : rien n'y disait le contraire ;
 * - **aucune trace de l'acceptation** — ni la date, ni le nom de qui a accepté.
 *   En cas de contestation, rien à opposer ;
 * - **la conversion se rejouait.** `archived` disait qu'elle avait eu lieu sans
 *   dire en quoi, et n'empêchait pas la seconde : **le même devis produisait
 *   deux bons de commande**, donc deux livraisons et deux factures ;
 * - **la pièce née héritait de la date de son aînée.** Une facture de juin
 *   issue d'un devis de janvier était **datée de janvier** : elle se rangeait
 *   dans la période de janvier et entrait dans la déclaration de TVA du mauvais
 *   mois.
 */
class DevisOpposableTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $magasin;
    private Produit $ciment;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom'               => 'Quincaillerie du Plateau',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Plateau, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00007',
            'ncc'               => '2601234A',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin central', 'ville' => 'Abidjan', 'commune' => 'Plateau',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Bamba', 'prenom' => 'Salif', 'email' => 'salif@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->ciment = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'CIM-001',
            'nom' => 'Ciment CPJ 45', 'type' => 'marchandise', 'unite' => 'sac',
            'prix_achat' => 5000, 'prix_vente' => 6500, 'taux_tva' => 18,
        ]);

        $this->client = Client::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Entreprise Konan BTP',
        ]);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    /**
     * Établir un devis par l'écran, comme le ferait un vendeur.
     */
    private function etablirUnDevis(array $champs = []): Vente
    {
        $this->post(route('admin.ventes.enregistrer'), array_merge([
            'etape'         => 'Devis',
            'client_id'     => $this->client->id,
            'mode_paiement' => 'Crédit',
            'articles'      => [[
                'produit_id' => $this->ciment->id,
                'quantite'   => 10,
                'unite'      => 'sac',
            ]],
        ], $champs));

        return Vente::where('etape', 'Devis')->latest('id')->firstOrFail();
    }

    /**
     * Un devis posé directement en base, pour maîtriser ses dates.
     */
    private function devis(array $champs = []): Vente
    {
        $devis = Vente::create(array_merge([
            'point_de_vente_id' => $this->magasin->id,
            'client_id'         => $this->client->id,
            'numero_facture'    => 'DV-' . uniqid(),
            'date_vente'        => now()->subDays(40)->toDateString(),
            'date_validite'     => now()->addDays(10)->toDateString(),
            'mode_paiement'     => 'Crédit',
            'montant_ht'        => 65000,
            'montant_tva'       => 11700,
            'montant_ttc'       => 76700,
            'statut'            => 'Brouillon',
            'etape'             => 'Devis',
        ], $champs));

        VenteDetail::create([
            'vente_id' => $devis->id, 'produit_id' => $this->ciment->id,
            'quantite' => 10, 'unite' => 'sac', 'prix_unitaire' => 6500,
            'montant_tva' => 11700, 'montant_ttc' => 76700,
        ]);

        return $devis;
    }

    // ══════════════ Le terme ══════════════

    public function test_un_devis_recoit_un_terme_par_defaut(): void
    {
        // Trente jours : l'usage commercial courant, et le delai que retiennent
        // les tribunaux quand l'offre est muette.
        $devis = $this->etablirUnDevis();

        $this->assertNotNull($devis->date_validite);
        $this->assertSame(
            now()->addDays(Vente::VALIDITE_PAR_DEFAUT)->toDateString(),
            $devis->date_validite->toDateString()
        );
    }

    public function test_le_vendeur_peut_fixer_un_autre_terme(): void
    {
        $devis = $this->etablirUnDevis([
            'date_validite' => now()->addDays(7)->toDateString(),
        ]);

        $this->assertSame(now()->addDays(7)->toDateString(), $devis->date_validite->toDateString());
    }

    public function test_une_facture_n_a_pas_de_terme(): void
    {
        // Une facture engage des son emission : elle n'expire pas.
        // Elle decremente le stock, contrairement au devis : il faut donc que
        // le magasin en ait.
        \App\Modules\Admin\Services\StockService::entree(
            $this->ciment, $this->magasin->id, 50,
            \App\Modules\Admin\Modeles\MouvementStock::RECEPTION, ['cout_unitaire' => 5000]
        );

        $this->post(route('admin.ventes.enregistrer'), [
            'etape'         => 'Facture',
            'client_id'     => $this->client->id,
            'mode_paiement' => 'Crédit',
            'articles'      => [['produit_id' => $this->ciment->id, 'quantite' => 1, 'unite' => 'sac']],
        ]);

        $this->assertNull(Vente::where('etape', 'Facture')->latest('id')->first()->date_validite);
    }

    public function test_un_terme_dans_le_passe_est_refuse(): void
    {
        // Une offre deja caduque au moment ou on la remet au client n'est pas
        // une offre.
        $this->post(route('admin.ventes.enregistrer'), [
            'etape'         => 'Devis',
            'client_id'     => $this->client->id,
            'mode_paiement' => 'Crédit',
            'date_validite' => now()->subDay()->toDateString(),
            'articles'      => [['produit_id' => $this->ciment->id, 'quantite' => 1, 'unite' => 'sac']],
        ])->assertSessionHasErrors('date_validite');

        $this->assertSame(0, Vente::where('etape', 'Devis')->count());
    }

    public function test_le_jour_du_terme_est_compris(): void
    {
        // Un devis valable jusqu'au 30 s'accepte le 30.
        $devis = $this->devis(['date_validite' => now()->toDateString()]);

        $this->assertFalse($devis->estExpire());
        $this->assertTrue($devis->estOpposable());
    }

    public function test_le_lendemain_du_terme_l_offre_ne_lie_plus(): void
    {
        $devis = $this->devis(['date_validite' => now()->subDay()->toDateString()]);

        $this->assertTrue($devis->estExpire());
        $this->assertFalse($devis->estOpposable());
    }

    public function test_un_devis_sans_terme_n_expire_pas(): void
    {
        // Les devis etablis avant ce lot n'ont pas de terme : on ne les
        // invalide pas retroactivement.
        $devis = $this->devis(['date_validite' => null]);

        $this->assertFalse($devis->estExpire());
    }

    public function test_une_offre_expiree_ne_se_convertit_pas(): void
    {
        // La reprendre telle quelle facturerait aux prix d'un mois revolu.
        $devis = $this->devis(['date_validite' => now()->subDay()->toDateString()]);

        $this->post(route('admin.ventes.convertir.commande', $devis))
            ->assertSessionHas('erreur');

        $this->assertSame(0, Vente::where('etape', 'Bon de commande')->count());
        $this->assertNull($devis->fresh()->converti_en_id);
    }

    public function test_prolonger_rend_l_offre_de_nouveau_convertible(): void
    {
        $devis = $this->devis(['date_validite' => now()->subDay()->toDateString()]);

        $this->post(route('admin.ventes.prolonger', $devis), [
            'date_validite' => now()->addDays(15)->toDateString(),
        ])->assertSessionHas('succes');

        $this->assertFalse($devis->fresh()->estExpire());

        $this->post(route('admin.ventes.convertir.commande', $devis->fresh()));

        $this->assertSame(1, Vente::where('etape', 'Bon de commande')->count());
    }

    public function test_prolonger_vers_le_passe_est_refuse(): void
    {
        $devis = $this->devis();

        $this->post(route('admin.ventes.prolonger', $devis), [
            'date_validite' => now()->subDays(3)->toDateString(),
        ])->assertSessionHasErrors('date_validite');
    }

    // ══════════════ L'acceptation ══════════════

    public function test_l_acceptation_retient_la_date_et_le_nom(): void
    {
        // C'est ce qu'on oppose en cas de contestation : sans cela, un devis
        // « accepte » ne repose que sur la memoire de deux personnes.
        $devis = $this->devis();

        $this->post(route('admin.ventes.accepter', $devis), [
            'accepte_par' => 'M. Konan, directeur technique',
        ])->assertSessionHas('succes');

        $devis->refresh();

        $this->assertSame(now()->toDateString(), $devis->date_acceptation->toDateString());
        $this->assertSame('M. Konan, directeur technique', $devis->accepte_par);
        $this->assertTrue($devis->estAccepte());
    }

    public function test_sans_nom_l_acceptation_retient_celui_du_client(): void
    {
        $devis = $this->devis();

        $this->post(route('admin.ventes.accepter', $devis), []);

        $this->assertSame('Entreprise Konan BTP', $devis->fresh()->accepte_par);
    }

    public function test_une_acceptation_ne_se_date_pas_dans_le_futur(): void
    {
        $devis = $this->devis();

        $this->post(route('admin.ventes.accepter', $devis), [
            'date_acceptation' => now()->addDays(5)->toDateString(),
        ])->assertSessionHasErrors('date_acceptation');

        $this->assertNull($devis->fresh()->date_acceptation);
    }

    public function test_une_offre_expiree_ne_s_accepte_plus(): void
    {
        // L'accepter apres coup ferait croire a un engagement qui n'existe pas.
        $devis = $this->devis(['date_validite' => now()->subDay()->toDateString()]);

        $this->post(route('admin.ventes.accepter', $devis))->assertSessionHas('erreur');

        $this->assertNull($devis->fresh()->date_acceptation);
    }

    public function test_une_facture_ne_s_accepte_pas(): void
    {
        $facture = $this->devis(['etape' => 'Facture', 'date_validite' => null]);

        $this->post(route('admin.ventes.accepter', $facture))->assertForbidden();
    }

    // ══════════════ Le gel ══════════════

    public function test_un_devis_accepte_ne_se_modifie_plus(): void
    {
        // Un devis dont les prix changent apres l'accord du client ne prouve
        // plus rien : la correction passe par un nouveau devis.
        $devis = $this->devis();
        $this->post(route('admin.ventes.accepter', $devis));

        $this->get(route('admin.ventes.modifier', $devis))->assertForbidden();

        $this->put(route('admin.ventes.modifier.enregistrer', $devis), [
            'mode_paiement' => 'Crédit',
            'articles'      => [['produit_id' => $this->ciment->id, 'quantite' => 99, 'unite' => 'sac']],
        ])->assertForbidden();

        $this->assertSame(10.0, (float) $devis->details()->first()->quantite);
    }

    public function test_un_devis_converti_ne_se_modifie_plus(): void
    {
        $devis = $this->devis();
        $this->post(route('admin.ventes.convertir.commande', $devis));

        $this->get(route('admin.ventes.modifier', $devis->fresh()))->assertForbidden();
    }

    public function test_un_devis_ni_accepte_ni_converti_se_modifie(): void
    {
        // Le gel ne doit pas gagner tout le reste : une offre en cours de
        // discussion se retouche.
        $devis = $this->devis();

        $this->get(route('admin.ventes.modifier', $devis))->assertOk();
    }

    public function test_une_facture_ordinaire_reste_modifiable(): void
    {
        $facture = $this->devis(['etape' => 'Facture', 'date_validite' => null]);

        $this->get(route('admin.ventes.modifier', $facture))->assertOk();
    }

    // ══════════════ La conversion ne se rejoue plus ══════════════

    public function test_un_devis_ne_produit_qu_un_bon_de_commande(): void
    {
        // **Le defaut principal** : `archived` n'empechait pas la seconde
        // conversion, et le meme devis produisait deux commandes, donc deux
        // livraisons et deux factures.
        $devis = $this->devis();

        $this->post(route('admin.ventes.convertir.commande', $devis));
        $this->post(route('admin.ventes.convertir.commande', $devis->fresh()))
            ->assertSessionHas('erreur');

        $this->assertSame(1, Vente::where('etape', 'Bon de commande')->count());
    }

    public function test_un_bon_de_commande_ne_produit_qu_une_facture(): void
    {
        // La facture d'une commande fait sortir la marchandise (recette du
        // 08/10/2026) : elle ne s'établit plus sur un stock vide.
        \App\Modules\Admin\Modeles\Stock::create(['produit_id' => $this->ciment->id, 'point_de_vente_id' => $this->magasin->id, 'quantite_disponible' => 100]);

        // Deux factures pour une commande, c'est un client facture deux fois.
        $devis = $this->devis();
        $this->post(route('admin.ventes.convertir.commande', $devis));

        $commande = Vente::where('etape', 'Bon de commande')->firstOrFail();

        $this->post(route('admin.ventes.convertir.facture', $commande));
        $this->post(route('admin.ventes.convertir.facture', $commande->fresh()))
            ->assertSessionHas('erreur');

        $this->assertSame(1, Vente::where('etape', 'Facture')->count());
    }

    public function test_le_devis_dit_ce_qu_il_est_devenu(): void
    {
        // `archived` disait qu'une conversion avait eu lieu sans dire en quoi.
        $devis = $this->devis();
        $this->post(route('admin.ventes.convertir.commande', $devis));

        $commande = Vente::where('etape', 'Bon de commande')->firstOrFail();

        $this->assertSame($commande->id, $devis->fresh()->converti_en_id);
        $this->assertSame($commande->numero_facture, $devis->fresh()->convertiEn->numero_facture);
    }

    public function test_confirmer_la_commande_respecte_les_memes_regles(): void
    {
        // Le bouton « Confirmer la commande » fait basculer l'etape sur place,
        // sans clone : il doit refuser les memes cas.
        $devis = $this->devis();
        $this->post(route('admin.ventes.convertir.commande', $devis));

        $this->post(route('admin.ventes.confirmer', $devis->fresh()))
            ->assertSessionHas('erreur');

        $this->assertSame('Devis', $devis->fresh()->etape);
    }

    public function test_un_devis_converti_ne_se_supprime_pas(): void
    {
        // Il fonde la piece qui en decoule : c'est precisement ce qu'on oppose
        // en cas de contestation.
        $devis = $this->devis();
        $this->post(route('admin.ventes.convertir.commande', $devis));

        $this->delete(route('admin.ventes.supprimer', $devis->fresh()))->assertForbidden();

        $this->assertNotNull(Vente::find($devis->id));
    }

    // ══════════════ La date de la pièce née ══════════════

    public function test_le_bon_de_commande_est_date_du_jour_de_sa_creation(): void
    {
        // `replicate()` recopiait la date du devis : une commande de juin issue
        // d'un devis de janvier etait datee de janvier, se rangeait dans la
        // periode de janvier, et la facture qui en decoulait entrait dans la
        // declaration de TVA du mauvais mois.
        $devis = $this->devis(['date_vente' => now()->subDays(40)->toDateString()]);

        $this->post(route('admin.ventes.convertir.commande', $devis));

        $this->assertSame(now()->toDateString(),
            Vente::where('etape', 'Bon de commande')->firstOrFail()->date_vente->toDateString());
    }

    public function test_la_facture_est_datee_du_jour_de_sa_creation(): void
    {
        // La facture d'une commande fait sortir la marchandise (recette du
        // 08/10/2026) : elle ne s'établit plus sur un stock vide.
        \App\Modules\Admin\Modeles\Stock::create(['produit_id' => $this->ciment->id, 'point_de_vente_id' => $this->magasin->id, 'quantite_disponible' => 100]);

        $devis = $this->devis(['date_vente' => now()->subDays(40)->toDateString()]);
        $this->post(route('admin.ventes.convertir.commande', $devis));

        $commande = Vente::where('etape', 'Bon de commande')->firstOrFail();
        $commande->update(['date_vente' => now()->subDays(20)->toDateString()]);

        $this->post(route('admin.ventes.convertir.facture', $commande));

        $facture = Vente::where('etape', 'Facture')->firstOrFail();

        $this->assertSame(now()->toDateString(), $facture->date_vente->toDateString());
        $this->assertNull($facture->date_validite, 'Une facture n\'a pas de terme.');
    }

    public function test_l_acceptation_du_devis_ne_se_recopie_pas_sur_la_commande(): void
    {
        // L'accord porte sur le devis, a sa date. Le recopier ferait croire que
        // le client a accepte une commande qu'il n'a jamais vue.
        $devis = $this->devis();
        $this->post(route('admin.ventes.accepter', $devis), ['accepte_par' => 'M. Konan']);
        $this->post(route('admin.ventes.convertir.commande', $devis->fresh()));

        $commande = Vente::where('etape', 'Bon de commande')->firstOrFail();

        $this->assertNull($commande->date_acceptation);
        $this->assertNull($commande->accepte_par);
    }

    public function test_le_bon_de_commande_recoit_son_propre_terme(): void
    {
        $devis = $this->devis(['date_validite' => now()->addDay()->toDateString()]);

        $this->post(route('admin.ventes.convertir.commande', $devis));

        $this->assertSame(
            now()->addDays(Vente::VALIDITE_PAR_DEFAUT)->toDateString(),
            Vente::where('etape', 'Bon de commande')->firstOrFail()->date_validite->toDateString()
        );
    }

    // ══════════════ Ce que le devis ne fait toujours pas ══════════════

    public function test_un_devis_ne_bouge_pas_le_stock(): void
    {
        $this->etablirUnDevis();

        $this->assertSame(0.0, $this->ciment->fresh()->stockActuel($this->magasin->id));
    }

    public function test_un_devis_n_ecrit_rien_en_comptabilite(): void
    {
        $this->etablirUnDevis();

        $this->assertSame(0, \App\Modules\Admin\Modeles\EcritureComptable::count());
    }

    public function test_un_devis_n_est_jamais_normalise(): void
    {
        // Un devis n'est pas une piece fiscale : ni normalise, ni transmis, ni
        // certifie.
        $devis = $this->etablirUnDevis();

        $this->assertFalse((bool) $devis->normalise);
        $this->assertNull($devis->numero_fne);
        $this->assertNull($devis->qr_code_data);
    }

    // ══════════════ Ce que le client lit sur le document ══════════════

    public function test_le_devis_imprime_porte_son_terme(): void
    {
        // Un terme qui ne figure que dans la base n'est opposable a personne :
        // c'est sur le document remis qu'il doit se lire.
        $devis = $this->devis(['date_validite' => now()->addDays(10)->toDateString()]);

        // `json_encode` echappe les non-ASCII : « aout » ressort en
        // « août » dans la source. On compare donc a la forme encodee.
        $this->get(route('admin.ventes.imprimer', $devis))
            ->assertOk()
            ->assertSee('Offre valable jusqu', false)
            ->assertSee(json_encode(now()->addDays(10)->isoFormat('D MMMM YYYY')), false);
    }

    public function test_le_devis_expire_le_dit_sur_le_document(): void
    {
        $devis = $this->devis(['date_validite' => now()->subDay()->toDateString()]);

        $this->get(route('admin.ventes.imprimer', $devis))
            ->assertOk()
            ->assertSee('est_expire: true', false);
    }

    public function test_le_devis_accepte_porte_l_accord_sur_le_document(): void
    {
        $devis = $this->devis();
        $this->post(route('admin.ventes.accepter', $devis), ['accepte_par' => 'M. Konan']);

        $this->get(route('admin.ventes.imprimer', $devis->fresh()))
            ->assertOk()
            ->assertSee('M. Konan', false);
    }

    public function test_la_facture_imprimee_ne_porte_aucun_terme(): void
    {
        // Le bloc ne doit pas deborder sur les pieces qui n'ont pas de terme.
        $facture = $this->devis(['etape' => 'Facture', 'date_validite' => null]);

        $this->get(route('admin.ventes.imprimer', $facture))
            ->assertOk()
            ->assertSee('date_validite: null', false);
    }

    // ══════════════ Cloisonnement ══════════════

    public function test_le_devis_d_une_autre_entreprise_ne_s_accepte_pas(): void
    {
        // **404 et non 403.** Repondre « acces refuse » sur la piece d'un
        // autre et « introuvable » sur une piece inexistante distingue les
        // deux : les identifiants etant sequentiels, compter les 403
        // donnait le volume de toute la plateforme. Ce qui n'est pas a
        // vous n'existe pas pour vous.
        $autre = Entreprise::create(['nom' => 'Quincaillerie rivale']);
        $sonMagasin = PointDeVente::create([
            'entreprise_id' => $autre->id,
            'nom' => 'Dépôt rival', 'ville' => 'Abidjan', 'commune' => 'Yopougon',
        ]);

        $sonDevis = Vente::create([
            'point_de_vente_id' => $sonMagasin->id,
            'numero_facture'    => 'DV-RIVAL-001',
            'date_vente'        => now()->toDateString(),
            'date_validite'     => now()->addDays(10)->toDateString(),
            'mode_paiement'     => 'Crédit',
            'montant_ht'        => 1000, 'montant_tva' => 180, 'montant_ttc' => 1180,
            'statut'            => 'Brouillon', 'etape' => 'Devis',
        ]);

        $this->post(route('admin.ventes.accepter', $sonDevis))->assertNotFound();
        $this->post(route('admin.ventes.prolonger', $sonDevis), [
            'date_validite' => now()->addYear()->toDateString(),
        ])->assertNotFound();

        $this->assertNull($sonDevis->fresh()->date_acceptation);
        $this->assertSame(now()->addDays(10)->toDateString(),
            $sonDevis->fresh()->date_validite->toDateString());
    }
}
