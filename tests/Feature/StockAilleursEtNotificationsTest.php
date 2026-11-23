<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 23/11/2026 (O'Super prix) : la fiche article disait 13, la vente disait 0.
 * Le stock était sur un autre site ; le message le dit désormais.
 */
class StockAilleursEtNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_refus_pour_stock_insuffisant_dit_ou_est_le_stock(): void
    {
        $e = Entreprise::create(['nom' => "O'Super prix", 'modules_actifs' => ['principal', 'ventes', 'stock', 'produits', 'tiers']]);
        $boutique = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Abobo']);
        $depot = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'Dépôt', 'ville' => 'Abidjan', 'commune' => 'Abobo']);
        $admin = Utilisateur::create(['nom' => 'T', 'prenom' => 'T', 'email' => 't@x.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'statut' => 'actif',
            'entreprise_id' => $e->id, 'point_de_vente_id' => $boutique->id]);
        $riz = Produit::create(['entreprise_id' => $e->id, 'reference' => 'GABI-22', 'nom' => 'GABI KC 22KG', 'type' => 'marchandise', 'unite' => 'sac',
            'prix_achat' => 8750, 'prix_vente' => 9500, 'taux_tva' => 0]);
        Stock::where('produit_id', $riz->id)->delete();
        Stock::create(['produit_id' => $riz->id, 'point_de_vente_id' => $depot->id, 'quantite_disponible' => 13, 'stock_minimum' => 5]);
        $client = Client::create(['entreprise_id' => $e->id, 'nom' => 'Client']);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $boutique->id])
            ->post(route('admin.ventes.enregistrer'), [
                'client_id' => $client->id, 'date_vente' => now()->toDateString(), 'mode_paiement' => 'Espèces', 'etape' => 'Facture',
                'articles' => [['produit_id' => $riz->id, 'quantite' => 1, 'unite' => 'sac']],
            ])
            ->assertSessionHas('error', fn ($m) => Str::contains($m, ['site « Boutique »']) && Str::contains($m, '13 sur « Dépôt »') && Str::contains($m, 'transfert'));
    }

    public function test_tout_marquer_comme_lu_et_le_panneau_se_ferme(): void
    {
        $e = Entreprise::create(['nom' => 'X', 'modules_actifs' => ['principal']]);
        $site = PointDeVente::create(['entreprise_id' => $e->id, 'nom' => 'S', 'ville' => 'A', 'commune' => 'C']);
        $u = Utilisateur::create(['nom' => 'A', 'prenom' => 'B', 'email' => 'ab@x.ci', 'password' => bcrypt('x'), 'role' => 'admin', 'statut' => 'actif',
            'entreprise_id' => $e->id, 'point_de_vente_id' => $site->id]);
        $u->notify(new \App\Notifications\PortailFneRecuperationNotification(['entreprise' => 'X', 'type_recuperation' => 'Ventes']));
        $u->notify(new \App\Notifications\PortailFneRecuperationNotification(['entreprise' => 'X', 'type_recuperation' => 'Achats']));

        $this->actingAs($u)->get('/admin')->assertOk()->assertSee('Tout marquer comme lu')->assertSee('panneau-notifications', false);
        $this->post(route('notifications.tout_lire'))->assertRedirect();
        $this->assertSame(0, $u->fresh()->unreadNotifications()->count());
    }
}
