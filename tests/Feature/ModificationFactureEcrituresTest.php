<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Services\ComptabiliteService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Retour du 16/10/2026 : une facture corrigée gardait ses anciennes écritures,
 * et Comptaflow avec elles. La modification contre-passe et repasse.
 */
class ModificationFactureEcrituresTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Produit $prestation;
    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom' => 'Correcteur SARL', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $admin = Utilisateur::create([
            'nom' => 'K', 'prenom' => 'L', 'email' => 'correcteur@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        $this->prestation = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'SRV-1', 'nom' => 'Conseil',
            'type' => 'service', 'unite' => 'unité', 'prix_achat' => 0, 'prix_vente' => 100000, 'taux_tva' => 18,
        ]);
        $this->client = Client::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Client']);
        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $site->id]);
    }

    /** Le solde d'un compte (débit − crédit), toutes opérations confondues. */
    private function solde(string $prefixe): float
    {
        $lignes = EcritureComptable::withoutGlobalScopes()->get();

        return round(
            $lignes->filter(fn ($e) => str_starts_with((string) $e->compte_debit, $prefixe))->sum('debit')
            - $lignes->filter(fn ($e) => str_starts_with((string) $e->compte_credit, $prefixe))->sum('credit'),
            2
        );
    }

    public function test_corriger_une_facture_corrige_ses_ecritures(): void
    {
        $this->post(route('admin.ventes.enregistrer'), [
            'etape' => 'Facture', 'type_piece' => Vente::TYPE_FACTURE, 'client_id' => $this->client->id,
            'mode_paiement' => 'Espèces',
            'articles' => [['produit_id' => $this->prestation->id, 'quantite' => 1000, 'unite' => 'unité']],
        ])->assertSessionDoesntHaveErrors();

        $vente = Vente::firstOrFail();
        $this->assertSame(-100000000.0, $this->solde('7'));

        // La saisie était fausse : 1 000 au lieu de 1.
        $this->put(route('admin.ventes.modifier.enregistrer', $vente), [
            'client_id' => $this->client->id, 'mode_paiement' => 'Espèces',
            'articles' => [['produit_id' => $this->prestation->id, 'quantite' => 1, 'unite' => 'unité']],
        ])->assertSessionDoesntHaveErrors();

        // Les livres disent ce que dit la facture : 100 000 HT, et le client soldé.
        $this->assertSame(-100000.0, $this->solde('7'));
        $this->assertSame(0.0, $this->solde('411'));
        $this->assertSame((float) TresorerieJournal::sum('montant_entree'), $this->solde('57'));

        // Rien n'a été effacé : l'ancienne facture est contre-passée.
        $this->assertSame(2, Operation::withoutGlobalScopes()->where('type_operation', ComptabiliteService::TYPE_CONTRE_PASSATION_VENTE)->count());
        $this->assertSame(0, Operation::withoutGlobalScopes()->where('est_equilibree', false)->count());

        // Une seconde correction ne contre-passe pas deux fois la première.
        $this->put(route('admin.ventes.modifier.enregistrer', $vente), [
            'client_id' => $this->client->id, 'mode_paiement' => 'Espèces',
            'articles' => [['produit_id' => $this->prestation->id, 'quantite' => 2, 'unite' => 'unité']],
        ]);
        $this->assertSame(-200000.0, $this->solde('7'));
        $this->assertSame(0.0, $this->solde('411'));
    }

    /** Le client tend plus que le dû : la différence est rendue, elle n'entre pas en caisse. */
    public function test_la_tresorerie_n_encaisse_que_le_du(): void
    {
        $this->post(route('admin.ventes.enregistrer'), [
            'etape' => 'Facture', 'type_piece' => Vente::TYPE_FACTURE, 'client_id' => $this->client->id,
            'mode_paiement' => 'Espèces', 'montant_paye' => 500000000000,
            'articles' => [['produit_id' => $this->prestation->id, 'quantite' => 1, 'unite' => 'unité']],
        ])->assertSessionDoesntHaveErrors();

        $vente = Vente::firstOrFail();
        $du = (float) $vente->montant_ttc + (float) $vente->timbre_quittance + (float) $vente->montant_autres_taxes;

        $this->assertEqualsWithDelta($du, (float) TresorerieJournal::sum('montant_entree'), 0.01);
        $this->assertEqualsWithDelta($du, (float) TresorerieJournal::first()->solde_resultat, 0.01);
        $this->assertEqualsWithDelta($du, $this->solde('57'), 0.01);
    }

    public function test_la_commande_ramene_les_encaissements_passes_au_du(): void
    {
        $this->post(route('admin.ventes.enregistrer'), [
            'etape' => 'Facture', 'type_piece' => Vente::TYPE_FACTURE, 'client_id' => $this->client->id,
            'mode_paiement' => 'Espèces',
            'articles' => [['produit_id' => $this->prestation->id, 'quantite' => 1, 'unite' => 'unité']],
        ]);
        // Un encaissement d'avant le plafond, écrit sans passer par le modèle.
        TresorerieJournal::withoutGlobalScopes()->first()->newQuery()->withoutGlobalScopes()
            ->update(['montant_entree' => 999999999, 'solde_resultat' => 999999999]);

        $this->artisan('selflow:corriger-tresorerie', ['entreprise' => $this->entreprise->id])->assertSuccessful();
        $this->assertSame(999999999.0, (float) TresorerieJournal::sum('montant_entree'), 'aperçu : rien n\'est écrit');

        $this->artisan('selflow:corriger-tresorerie', ['entreprise' => $this->entreprise->id, '--appliquer' => true])->assertSuccessful();
        $vente = Vente::firstOrFail();
        $du = (float) $vente->montant_ttc + (float) $vente->timbre_quittance + (float) $vente->montant_autres_taxes;
        $this->assertEqualsWithDelta($du, (float) TresorerieJournal::sum('montant_entree'), 0.01);
        $this->assertEqualsWithDelta($du, (float) TresorerieJournal::first()->solde_resultat, 0.01);
    }

    public function test_recomptabiliser_repare_une_operation_desequilibree(): void
    {
        $this->post(route('admin.ventes.enregistrer'), [
            'etape' => 'Facture', 'type_piece' => Vente::TYPE_FACTURE, 'client_id' => $this->client->id,
            'mode_paiement' => 'Espèces',
            'articles' => [['produit_id' => $this->prestation->id, 'quantite' => 1, 'unite' => 'unité']],
        ]);
        $vente = Vente::firstOrFail();

        // Une opération passée par une version antérieure : la classe 7 à 104 000.
        $op = Operation::withoutGlobalScopes()->where('type_operation', 'FactureVente')->firstOrFail();
        EcritureComptable::withoutGlobalScopes()->where('operation_id', $op->id)->where('compte_credit', 'like', '7%')
            ->update(['credit' => 104000]);
        $op->forceFill(['est_equilibree' => false])->save();

        $this->artisan('selflow:recomptabiliser', ['entreprise' => $this->entreprise->id, 'piece' => $vente->numero_facture, '--appliquer' => true])
            ->assertSuccessful();

        $this->assertSame(0, Operation::withoutGlobalScopes()->where('est_equilibree', false)->count());
        $this->assertSame(-100000.0, $this->solde('7'));
        $this->assertSame(0.0, $this->solde('411'));
    }
}
