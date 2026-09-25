<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Choisir la pièce d'origine d'un avoir.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Le défaut
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Les adresses de l'application portent l'`uuid` depuis le lot 8.3, pour ne
 * pas publier le nombre de pièces de la plateforme. La liste déroulante de
 * l'avoir rendait pourtant `$f->id` — le numéro de ligne :
 *
 *     GET /admin/ventes/facture-details/169  →  404 (Not Found — introuvable)
 *     SyntaxError: Unexpected token '<', "<!DOCTYPE "... is not valid JSON
 *
 * Le second message découle du premier : le script lisait la réponse en JSON
 * et recevait la page d'erreur en HTML. **Choisir une facture d'origine ne
 * faisait donc rien** — aucun message à l'écran, la modale restait vide, et
 * seule la console du navigateur en disait quelque chose.
 *
 * Et si la requête avait abouti, l'envoi du formulaire aurait échoué au coup
 * d'après : `parent_id` est validé `['required', 'uuid', …]`.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Pourquoi il avait survécu
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Le même écran porte **deux** façons de choisir la pièce : une liste
 * déroulante, et un champ de recherche. Le champ de recherche avait été
 * corrigé — son point d'entrée rend l'`uuid`, et le commentaire du contrôleur
 * décrit exactement ce défaut. La liste, elle, était restée au numéro de
 * ligne. Une moitié réparée cachait l'autre.
 *
 * Le même défaut existait sur l'avoir d'achat, où personne ne l'avait
 * rencontré.
 */
class AvoirChoixDeLaPieceTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'DC-Knowing CGA', 'regime_imposition' => 'RNI',
            'adresse' => 'Cocody, Abidjan', 'rccm' => 'CI-ABJ-2026-B-00042',
            'ncc' => '2601234A', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'tiers'],
        ]);

        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Boutique Angré', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouadio', 'prenom' => 'Lewis', 'email' => 'lewis-avoir@dc-knowing.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function uneFacture(): Vente
    {
        $client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Konan Yao']);

        return Vente::create([
            'point_de_vente_id' => $this->site->id,
            'client_id'      => $client->id,
            'numero_facture' => 'VT-2026-0001',
            'etape'          => 'Facture',
            'montant_ttc'    => 118000,
            'montant_ht'     => 100000,
            'montant_tva'    => 18000,
            'mode_paiement'  => 'Espèces',
            'date_vente'     => now(),
        ]);
    }

    private function unAchat(): Achat
    {
        // Le NCC n'est pas décoratif ici : un fournisseur qui n'en a pas fait
        // de sa facture un bordereau BAPA, et les bordereaux ne sont plus
        // avoirables — la DGI ne normalise pas leur avoir. Voir
        // `AvoirDeBapaTest`.
        $fournisseur = Fournisseur::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Grossiste CI',
            'ncc' => '2609876B',
        ]);

        return Achat::create([
            'point_de_vente_id' => $this->site->id,
            'fournisseur_id' => $fournisseur->id,
            'numero_facture' => 'AC-2026-0001',
            'etape'          => 'Facture',
            'montant_ttc'    => 59000,
            'montant_ht'     => 50000,
            'montant_tva'    => 9000,
            'mode_paiement'  => 'Espèces',
            'date_achat'     => now(),
        ]);
    }

    // ── La liste déroulante ──────────────────────────────────────────

    public function test_la_liste_des_factures_d_origine_porte_l_identifiant_public(): void
    {
        $facture = $this->uneFacture();

        $corps = $this->get(route('admin.ventes.factures', ['type' => 'avoir']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('value="' . $facture->uuid . '"', $corps);
        $this->assertStringNotContainsString('value="' . $facture->id . '">VT-2026-0001', $corps);
    }

    /*
     * `test_la_liste_des_achats_d_origine_porte_l_identifiant_public()` vivait
     * ici. **L'avoir fournisseur a été retiré le 25/09/2026** : un acheteur
     * n'établit pas l'avoir de son fournisseur, la DGI ne le prévoit pas, et
     * la liste déroulante qu'il alimentait n'existe plus.
     *
     * L'avoir CLIENT, lui, reste : c'est le vendeur qui l'établit, et la
     * plateforme le certifie. Les cas qui le concernent sont restés ici.
     */

    // ── Ce que la liste alimente ─────────────────────────────────────

    public function test_le_detail_repond_sur_l_identifiant_que_la_liste_donne(): void
    {
        $facture = $this->uneFacture();

        // C'est le chemin exact que suivait le clic : la valeur de l'option
        // part telle quelle dans l'adresse.
        $this->getJson(route('admin.ventes.factures.details', $facture->uuid))
            ->assertOk()
            ->assertJsonPath('numero_facture', 'VT-2026-0001');
    }

    public function test_le_numero_de_ligne_ne_resout_aucune_facture(): void
    {
        $facture = $this->uneFacture();

        // Ce n'est pas un défaut à corriger : c'est ce que la route doit faire.
        // L'épreuve fixe la raison pour laquelle la liste doit rendre l'`uuid`.
        $this->getJson(route('admin.ventes.factures.details', $facture->id))
            ->assertNotFound();
    }

    public function test_le_detail_ne_traverse_pas_les_entreprises(): void
    {
        $voisine = Entreprise::create(['nom' => 'Voisine SARL']);
        $sonSite = PointDeVente::create([
            'entreprise_id' => $voisine->id,
            'nom' => 'Magasin du voisin', 'ville' => 'Abidjan', 'commune' => 'Treichville',
        ]);
        $saFacture = Vente::create([
            'point_de_vente_id' => $sonSite->id,
            'numero_facture' => 'VT-VOISIN-0001', 'etape' => 'Facture',
            'montant_ttc' => 50000, 'montant_ht' => 42373, 'montant_tva' => 7627,
            'mode_paiement' => 'Espèces', 'date_vente' => now(),
        ]);

        // L'`uuid` ne se devine pas, mais il peut se transmettre : la
        // vérification d'appartenance reste la seule barrière.
        $this->getJson(route('admin.ventes.factures.details', $saFacture->uuid))
            ->assertNotFound();
    }

    public function test_la_recherche_rend_le_meme_identifiant_que_la_liste(): void
    {
        $facture = $this->uneFacture();

        // Les deux façons de choisir la pièce doivent rendre la même chose :
        // c'est leur divergence qui a laissé le défaut vivre.
        $this->getJson(route('admin.ventes.factures.rechercher', ['q' => 'VT-2026']))
            ->assertOk()
            ->assertJsonFragment(['id' => $facture->uuid]);
    }
}
