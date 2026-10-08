<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FicheTechnique;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\OrdreProduction;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Services\StockService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Recette du module Production, de bout en bout par les routes HTTP.
 *
 * Une boulangerie : farine et levure en matières premières, la baguette en
 * produit fini. On écrit la recette, on lance un ordre, on le valide, et l'on
 * regarde ce que deviennent le stock, le coût de revient, le journal de stock
 * et la comptabilité.
 *
 * Écrite à la recette du 08/10/2026 : quinze épreuves y échouaient, chacune
 * sur un défaut constaté. Corrigés depuis, elles restent comme épreuve
 * permanente — chaque commentaire dit la règle, et ce qu'il advenait sans elle.
 */
class ProductionRecetteTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $fournil;
    private PointDeVente $boutique;
    private Utilisateur $admin;
    private Categorie $rayonMatieres;
    private Categorie $rayonFinis;
    private Produit $farine;
    private Produit $levure;
    private Produit $baguette;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->entreprise = Entreprise::create([
            'nom' => 'Boulangerie de Cocody', 'regime_imposition' => 'RNI', 'adresse' => 'Cocody, Abidjan',
            'rccm' => 'CI-ABJ-2026-B-00777', 'ncc' => '2607777Q', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Production'],
            'modules_actifs' => ['principal', 'production', 'stock', 'produits', 'points_de_vente'],
        ]);
        $this->fournil = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Fournil', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->boutique = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Boutique', 'ville' => 'Abidjan', 'commune' => 'Riviera']);

        CodeJournal::create(['entreprise_id' => $this->entreprise->id, 'code' => 'OD', 'intitule' => 'Opérations diverses', 'type' => 'OD']);

        $this->admin = Utilisateur::create([
            'nom' => 'Kouassi', 'prenom' => 'Awa', 'email' => 'awa-prod@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->fournil->id,
        ]);

        $this->rayonMatieres = Categorie::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Matières premières', 'prefixe' => 'MP',
            'compte_vente' => '701000', 'compte_achat' => '602000', 'compte_stock' => '321000', 'compte_variation' => '603200',
        ]);
        $this->rayonFinis = Categorie::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Produits finis', 'prefixe' => 'PF',
            'compte_vente' => '701000', 'compte_achat' => '601000', 'compte_stock' => '361000', 'compte_variation' => '736100',
        ]);

        $this->farine = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'MP-001', 'nom' => 'Farine de blé',
            'type' => 'matiere_premiere', 'unite' => 'kg', 'prix_achat' => 500, 'prix_vente' => 0,
            'categorie_id' => $this->rayonMatieres->id, 'statut' => 'actif',
        ]);
        $this->levure = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'MP-002', 'nom' => 'Levure',
            'type' => 'matiere_premiere', 'unite' => 'kg', 'prix_achat' => 2000, 'prix_vente' => 0,
            'categorie_id' => $this->rayonMatieres->id, 'statut' => 'actif',
        ]);
        $this->baguette = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'PF-001', 'nom' => 'Baguette',
            'type' => 'produit_fini', 'unite' => 'pièce', 'prix_achat' => 0, 'prix_vente' => 150,
            'categorie_id' => $this->rayonFinis->id, 'statut' => 'actif',
        ]);

        // 100 kg de farine à 500, 5 kg de levure à 2 000 : 60 000 F de matières.
        StockService::entree($this->farine, $this->fournil->id, 100, MouvementStock::RECEPTION, ['cout_unitaire' => 500]);
        StockService::entree($this->levure, $this->fournil->id, 5, MouvementStock::RECEPTION, ['cout_unitaire' => 2000]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->fournil->id]);
    }

    // ─── Outils ──────────────────────────────────────────────────────

    /** La recette de la baguette : 0,2 kg de farine et 0,005 kg de levure. */
    private function ecrireRecette(array $ingredients = null): FicheTechnique
    {
        $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'produit_fini_id' => $this->baguette->id,
            'description'     => 'Baguette de 250 g, cuisson 20 min',
            'ingredients'     => $ingredients ?? [
                ['ingredient_id' => $this->farine->id, 'quantite' => 0.2,   'unite' => 'kg'],
                ['ingredient_id' => $this->levure->id, 'quantite' => 0.005, 'unite' => 'kg'],
            ],
        ])->assertRedirect(route('admin.production.fiches_techniques.index'))->assertSessionHasNoErrors();

        return FicheTechnique::where('produit_fini_id', $this->baguette->id)->sole();
    }

    private function lancerOrdre(float $quantite, ?int $site = null): OrdreProduction
    {
        $this->post(route('admin.production.ordres.enregistrer'), [
            'produit_fini_id'   => $this->baguette->id,
            'point_de_vente_id' => $site ?? $this->fournil->id,
            'quantite_cible'    => $quantite,
            'date_production'   => '2026-10-08',
        ])->assertRedirect(route('admin.production.ordres.index'))->assertSessionHasNoErrors();

        return OrdreProduction::latest('id')->first();
    }

    private function valider(OrdreProduction $ordre)
    {
        return $this->from(route('admin.production.ordres.index'))
            ->post(route('admin.production.ordres.valider', $ordre));
    }

    private function stock(Produit $p, ?int $site = null): float
    {
        return $p->fresh()->stockActuel($site ?? $this->fournil->id);
    }

    // ═════════ Parcours nominal ═════════

    public function test_la_recette_s_enregistre_par_le_formulaire(): void
    {
        $fiche = $this->ecrireRecette();

        $this->assertSame($this->entreprise->id, $fiche->entreprise_id);
        $this->assertCount(2, $fiche->details);
        $this->assertEqualsWithDelta(0.2, (float) $fiche->details->firstWhere('ingredient_id', $this->farine->id)->quantite, 1e-9);
        $this->assertEqualsWithDelta(0.005, (float) $fiche->details->firstWhere('ingredient_id', $this->levure->id)->quantite, 1e-9);
    }

    public function test_l_ordre_se_cree_en_brouillon_avec_un_code_et_ne_touche_pas_au_stock(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(100);

        $this->assertSame('Brouillon', $ordre->statut);
        $this->assertSame('OP-' . now()->year . '-0001', $ordre->code_ordre);
        $this->assertSame(100.0, $this->stock($this->farine));
        $this->assertSame(0.0, $this->stock($this->baguette));
        $this->assertSame(0, MouvementStock::where('sous_type', 'like', 'production%')->count());
    }

    public function test_valider_consomme_exactement_recette_fois_quantite_et_entre_le_produit_fini(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(100);

        $this->valider($ordre)->assertRedirect(route('admin.production.ordres.index'))->assertSessionHas('succes');

        $this->assertSame('Terminé', $ordre->fresh()->statut);
        $this->assertSame(80.0, $this->stock($this->farine), '100 kg − 0,2 × 100');
        $this->assertSame(4.5, $this->stock($this->levure), '5 kg − 0,005 × 100');
        $this->assertSame(100.0, $this->stock($this->baguette));
    }

    public function test_le_cout_de_revient_est_celui_des_matieres_consommees(): void
    {
        $this->ecrireRecette();
        $this->valider($this->lancerOrdre(100));

        // 20 kg × 500 + 0,5 kg × 2 000 = 11 000 F pour 100 baguettes.
        $fiche = Stock::where('produit_id', $this->baguette->id)->where('point_de_vente_id', $this->fournil->id)->sole();
        $this->assertSame(110.0, (float) $fiche->cump);

        $entree = MouvementStock::where('produit_id', $this->baguette->id)->sole();
        $this->assertSame(MouvementStock::PRODUCTION_ENTREE, $entree->sous_type);
        $this->assertSame(110.0, (float) $entree->cout_unitaire);
    }

    public function test_les_mouvements_sont_traces_une_fois_avec_un_stock_apres_coherent(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(100);
        $this->valider($ordre);

        $production = MouvementStock::whereIn('sous_type', [MouvementStock::PRODUCTION_CONSOMMATION, MouvementStock::PRODUCTION_ENTREE])->get();
        $this->assertCount(3, $production, 'Deux consommations et une entrée.');

        foreach ($production as $m) {
            $this->assertSame($ordre->code_ordre, $m->reference_document);
            $this->assertSame($ordre->getMorphClass(), $m->piece_type);
            $this->assertSame($ordre->id, (int) $m->piece_id);
            $attendu = $m->type_mouvement === MouvementStock::ENTREE
                ? (float) $m->stock_avant + (float) $m->quantite
                : (float) $m->stock_avant - (float) $m->quantite;
            $this->assertEqualsWithDelta($attendu, (float) $m->stock_apres, 0.0005);

            // Le dernier mouvement d'un article dit son stock.
            $dernier = MouvementStock::where('produit_id', $m->produit_id)->latest('id')->first();
            $this->assertEqualsWithDelta($this->stock(Produit::find($m->produit_id)), (float) $dernier->stock_apres, 0.0005);
        }

        $this->assertSame(1, MouvementStock::where('produit_id', $this->farine->id)->where('sous_type', MouvementStock::PRODUCTION_CONSOMMATION)->count());
        $this->assertEqualsWithDelta(20.0, (float) MouvementStock::where('produit_id', $this->farine->id)->where('sous_type', MouvementStock::PRODUCTION_CONSOMMATION)->value('quantite'), 1e-9);
    }

    public function test_les_ecritures_de_production_sont_equilibrees_et_la_valeur_se_conserve(): void
    {
        $this->ecrireRecette();
        $this->valider($this->lancerOrdre(100));

        $this->assertEqualsWithDelta((float) EcritureComptable::sum('debit'), (float) EcritureComptable::sum('credit'), 0.01);
        $this->assertSame(11000.0, (float) EcritureComptable::where('compte_credit', '321000')->sum('credit'), 'Matières sorties du 32 une seule fois.');
        $this->assertSame(11000.0, (float) EcritureComptable::where('compte_debit', '361000')->sum('debit'), 'Produit fini entré au 36 pour la même valeur.');
    }

    public function test_la_double_validation_ne_fabrique_qu_une_fois(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(100);

        $this->valider($ordre);
        $this->valider($ordre)->assertSessionHas('info');

        $this->assertSame(80.0, $this->stock($this->farine));
        $this->assertSame(100.0, $this->stock($this->baguette));
        $this->assertSame(1, MouvementStock::where('sous_type', MouvementStock::PRODUCTION_ENTREE)->count());
    }

    public function test_un_stock_insuffisant_refuse_l_ordre_sans_rien_bouger(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(600); // 120 kg de farine pour 100 en stock

        $ecrituresAvant = EcritureComptable::count();
        $this->valider($ordre)->assertSessionHas('erreur')->assertSessionHas('erreurs_validation');

        $this->assertSame('Brouillon', $ordre->fresh()->statut);
        $this->assertSame(100.0, $this->stock($this->farine));
        $this->assertSame(5.0, $this->stock($this->levure));
        $this->assertSame(0.0, $this->stock($this->baguette));
        $this->assertSame(0, MouvementStock::where('sous_type', 'like', 'production%')->count());
        $this->assertSame($ecrituresAvant, EcritureComptable::count());
    }

    public function test_le_stock_d_un_autre_site_ne_sert_pas(): void
    {
        // Les matières sont au fournil ; un ordre lancé à la boutique doit être refusé.
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10, $this->boutique->id);

        $this->valider($ordre)->assertSessionHas('erreur');
        $this->assertSame('Brouillon', $ordre->fresh()->statut);
        $this->assertSame(100.0, $this->stock($this->farine));
    }

    // ═════════ Quantités décimales ═════════

    public function test_des_quantites_decimales_sont_consommees_exactement(): void
    {
        // 2,5 kg de farine par fournée, 2,5 fournées : 6,25 kg.
        $this->ecrireRecette([
            ['ingredient_id' => $this->farine->id, 'quantite' => 2.5, 'unite' => 'kg'],
        ]);
        $this->valider($this->lancerOrdre(2.5));

        $this->assertSame(93.75, $this->stock($this->farine));
        $this->assertSame(2.5, $this->stock($this->baguette));
        // 6,25 kg × 500 = 3 125 F pour 2,5 unités : 1 250 F l'unité.
        $this->assertSame(1250.0, (float) Stock::where('produit_id', $this->baguette->id)->where('point_de_vente_id', $this->fournil->id)->value('cump'));
    }

    /** La liste montre la quantité jusqu'à trois décimales : 2,5 s'affichait « 3 ». */
    public function test_la_liste_des_ordres_affiche_la_quantite_decimale(): void
    {
        $this->ecrireRecette();
        $this->lancerOrdre(2.5);

        $html = $this->get(route('admin.production.ordres.index'))->assertOk()->getContent();

        // La cellule « Qté à produire » de la ligne de l'ordre.
        preg_match('#<td style="text-align: center; font-weight:700;">\s*([^<]*?)\s*</td>#u', $html, $m);
        $this->assertStringStartsWith('2,5', $m[1] ?? '', 'Quantité affichée : ' . ($m[1] ?? '?'));
    }

    /** Besoin et stock se comparent à la précision du stock : 0,1 × 3 vaut 0,30000000000000004 en flottant, et un stock exact de 0,3 kg était déclaré insuffisant. */
    public function test_un_stock_exactement_suffisant_n_est_pas_refuse_par_un_arrondi(): void
    {
        $sel = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'MP-003', 'nom' => 'Sel',
            'type' => 'matiere_premiere', 'unite' => 'kg', 'prix_achat' => 300, 'prix_vente' => 0,
            'categorie_id' => $this->rayonMatieres->id, 'statut' => 'actif',
        ]);
        StockService::entree($sel, $this->fournil->id, 0.3, MouvementStock::RECEPTION, ['cout_unitaire' => 300]);

        $this->ecrireRecette([['ingredient_id' => $sel->id, 'quantite' => 0.1, 'unite' => 'kg']]);
        $ordre = $this->lancerOrdre(3);
        $this->valider($ordre);

        $this->assertSame('Terminé', $ordre->fresh()->statut, 'Validation refusée alors que 0,3 kg = 0,1 × 3.');
        $this->assertSame(0.0, $this->stock($sel));
    }

    /** Un besoin que la précision du stock (3 décimales) ramène à zéro est refusé avec un message : il levait une exception (erreur 500). */
    public function test_un_besoin_inferieur_a_la_precision_du_stock_ne_provoque_pas_d_erreur_500(): void
    {
        $this->ecrireRecette([
            ['ingredient_id' => $this->farine->id, 'quantite' => 0.2,    'unite' => 'kg'],
            ['ingredient_id' => $this->levure->id, 'quantite' => 0.0004, 'unite' => 'kg'],
        ]);
        $ordre = $this->lancerOrdre(1);

        $reponse = $this->valider($ordre);
        $this->assertNotSame(500, $reponse->status(), 'Erreur 500 à la validation.');
    }

    // ═════════ Unités ═════════

    /** L'unité de la recette est convertie dans l'unité de stock : 200 g de farine stockée en kg consommaient 200 kg. */
    public function test_une_recette_en_grammes_consomme_des_kilos_convertis(): void
    {
        $this->ecrireRecette([
            ['ingredient_id' => $this->farine->id, 'quantite' => 200, 'unite' => 'g'],
        ]);
        $ordre = $this->lancerOrdre(10); // 10 × 200 g = 2 kg

        $this->valider($ordre);

        $this->assertSame('Terminé', $ordre->fresh()->statut);
        $this->assertSame(98.0, $this->stock($this->farine));
    }

    // ═════════ Annulation ═════════

    /** Un ordre s'annule : aucune route ne le permettait. */
    public function test_un_ordre_peut_etre_annule(): void
    {
        $this->assertTrue(Route::has('admin.production.ordres.annuler'),
            'Aucune route d\'annulation : un ordre saisi par erreur reste à jamais en Brouillon, avec son bouton « Produire & Valider ».');
    }

    /** Seul un brouillon se valide : un ordre « Annulé » se validait et fabriquait. */
    public function test_un_ordre_annule_ne_se_valide_pas(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10);
        $ordre->update(['statut' => 'Annulé']);

        $this->valider($ordre);

        $this->assertSame('Annulé', $ordre->fresh()->statut);
        $this->assertSame(100.0, $this->stock($this->farine));
    }

    // ═════════ Contrôles de saisie de la recette ═════════

    /** Un même ingrédient deux fois est refusé à la saisie : il heurtait l'index unique (erreur 500). */
    public function test_un_ingredient_en_double_est_refuse_proprement(): void
    {
        $reponse = $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'produit_fini_id' => $this->baguette->id,
            'ingredients' => [
                ['ingredient_id' => $this->farine->id, 'quantite' => 0.2, 'unite' => 'kg'],
                ['ingredient_id' => $this->farine->id, 'quantite' => 0.1, 'unite' => 'kg'],
            ],
        ]);

        $this->assertNotSame(500, $reponse->status(), 'Erreur 500 sur un ingrédient saisi deux fois.');
        $reponse->assertSessionHasErrors();
    }

    /** Le produit fini ne figure pas parmi ses propres ingrédients. */
    public function test_le_produit_fini_ne_peut_pas_etre_son_propre_ingredient(): void
    {
        $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'produit_fini_id' => $this->baguette->id,
            'ingredients' => [
                ['ingredient_id' => $this->farine->id,   'quantite' => 0.2, 'unite' => 'kg'],
                ['ingredient_id' => $this->baguette->id, 'quantite' => 1,   'unite' => 'Unité'],
            ],
        ])->assertSessionHasErrors();

        $this->assertSame(0, FicheTechnique::count());
    }

    /** Le produit fabriqué est un produit fini : une matière première pouvait l'être. */
    public function test_le_produit_fabrique_doit_etre_un_produit_fini(): void
    {
        $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'produit_fini_id' => $this->levure->id,
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 1, 'unite' => 'kg']],
        ])->assertSessionHasErrors('produit_fini_id');
    }

    /** Un ordre ne se crée que pour un article qui a une recette : l'erreur n'arrivait qu'à la validation. */
    public function test_un_ordre_sur_un_article_sans_recette_est_refuse_a_la_creation(): void
    {
        $this->post(route('admin.production.ordres.enregistrer'), [
            'produit_fini_id' => $this->baguette->id, 'point_de_vente_id' => $this->fournil->id,
            'quantite_cible' => 10, 'date_production' => '2026-10-08',
        ])->assertSessionHasErrors('produit_fini_id');

        $this->assertSame(0, OrdreProduction::count());
    }

    public function test_la_saisie_libre_cree_le_produit_fini_et_son_stock_a_zero(): void
    {
        $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'nouveau_produit_fini_nom' => 'Pain de mie',
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 0.5, 'unite' => 'kg']],
        ])->assertSessionHasNoErrors();

        $pain = Produit::where('nom', 'Pain de mie')->sole();
        $this->assertSame('produit_fini', $pain->type);
        $this->assertSame(2, Stock::where('produit_id', $pain->id)->count(), 'Une fiche de stock par site.');
        $this->assertSame(1, FicheTechnique::where('produit_fini_id', $pain->id)->count());
    }

    /** La saisie libre ne reprend qu'un produit fini du même nom : elle reprenait n'importe quel article, ici une matière première. */
    public function test_la_saisie_libre_ne_reprend_pas_une_matiere_premiere_du_meme_nom(): void
    {
        $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'nouveau_produit_fini_nom' => 'Levure',
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 0.5, 'unite' => 'kg']],
        ]);

        $this->assertSame(0, FicheTechnique::where('produit_fini_id', $this->levure->id)->count(),
            'La levure (matière première) est devenue le « produit fini » d\'une recette.');
    }

    public function test_modifier_la_recette_remplace_ses_lignes(): void
    {
        $fiche = $this->ecrireRecette();

        $this->put(route('admin.production.fiches_techniques.modifier.enregistrer', $fiche), [
            'description' => 'Nouvelle formule',
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 0.25, 'unite' => 'kg']],
        ])->assertRedirect(route('admin.production.fiches_techniques.index'));

        $fiche->refresh();
        $this->assertSame('Nouvelle formule', $fiche->description);
        $this->assertCount(1, $fiche->details);
        $this->assertEqualsWithDelta(0.25, (float) $fiche->details->first()->quantite, 1e-9);
    }

    public function test_supprimer_la_recette(): void
    {
        $fiche = $this->ecrireRecette();
        $this->delete(route('admin.production.fiches_techniques.supprimer', $fiche))
            ->assertRedirect(route('admin.production.fiches_techniques.index'));
        $this->assertSame(0, FicheTechnique::count());
    }

    // ═════════ Cloisonnement ═════════

    public function test_une_autre_entreprise_ne_voit_ni_ne_touche_rien(): void
    {
        $fiche = $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10);

        $autre = Entreprise::create(['nom' => 'Pâtisserie rivale', 'modules_actifs' => ['principal', 'production', 'stock', 'produits']]);
        $sonSite = PointDeVente::create(['entreprise_id' => $autre->id, 'nom' => 'Labo rival', 'ville' => 'Abidjan', 'commune' => 'Yopougon']);
        $intrus = Utilisateur::create([
            'nom' => 'Rival', 'prenom' => 'R', 'email' => 'rival-prod@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $autre->id, 'point_de_vente_id' => $sonSite->id,
        ]);
        $sonGateau = Produit::create(['entreprise_id' => $autre->id, 'reference' => 'G-1', 'nom' => 'Gâteau', 'type' => 'produit_fini', 'prix_achat' => 0, 'prix_vente' => 1000, 'statut' => 'actif']);

        $this->actingAs($intrus)->withSession(['point_de_vente_actif_id' => $sonSite->id]);

        $this->get(route('admin.production.fiches_techniques.index'))->assertOk()->assertDontSee('Farine de blé')->assertDontSee('PF-001');
        $this->get(route('admin.production.ordres.index'))->assertOk()->assertDontSee($ordre->code_ordre);
        $this->get(route('admin.production.ordres.creer'))->assertOk()->assertDontSee('Baguette');
        $this->get(route('admin.production.fiches_techniques.creer'))->assertOk()->assertDontSee('Farine de blé');
        $this->get(route('admin.production.fiches_techniques.modifier', $fiche))->assertNotFound();
        $this->put(route('admin.production.fiches_techniques.modifier.enregistrer', $fiche), [
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 9, 'unite' => 'kg']],
        ])->assertNotFound();
        $this->delete(route('admin.production.fiches_techniques.supprimer', $fiche))->assertNotFound();
        $this->post(route('admin.production.ordres.valider', $ordre))->assertNotFound();

        // Ses propres pièces ne peuvent pas emprunter les nôtres.
        $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'produit_fini_id' => $sonGateau->id,
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 1, 'unite' => 'kg']],
        ])->assertSessionHasErrors('ingredients.0.ingredient_id');
        $this->post(route('admin.production.ordres.enregistrer'), [
            'produit_fini_id' => $this->baguette->id, 'point_de_vente_id' => $this->fournil->id,
            'quantite_cible' => 1, 'date_production' => '2026-10-08',
        ])->assertSessionHasErrors(['produit_fini_id', 'point_de_vente_id']);

        $this->assertSame('Brouillon', $ordre->fresh()->statut);
        $this->assertSame(2, $fiche->fresh()->details()->count());
        $this->assertSame(100.0, $this->stock($this->farine));
    }

    public function test_deux_entreprises_numerotent_leurs_ordres_independamment(): void
    {
        $this->ecrireRecette();
        $this->lancerOrdre(10);

        $autre = Entreprise::create(['nom' => 'Autre atelier']);
        $this->assertSame('OP-' . now()->year . '-0001', OrdreProduction::genererCode($autre->id));
    }

    public function test_un_employe_sans_habilitation_n_atteint_pas_la_production(): void
    {
        $caissier = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'K', 'email' => 'yao-prod@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'caissier', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->fournil->id,
            'habilitations' => [],
        ]);
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10);

        $this->actingAs($caissier);
        $this->assertContains($this->get(route('admin.production.ordres.index'))->status(), [302, 403]);
        $this->assertContains($this->post(route('admin.production.ordres.valider', $ordre))->status(), [302, 403]);
        $this->assertSame('Brouillon', $ordre->fresh()->statut);
    }

    // ═════════ Pages et boutons ═════════

    public function test_chaque_page_du_module_s_affiche(): void
    {
        // À vide.
        $this->get(route('admin.production.fiches_techniques.index'))->assertOk()->assertSee('Nouvelle Recette');
        $this->get(route('admin.production.fiches_techniques.creer'))->assertOk()->assertSee('Enregistrer la Recette');
        // « Lancer une Production » est devenu « Nouvel ordre » (recette du 08/10/2026).
        $this->get(route('admin.production.ordres.index'))->assertOk()->assertSee('Nouvel ordre')->assertSee('Aucun ordre de production');
        $this->get(route('admin.production.ordres.creer'))->assertOk()->assertSee("Créer l'Ordre de Production", false);

        // Garnies.
        $fiche = $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10);

        $this->get(route('admin.production.fiches_techniques.index'))->assertOk()
            ->assertSee('Baguette')->assertSee('2 ingrédient(s)')->assertSee('Baguette de 250 g');
        $this->get(route('admin.production.fiches_techniques.modifier', $fiche))->assertOk()
            ->assertSee('Modifier la Recette')->assertSee('Farine de blé');
        $this->get(route('admin.production.ordres.creer'))->assertOk()->assertSee('Baguette (PF-001)', false);
        $this->get(route('admin.production.ordres.index'))->assertOk()
            ->assertSee($ordre->code_ordre)->assertSee('Produire & Valider', false);

        $this->valider($ordre);
        $this->get(route('admin.production.ordres.index'))->assertOk()
            ->assertSee('Terminé')->assertSee('Complété')->assertDontSee('Produire & Valider', false);
        $this->get(route('admin.production.ordres.index', ['statut' => 'Terminé']))->assertOk()->assertSee($ordre->code_ordre);
        $this->get(route('admin.production.ordres.index', ['statut' => 'Brouillon']))->assertOk()->assertDontSee($ordre->code_ordre);
        $this->get(route('admin.production.fiches_techniques.index', ['recherche' => 'PF-001']))->assertOk()->assertSee('Baguette');

        // Le journal des mouvements montre la production.
        $this->get(route('admin.stock.mouvements'))->assertOk()->assertSee($ordre->code_ordre)->assertSee('Consommation');
    }

    /** L'ordre a une page de détail et un bon de fabrication imprimable : le module n'en avait aucun. */
    public function test_l_ordre_a_un_document_imprimable(): void
    {
        $candidates = collect(Route::getRoutes()->getRoutesByName())->keys()
            ->filter(fn ($n) => str_starts_with($n, 'admin.production.') && preg_match('/imprim|pdf|voir|show|detail/', $n));

        $this->assertNotEmpty($candidates->all(), 'Ni page de détail ni document imprimable pour un ordre de production.');
    }

    /** La liste montre le coût de revient de l'ordre validé : il n'apparaissait nulle part. */
    public function test_la_liste_des_ordres_montre_le_cout_de_revient(): void
    {
        $this->ecrireRecette();
        $this->valider($this->lancerOrdre(100));

        $this->get(route('admin.production.ordres.index'))->assertOk()->assertSee('11 000');
    }

    /** La vignette des recettes passe par `photo_url` : elle passait par asset('storage/…'), que le lot des photos a abandonné. */
    public function test_la_vignette_des_recettes_ne_depend_pas_du_lien_de_stockage(): void
    {
        $this->baguette->update(['photo' => 'produits/baguette.jpg']);
        $this->ecrireRecette();

        $this->get(route('admin.production.fiches_techniques.index'))->assertOk()
            ->assertDontSee(asset('storage/produits/baguette.jpg'), false);
    }

    /** Un ordre lancé pour un autre site que le site actif reste dans la liste : il disparaissait juste après sa création. */
    public function test_l_ordre_cree_pour_un_autre_site_reste_visible_apres_creation(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10, $this->boutique->id);

        $this->get(route('admin.production.ordres.index'))->assertOk()->assertSee($ordre->code_ordre);
    }

    // ═════════ Règles posées à la correction de la recette ═════════

    public function test_annuler_un_brouillon_ne_bouge_rien(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10);

        $this->from(route('admin.production.ordres.index'))
            ->post(route('admin.production.ordres.annuler', $ordre))->assertSessionHas('succes');

        $this->assertSame('Annulé', $ordre->fresh()->statut);
        $this->assertSame(100.0, $this->stock($this->farine));
        $this->assertSame(0, MouvementStock::where('sous_type', 'like', 'production%')->count());

        // Annulé, il ne se valide plus — et le dit.
        $this->valider($ordre)->assertSessionHas('erreur');
        $this->assertSame('Annulé', $ordre->fresh()->statut);
    }

    public function test_annuler_un_ordre_termine_contre_passe_stock_et_ecritures(): void
    {
        $solde = fn (string $compte) => (float) EcritureComptable::where('compte_debit', $compte)->sum('debit')
                                      - (float) EcritureComptable::where('compte_credit', $compte)->sum('credit');
        $avant = ['321000' => $solde('321000'), '361000' => $solde('361000')];

        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(100);
        $this->valider($ordre);
        $this->assertSame(11000.0, $solde('361000'));

        $this->post(route('admin.production.ordres.annuler', $ordre))->assertSessionHas('succes');

        $this->assertSame('Annulé', $ordre->fresh()->statut);
        $this->assertSame(100.0, $this->stock($this->farine));
        $this->assertSame(5.0, $this->stock($this->levure));
        $this->assertSame(0.0, $this->stock($this->baguette));
        // Les mouvements d'origine restent, chacun désigné par son inverse.
        $this->assertSame(3, MouvementStock::where('sous_type', MouvementStock::CONTREPASSATION)->count());
        // Les comptes de stock reviennent à leur solde d'avant la fabrication, au centime.
        foreach ($avant as $compte => $attendu) {
            $this->assertEqualsWithDelta($attendu, $solde($compte), 0.001, "Le compte {$compte} ne revient pas à son solde d'origine.");
        }
        $this->assertEqualsWithDelta((float) EcritureComptable::sum('debit'), (float) EcritureComptable::sum('credit'), 0.01);

        // Une seconde annulation ne contre-passe rien de plus.
        $this->post(route('admin.production.ordres.annuler', $ordre))->assertSessionHas('info');
        $this->assertSame(3, MouvementStock::where('sous_type', MouvementStock::CONTREPASSATION)->count());
    }

    public function test_l_annulation_d_un_ordre_dont_le_produit_est_parti_est_refusee(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(100);
        $this->valider($ordre);
        StockService::sortie($this->baguette, $this->fournil->id, 10, MouvementStock::LIVRAISON);

        $this->post(route('admin.production.ordres.annuler', $ordre))->assertSessionHas('erreur');

        $this->assertSame('Terminé', $ordre->fresh()->statut);
        $this->assertSame(90.0, $this->stock($this->baguette));
        $this->assertSame(0, MouvementStock::where('sous_type', MouvementStock::CONTREPASSATION)->count());
    }

    public function test_l_ordre_fige_la_recette_a_sa_creation(): void
    {
        $fiche = $this->ecrireRecette();
        $ordre = $this->lancerOrdre(100);

        // La recette change après le lancement : l'ordre garde ce qu'elle disait.
        $this->put(route('admin.production.fiches_techniques.modifier.enregistrer', $fiche), [
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 0.5, 'unite' => 'kg']],
        ])->assertSessionHasNoErrors();

        $this->valider($ordre);

        $this->assertSame(80.0, $this->stock($this->farine), '0,2 kg × 100, et non 0,5 kg × 100');
        $this->assertSame(4.5, $this->stock($this->levure));
    }

    public function test_une_unite_incompatible_avec_le_stock_est_refusee(): void
    {
        $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'produit_fini_id' => $this->baguette->id,
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 1, 'unite' => 'ml']],
        ])->assertSessionHasErrors('ingredients.0.unite');

        $this->assertSame(0, FicheTechnique::count());
    }

    public function test_le_formulaire_ne_propose_que_les_unites_compatibles(): void
    {
        $html = $this->get(route('admin.production.fiches_techniques.modifier', $this->ecrireRecette()))->assertOk()->getContent();

        // La farine est stockée en kg : kg et g, jamais l ni ml.
        preg_match('#name="ingredients\[0\]\[unite\]"[^>]*>(.*?)</select>#s', $html, $m);
        $this->assertStringContainsString('value="kg"', $m[1] ?? '');
        $this->assertStringContainsString('value="g"', $m[1] ?? '');
        $this->assertStringNotContainsString('value="ml"', $m[1] ?? '');
        $this->assertStringNotContainsString('value="l"', $m[1] ?? '');
    }

    public function test_un_ordre_se_dose_a_trois_decimales(): void
    {
        $this->ecrireRecette();

        $this->post(route('admin.production.ordres.enregistrer'), [
            'produit_fini_id' => $this->baguette->id, 'point_de_vente_id' => $this->fournil->id,
            'quantite_cible' => 1.0005, 'date_production' => '2026-10-08',
        ])->assertSessionHasErrors('quantite_cible');
    }

    public function test_la_recette_ne_se_supprime_pas_sous_un_ordre_en_brouillon(): void
    {
        $fiche = $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10);

        $this->delete(route('admin.production.fiches_techniques.supprimer', $fiche))->assertSessionHas('erreur');
        $this->assertSame(1, FicheTechnique::count());

        // L'ordre annulé, la recette se supprime.
        $this->post(route('admin.production.ordres.annuler', $ordre));
        $this->delete(route('admin.production.fiches_techniques.supprimer', $fiche))->assertSessionHas('succes');
        $this->assertSame(0, FicheTechnique::count());
    }

    public function test_le_produit_cree_par_la_saisie_libre_se_fabrique(): void
    {
        $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'nouveau_produit_fini_nom' => 'Pain de mie',
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 0.5, 'unite' => 'kg']],
        ])->assertSessionHasNoErrors();

        $pain = Produit::where('nom', 'Pain de mie')->sole();
        $this->assertNotEmpty($pain->reference);

        $this->post(route('admin.production.ordres.enregistrer'), [
            'produit_fini_id' => $pain->id, 'point_de_vente_id' => $this->fournil->id,
            'quantite_cible' => 10, 'date_production' => '2026-10-08',
        ])->assertSessionHasNoErrors();
        $ordre = OrdreProduction::latest('id')->first();

        // Les matières s'imputent : le garde-fou d'imputation ne doit pas bloquer.
        $this->valider($ordre)->assertSessionHas('succes');
        $this->assertSame('Terminé', $ordre->fresh()->statut);
        $this->assertSame(95.0, $this->stock($this->farine));
        $this->assertEqualsWithDelta((float) EcritureComptable::sum('debit'), (float) EcritureComptable::sum('credit'), 0.01);
    }

    public function test_la_saisie_libre_reprend_le_produit_fini_du_meme_nom(): void
    {
        $this->post(route('admin.production.fiches_techniques.enregistrer'), [
            'nouveau_produit_fini_nom' => 'baguette',
            'ingredients' => [['ingredient_id' => $this->farine->id, 'quantite' => 0.2, 'unite' => 'kg']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Produit::where('type', 'produit_fini')->count());
        $this->assertSame(1, FicheTechnique::where('produit_fini_id', $this->baguette->id)->count());
    }

    public function test_la_page_et_le_bon_de_fabrication_montrent_matieres_et_couts(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(100);

        $this->get(route('admin.production.ordres.imprimer', $ordre))->assertOk()
            ->assertSee('Bon de fabrication')->assertSee('Farine de blé')->assertSee('20 kg');

        $this->valider($ordre);

        foreach (['voir', 'imprimer'] as $page) {
            $this->get(route("admin.production.ordres.{$page}", $ordre))->assertOk()
                ->assertSee($ordre->code_ordre)
                ->assertSee('Farine de blé')->assertSee('Levure')
                ->assertSee('10 000')   // 20 kg × 500
                ->assertSee('11 000')   // coût de l'ordre
                ->assertSee('110,00');  // coût unitaire de la baguette
        }
    }

    public function test_le_filtre_propose_et_applique_le_statut_annule(): void
    {
        $this->ecrireRecette();
        $annule = $this->lancerOrdre(10);
        $this->post(route('admin.production.ordres.annuler', $annule));
        $brouillon = $this->lancerOrdre(5);

        $this->get(route('admin.production.ordres.index'))->assertOk()->assertSee('<option value="Annulé"', false);
        $this->get(route('admin.production.ordres.index', ['statut' => 'Annulé']))->assertOk()
            ->assertSee($annule->code_ordre)->assertDontSee($brouillon->code_ordre);
    }

    public function test_les_nouvelles_routes_ont_une_habilitation(): void
    {
        foreach (['annuler', 'voir', 'imprimer'] as $route) {
            $this->assertSame('production_ordres',
                \App\Modules\Authentification\Regles\Habilitations::PAR_ROUTE["admin.production.ordres.{$route}"] ?? null);
        }

        $caissier = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'K', 'email' => 'yao-annul@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'caissier', 'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->fournil->id,
            'habilitations' => [],
        ]);
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10);

        $this->actingAs($caissier);
        $this->assertContains($this->post(route('admin.production.ordres.annuler', $ordre))->status(), [302, 403]);
        $this->assertContains($this->get(route('admin.production.ordres.voir', $ordre))->status(), [302, 403]);
        $this->assertSame('Brouillon', $ordre->fresh()->statut);
    }

    public function test_une_autre_entreprise_n_atteint_ni_la_fiche_ni_l_annulation(): void
    {
        $this->ecrireRecette();
        $ordre = $this->lancerOrdre(10);

        $autre = Entreprise::create(['nom' => 'Pâtisserie rivale', 'modules_actifs' => ['principal', 'production', 'stock', 'produits']]);
        $sonSite = PointDeVente::create(['entreprise_id' => $autre->id, 'nom' => 'Labo rival', 'ville' => 'Abidjan', 'commune' => 'Yopougon']);
        $intrus = Utilisateur::create([
            'nom' => 'Rival', 'prenom' => 'R', 'email' => 'rival-annul@exemple.ci', 'password' => bcrypt('x'),
            'role' => 'admin', 'entreprise_id' => $autre->id, 'point_de_vente_id' => $sonSite->id,
        ]);

        $this->actingAs($intrus)->withSession(['point_de_vente_actif_id' => $sonSite->id]);
        $this->get(route('admin.production.ordres.voir', $ordre))->assertNotFound();
        $this->get(route('admin.production.ordres.imprimer', $ordre))->assertNotFound();
        $this->post(route('admin.production.ordres.annuler', $ordre))->assertNotFound();
        $this->assertSame('Brouillon', $ordre->fresh()->statut);
    }

    public function test_un_ordre_anterieur_sans_lignes_figees_se_valide_sur_la_recette_convertie_et_arrondie(): void
    {
        // Un ordre établi avant le 08/10/2026 n'a pas de lignes figées : il se
        // valide sur la recette en place, convertie et arrondie de même.
        $sel = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'MP-004', 'nom' => 'Sel fin',
            'type' => 'matiere_premiere', 'unite' => 'kg', 'prix_achat' => 300, 'prix_vente' => 0,
            'categorie_id' => $this->rayonMatieres->id, 'statut' => 'actif',
        ]);
        StockService::entree($sel, $this->fournil->id, 0.3, MouvementStock::RECEPTION, ['cout_unitaire' => 300]);
        $this->ecrireRecette([['ingredient_id' => $sel->id, 'quantite' => 100, 'unite' => 'g']]);

        $ordre = OrdreProduction::create([
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->fournil->id,
            'produit_fini_id' => $this->baguette->id, 'code_ordre' => 'OP-ANCIEN-1',
            'quantite_cible' => 3, 'statut' => 'Brouillon', 'date_production' => '2026-10-01',
        ]);
        $this->assertSame(0, $ordre->lignes()->count());

        $this->valider($ordre)->assertSessionHas('succes');
        $this->assertSame('Terminé', $ordre->fresh()->statut, '100 g × 3 = 0,3 kg, exactement le stock.');
        $this->assertSame(0.0, $this->stock($sel));
    }
}
