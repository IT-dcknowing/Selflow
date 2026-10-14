<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Second retest de production (« des corrections à revoir pour Selflow »)
 * et l'erreur 500 relevée en ligne le 14/10/2026.
 */
class Retest2Test extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Axenova', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody', 'rccm' => 'CI-ABJ-2026-B-1',
            'ncc' => '2601234A', 'gerant_fonction' => 'Gérant', 'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Siège', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'Assem', 'prenom' => 'Eve', 'email' => 'eve@axenova.ci', 'password' => bcrypt('secret-de-test'),
            'role' => 'admin', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id]);
    }

    private function article(array $champs): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('admin.produits.index'))->post(route('admin.produits.creer'), $champs + [
            'nom' => 'Ciment 50 kg', 'type' => 'marchandise', 'prix_achat' => 400, 'prix_vente' => 700,
            'taux_tva' => 18, 'stock_actuel' => 0, 'stock_minimum' => 2,
        ]);
    }

    // ── L'erreur 500 de la création d'article ──────────────────────────

    public function test_nouvelle_categorie_sans_nom_est_refusee_par_un_message_et_non_par_une_erreur_500(): void
    {
        // En production : `categorie_id = 'nouvelle'` partait tel quel en base,
        // et MySQL refusait « Incorrect integer value ».
        $this->article(['categorie_id' => 'nouvelle', 'nouvelle_categorie' => ''])
            ->assertRedirect(route('admin.produits.index'))
            ->assertSessionHasErrors('nouvelle_categorie');

        $this->assertSame(0, Produit::count());
    }

    public function test_la_categorie_d_une_autre_entreprise_est_refusee(): void
    {
        $autre = Entreprise::create(['nom' => 'Autre']);
        $categorie = Categorie::create(['entreprise_id' => $autre->id, 'nom' => 'Étrangère', 'prefixe' => 'ETR']);

        $this->article(['categorie_id' => (string) $categorie->id])->assertSessionHasErrors('categorie_id');
        $this->article(['categorie_id' => 'n-importe-quoi'])->assertSessionHasErrors('categorie_id');
        $this->assertSame(0, Produit::count());
    }

    public function test_seuls_des_entiers_partent_en_base(): void
    {
        $categorie = Categorie::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Matériaux', 'prefixe' => 'MAT']);

        $this->article(['categorie_id' => (string) $categorie->id, 'sous_categorie_id' => 'nouvelle', 'nouvelle_sous_categorie' => ''])
            ->assertSessionHasNoErrors();

        $produit = Produit::sole();
        $this->assertSame($categorie->id, $produit->getRawOriginal('categorie_id') + 0);
        $this->assertNull($produit->sous_categorie_id);
    }

    public function test_sans_categorie_l_article_se_cree(): void
    {
        $this->article(['categorie_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull(Produit::sole()->categorie_id);
    }

    // ── Mot de passe oublié : la page 429 brute ────────────────────────

    public function test_trop_de_demandes_pour_une_adresse_rendent_la_page_avec_un_message(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        auth()->logout();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('password.email'), ['email' => 'eve@axenova.ci'])->assertRedirect();
        }

        $reponse = $this->post(route('password.email'), ['email' => 'eve@axenova.ci']);
        $reponse->assertStatus(429)->assertSee('Trop de demandes de lien pour cette adresse')
            ->assertSee('Mot de passe oublié');

        // Une autre personne du même bureau (même IP) n'est pas bloquée.
        $this->post(route('password.email'), ['email' => 'collegue@axenova.ci'])->assertRedirect();
    }

    public function test_les_regles_du_mot_de_passe_sont_dites_sous_le_champ(): void
    {
        auth()->logout();
        $page = $this->get(route('inscription'))->assertOk()->getContent();

        foreach (['8 caractères au moins', 'une lettre majuscule', 'une lettre minuscule', 'un chiffre', 'un caractère spécial'] as $regle) {
            $this->assertStringContainsString($regle, $page);
        }
    }

    // ── Les recherches ne rechargent plus la page à chaque frappe ──────

    public function test_aucune_recherche_ne_se_relance_a_chaque_frappe(): void
    {
        $fichiers = glob(base_path('app/Modules/Admin/Vues/{,*/,*/*/}*.blade.php'), GLOB_BRACE);
        foreach ($fichiers as $fichier) {
            $this->assertDoesNotMatchRegularExpression('/oninput="clearTimeout\(window\.\w+\); window\.\w+ = setTimeout\(\(\) => [^"]*submit\(\)/',
                file_get_contents($fichier), basename($fichier));
        }

        $this->get(route('admin.fournisseurs.index'))->assertOk()->assertSee("event.key === 'Enter'", false);
    }

    // ── L'import du stock d'ouverture ──────────────────────────────────

    private function importerStock(array $lignes): \Illuminate\Testing\TestResponse
    {
        $contenu = '';
        foreach ($lignes as $ligne) {
            $contenu .= implode(';', array_map(fn ($v) => '"' . $v . '"', $ligne)) . "\r\n";
        }
        $chemin = tempnam(sys_get_temp_dir(), 'import') . '.csv';
        file_put_contents($chemin, $contenu);

        return $this->post(route('admin.import.importer', ['type' => 'stock-initial']), [
            'fichier' => new \Illuminate\Http\UploadedFile($chemin, 'stock.csv', 'text/csv', null, true),
        ]);
    }

    public function test_le_modele_du_stock_d_ouverture_porte_les_articles_de_l_entreprise(): void
    {
        Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'LOCE-CSM-001', 'nom' => 'Housses de chaises',
            'type' => 'consommable_stockable', 'prix_achat' => 1500, 'prix_vente' => 3000]);

        $csv = $this->get(route('admin.import.exemple', ['type' => 'stock-initial']))->assertOk()->getContent();

        $this->assertStringContainsString('LOCE-CSM-001', $csv);
        $this->assertStringContainsString('Housses de chaises', $csv);
        $this->assertStringNotContainsString('STYL-BLU-001', $csv);
    }

    public function test_casse_designation_et_lignes_vides(): void
    {
        $housses = Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'LOCE-CSM-001', 'nom' => 'Housses de chaises',
            'type' => 'consommable_stockable', 'prix_achat' => 1500, 'prix_vente' => 3000]);
        $nappes = Produit::create(['entreprise_id' => $this->entreprise->id, 'reference' => 'LOCE-CSM-002', 'nom' => 'Nappes',
            'type' => 'consommable_stockable', 'prix_achat' => 1000, 'prix_vente' => 2000]);

        $reponse = $this->importerStock([
            ['reference', 'designation', 'point_de_vente', 'quantite', 'cout_unitaire'],
            [' loce-csm-001 ', 'Housses de chaises', 'Siège', '40', '1500'],
            ['', 'nappes', 'Siège', '12', '1000'],
            ['LOCE-CSM-003', 'Inconnu', 'Siège', '', ''],
        ]);

        $this->assertSame(2, $reponse->json('importes'), json_encode($reponse->json('erreurs')));
        $this->assertSame([], $reponse->json('erreurs'));
        $this->assertStringContainsString('1 ligne(s) sans quantité', $reponse->json('message'));
        $this->assertSame(40.0, $housses->fresh()->stockActuel(PointDeVente::first()->id));
        $this->assertSame(12.0, $nappes->fresh()->stockActuel(PointDeVente::first()->id));
    }

    public function test_tout_refuse_n_est_plus_annonce_comme_un_succes(): void
    {
        $reponse = $this->importerStock([
            ['reference', 'point_de_vente', 'quantite'],
            ['STYL-BLU-01', 'Siège', '240'],
        ]);

        $this->assertFalse($reponse->json('success'));
        $this->assertStringContainsString('Aucune ligne importée', $reponse->json('message'));
    }
}
