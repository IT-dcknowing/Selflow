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
 * Ce qui distingue un bordereau d'achat, et pourquoi la distinction porte.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce que ce fichier gardait
 * ─────────────────────────────────────────────────────────────────────────
 *
 * Il fermait l'avoir d'un bordereau : **la DGI ne normalise pas l'avoir d'un
 * BAPA.** Selflow le proposait quand même, et la pièce se serait établie dans
 * les livres sans que rien ne parte à la plateforme.
 *
 * **Le 25/09/2026, la fermeture s'est élargie à tous les avoirs fournisseurs**
 * — un acheteur n'établit pas l'avoir de son fournisseur, quel qu'il soit. Les
 * quatre routes ont disparu ; `TroisNaturesDAchatTest` le vérifie. Les cas qui
 * visaient ces routes n'ont plus de route à viser.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Ce qui reste, et qui porte plus qu'avant
 * ─────────────────────────────────────────────────────────────────────────
 *
 * **Deux chemins mènent au bordereau, et un seul se lit dans
 * `type_facture`.** Le second est `validerFacture()` : une facture d'achat
 * ordinaire dont le fournisseur n'a pas de NCC part elle aussi en
 * normalisation BAPA. `Achat::estBapa()` tient les deux ensemble.
 *
 * Ce critère servait à interdire l'avoir. **Il sert désormais à ranger** : il
 * décide de quelle section de l'écran des factures d'achat relève une pièce.
 * S'en tenir à `type_facture` mettrait les achats auprès d'un vendeur non
 * immatriculé — le cas le plus courant — sous « Factures enregistrées », où
 * les colonnes DGI annoncent « Aucune donnée » alors qu'elles sont
 * normalisées. `Achat::scopeBordereaux()` dit la même chose en SQL, parce que
 * l'écran range en base, et écrire la règle deux fois c'est la voir diverger.
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

    // ── La même règle, en base ───────────────────────────────────────

    public function test_la_portee_range_les_bordereaux_comme_l_accesseur(): void
    {
        // `estBapa()` répond pour une pièce chargée, `scopeBordereaux()` pour
        // une requête. Les deux doivent dire la même chose, sans quoi une
        // pièce serait rangée d'un côté et lue de l'autre.
        $declare  = $this->unAchat('2601234A', 'BA-2026-0001', 'bapa');
        $sansNcc  = $this->unAchat(null, 'ACH-2026-0002');
        $ordinaire = $this->unAchat('2601234A', 'ACH-2026-0003');

        $bordereaux = Achat::bordereaux()->pluck('numero_facture')->all();
        $autres     = Achat::horsBordereaux()->pluck('numero_facture')->all();

        $this->assertContains($declare->numero_facture, $bordereaux);
        $this->assertContains($sansNcc->numero_facture, $bordereaux);
        $this->assertNotContains($ordinaire->numero_facture, $bordereaux);

        $this->assertContains($ordinaire->numero_facture, $autres);
        $this->assertNotContains($sansNcc->numero_facture, $autres);
    }

    public function test_les_deux_portees_se_partagent_toutes_les_pieces(): void
    {
        // Aucune pièce ne doit tomber entre les deux, ni figurer dans les
        // deux : une section perdrait des lignes, ou les compterait deux fois.
        $this->unAchat('2601234A', 'BA-2026-0004', 'bapa');
        $this->unAchat(null, 'ACH-2026-0005');
        $this->unAchat('2601234A', 'ACH-2026-0006');

        $total = Achat::count();

        $this->assertSame($total, Achat::bordereaux()->count() + Achat::horsBordereaux()->count());
    }

    // ── L'avoir fournisseur, fermé dans son entier ───────────────────

    public function test_plus_aucune_adresse_n_etablit_d_avoir_fournisseur(): void
    {
        // La fermeture visait les bordereaux ; elle vaut maintenant pour
        // toutes les factures d'achat, bordereau ou non. Le détail est dans
        // `TroisNaturesDAchatTest` ; ce qui est vérifié ici est que ce fichier
        // ne garde pas la mémoire d'une porte encore ouverte.
        $this->assertNull(app('router')->getRoutes()->getByName('admin.achats.avoir'));
        $this->assertNull(app('router')->getRoutes()->getByName('admin.achats.avoir.creer_nouveau'));
    }
}
