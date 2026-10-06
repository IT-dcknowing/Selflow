<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retrouver les fiches que l'ancien écran de stock a pu fausser (lot 43).
 */
class VerifierFichesDeStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_fiche_qui_s_ecarte_de_son_journal_est_signalee_et_non_corrigee(): void
    {
        $entreprise = Entreprise::create(['nom' => 'Boutique']);
        $site = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Plateau']);
        $produit = Produit::create([
            'entreprise_id' => $entreprise->id, 'reference' => 'EAU-1', 'nom' => 'Eau', 'type' => 'marchandise',
            'prix_achat' => 100, 'prix_vente' => 150, 'taux_tva' => 18,
        ]);
        $fiche = Stock::create(['produit_id' => $produit->id, 'point_de_vente_id' => $site->id, 'quantite_disponible' => 10]);
        MouvementStock::create([
            'produit_id' => $produit->id, 'point_de_vente_id' => $site->id,
            'type_mouvement' => MouvementStock::ENTREE, 'sous_type' => MouvementStock::INVENTAIRE,
            'quantite' => 10, 'stock_avant' => 0, 'stock_apres' => 10, 'reference_document' => 'OUVERTURE',
        ]);

        $this->artisan('selflow:verifier-stocks')->assertSuccessful();

        // L'ancien écran : la somme des sites posée dans la fiche du site actif.
        Stock::whereKey($fiche->id)->update(['quantite_disponible' => 30]);

        $this->artisan('selflow:verifier-stocks')->assertFailed()->expectsOutputToContain('EAU-1');
        $this->assertSame(30.0, (float) $fiche->fresh()->quantite_disponible);
    }
}
