<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'avoir d'un bordereau d'achat aux producteurs agricoles est fermé.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Pourquoi
 * ─────────────────────────────────────────────────────────────────────────
 *
 * **La DGI ne normalise pas l'avoir d'un BAPA.** Selflow le proposait quand
 * même : la pièce se serait établie dans les livres, avec son numéro, ses
 * écritures et son mouvement de stock, et rien ne serait jamais parti à la
 * plateforme. Les deux états auraient divergé en silence — le pire des
 * défauts, puisqu'il ne se voit qu'à la révision.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui rendait la fermeture incomplète
 * ─────────────────────────────────────────────────────────────────────────
 *
 * **Deux chemins mènent au bordereau, et un seul se lit dans
 * `type_facture`.** Le second est `validerFacture()` : une facture d'achat
 * ordinaire dont le fournisseur n'a pas de NCC part elle aussi en
 * normalisation BAPA. Fermer sur le seul `type_facture === 'bapa'` aurait
 * laissé passer tous les achats auprès d'un vendeur non immatriculé, c'est-à-
 * dire le cas le plus courant. `Achat::estBapa()` tient les deux ensemble.
 *
 * Et la fermeture est posée **au contrôleur**, pas seulement à l'écran :
 * masquer un bouton ne ferme pas une route, qui reste atteignable à la main.
 */
class AvoirDeBapaTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Coopérative du Bélier', 'regime_imposition' => 'RNI',
            'adresse' => 'Yamoussoukro', 'rccm' => 'CI-YAM-2026-B-00007',
            'ncc' => '2601234A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'tiers'],
            'bapa' => true,
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Magasin de Yamoussoukro', 'ville' => 'Yamoussoukro', 'commune' => 'Centre',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Koffi', 'prenom' => 'Adjoua', 'email' => 'adjoua-bapa@coop.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    /**
     * Une pièce d'achat.
     *
     * `$ncc` porte toute la différence : un fournisseur immatriculé donne une
     * facture d'achat ordinaire, un vendeur sans NCC donne un bordereau.
     */
    private function unAchat(?string $ncc, string $numero, string $type = 'normale'): Achat
    {
        $fournisseur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => $ncc ? 'Grossiste CI' : 'Planteur Yao N\'Guessan',
            'ncc' => $ncc,
        ]);

        return Achat::create([
            'point_de_vente_id' => $this->site->id,
            'fournisseur_id'    => $fournisseur->id,
            'numero_facture'    => $numero,
            'type_facture'      => $type,
            'etape'             => 'Facture',
            'montant_ttc'       => 250000,
            'montant_ht'        => 250000,
            'montant_tva'       => 0,
            'mode_paiement'     => 'Espèces',
            'date_achat'        => now(),
        ]);
    }

    // ── Le critère, qui tient les deux chemins ───────────────────────

    public function test_le_type_declare_designe_un_bordereau(): void
    {
        $this->assertTrue($this->unAchat('2601234A', 'BA-2026-0001', 'bapa')->estBapa());
    }

    public function test_un_fournisseur_sans_ncc_designe_aussi_un_bordereau(): void
    {
        // C'est le chemin que `type_facture` ne dit pas : `validerFacture()`
        // déclenche la normalisation BAPA sur ce seul critère.
        $this->assertTrue($this->unAchat(null, 'ACH-2026-0002')->estBapa());
    }

    public function test_une_facture_d_achat_ordinaire_n_est_pas_un_bordereau(): void
    {
        $this->assertFalse($this->unAchat('2601234A', 'ACH-2026-0003')->estBapa());
    }

    // ── Les deux listes de choix ─────────────────────────────────────

    public function test_la_liste_deroulante_n_offre_aucun_bordereau(): void
    {
        $bordereau = $this->unAchat(null, 'BA-2026-0004', 'bapa');
        $ordinaire = $this->unAchat('2601234A', 'ACH-2026-0005');

        $corps = $this->get(route('admin.achats.factures', ['type' => 'avoir']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="' . $ordinaire->uuid . '"', $corps);
        $this->assertStringNotContainsString('value="' . $bordereau->uuid . '"', $corps);
    }

    public function test_la_recherche_n_offre_aucun_bordereau(): void
    {
        // Le même écran porte deux façons de choisir la pièce. En fermer une
        // seule laisse l'autre ouverte : c'est ce qui avait laissé vivre le
        // défaut d'identifiant du lot précédent, sur ce même écran.
        $this->unAchat(null, 'BA-2026-0006', 'bapa');
        $ordinaire = $this->unAchat('2601234A', 'ACH-2026-0007');

        $reponse = $this->getJson(route('admin.achats.factures.rechercher', ['q' => '2026']))
            ->assertOk();

        $reponse->assertJsonFragment(['id' => $ordinaire->uuid]);
        $reponse->assertJsonMissing(['id' => 'BA-2026-0006']);
        $this->assertStringNotContainsString('BA-2026-0006', $reponse->getContent());
    }

    // ── La route, que masquer un bouton ne ferme pas ─────────────────

    public function test_le_detail_d_un_bordereau_est_refuse(): void
    {
        $bordereau = $this->unAchat(null, 'BA-2026-0008', 'bapa');

        $this->getJson(route('admin.achats.factures.details', $bordereau->uuid))
            ->assertStatus(400);
    }

    public function test_l_avoir_direct_sur_un_bordereau_est_refuse(): void
    {
        $bordereau = $this->unAchat(null, 'BA-2026-0009', 'bapa');

        $this->post(route('admin.achats.avoir', $bordereau), ['raison' => 'Retour de marchandise'])
            ->assertStatus(400);

        $this->assertSame(0, Achat::where('type_facture', 'avoir')->count());
    }

    public function test_l_avoir_sur_une_facture_d_un_vendeur_sans_ncc_est_refuse(): void
    {
        // Le cas que `type_facture` ne trahit pas — et le plus courant.
        $bordereau = $this->unAchat(null, 'ACH-2026-0010');

        $this->post(route('admin.achats.avoir', $bordereau), ['raison' => 'Retour de marchandise'])
            ->assertStatus(400);

        $this->assertSame(0, Achat::where('type_facture', 'avoir')->count());
    }

    public function test_l_avoir_detaille_sur_un_bordereau_est_refuse(): void
    {
        $bordereau = $this->unAchat(null, 'BA-2026-0011', 'bapa');

        $this->post(route('admin.achats.avoir.creer_nouveau'), [
            'parent_id' => $bordereau->uuid,
            'raison'    => 'Retour de marchandise',
            'items'     => [['detail_id' => 1, 'quantite' => 1, 'prix_unitaire' => 1000]],
        ])->assertStatus(400);

        $this->assertSame(0, Achat::where('type_facture', 'avoir')->count());
    }

    // ── Ce qui reste ouvert ──────────────────────────────────────────

    public function test_l_avoir_reste_possible_sur_une_facture_d_achat_ordinaire(): void
    {
        // La fermeture vise les bordereaux, et eux seuls : un avoir
        // fournisseur ordinaire doit continuer de s'établir.
        $facture = $this->unAchat('2601234A', 'ACH-2026-0012');

        $this->post(route('admin.achats.avoir', $facture), ['raison' => 'Article défectueux'])
            ->assertRedirect();

        $this->assertSame(1, Achat::where('type_facture', 'avoir')->count());
    }

    public function test_l_ecran_d_une_piece_de_bordereau_n_offre_pas_l_avoir(): void
    {
        $bordereau = $this->unAchat(null, 'BA-2026-0013', 'bapa');

        $corps = $this->get(route('admin.achats.imprimer', $bordereau))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('id="modalAvoir"', $corps);
    }
}
