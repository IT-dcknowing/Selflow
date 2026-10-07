<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\PortailFneFiche;
use App\Modules\Admin\Modeles\PortailFneImport;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Le compte sur la plateforme FNE, dans les paramètres (section 10 du plan) :
 * ne demander que ce qui manque.
 *
 * Écran et lecture du relevé seulement : rien ici ne touche à ce qui part à
 * la DGI (périmètre gelé, `CLAUDE.md`).
 */
class CompteFneParametresTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Pharmacie des Deux-Plateaux', 'regime_imposition' => 'RNI',
            'adresse' => 'Cocody, Abidjan', 'rccm' => 'CI-ABJ-2026-B-00077',
            'ncc' => '2601237D', 'gerant_fonction' => 'Gérant', 'telephone' => '0707070707',
            'secteur_activite' => ['Santé'],
            'modules_actifs' => ['principal', 'ventes', 'produits', 'tiers', 'points_de_vente'],
        ]);
        $site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Officine', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'Diomandé', 'prenom' => 'Awa', 'email' => 'awa-fne@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $site->id,
        ]);

        $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $site->id]);
    }

    private function aUnCompte(?bool $oui): void
    {
        $this->entreprise->update(['possede_compte_fne' => $oui]);
        $this->admin->unsetRelation('entreprise');
    }

    private function page(): string
    {
        return $this->get(route('admin.entreprise.parametres'))->assertOk()->getContent();
    }

    private function releve(array $champs): PortailFneFiche
    {
        $import = PortailFneImport::create([
            'entreprise_id' => $this->entreprise->id, 'login' => '2601237D', 'date_scraping' => '2026-10-01',
            'type' => 'fiche', 'fichier_nom' => '2601237D_20261001.json', 'fichier_empreinte' => str_repeat('a', 64),
        ]);

        return PortailFneFiche::create($champs + [
            'import_id' => $import->id, 'entreprise_id' => $this->entreprise->id,
            'login' => '2601237D', 'date_scraping' => '2026-10-01',
        ]);
    }

    // ── 10.2 — « Je n'en ai pas encore » : seulement ce qui manque ───

    public function test_sans_compte_seules_les_informations_manquantes_sont_listees(): void
    {
        $this->aUnCompte(false);

        $page = $this->page();

        // Le centre des impôts manque ; le NCC et le RCCM ont été pris à
        // l'inscription, et ne se redemandent pas.
        $this->assertStringContainsString('Centre des impôts', $page);
        $this->assertStringNotContainsString('NCC — Numéro de Compte Contribuable', $page);
        $this->assertStringNotContainsString('Registre du Commerce et du Crédit Mobilier', $page);
    }

    // ── 10.3 — « J'ai déjà un compte » : NCC et mot de passe s'ils manquent ──

    public function test_le_ncc_et_le_mot_de_passe_connus_ne_se_redemandent_pas(): void
    {
        $this->aUnCompte(true);
        FneCredential::create(['entreprise_id' => $this->entreprise->id, 'ncc_associe' => '2601237D', 'cle_reelle' => 'cle-de-production']);

        $page = $this->page();

        $this->assertStringNotContainsString('name="fne_ncc"', $page);
        $this->assertStringNotContainsString('name="fne_mot_de_passe"', $page);
    }

    public function test_le_mot_de_passe_se_demande_tant_que_l_acces_n_est_pas_donne(): void
    {
        $this->aUnCompte(true);

        $page = $this->page();

        $this->assertStringContainsString('name="fne_mot_de_passe"', $page);
        $this->assertStringNotContainsString('name="fne_ncc"', $page, 'Le NCC est connu depuis l\'inscription.');

        $this->put(route('admin.entreprise.parametres.enregistrer'), [
            'nom' => $this->entreprise->nom, 'gerant_fonction' => 'Gérant', 'adresse' => 'Cocody, Abidjan',
            'rccm' => 'CI-ABJ-2026-B-00077', 'ncc' => '2601237D', 'fne_mot_de_passe' => 'secret-de-l-espace',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(FneCredential::where('entreprise_id', $this->entreprise->id)->value('acces_fourni_at'));

        // L'épreuve garde le même utilisateur d'une requête à l'autre ; une
        // requête réelle le rebâtit, relation comprise.
        $this->admin->unsetRelation('entreprise');
        $this->assertStringNotContainsString('name="fne_mot_de_passe"', $this->page());
    }

    // ── 10.4 — Ce que le portail détient ne se redemande pas ─────────

    public function test_un_champ_releve_et_identique_ne_se_saisit_plus(): void
    {
        $this->aUnCompte(true);
        $this->entreprise->update(['commune' => 'COCODY', 'quartier' => 'Deux-Plateaux']);
        $this->releve(['commune' => 'COCODY', 'quartier' => 'Riviera', 'idu' => 'CI-001-2025-A1']);

        $page = $this->page();

        $this->assertStringNotContainsString('name="commune"', $page, 'Identique au portail : réglé.');
        $this->assertStringContainsString('name="quartier"', $page, 'Différent : il reste à l\'écran tant qu\'on ne l\'a pas repris.');
        $this->assertStringContainsString('name="idu"', $page, 'Absent chez nous : il reste à l\'écran.');
        $this->assertStringContainsString('name="centre_impots"', $page, 'Le portail ne le rend pas : il reste à saisir.');
        $this->assertStringContainsString('Reprendre les valeurs du portail', $page);
    }

    public function test_sans_compte_tout_reste_a_saisir(): void
    {
        $this->aUnCompte(false);
        $this->entreprise->update(['commune' => 'COCODY']);
        $this->releve(['commune' => 'COCODY']);

        $this->assertStringContainsString('name="commune"', $this->page());
    }

    public function test_reprendre_le_releve_copie_le_descriptif_et_rien_d_autre(): void
    {
        $this->aUnCompte(true);
        $this->releve([
            'quartier' => 'Riviera', 'idu' => 'CI-001-2025-A1', 'pied_de_page_facture' => 'Merci de votre visite',
            'timbre_quittance' => true, 'bapa' => true, 'sticker_solde_alerte' => 50,
        ]);

        $this->post(route('admin.entreprise.portail.reprendre'))->assertSessionHas('succes');

        $fiche = $this->entreprise->fresh();
        $this->assertSame('Riviera', $fiche->quartier);
        $this->assertSame('CI-001-2025-A1', $fiche->idu);
        $this->assertSame('Merci de votre visite', $fiche->pied_de_page_facture);
        $this->assertNotEquals(50, $fiche->sticker_solde_alerte, 'Le solde d\'alerte commande un comportement : il ne se reprend pas d\'office.');
        $this->assertFalse((bool) $fiche->timbre_quittance, 'Le timbre change ce que paie le client : jamais repris d\'office.');

        $this->admin->unsetRelation('entreprise');
        $this->assertStringNotContainsString('name="quartier"', $this->page());
    }

    // ── 10.5 — Le logo FNE, posé par le système ──────────────────────

    public function test_le_logo_fne_est_affiche_et_ne_se_depose_plus(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('logo-FNE.png', $page);
        $this->assertStringNotContainsString('name="logo_fne"', $page);

        $this->put(route('admin.entreprise.parametres.enregistrer'), [
            'nom' => $this->entreprise->nom, 'gerant_fonction' => 'Gérant', 'adresse' => 'Cocody, Abidjan',
            'rccm' => 'CI-ABJ-2026-B-00077', 'ncc' => '2601237D',
            'logo_fne' => UploadedFile::fake()->image('autre.png'),
        ])->assertSessionHasNoErrors();

        $this->assertNull($this->entreprise->fresh()->logo_fne_path);
    }
}
