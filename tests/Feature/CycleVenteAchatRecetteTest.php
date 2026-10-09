<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\B2bNegotiation;
use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Recette du cycle Devis → Bon de commande → Bon de livraison → Facture, et
 * du cycle achat Demande de prix → Bon de commande → Réception → Facture.
 *
 * Tout passe par les vraies routes HTTP. Écrites lors de la recette du
 * 08/10/2026, où vingt-cinq d'entre elles constataient un défaut ; corrigés
 * le jour même, elles sont devenues l'épreuve permanente du cycle. Chacune
 * énonce la règle qu'elle tient.
 */
class CycleVenteAchatRecetteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;
    private Utilisateur $chauffeur;
    private Client $client;
    private Fournisseur $fournisseur;
    private Fournisseur $fournisseurSansNcc;
    private Produit $ciment;
    private Produit $fer;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom' => 'Quincaillerie de la lagune', 'regime_imposition' => 'RNI', 'adresse' => 'Treichville',
            'rccm' => 'CI-ABJ-2026-B-00777', 'ncc' => '2607777Z', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'points_de_vente'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Magasin', 'ville' => 'Abidjan', 'commune' => 'Treichville']);
        $this->admin = Utilisateur::create([
            'nom' => 'Traoré', 'prenom' => 'Awa', 'email' => 'awa-recette@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
        $this->chauffeur = Utilisateur::create([
            'nom' => 'Bamba', 'prenom' => 'Issa', 'email' => 'issa-recette@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'caissier',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);

        $this->ciment = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'CIM-01', 'nom' => 'Ciment',
            'type' => 'marchandise', 'unite' => 'sac', 'prix_achat' => 5000, 'prix_vente' => 6500, 'taux_tva' => 18,
        ]);
        $this->fer = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'FER-01', 'nom' => 'Fer à béton',
            'type' => 'marchandise', 'unite' => 'barre', 'prix_achat' => 1500, 'prix_vente' => 2000, 'taux_tva' => 18,
        ]);
        Stock::create(['produit_id' => $this->ciment->id, 'point_de_vente_id' => $this->site->id, 'quantite_disponible' => 100]);
        Stock::create(['produit_id' => $this->fer->id, 'point_de_vente_id' => $this->site->id, 'quantite_disponible' => 50]);

        $this->client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'BTP Yopougon', 'adresse' => 'Yopougon']);
        $this->fournisseur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Cimenterie du Sud', 'ncc' => '1900001A']);
        $this->fournisseurSansNcc = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Ferrailleur du marché']);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    // ═════════════════════════ Outils ═════════════════════════

    private function stock(Produit $p): float
    {
        return (float) $p->fresh()->stockActuel($this->site->id);
    }

    /** 10 sacs × 6 500 + 5 barres × 2 000 = 75 000 HT, 13 500 TVA, 88 500 TTC. */
    private function devis(array $extra = []): Vente
    {
        $this->post(route('admin.ventes.enregistrer'), array_merge([
            'client_id' => $this->client->id, 'mode_paiement' => 'Crédit', 'etape' => 'Devis',
            'articles' => [
                ['produit_id' => $this->ciment->id, 'quantite' => 10, 'unite' => 'sac'],
                ['produit_id' => $this->fer->id, 'quantite' => 5, 'unite' => 'barre'],
            ],
        ], $extra))->assertSessionHasNoErrors();

        return Vente::where('etape', 'Devis')->latest('id')->firstOrFail();
    }

    private function commandeDepuis(Vente $devis): Vente
    {
        $this->post(route('admin.ventes.convertir.commande', $devis))->assertSessionHasNoErrors();

        return Vente::findOrFail($devis->fresh()->converti_en_id);
    }

    private function transport(array $champs = []): array
    {
        return array_merge([
            'adresse_livraison' => 'Chantier Yopougon', 'livreur_type' => 'personnel',
            'livreur_utilisateur_id' => $this->chauffeur->id, 'vehicule' => '4521 GH 01',
            'heure_depart' => now()->format('Y-m-d H:i'),
        ], $champs);
    }

    /** Le BL des ventes, quantités livrées par produit. */
    private function livrer(Vente $bc, array $qtes)
    {
        $lignes = [];
        foreach ($bc->details()->with('produit')->get() as $d) {
            $lignes[] = [
                'produit_id' => $d->produit_id, 'qte_commandee' => $d->quantite,
                'qte_livree' => $qtes[$d->produit_id] ?? 0,
                'libelle' => $d->produit->nom, 'unite' => $d->unite,
            ];
        }

        return $this->post(route('admin.ventes.livraison.enregistrer', $bc), $this->transport([
            'date_livraison' => now()->toDateString(), 'lignes' => $lignes,
        ]));
    }

    private function arrivee(BonLivraison $bl)
    {
        return $this->post(route('admin.ventes.livraison.livrer', $bl), [
            'heure_arrivee' => now()->addHour()->format('Y-m-d H:i'), 'receptionnaire_nom' => 'Chef de chantier',
            'receptionnaire_signature' => self::SIGNATURE, 'observations' => 'RAS',
        ]);
    }

    private function facturerBl(BonLivraison $bl, array $champs = [])
    {
        return $this->post(route('admin.ventes.livraison.facturer', $bl), array_merge([
            'base_facturation' => 'livree', 'mode_paiement' => 'Crédit',
        ], $champs));
    }

    private function ecritures(string $reference): int
    {
        return EcritureComptable::withoutGlobalScopes()->where('reference_document', $reference)->count();
    }

    private function achat(string $etape, array $extra = []): Achat
    {
        $this->post(route('admin.achats.enregistrer'), array_merge([
            'fournisseur_id' => $this->fournisseur->id, 'date_achat' => now()->toDateString(),
            'etape' => $etape, 'mode_paiement' => 'Crédit',
            'articles' => [['produit_id' => $this->ciment->id, 'quantite' => 20, 'prix_unitaire' => 5000, 'unite' => 'sac']],
        ], $extra))->assertSessionHasNoErrors();

        return Achat::latest('id')->firstOrFail();
    }

    // ═════════════════════════ VENTES — le chemin nominal ═════════════════════════

    public function test_cycle_vente_nominal_devis_commande_livraison_facture(): void
    {
        // ── Devis
        $devis = $this->devis();
        $this->assertStringStartsWith('DV-', $devis->numero_facture);
        $this->assertEqualsWithDelta(88500, (float) $devis->montant_ttc, 0.01);
        $this->assertNotNull($devis->date_validite, 'Un devis porte un terme.');
        $this->assertSame(100.0, $this->stock($this->ciment), 'Un devis ne sort rien du stock.');
        $this->get(route('admin.ventes.imprimer', $devis))->assertOk()->assertSee('Confirmer la commande');
        $this->get(route('admin.ventes.factures', ['etape' => 'Devis']))->assertOk()->assertSee($devis->numero_facture);

        // ── Bon de commande
        $bc = $this->commandeDepuis($devis);
        $this->assertStringStartsWith('BC-', $bc->numero_facture);
        $this->assertSame('Bon de commande', $bc->etape);
        $this->assertTrue((bool) $devis->fresh()->archived);
        $this->assertSame($bc->id, $devis->fresh()->converti_en_id);
        $this->assertEqualsWithDelta(88500, (float) $bc->montant_ttc, 0.01, 'Le BC garde les montants du devis.');
        $this->assertSame(2, $bc->details()->count());
        $this->assertSame(100.0, $this->stock($this->ciment), 'Un BC ne sort rien du stock.');
        $this->get(route('admin.ventes.imprimer', $bc))->assertOk()->assertSee('Valider & Facturer', false);
        $this->get(route('admin.ventes.livraison.creer', $bc))->assertOk();

        // ── Bon de livraison (total)
        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5])->assertSessionHasNoErrors();
        $bl = BonLivraison::sole();
        $this->assertStringStartsWith('BL-', $bl->numero_bl);
        $this->assertSame($bc->id, $bl->vente_id);
        $this->assertSame(90.0, $this->stock($this->ciment), 'Le stock sort au BL.');
        $this->assertSame(45.0, $this->stock($this->fer));
        $this->assertSame('En livraison', $bc->fresh()->statut);
        $this->get(route('admin.ventes.livraison.voir', $bl))->assertOk()->assertSee('Marquer Livré')->assertSee('→ Facturer', false);

        $this->arrivee($bl)->assertSessionHasNoErrors();
        $this->assertSame('livre', $bl->fresh()->statut);
        $this->get(route('admin.ventes.livraison.voir', $bl))->assertOk()->assertDontSee('Marquer Livré');

        // ── Facture depuis le BL
        $mouvementsAvant = MouvementStock::count();
        $this->facturerBl($bl)->assertSessionHasNoErrors();
        $facture = Vente::where('etape', 'Facture')->sole();
        $this->assertStringStartsWith('VTE-', $facture->numero_facture);
        $this->assertSame($bl->id, $facture->bon_livraison_id);
        $this->assertSame($facture->id, $bl->fresh()->facture_vente_id);
        $this->assertSame('facture', $bl->fresh()->statut);
        $this->assertEqualsWithDelta(88500, (float) $facture->montant_ttc, 0.01, 'La facture garde le montant du BC.');
        $this->assertSame($mouvementsAvant, MouvementStock::count(), 'La facture issue d\'un BL ne ressort pas le stock.');
        $this->assertSame(90.0, $this->stock($this->ciment));
        $this->assertGreaterThan(0, $this->ecritures($facture->numero_facture), 'La facture est passée en comptabilité.');

        $this->get(route('admin.ventes.imprimer', $facture))->assertOk();
        $this->get(route('admin.ventes.pdf', $facture))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('admin.ventes.ticket', $facture))->assertOk();
        $this->get(route('admin.ventes.livraison.voir', $bl))->assertOk()->assertDontSee('→ Facturer', false);
        $this->get(route('admin.ventes.factures', ['etape' => 'Bon de livraison']))->assertOk()->assertSee($bl->numero_bl);
        $this->get(route('admin.ventes.factures', ['etape' => 'Facture']))->assertOk()->assertSee($facture->numero_facture);
    }

    public function test_un_devis_en_especes_sans_acompte_n_est_ni_paye_ni_encaisse(): void
    {
        // Recette du 08/10/2026 : le mode « Espèces » par défaut faisait d'un
        // devis une pièce « Payé », avec un acompte de tout le montant en caisse.
        $devis = $this->devis(['mode_paiement' => 'Espèces']);

        $this->assertSame('Brouillon', $devis->statut);
        $this->assertSame(0, \App\Modules\Admin\Modeles\TresorerieJournal::where('reference_document', $devis->numero_facture)->count());

        $bc = $this->commandeDepuis($devis);
        $this->assertNotSame('Payé', $bc->statut);
    }

    public function test_un_acompte_saisi_sur_un_devis_est_encaisse_tel_quel(): void
    {
        $devis = $this->devis(['mode_paiement' => 'Espèces', 'montant_paye' => 5000]);

        $this->assertSame('Avance', $devis->statut);
        $this->assertEqualsWithDelta(5000, (float) \App\Modules\Admin\Modeles\TresorerieJournal::where('reference_document', $devis->numero_facture)->sum('montant_entree'), 0.01);
    }

    public function test_un_devis_ne_se_convertit_pas_deux_fois(): void
    {
        $devis = $this->devis();
        $this->commandeDepuis($devis);

        $this->post(route('admin.ventes.convertir.commande', $devis))->assertSessionHas('erreur');
        $this->post(route('admin.ventes.confirmer', $devis))->assertSessionHas('erreur');
        $this->assertSame(1, Vente::where('etape', 'Bon de commande')->count());

        // Le devis converti est figé, et sa page ne propose plus la conversion.
        $this->get(route('admin.ventes.modifier', $devis))->assertForbidden();
        $this->get(route('admin.ventes.imprimer', $devis))->assertOk()->assertDontSee('Confirmer la commande');
    }

    public function test_un_bl_facture_ne_se_refacture_pas(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5]);
        $bl = BonLivraison::sole();
        $this->facturerBl($bl);

        $this->facturerBl($bl)->assertSessionHas('erreur');
        $this->assertSame(1, Vente::where('etape', 'Facture')->count());
    }

    public function test_un_bl_n_est_pas_demande_sur_un_devis_ni_une_facture(): void
    {
        $devis = $this->devis();
        $this->get(route('admin.ventes.livraison.creer', $devis))->assertSessionHas('erreur');
        $this->post(route('admin.ventes.livraison.enregistrer', $devis), [])->assertSessionHas('erreur');
        $this->assertSame(0, BonLivraison::count());
    }

    public function test_la_page_d_une_facture_n_offre_ni_modifier_ni_annuler_ni_avoir_direct(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5]);
        $this->facturerBl(BonLivraison::sole());
        $facture = Vente::where('etape', 'Facture')->sole();

        $this->get(route('admin.ventes.imprimer', $facture))->assertOk()
            ->assertDontSee(route('admin.ventes.modifier', $facture))
            ->assertDontSee('Valider & Facturer', false)
            ->assertDontSee('Confirmer la commande');

        $this->get(route('admin.ventes.factures', ['etape' => 'Facture']))->assertOk()
            ->assertDontSee(route('admin.ventes.modifier', $facture));
    }

    // ═════════════════════════ VENTES — les règles du cycle ═════════════════════════

    /** « Valider & Facturer » sur un BC déjà livré ne fait sortir que le reste à livrer. */
    public function test_facturer_un_bc_deja_livre_ne_ressort_pas_le_stock(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 6, $this->fer->id => 5])->assertSessionHasNoErrors();
        $this->assertSame(94.0, $this->stock($this->ciment));

        // Le bouton « Valider & Facturer » de la page du BC.
        $this->post(route('admin.ventes.facturer', $bc));

        $this->assertSame(90.0, $this->stock($this->ciment),
            'Seuls les 4 sacs non livrés devaient sortir ; tout le BC est ressorti.');
        $this->assertSame(45.0, $this->stock($this->fer), 'Le fer, déjà livré en entier, ressort une seconde fois.');
    }

    /** Un BC facturé par son BL est clos : ni « → Facture » ni « Valider & Facturer » ne le refacturent. */
    public function test_un_bc_deja_facture_par_son_bl_ne_se_refacture_pas(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5]);
        $this->facturerBl(BonLivraison::sole())->assertSessionHasNoErrors();
        $this->assertSame(1, Vente::where('etape', 'Facture')->count());

        $this->post(route('admin.ventes.convertir.facture', $bc));
        $this->post(route('admin.ventes.facturer', $bc->fresh()));

        $factures = Vente::where('etape', 'Facture')->pluck('numero_facture')->all();
        $this->assertCount(1, $factures,
            'Le client est facturé plusieurs fois pour la même commande : ' . implode(', ', $factures));
        $this->assertSame(90.0, $this->stock($this->ciment));
    }

    /** Un BC livré (et facturé) est figé : il ne se modifie plus. */
    public function test_un_bc_livre_ne_se_modifie_plus(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5]);
        $this->facturerBl(BonLivraison::sole());

        $this->get(route('admin.ventes.modifier', $bc))->assertForbidden();
    }

    /** « Valider & Facturer » établit une facture nouvelle, numérotée VTE- ; le BC reste lui-même. */
    public function test_facturer_un_bc_produit_une_facture_numerotee_vte(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->post(route('admin.ventes.facturer', $bc));

        $facture = Vente::where('etape', 'Facture')->sole();
        $this->assertStringStartsWith('VTE-', $facture->numero_facture,
            'La facture porte le numéro du bon de commande : ' . $facture->numero_facture);
        $this->assertNotSame($bc->id, $facture->id, 'Le BC disparaît : il est devenu la facture.');
    }

    /** « Confirmer la commande » (page du devis) produit un BC numéroté BC-. */
    public function test_confirmer_un_devis_produit_un_bc_numerote_bc(): void
    {
        $devis = $this->devis();
        $this->post(route('admin.ventes.confirmer', $devis))->assertSessionHasNoErrors();

        $bc = Vente::where('etape', 'Bon de commande')->sole();
        $this->assertStringStartsWith('BC-', $bc->numero_facture,
            'Le bon de commande garde le numéro du devis : ' . $bc->numero_facture);
    }

    /** Un avoir ne s'établit que sur une facture : ni sur un devis, ni sur un BC. */
    public function test_un_avoir_est_refuse_sur_un_devis_ou_un_bc(): void
    {
        $devis = $this->devis();
        $this->post(route('admin.ventes.avoir', $devis), ['raison' => 'Essai']);

        $bc = $this->commandeDepuis($this->devis());
        $this->post(route('admin.ventes.avoir', $bc), ['raison' => 'Essai']);

        $this->assertSame(100.0, $this->stock($this->ciment), 'Le stock a gonflé de marchandise jamais sortie.');
        $this->assertSame(0, Vente::where('type_facture', 'avoir')->count(), 'Un avoir est né d\'une offre.');
    }

    /** Les pages d'un devis, d'un BC et d'un BL ne portent pas le formulaire d'avoir. */
    public function test_les_pages_hors_facture_ne_portent_pas_le_formulaire_d_avoir(): void
    {
        $devis = $this->devis();
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5]);
        $bl = BonLivraison::sole();

        $this->get(route('admin.ventes.imprimer', $devis))->assertDontSee(route('admin.ventes.avoir', $devis));
        $this->get(route('admin.ventes.imprimer', $bc))->assertDontSee(route('admin.ventes.avoir', $bc));
        $this->get(route('admin.ventes.livraison.voir', $bl))->assertDontSee(route('admin.ventes.avoir', $bc));
    }

    /** Une livraison partielle se solde par un second BL, plafonné au reste. */
    public function test_une_livraison_partielle_se_solde_par_un_second_bl(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 6, $this->fer->id => 5])->assertSessionHasNoErrors();
        $this->assertSame('Partiel', $bc->fresh()->statut);
        $this->assertSame(94.0, $this->stock($this->ciment));

        $this->livrer($bc->fresh(), [$this->ciment->id => 4]);

        $this->assertSame(2, BonLivraison::count(), 'Le solde de 4 sacs ne peut pas être livré : un seul BL par BC.');
        $this->assertSame(90.0, $this->stock($this->ciment));
    }

    /** Le solde par la file du stock ne change que le statut logistique : le BC reste un BC. */
    public function test_le_solde_par_la_file_du_stock_ne_transforme_pas_le_bc_en_facture_payee(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 6, $this->fer->id => 5])->assertSessionHasNoErrors();

        $ligneCiment = $bc->details()->where('produit_id', $this->ciment->id)->first();
        $this->post(route('admin.stock.livraisons.valider', $bc), $this->transport([
            'livraison' => [$ligneCiment->id => 4],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(90.0, $this->stock($this->ciment), 'Le solde sort bien une fois.');
        $bc->refresh();
        $this->assertSame('Bon de commande', $bc->etape,
            "Le BC {$bc->numero_facture} est passé en « {$bc->etape} / {$bc->statut} » sans paiement, sans écriture, sans numéro VTE-.");
    }

    /** La facture issue d'un BL garde le montant du BC, remise globale comprise. */
    public function test_la_facture_issue_du_bl_garde_le_montant_remise_comprise(): void
    {
        $bc = $this->commandeDepuis($this->devis(['remise_taux' => 10]));
        $ttcBc = (float) $bc->montant_ttc;
        $this->assertEqualsWithDelta(79650, $ttcBc, 0.01, '75 000 − 10 % = 67 500 HT + 18 % = 79 650 TTC.');

        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5]);
        $this->facturerBl(BonLivraison::sole())->assertSessionHasNoErrors();

        $facture = Vente::where('etape', 'Facture')->sole();
        $this->assertEqualsWithDelta($ttcBc, (float) $facture->montant_ttc, 0.01,
            'La facture du BL vaut ' . $facture->montant_ttc . ' pour un BC de ' . $ttcBc);
    }

    /** Un BL partiel propose le montant de sa propre facture, et n'encaisse jamais plus qu'elle. */
    public function test_facturer_un_bl_partiel_n_encaisse_pas_plus_que_la_facture(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 6, $this->fer->id => 5]);
        $bl = BonLivraison::sole();

        // La modale propose le montant de la facture de CE bon (6 sacs + 5
        // barres = 49 000 HT, 57 820 TTC), et non le TTC du BC entier.
        $this->get(route('admin.ventes.livraison.voir', $bl))
            ->assertSee('value="57820"', false)
            ->assertDontSee('value="88500"', false);
        // Même si l'on tend le TTC du BC entier, la caisse n'encaisse que le dû.
        $this->facturerBl($bl, ['mode_paiement' => 'Caisse', 'montant_paye' => 88500])->assertSessionHasNoErrors();

        $facture = Vente::where('etape', 'Facture')->sole();
        $encaisse = (float) TresorerieJournal::where('reference_document', $facture->numero_facture)->sum('montant_entree');
        $this->assertLessThanOrEqual((float) $facture->netAPayer() + 0.01, $encaisse,
            "Facture de {$facture->montant_ttc} F, caisse créditée de {$encaisse} F.");
    }

    /** Le BL des ventes est borné au reste à livrer, et accepte les décimales. */
    public function test_le_bl_des_ventes_borne_les_quantites_au_commande(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 25, $this->fer->id => 5]);

        $this->assertSame(0, BonLivraison::count(), '25 sacs livrés pour 10 commandés.');
        $this->assertSame(100.0, $this->stock($this->ciment));
    }

    public function test_le_bl_des_ventes_accepte_une_quantite_decimale(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 2.5, $this->fer->id => 5])
            ->assertSessionHasNoErrors();
    }

    /** « → Facture » est une vraie facturation : aux prix de la commande, passée en comptabilité. */
    public function test_la_facture_issue_d_un_bc_est_passee_en_comptabilite(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->post(route('admin.ventes.convertir.facture', $bc))->assertRedirect();

        $facture = Vente::where('etape', 'Facture')->sole();
        $this->assertStringStartsWith('VTE-', $facture->numero_facture);
        $this->assertEqualsWithDelta(88500, (float) $facture->montant_ttc, 0.01);

        // L'utilisateur finalise la facture dans l'écran où il est renvoyé.
        $this->put(route('admin.ventes.modifier.enregistrer', $facture), [
            'client_id' => $this->client->id, 'mode_paiement' => 'Crédit',
            'articles' => [
                ['produit_id' => $this->ciment->id, 'quantite' => 10, 'unite' => 'sac'],
                ['produit_id' => $this->fer->id, 'quantite' => 5, 'unite' => 'barre'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(90.0, $this->stock($this->ciment));
        $this->assertGreaterThan(0, $this->ecritures($facture->numero_facture),
            'Ni la conversion ni la finalisation ne passent la facture en comptabilité.');
    }

    /** La page d'un BL ne propose pas de télécharger le PDF du BC. */
    public function test_le_pdf_de_la_page_d_un_bl_est_celui_du_bl(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5]);
        $bl = BonLivraison::sole();

        $this->get(route('admin.ventes.livraison.voir', $bl))->assertOk()
            ->assertDontSee(route('admin.ventes.pdf', $bc));
    }

    /**
     * Le bouton « Livré » de la liste des BL mène à la page du bon, modale
     * d'arrivée ouverte : l'arrivée se confirme avec l'heure, le réceptionnaire
     * et sa signature (chantier 15.3, TransportLivraisonTest). Il postait un
     * formulaire vide, toujours refusé.
     */
    public function test_le_bouton_livre_de_la_liste_mene_a_la_confirmation_d_arrivee(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5]);
        $bl = BonLivraison::sole();

        $this->get(route('admin.ventes.factures', ['etape' => 'Bon de livraison']))->assertOk()
            ->assertSee(route('admin.ventes.livraison.voir', $bl) . '?arrivee=1', false)
            ->assertDontSee('action="' . route('admin.ventes.livraison.livrer', $bl) . '"', false);

        // La page où il mène porte la modale d'arrivée, qui poste au bon endroit.
        $this->get(route('admin.ventes.livraison.voir', $bl) . '?arrivee=1')->assertOk()
            ->assertSee('modalArriveeLivraison', false)
            ->assertSee(route('admin.ventes.livraison.livrer', $bl), false);

        // Et l'arrivée signée aboutit.
        $this->arrivee($bl)->assertSessionHasNoErrors();
        $this->assertSame('livre', $bl->fresh()->statut);
    }

    /** La liste ne propose « Modifier » que sur une pièce qui se modifie encore. */
    public function test_la_liste_ne_propose_pas_modifier_sur_un_devis_accepte(): void
    {
        $devis = $this->devis();
        $this->post(route('admin.ventes.accepter', $devis), ['accepte_par' => 'M. Koffi'])->assertSessionHasNoErrors();
        $this->get(route('admin.ventes.modifier', $devis))->assertForbidden();

        $this->get(route('admin.ventes.factures', ['etape' => 'Devis']))->assertOk()
            ->assertDontSee(route('admin.ventes.modifier', $devis));
    }

    /** Un BC pour un client inscrit dans Selflow (même NCC) lui est transmis en B2B. */
    public function test_un_bc_pour_un_client_inscrit_part_en_b2b(): void
    {
        $autre = Entreprise::create(['nom' => 'BTP Yopougon SA', 'ncc' => '2609999Y']);
        $this->client->update(['ncc' => '2609999Y']);

        $this->post(route('admin.ventes.enregistrer'), [
            'client_id' => $this->client->id, 'mode_paiement' => 'Crédit', 'etape' => 'Bon de commande',
            'articles' => [['produit_id' => $this->ciment->id, 'quantite' => 3, 'unite' => 'sac']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, B2bNegotiation::where('entreprise_fournisseur_id', $autre->id)->count());
    }

    /** Le titre de la page dit la nature de la pièce. */
    public function test_le_titre_de_la_page_dit_la_nature_de_la_piece(): void
    {
        $devis = $this->devis();
        $bc = $this->commandeDepuis($this->devis());

        $this->get(route('admin.ventes.imprimer', $devis))->assertDontSee('<title>Facture ' . $devis->numero_facture, false);
        $this->get(route('admin.ventes.imprimer', $bc))->assertDontSee('<title>Facture ' . $bc->numero_facture, false);
    }

    /** Le ticket n'imprime que le BL de cette vente, jamais celui d'une autre entreprise. */
    public function test_le_ticket_ne_montre_pas_le_bl_d_une_autre_entreprise(): void
    {
        $autre = Entreprise::create(['nom' => 'Concurrent']);
        $siteAutre = PointDeVente::create(['entreprise_id' => $autre->id, 'nom' => 'Ailleurs', 'ville' => 'Bouaké', 'commune' => 'Centre']);
        $venteAutre = Vente::create([
            'point_de_vente_id' => $siteAutre->id, 'numero_facture' => 'BC-AUTRE-1', 'date_vente' => now()->toDateString(),
            'mode_paiement' => 'Crédit', 'montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0,
            'statut' => 'Brouillon', 'etape' => 'Bon de commande',
        ]);
        $blAutre = BonLivraison::create([
            'numero_bl' => 'BL-SECRET-CONCURRENT', 'vente_id' => $venteAutre->id, 'point_de_vente_id' => $siteAutre->id,
            'date_livraison' => now()->toDateString(), 'statut' => 'en_preparation', 'created_by' => $this->admin->id,
        ]);

        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 10, $this->fer->id => 5]);
        $this->facturerBl(BonLivraison::where('vente_id', $bc->id)->sole());
        $facture = Vente::where('etape', 'Facture')->sole();

        $this->get(route('admin.ventes.ticket', $facture) . '?bl=' . $blAutre->id)
            ->assertDontSee('BL-SECRET-CONCURRENT');
    }

    /** Deux BL facturés chacun : deux factures, et le BC se clôt avec la dernière. */
    public function test_une_commande_livree_en_deux_fois_se_facture_par_ses_deux_bons(): void
    {
        $bc = $this->commandeDepuis($this->devis(['remise_taux' => 10]));
        $this->livrer($bc, [$this->ciment->id => 6, $this->fer->id => 5])->assertSessionHasNoErrors();
        $bl1 = BonLivraison::sole();
        $this->facturerBl($bl1)->assertSessionHasNoErrors();
        $this->assertFalse((bool) $bc->fresh()->archived, 'Il reste 4 sacs à livrer : la commande reste ouverte.');

        // Facturer en bloc reprendrait ce qui figure déjà sur la première facture.
        $this->post(route('admin.ventes.facturer', $bc->fresh()))->assertSessionHas('erreur');

        $this->livrer($bc->fresh(), [$this->ciment->id => 4])->assertSessionHasNoErrors();
        $bl2 = BonLivraison::where('id', '!=', $bl1->id)->sole();
        $this->facturerBl($bl2)->assertSessionHasNoErrors();

        $factures = Vente::where('etape', 'Facture')->get();
        $this->assertCount(2, $factures);
        $this->assertEqualsWithDelta((float) $bc->montant_ttc, (float) $factures->sum('montant_ttc'), 0.05,
            'Les deux factures font ensemble le montant de la commande, remise comprise.');
        $this->assertTrue((bool) $bc->fresh()->archived);
        $this->assertSame(90.0, $this->stock($this->ciment));
        $this->assertSame(1, $this->ecritures($factures->first()->numero_facture) > 0 ? 1 : 0);
    }

    /** « Qtés BC d'origine » facture toute la commande et fait partir le reste à l'instant. */
    public function test_facturer_un_bl_sur_les_quantites_commandees_expedie_le_reste(): void
    {
        $bc = $this->commandeDepuis($this->devis());
        $this->livrer($bc, [$this->ciment->id => 6, $this->fer->id => 5]);

        $this->facturerBl(BonLivraison::sole(), ['base_facturation' => 'commandee'])->assertSessionHasNoErrors();

        $facture = Vente::where('etape', 'Facture')->sole();
        $this->assertEqualsWithDelta(88500, (float) $facture->montant_ttc, 0.01);
        $this->assertSame(90.0, $this->stock($this->ciment), 'Les 4 sacs restants sortent, une fois.');
        $this->assertSame($facture->id, $bc->fresh()->converti_en_id);
    }

    /** Le caissier habilité aux factures se sert des boutons de la page d'un devis et d'une commande. */
    public function test_les_boutons_du_devis_et_de_la_commande_suivent_l_espace_du_caissier(): void
    {
        $devis = $this->devis();
        $bc = $this->commandeDepuis($this->devis());

        $this->chauffeur->update(['habilitations' => ['nouvelle_vente', 'factures_vente']]);
        $this->actingAs($this->chauffeur->fresh());

        $this->get(route('caissier.ventes.imprimer', $devis))->assertOk()
            ->assertSee(route('caissier.ventes.confirmer', $devis), false)
            ->assertDontSee(route('admin.ventes.confirmer', $devis), false);
        $this->get(route('caissier.ventes.imprimer', $bc))->assertOk()
            ->assertSee(route('caissier.ventes.facturer', $bc), false);

        // Et le caissier s'en sert.
        $this->post(route('caissier.ventes.confirmer', $devis))->assertSessionHasNoErrors();
        $this->assertSame(2, Vente::where('etape', 'Bon de commande')->count());
    }

    // ═════════════════════════ ACHATS — le chemin nominal ═════════════════════════

    public function test_cycle_achat_nominal_demande_commande_facture(): void
    {
        $dp = $this->achat('Demande de prix');
        $this->assertStringStartsWith('DP-', $dp->numero_facture);
        $this->assertSame(100.0, $this->stock($this->ciment));
        $this->get(route('admin.achats.imprimer', $dp))->assertOk()->assertSee('Confirmer la commande');
        $this->get(route('admin.achats.factures', ['etape' => 'Demande de prix']))->assertOk()->assertSee($dp->numero_facture);

        $this->post(route('admin.achats.confirmer', $dp))->assertSessionHasNoErrors();
        $bc = $dp->fresh();
        $this->assertSame('Bon de commande', $bc->etape);
        $this->assertSame(100.0, $this->stock($this->ciment), 'Un BC fournisseur ne fait rien entrer.');
        $this->get(route('admin.achats.imprimer', $bc))->assertOk()->assertSee('Valider & Facturer', false);
        $this->get(route('admin.stock.receptions'))->assertOk()->assertSee($bc->numero_facture);

        $this->post(route('admin.achats.facturer', $bc))->assertSessionHasNoErrors();
        $facture = $bc->fresh();
        $this->assertSame('Facture', $facture->etape);
        $this->assertSame('Crédit', $facture->statut);
        $this->assertSame(120.0, $this->stock($this->ciment), 'La facture fait entrer les 20 sacs, une fois.');
        $this->assertEqualsWithDelta(118000, (float) $facture->montant_ttc, 0.01);
        $this->assertGreaterThan(0, $this->ecritures($facture->numero_facture));

        // Ni deuxième facturation, ni retour à la confirmation.
        $this->post(route('admin.achats.facturer', $facture))->assertSessionHas('info');
        $this->post(route('admin.achats.confirmer', $facture))->assertSessionHas('info');
        $this->assertSame(120.0, $this->stock($this->ciment));
        $this->get(route('admin.stock.receptions'))->assertOk()->assertDontSee($facture->numero_facture);

        $this->get(route('admin.achats.imprimer', $facture))->assertOk()->assertDontSee('Valider & Facturer', false);
        $this->get(route('admin.achats.pdf', $facture))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_une_reception_partielle_par_la_file_du_stock(): void
    {
        $bc = $this->achat('Bon de commande');
        $ligne = $bc->details()->first();

        $this->post(route('admin.stock.receptions.valider', $bc), ['reception' => [$ligne->id => 8]])->assertSessionHasNoErrors();
        $this->assertSame(108.0, $this->stock($this->ciment));
        $this->assertSame('Bon de commande', $bc->fresh()->etape);

        // Recevoir plus que le reste est refusé.
        $this->expectException(\InvalidArgumentException::class);
        $this->withoutExceptionHandling()->post(route('admin.stock.receptions.valider', $bc), ['reception' => [$ligne->id => 13]]);
    }

    // ═════════════════════════ ACHATS — les règles du cycle ═════════════════════════

    /** Le numéro d'une pièce d'achat suit son étape : DP-, puis BCF-, puis ACH-. */
    public function test_la_facture_d_achat_porte_un_numero_ach(): void
    {
        $dp = $this->achat('Demande de prix');
        $this->post(route('admin.achats.confirmer', $dp));
        $this->assertStringStartsWith('BCF-', $dp->fresh()->numero_facture, 'Le BC garde le numéro DP-.');

        $this->post(route('admin.achats.facturer', $dp->fresh()));
        $this->assertStringStartsWith('ACH-', $dp->fresh()->numero_facture, 'La facture garde le numéro DP-.');
    }

    /** Facturer après une réception partielle n'entre que le reste à recevoir. */
    public function test_facturer_apres_reception_partielle_n_entre_pas_deux_fois(): void
    {
        $bc = $this->achat('Bon de commande');
        $ligne = $bc->details()->first();
        $this->post(route('admin.stock.receptions.valider', $bc), ['reception' => [$ligne->id => 8]]);
        $this->assertSame(108.0, $this->stock($this->ciment));

        $this->post(route('admin.achats.facturer', $bc->fresh()));

        $this->assertSame(120.0, $this->stock($this->ciment), '8 + 20 sacs entrés pour 20 commandés.');
    }

    /** Une réception complète ne vaut pas facture, ni paiement. */
    public function test_la_reception_complete_ne_vaut_pas_facture_payee(): void
    {
        $bc = $this->achat('Bon de commande');
        $ligne = $bc->details()->first();
        $this->post(route('admin.stock.receptions.valider', $bc), ['reception' => [$ligne->id => 20]])->assertSessionHasNoErrors();

        $bc->refresh();
        $this->assertSame(120.0, $this->stock($this->ciment));
        $this->assertFalse($bc->etape === 'Facture' && $this->ecritures($bc->numero_facture) === 0,
            "Le BC est devenu « {$bc->etape} / {$bc->statut} » sans écriture comptable ni décaissement.");
        $this->assertNotSame('Payé', $bc->statut, 'Une commande à crédit est déclarée payée.');
    }

    /** Une commande facturée sans règlement saisi part à crédit : rien n'est décaissé. */
    public function test_un_bc_sans_mode_de_paiement_n_est_pas_facture_paye(): void
    {
        $this->post(route('admin.achats.enregistrer'), [
            'fournisseur_id' => $this->fournisseur->id, 'date_achat' => now()->toDateString(), 'etape' => 'Bon de commande',
            'articles' => [['produit_id' => $this->ciment->id, 'quantite' => 2, 'prix_unitaire' => 5000]],
        ])->assertSessionHasNoErrors();
        $bc = Achat::sole();
        $this->post(route('admin.achats.facturer', $bc));

        $this->assertSame(0.0, (float) TresorerieJournal::where('reference_document', $bc->fresh()->numero_facture)->sum('montant_sortie'),
            'La caisse est décaissée d\'un paiement que personne n\'a saisi (mode « Caisse » par défaut).');
    }

    /** Le règlement saisi à « Valider & Facturer » est décaissé, jamais plus que le dû. */
    public function test_facturer_une_commande_d_achat_avec_reglement(): void
    {
        $bc = $this->achat('Bon de commande');
        $this->post(route('admin.achats.facturer', $bc), ['mode_paiement' => 'Caisse', 'montant_paye' => 500000])
            ->assertSessionHasNoErrors();

        $facture = $bc->fresh();
        $this->assertSame('Payé', $facture->statut);
        $this->assertEqualsWithDelta(118000, (float) TresorerieJournal::where('reference_document', $facture->numero_facture)->sum('montant_sortie'), 0.01);
    }

    /** Le format BAPA n'est offert que pour un bordereau établi, jamais sur une demande de prix. */
    public function test_le_format_bapa_n_est_pas_offert_sur_une_demande_de_prix(): void
    {
        $dp = $this->achat('Demande de prix', ['fournisseur_id' => $this->fournisseurSansNcc->id]);

        $this->get(route('admin.achats.bapa', $dp))->assertNotFound();
        $this->get(route('admin.achats.imprimer', $dp))->assertOk()
            ->assertDontSee(route('admin.achats.bapa', $dp));
    }
}
