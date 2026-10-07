<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les écrans d'entrée (section 9 du plan) : inscription, création d'un point
 * de vente, visite guidée.
 */
class EcransDEntreeTest extends TestCase
{
    use RefreshDatabase;

    // ── 9.1 — Les icônes de la page publique ─────────────────────────

    /**
     * Les pages publiques chargeaient Tabler Icons en `@latest` : une version
     * qui change sous nos pieds. Les boutons « Retour », « Suivant » et
     * « Terminer sans remplir la suite » montraient un carré vide (image 3).
     */
    public function test_les_pages_publiques_chargent_une_police_d_icones_epinglee(): void
    {
        // Le partiel du compte FNE est inclus par l'inscription : il en fait
        // partie, et ses deux icônes Tabler avaient échappé au premier passage.
        $vues = array_merge(
            glob(base_path('app/Modules/Authentification/Vues/*.blade.php')),
            [base_path('app/Modules/Admin/Vues/partiels/compte-fne.blade.php')]
        );

        foreach ($vues as $vue) {
            $source = file_get_contents($vue);

            $this->assertStringNotContainsString('tabler-icons', $source, basename($vue));
            $this->assertDoesNotMatchRegularExpression('/\bti ti-/', $source, basename($vue) . ' garde une icône Tabler.');
            $this->assertDoesNotMatchRegularExpression('/@latest/', $source, basename($vue) . ' charge une version flottante.');
        }
    }

    public function test_les_boutons_de_l_inscription_portent_leur_icone(): void
    {
        $this->get(route('inscription'))
            ->assertOk()
            ->assertSee('font-awesome/6.5.0/css/all.min.css', false)
            ->assertSee('fa-solid fa-arrow-left', false)
            ->assertSee('fa-solid fa-arrow-right', false)
            ->assertSee('fa-solid fa-forward', false);
    }

    // ── 9.2 — Deux yeux, liés ─────────────────────────────────────────

    public function test_la_confirmation_du_mot_de_passe_porte_son_oeil(): void
    {
        $corps = $this->get(route('inscription'))->assertOk()->getContent();

        $this->assertStringContainsString('id="toggle-password-confirmation"', $corps);
        // Les deux boutons appellent la même bascule : on compare deux
        // saisies, et l'on ne peut pas les comparer si une seule est lisible.
        $this->assertStringContainsString("getElementById('toggle-password').addEventListener('click', basculerLesMotsDePasse)", $corps);
        $this->assertStringContainsString("getElementById('toggle-password-confirmation').addEventListener('click', basculerLesMotsDePasse)", $corps);
    }

    // ── 9.3 — Le point de vente se crée sans figer l'écran ───────────

    private function entrepriseAvecCatalogue(int $articles): array
    {
        $entreprise = Entreprise::create([
            'nom' => 'Grossiste du port', 'regime_imposition' => 'RNI', 'ncc' => '2601236C',
            'modules_actifs' => ['principal', 'points_de_vente', 'stock', 'produits'],
            'quota_points_de_vente' => 10,
        ]);
        $siege = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Siège', 'ville' => 'San-Pédro', 'commune' => 'Port']);
        $admin = Utilisateur::create([
            'nom' => 'Bah', 'prenom' => 'Ali', 'email' => 'ali-pdv@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $entreprise->id, 'point_de_vente_id' => $siege->id,
        ]);

        $lignes = [];
        for ($i = 0; $i < $articles; $i++) {
            $lignes[] = [
                'entreprise_id' => $entreprise->id, 'reference' => 'ART-' . $i, 'nom' => 'Article ' . $i,
                'type' => $i % 10 === 0 ? 'service' : 'marchandise',
                'prix_achat' => 1, 'prix_vente' => 2, 'statut' => 'actif',
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($lignes, 300) as $paquet) {
            DB::table('produits')->insert($paquet);
        }

        return [$entreprise, $siege, $admin];
    }

    /**
     * Mesuré le 07/10/2026 : 3 001 requêtes pour 1 500 articles — un
     * `firstOrCreate` par article. L'écran restait figé après « Créer ».
     */
    public function test_les_fiches_de_stock_se_posent_en_quelques_requetes(): void
    {
        [$entreprise] = $this->entrepriseAvecCatalogue(600);
        $site = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Agence', 'ville' => 'Abidjan', 'commune' => 'Yopougon']);

        DB::enableQueryLog();
        $site->initialiserLesFichesDeStock();
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $requetes, "{$requetes} requêtes pour 600 articles.");
        $this->assertSame(540, Stock::where('point_de_vente_id', $site->id)->count(), 'Une fiche par article stockable, aucune pour un service.');
    }

    public function test_une_fiche_deja_posee_n_est_pas_doublee(): void
    {
        [$entreprise] = $this->entrepriseAvecCatalogue(20);
        $site = PointDeVente::create(['entreprise_id' => $entreprise->id, 'nom' => 'Agence', 'ville' => 'Abidjan', 'commune' => 'Yopougon']);
        $article = Produit::where('entreprise_id', $entreprise->id)->where('type', 'marchandise')->first();
        Stock::create(['produit_id' => $article->id, 'point_de_vente_id' => $site->id, 'quantite_disponible' => 7]);

        $site->initialiserLesFichesDeStock();
        $site->initialiserLesFichesDeStock();

        $this->assertSame(18, Stock::where('point_de_vente_id', $site->id)->count());
        $this->assertSame(7.0, Stock::where('point_de_vente_id', $site->id)->where('produit_id', $article->id)->value('quantite_disponible') + 0.0);
    }

    public function test_le_bouton_creer_annonce_ce_qui_se_passe(): void
    {
        [, $siege, $admin] = $this->entrepriseAvecCatalogue(1);

        $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $siege->id])
            ->get(route('admin.pdv.index'))
            ->assertOk()
            ->assertSee('Création du site et de ses fiches de stock', false);
    }

    // ── 9.4 — La visite guidée ne promet que ce qui existe ───────────

    public function test_la_visite_ouvre_ce_qu_elle_designe_et_ne_promet_pas_de_livres(): void
    {
        [$entreprise, $siege, $admin] = $this->entrepriseAvecCatalogue(1);

        $corps = $this->actingAs($admin)->withSession(['point_de_vente_actif_id' => $siege->id])
            ->get(route('admin.tableau_de_bord'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('function atteignable(etape)', $corps);
        $this->assertStringContainsString('function reveler(cible)', $corps);
        $this->assertStringNotContainsString('votre plan comptable', $corps, 'Comptabilité fermée, la visite ne promet pas de plan comptable.');
    }
}
