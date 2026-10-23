<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retour du 18/10/2026 :
 *
 * - la facture s'affiche dès l'enregistrement, sur l'écran de vente, et le
 *   vendeur qui n'a pas « Factures vente » l'imprime quand même — la sienne,
 *   pas celles des autres ;
 * - annuler une facture est un droit à part, une case des habilitations.
 */
class ImpressionDepuisLaVenteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Produit $lavage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Poissonnerie test', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody',
            'rccm' => 'CI-ABJ-2026-B-00077', 'ncc' => '2607777A', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'produits', 'tiers'],
            'normalisation_auto_factures' => false, 'normalisation_auto_recus' => false,
        ]);
        $this->site = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Comptoir', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);
        Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Client comptoir']);
        $this->lavage = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV-001', 'nom' => 'Écaillage',
            'type' => 'service', 'unite' => 'unité', 'prix_achat' => 0, 'prix_vente' => 1000, 'taux_tva' => 18,
        ]);
    }

    private function membre(string $email, array $habilitations, string $role = 'caissier'): Utilisateur
    {
        return Utilisateur::create([
            'nom' => 'Vendeur', 'prenom' => 'Test', 'email' => $email, 'password' => bcrypt('secret-de-test'),
            'role' => $role, 'statut' => 'actif', 'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id, 'habilitations' => $habilitations,
        ]);
    }

    private function vendre(Utilisateur $qui, string $prefixe = 'caissier')
    {
        return $this->actingAs($qui)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->post(route($prefixe . '.ventes.enregistrer'), [
                'client_id' => Client::firstOrFail()->id, 'date_vente' => now()->toDateString(),
                'mode_paiement' => 'Espèces', 'etape' => 'Facture', 'montant_paye' => 2000,
                'articles' => [['produit_id' => $this->lavage->id, 'quantite' => 1, 'prix_unitaire' => 1000, 'unite' => 'unité']],
            ]);
    }

    public function test_le_vendeur_sans_factures_vente_revient_a_la_vente_et_voit_sa_facture(): void
    {
        $vendeur = $this->membre('vendeur@test.ci', ['nouvelle_vente']);

        $reponse = $this->vendre($vendeur)->assertRedirect(route('caissier.ventes.nouvelle'));
        $vente = Vente::latest('id')->firstOrFail();
        $reponse->assertSessionHas('piece_a_imprimer', fn ($p) => $p['facture'] === route('caissier.ventes.imprimer', [$vente, 'integre' => 1]));

        $page = $this->get(route('caissier.ventes.nouvelle'))->assertOk()->getContent();
        $this->assertStringContainsString('id="piece-a-imprimer"', $page);
        $this->assertStringContainsString(e(route('caissier.ventes.imprimer', [$vente, 'integre' => 1])), $page);

        $this->get(route('caissier.ventes.imprimer', $vente))->assertOk();
        $this->get(route('caissier.ventes.ticket', $vente))->assertOk();
        // La liste des factures, elle, reste fermée.
        $this->get(route('caissier.ventes.factures'))->assertForbidden();
    }

    /**
     * Retours du 23/10/2026 : pas de doublon « Ticket » / « Imprimer » dans
     * l'en-tête de la fenêtre (la pièce porte les siens) ; une page chargée
     * dans la fenêtre s'y affiche sans menu ; le panier du navigateur porte sa
     * portée (entreprise, site, utilisateur).
     */
    public function test_la_fenetre_de_la_piece_sans_doublons_et_le_panier_borne(): void
    {
        $vendeur = $this->membre('vendeur2@test.ci', ['nouvelle_vente']);
        $this->vendre($vendeur);

        $page = $this->get(route('caissier.ventes.nouvelle'))->assertOk()->getContent();
        $debut = strpos($page, 'id="piece-a-imprimer"');
        $fenetre = substr($page, $debut, strpos($page, '<iframe', $debut) - $debut);
        $this->assertStringContainsString('piece-a-imprimer-fermer', $fenetre);
        $this->assertStringNotContainsString('Ticket', $fenetre);
        $this->assertStringNotContainsString('piece-a-imprimer-imprimer', $fenetre);
        $this->assertStringContainsString("window.frameElement.id === 'piece-a-imprimer-cadre'", $page);

        $portee = $vendeur->entreprise_id . ':' . $this->site->id . ':' . $vendeur->id;
        $this->assertStringContainsString("const PORTEE_PANIER = '" . $portee . "'", $page);
    }

    public function test_le_vendeur_n_imprime_pas_les_factures_des_autres(): void
    {
        $collegue = $this->membre('collegue@test.ci', ['nouvelle_vente']);
        $this->vendre($collegue);
        $venteDuCollegue = Vente::latest('id')->firstOrFail();

        $vendeur = $this->membre('vendeur@test.ci', ['nouvelle_vente']);
        $this->actingAs($vendeur)->get(route('caissier.ventes.imprimer', $venteDuCollegue))->assertNotFound();

        // Qui tient « Factures vente » les lit toutes.
        $gestionnaire = $this->membre('gestion@test.ci', ['nouvelle_vente', 'factures_vente']);
        $this->actingAs($gestionnaire)->get(route('caissier.ventes.imprimer', $venteDuCollegue))->assertOk();
    }

    public function test_qui_a_la_liste_y_revient_avec_la_facture_ouverte(): void
    {
        $gestionnaire = $this->membre('gestion@test.ci', ['nouvelle_vente', 'factures_vente']);

        $this->vendre($gestionnaire)
            ->assertRedirect(route('caissier.ventes.factures', ['etape' => 'Facture']))
            ->assertSessionHas('piece_a_imprimer');
    }

    public function test_annuler_demande_son_propre_droit(): void
    {
        $gestionnaire = $this->membre('gestion@test.ci', ['nouvelle_vente', 'factures_vente']);
        $this->vendre($gestionnaire);
        $vente = Vente::latest('id')->firstOrFail();

        // Sans la case : ni bouton, ni route.
        $this->get(route('caissier.ventes.factures'))->assertOk()->assertDontSee('form-annuler-facture');
        $this->post(route('caissier.ventes.annuler', $vente), ['motif' => 'Erreur'])->assertForbidden();
        $this->assertNotSame(Vente::STATUT_ANNULEE, $vente->fresh()->statut);

        $gestionnaire->update(['habilitations' => ['nouvelle_vente', 'factures_vente', 'ventes_annuler']]);
        $this->actingAs($gestionnaire->fresh());
        $this->get(route('caissier.ventes.factures'))->assertOk()->assertSee('form-annuler-facture');
        $this->post(route('caissier.ventes.annuler', $vente), ['motif' => 'Erreur'])->assertSessionHas('succes');
        $this->assertSame(Vente::STATUT_ANNULEE, $vente->fresh()->statut);
    }

    public function test_la_case_figure_dans_les_habilitations(): void
    {
        $droits = \App\Modules\Authentification\Regles\Habilitations::pourLEntreprise($this->entreprise);

        $this->assertArrayHasKey('ventes_annuler', $droits['Ventes']);
    }
}
