<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Les modèles d'import reflètent les champs de la plateforme (section 11 du
 * plan) : une colonne du modèle = un champ réellement saisi à l'écran.
 *
 * Un modèle qui promet une colonne que l'écran ne propose plus fait saisir
 * pour rien, et laisse croire que la donnée est entrée. Cette épreuve compare
 * les en-têtes téléchargés aux champs des formulaires, pour que le prochain
 * champ retiré d'un écran ne reste pas dans un modèle.
 */
class ModelesDImportTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    /**
     * Les colonnes du modèle qui ne sont pas des champs d'un formulaire, et
     * pourquoi. Toute autre colonne doit se retrouver à l'écran.
     */
    private const HORS_FORMULAIRE = [
        // Le nom de la famille plutôt que son identifiant : un fichier ne
        // connaît pas nos numéros de ligne.
        'categorie'      => 'categorie_id',
        'sous_categorie' => 'sous_categorie_id',
        // Le stock d'ouverture : il se pose par l'inventaire et par le
        // parcours de configuration, pas par la fiche — mais une migration a
        // besoin de lui dans la même feuille.
        'point_de_vente' => null,
        'stock_initial'  => null,
        'cout_unitaire'  => null,
        // L'archivage se fait d'un geste sur la fiche, pas d'un champ.
        'statut'         => null,
        // La référence se saisit : le script donne son nom au champ quand on
        // décoche « référence automatique », que l'écran porte, lui, d'emblée.
        'reference'      => 'reference_auto',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Bazar du marché', 'regime_imposition' => 'RNI', 'adresse' => 'Treichville',
            'rccm' => 'CI-ABJ-2026-B-00909', 'ncc' => '2601238E', 'gerant_fonction' => 'Gérant',
            'secteur_activite' => ['Commerce'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite', 'points_de_vente'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Bazar', 'ville' => 'Abidjan', 'commune' => 'Treichville']);
        $this->admin = Utilisateur::create([
            'nom' => 'Touré', 'prenom' => 'Issa', 'email' => 'issa-import@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);
        Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'BAZ-001', 'nom' => 'Seau',
            'type' => 'marchandise', 'prix_achat' => 1, 'prix_vente' => 2,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id]);
    }

    private function ouvrirLaComptabilite(): void
    {
        $this->entreprise->comptabilite_activee = true;
        $this->entreprise->save();
        $this->admin->unsetRelation('entreprise');
    }

    /** @return array<int, string> */
    private function entetes(string $module): array
    {
        $csv = $this->get(route('admin.import.exemple', ['type' => $module]))->assertOk()->getContent();

        return array_map(fn ($c) => trim($c, '"'), explode(';', strtok($csv, "\r\n")));
    }

    /** @return array<int, string> les champs que des écrans proposent */
    private function champs(string ...$ecrans): array
    {
        $noms = [];
        foreach ($ecrans as $ecran) {
            preg_match_all('/name="([a-z_]+)(?:\[[^"]*\])?"/', $this->get($ecran)->assertOk()->getContent(), $m);
            $noms = array_merge($noms, $m[1]);
        }

        return array_values(array_unique($noms));
    }

    private function assertCorrespond(string $module, array $champs): void
    {
        foreach ($this->entetes($module) as $colonne) {
            $attendu = array_key_exists($colonne, self::HORS_FORMULAIRE) ? self::HORS_FORMULAIRE[$colonne] : $colonne;

            if ($attendu === null) {
                continue;
            }

            $this->assertContains($attendu, $champs,
                "Le modèle « {$module} » promet la colonne « {$colonne} », qu'aucun écran ne propose.");
        }
    }

    // ── 11.4 — La correspondance, comptabilité fermée puis ouverte ───

    public function test_comptabilite_fermee_chaque_colonne_est_un_champ_de_l_ecran(): void
    {
        $this->assertCorrespond('clients', $this->champs(route('admin.clients.index')));
        $this->assertCorrespond('fournisseurs', $this->champs(route('admin.fournisseurs.index')));
        $this->assertCorrespond('produits', $this->champs(
            route('admin.produits.index'),
            route('admin.produits.fiche', Produit::firstOrFail())
        ));
    }

    public function test_comptabilite_ouverte_chaque_colonne_est_un_champ_de_l_ecran(): void
    {
        $this->ouvrirLaComptabilite();

        $this->assertCorrespond('clients', $this->champs(route('admin.clients.index')));
        $this->assertCorrespond('fournisseurs', $this->champs(route('admin.fournisseurs.index')));
        $this->assertCorrespond('produits', $this->champs(
            route('admin.produits.index'),
            route('admin.produits.fiche', Produit::firstOrFail())
        ));
    }

    // ── 11.2 / 11.3 — Ce qui a quitté les modèles ────────────────────

    public function test_les_modeles_ne_demandent_ni_compte_ni_numero_de_tiers(): void
    {
        foreach (['clients', 'fournisseurs', 'produits'] as $module) {
            $entetes = $this->entetes($module);

            $this->assertNotContains('numero_tiers', $entetes, $module);
            $this->assertEmpty(preg_grep('/^compte_/', $entetes), "{$module} : un compte subsiste.");
        }

        $this->get(route('admin.import.exemple', ['type' => 'immobilisations']))->assertNotFound();
    }

    public function test_le_compte_collectif_revient_avec_la_comptabilite(): void
    {
        $this->ouvrirLaComptabilite();

        $this->assertContains('compte_comptable', $this->entetes('clients'));
        $this->assertNotContains('numero_tiers', $this->entetes('clients'));
        $this->assertSame(200, $this->get(route('admin.import.exemple', ['type' => 'immobilisations']))->status());
    }

    public function test_un_ancien_modele_ne_fait_entrer_ni_compte_ni_numero(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'ancien') . '.csv';
        file_put_contents($chemin, "\"nom\";\"type_facturation\";\"compte_comptable\";\"numero_tiers\"\r\n\"Koné SARL\";\"B2C\";\"411900\";\"419999\"\r\n");

        $this->post(route('admin.import.importer', ['type' => 'clients']), [
            'fichier' => new UploadedFile($chemin, 'clients.csv', 'text/csv', null, true),
        ])->assertOk();

        $client = Client::where('nom', 'Koné SARL')->firstOrFail();
        $this->assertSame('411000', $client->compte_comptable);
        $this->assertNotSame('419999', $client->numero_tiers);
    }

    /**
     * Trouvé en comparant le modèle aux écrans : le prix de consignation et
     * le délai de retour ne se saisissaient nulle part. L'import était la
     * seule porte vers les consignations.
     */
    public function test_la_consignation_se_saisit_sur_la_fiche(): void
    {
        $seau = Produit::firstOrFail();

        $this->put(route('admin.produits.modifier', $seau), [
            'nom' => 'Seau', 'type' => 'marchandise', 'prix_achat' => 1, 'prix_vente' => 2,
            'taux_tva' => 18, 'stock_minimum' => 0,
            'prix_consignation' => 500, 'delai_retour_jours' => 30,
        ])->assertSessionHasNoErrors();

        $this->assertSame(500.0, (float) $seau->fresh()->prix_consignation);
        $this->assertSame(30, $seau->fresh()->delai_retour_jours);
    }
}
