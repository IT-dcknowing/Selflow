<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les habilitations mises à jour sur le menu actuel (15/10/2026).
 */
class HabilitationsAJourTest extends TestCase
{
    use RefreshDatabase;

    private function cles(): array
    {
        $cles = [];
        foreach (Habilitations::CATALOGUE as $groupes) {
            foreach ($groupes as $droits) {
                $cles = array_merge($cles, array_keys($droits));
            }
        }

        return $cles;
    }

    public function test_chaque_ecran_a_son_droit_et_chaque_droit_ouvre_quelque_chose(): void
    {
        $cles = $this->cles();
        foreach (['ventes_livraisons', 'b2b_negociations', 'stock_consignations', 'immobilisations', 'fne_gestion', 'stickers_commande', 'parametres_entreprise'] as $nouveau) {
            $this->assertContains($nouveau, $cles);
        }

        $utilisees = array_unique(array_values(Habilitations::PAR_ROUTE));
        $this->assertSame([], array_values(array_diff($utilisees, $cles)), 'Un droit exigé par une route manque à la liste.');
        $this->assertSame([], array_values(array_diff($cles, $utilisees)), 'Un droit de la liste n\'ouvre aucun écran.');

        $this->assertSame('fne_gestion', Habilitations::pour('admin.fne.gestion'));
        $this->assertSame('b2b_negociations', Habilitations::pour('admin.b2b.client.voir'));
        $this->assertSame('stickers_commande', Habilitations::pour('admin.stickers.index'));
        $this->assertSame('immobilisations', Habilitations::pour('admin.immobilisations.index'));
    }

    public function test_produits_et_tiers_figurent_pour_une_entreprise_inscrite(): void
    {
        // Les modules tels que l'inscription les enregistre : sans `produits` ni `tiers`.
        $entreprise = Entreprise::create([
            'nom' => 'Boutique', 'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'production', 'comptabilite', 'points_de_vente', 'personnel', 'b2b', 'fne'],
        ]);

        $groupes = array_keys(Habilitations::pourLEntreprise($entreprise));
        foreach (['Produits', 'Tiers', 'B2B', 'Fiscalité & DGI', 'Entreprise', 'Ventes', 'Stock'] as $groupe) {
            $this->assertContains($groupe, $groupes);
        }
    }

    public function test_personne_ne_perd_un_ecran(): void
    {
        $entreprise = Entreprise::create(['nom' => 'Boutique']);
        $caissier = Utilisateur::create([
            'nom' => 'Koné', 'prenom' => 'Awa', 'email' => 'awa@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'caissier', 'entreprise_id' => $entreprise->id,
            'habilitations' => ['factures_vente', 'stock_articles', 'nouvel_achat', 'gestion_habilitations'],
        ]);

        (require base_path('app/Modules/Admin/Migrations/2026_10_15_000003_les_habilitations_a_jour.php'))->up();

        $droits = $caissier->fresh()->habilitations;
        foreach (['fne_gestion', 'stickers_commande', 'ventes_livraisons', 'stock_consignations', 'b2b_negociations', 'gestion_personnel'] as $herite) {
            $this->assertContains($herite, $droits);
        }
        $this->assertNotContains('immobilisations', $droits);
    }

    public function test_la_fenetre_du_personnel_liste_les_nouveaux_droits(): void
    {
        $entreprise = Entreprise::create([
            'nom' => 'Boutique', 'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'comptabilite', 'points_de_vente', 'personnel', 'b2b', 'fne'],
        ]);
        PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $entreprise->id,
        ]);

        $page = $this->actingAs($admin)->get(route('admin.personnel.index'))->assertOk()->getContent();
        foreach (['Bons de livraison', 'Négociations B2B', 'Gestion FNE', 'Commander des stickers', 'Catalogue produits', 'Clients', 'Paramètres de l&#039;entreprise'] as $libelle) {
            $this->assertStringContainsString($libelle, $page);
        }
    }
}
