<?php

namespace Tests\Feature;

use App\Jobs\DeverserOperationComptaflow;
use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Operation;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Services\NumerotationService;
use App\Modules\Authentification\Modeles\Utilisateur;
use App\Modules\Authentification\Regles\Habilitations;
use Database\Seeders\ReferentielSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Recette du 08/10/2026 : une entreprise créée de zéro, par l'interface.
 *
 * Chaque épreuve tient un défaut constaté pendant le parcours et corrigé dans
 * la foulée. Le parcours complet et les défauts laissés à d'autres sont dans
 * le rapport de recette (journal, même date).
 */
class RecetteNouvelleEntrepriseTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private PointDeVente $site;
    private Utilisateur $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom' => 'Atelier Baoulé SARL', 'regime_imposition' => 'TEE', 'adresse' => 'Riviera 3',
            'rccm' => 'CI-ABJ-2024-B-55555', 'ncc' => '1904455B', 'gerant_fonction' => 'Gérante',
            'centre_impots' => 'Cocody', 'secteur_activite' => ['Production'],
            'modules_actifs' => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite', 'points_de_vente'],
        ]);
        $this->site = PointDeVente::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Atelier Cocody', 'ville' => 'Abidjan', 'commune' => 'Cocody']);
        $this->admin = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Konan', 'email' => 'konan@recette.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id, 'point_de_vente_id' => $this->site->id,
        ]);
    }

    private function enAdmin(): static
    {
        return $this->actingAs($this->admin)->withSession(['point_de_vente_actif_id' => $this->site->id]);
    }

    private function parametres(): string
    {
        return $this->enAdmin()->get(route('admin.entreprise.parametres'))->assertOk()->getContent();
    }

    // ── Inscription ──

    public function test_un_ncc_tape_en_minuscules_est_accepte_a_l_inscription(): void
    {
        $this->seed(ReferentielSeeder::class);

        $this->post(route('inscription.traitement'), [
            'nom_entreprise' => 'Boutique Akwaba SARL', 'nom' => 'Kouassi', 'prenom' => 'Aya',
            'email' => 'aya@recette.ci', 'password' => 'Selflow!2026', 'password_confirmation' => 'Selflow!2026',
            'conditions' => '1', 'possede_compte_fne' => '1', 'fne_ncc' => '2603210a', 'fne_mot_de_passe' => 'mdp',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.tableau_de_bord'));

        $this->assertSame('2603210A', Entreprise::where('nom', 'Boutique Akwaba SARL')->value('ncc'));

        auth()->logout();

        $this->post(route('inscription.traitement'), [
            'nom_entreprise' => 'Atelier Yao', 'nom' => 'Yao', 'prenom' => 'Ali',
            'email' => 'ali@recette.ci', 'password' => 'Selflow!2026', 'password_confirmation' => 'Selflow!2026',
            'conditions' => '1', 'possede_compte_fne' => '0', 'ncc' => ' 1904 456c ',
        ])->assertSessionHasNoErrors();

        $this->assertSame('1904456C', Entreprise::where('nom', 'Atelier Yao')->value('ncc'));
    }

    // ── Paramètres ──

    public function test_les_champs_du_portail_portent_un_title_valide(): void
    {
        // L'attribut était écrit `title=\"…\"` : du HTML cassé, dont le
        // navigateur faisait six attributs. L'adresse exigée porte son astérisque.
        $this->entreprise->update(['possede_compte_fne' => true]);
        // Les champs ne se grisent qu'une fois le portail relevé.
        $import = \App\Modules\Admin\Modeles\PortailFneImport::create([
            'entreprise_id' => $this->entreprise->id, 'login' => 'X', 'date_scraping' => now()->toDateString(),
            'type' => \App\Modules\Admin\Modeles\PortailFneImport::TYPE_FICHE, 'fichier_nom' => 'X_20261008.json',
            'fichier_empreinte' => hash('sha256', uniqid('', true)), 'statut' => \App\Modules\Admin\Modeles\PortailFneImport::STATUT_IMPORTE,
        ]);
        \App\Modules\Admin\Modeles\PortailFneFiche::create([
            'import_id' => $import->id, 'entreprise_id' => $this->entreprise->id, 'login' => 'X', 'date_scraping' => now()->toDateString(),
        ]);

        $page = $this->parametres();

        $this->assertMatchesRegularExpression('/name="adresse" data-champ-portail\s+readonly title="Repris automatiquement de votre espace FNE"/', $page);
        $this->assertStringNotContainsString('title=\"', $page);
        $this->assertMatchesRegularExpression('/Adresse physique <span[^>]*>\*<\/span>/', $page);
    }

    public function test_le_reste_a_deverser_ne_montre_plus_de_directive_blade(): void
    {
        Queue::fake();
        $this->entreprise->forceFill(['comptaflow_sync_status' => 'active', 'comptaflow_sync_key' => 'cle'])->save();
        $this->operation(now()->toDateString());

        $page = $this->parametres();

        $this->assertStringContainsString('ne sont pas encore chez Comptaflow.', $page);
        $this->assertStringNotContainsString('@if(', $page);
        $this->assertStringNotContainsString('@endif', $page);
    }

    public function test_la_comptabilite_accordee_se_montre_cochee_et_verrouillee(): void
    {
        $this->entreprise->attributions = ['comptabilite'];
        $this->entreprise->comptabilite_activee = false;
        $this->entreprise->save();

        $page = $this->parametres();

        $this->assertMatchesRegularExpression(
            '/<input type="checkbox" name="comptabilite_activee"[^>]*\bchecked\b[^>]*\bdisabled\b/s',
            preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $page)
        );
    }

    // ── Tableau de bord ──

    public function test_la_banniere_dit_ce_qui_manque(): void
    {
        $this->entreprise->update(['adresse' => null]);

        $page = $this->enAdmin()->get(route('admin.tableau_de_bord'))->assertOk()->getContent();

        $this->assertStringContainsString(e("L'adresse de l'entreprise"), $page);
        $this->assertStringNotContainsString("inscription complète", $page);
        $this->assertStringContainsString('Configurez votre métier en cinq minutes', $page);
    }

    // ── Caissier ──

    public function test_le_caissier_ne_voit_que_les_menus_qu_il_peut_ouvrir(): void
    {
        $caissier = Utilisateur::create([
            'nom' => 'Traoré', 'prenom' => 'Awa', 'email' => 'awa@recette.ci',
            'password' => bcrypt('x'), 'role' => 'caissier', 'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->site->id, 'habilitations' => ['tableau_de_bord_personnel', 'nouvelle_vente'],
        ]);

        $page = $this->actingAs($caissier)->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->get(route('caissier.ventes.nouvelle'))->assertOk()->getContent();

        $this->assertStringContainsString('Nouvelle vente', $page);
        foreach (['Mes factures', 'Consulter stock', 'Mes encaissements', '> Facturation', 'fa-gear'] as $absent) {
            $this->assertStringNotContainsString($absent, $page, $absent);
        }

        // Avec l'habilitation, le lien revient.
        $caissier->update(['habilitations' => ['nouvelle_vente', 'stock_articles']]);
        $page = $this->actingAs($caissier->fresh())->withSession(['point_de_vente_actif_id' => $this->site->id])
            ->get(route('caissier.ventes.nouvelle'))->getContent();
        $this->assertStringContainsString('Consulter stock', $page);
        $this->assertTrue(Habilitations::ouvre($caissier->fresh(), 'caissier.stock.index'));
        $this->actingAs($caissier->fresh())->get(route('caissier.stock.index'))->assertOk();
    }

    // ── Import ──

    private function xlsxEnrichi(array $lignes): UploadedFile
    {
        $classeur = new Spreadsheet();
        $feuille = $classeur->getActiveSheet();

        foreach ($lignes as $i => $ligne) {
            foreach (array_values($ligne) as $j => $valeur) {
                $cellule = $feuille->getCell([$j + 1, $i + 1]);
                if (is_string($valeur) && $valeur !== '') {
                    $texte = new RichText();
                    $texte->createText($valeur);
                    $cellule->setValue($texte);
                } else {
                    $cellule->setValue($valeur);
                }
            }
        }

        $chemin = tempnam(sys_get_temp_dir(), 'recette') . '.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'catalogue.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_l_apercu_d_un_classeur_lit_le_texte_enrichi(): void
    {
        $fichier = $this->xlsxEnrichi([['nom', 'type', 'prix_achat', 'prix_vente'], ['Farine T55', 'matiere_premiere', 14000, 0]]);

        $reponse = $this->enAdmin()->post(route('admin.import.preview', ['type' => 'produits']), ['fichier' => $fichier])->assertOk();

        $this->assertSame(['nom', 'type', 'prix_achat', 'prix_vente'], $reponse->json('entetes'));
        $this->assertSame('Farine T55', $reponse->json('lignes.0.0'));
    }

    public function test_un_produit_fini_et_une_matiere_premiere_s_importent_comme_a_l_ecran(): void
    {
        CodeJournal::create(['entreprise_id' => $this->entreprise->id, 'code' => 'OD', 'intitule' => 'Opérations diverses', 'type' => 'OD']);
        Categorie::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Production', 'prefixe' => 'PRO']);

        $fichier = $this->xlsxEnrichi([
            ['nom', 'type', 'categorie', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Brioche', 'produit_fini', 'Production', 0, 1200, 18, 'BRIO-1'],
            ['Farine T55', 'matiere_premiere', 'Production', 14000, 0, 18, 'FAR-1'],
            ['Marteau', 'marchandise', 'Production', 0, 4500, 18, 'MART-1'],
        ]);

        $reponse = $this->enAdmin()->post(route('admin.import.importer', ['type' => 'produits']), ['fichier' => $fichier]);

        $this->assertSame(2, $reponse->json('importes'), json_encode($reponse->json('erreurs')));
        $this->assertSame(1, Produit::where('reference', 'BRIO-1')->count());
        $this->assertSame(1, Produit::where('reference', 'FAR-1')->count());
        // La marchandise garde son prix d'achat obligatoire.
        $this->assertSame(0, Produit::where('reference', 'MART-1')->count());
    }

    // ── Numérotation ──

    public function test_un_achat_date_d_un_autre_exercice_ne_fait_pas_doublonner_le_numero(): void
    {
        $fournisseur = Fournisseur::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Grands Moulins']);
        $premier = NumerotationService::genererNumeroAchat($this->entreprise->id, 'Facture');
        DB::table('achats')->insert([
            'point_de_vente_id' => $this->site->id, 'fournisseur_id' => $fournisseur->id,
            'numero_facture' => $premier, 'date_achat' => now()->subYear()->toDateString(),
            'mode_paiement' => 'Caisse', 'montant_ht' => 4500, 'montant_tva' => 810, 'montant_ttc' => 5310,
        ]);

        // L'exercice affiché est l'exercice en cours : l'achat de l'an dernier
        // n'y figure pas, et ne comptait donc plus.
        session(['active_periode_debut' => now()->startOfYear()->toDateString(), 'active_periode_fin' => now()->endOfYear()->toDateString()]);

        $this->assertNotSame($premier, NumerotationService::genererNumeroAchat($this->entreprise->id, 'Facture'));
    }

    // ── Comptaflow ──

    private function operation(string $date): Operation
    {
        $operation = Operation::creer($this->entreprise->id, $this->site->id, $date, 'test', 'ACH', 'ACH-' . $date, 'Achat');
        foreach ([['compte_debit' => '601000', 'debit' => 4500, 'credit' => 0], ['compte_credit' => '401000', 'debit' => 0, 'credit' => 4500]] as $ligne) {
            EcritureComptable::create($ligne + [
                'operation_id' => $operation->id, 'entreprise_id' => $this->entreprise->id,
                'point_de_vente_id' => $this->site->id, 'date_ecriture' => $date,
                'libelle' => 'Achat', 'reference_document' => 'ACH-' . $date, 'code_journal' => 'ACH',
            ]);
        }
        $operation->cloturerEquilibre();

        return $operation;
    }

    public function test_une_operation_d_un_exercice_anterieur_part_meme_avec_la_periode_affichee(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true], 200)]);
        config(['selflow.comptaflow_api_secret' => 'secret-de-test']);
        $this->entreprise->forceFill(['comptaflow_sync_status' => 'active', 'comptaflow_sync_key' => 'cle'])->save();

        $operation = $this->operation(now()->subYear()->format('Y') . '-06-15');

        // La tâche tourne dans la requête (file `sync`) : la session porte
        // l'exercice en cours, et le filtre de période vidait l'opération.
        session(['active_periode_debut' => now()->startOfYear()->toDateString(), 'active_periode_fin' => now()->endOfYear()->toDateString()]);

        (new DeverserOperationComptaflow($operation->id))->handle();

        Http::assertSent(fn ($requete) => str_ends_with($requete->url(), '/api/external/ecritures/deverser')
            && count($requete['ecritures']) === 2);
        $this->assertSame(2, EcritureComptable::withoutGlobalScopes()->where('operation_id', $operation->id)
            ->where('comptaflow_sync_status', 'synced')->count());
    }

    // ── Superadministrateur ──

    private function superadmin(): Utilisateur
    {
        return Utilisateur::create([
            'nom' => 'Plateforme', 'prenom' => 'Super', 'email' => 'super@recette.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'superadmin', 'habilitations' => Habilitations::PLATEFORME,
        ]);
    }

    public function test_voir_les_acces_fne_appelle_l_entreprise_par_son_identifiant_d_url(): void
    {
        FneCredential::updateOrCreate(['entreprise_id' => $this->entreprise->id], [
            'ncc_associe' => '1904455B', 'acces_mot_de_passe' => 'Mdp-Espace', 'acces_fourni_at' => now(),
        ]);
        $super = $this->superadmin();

        $page = $this->actingAs($super)->get(route('superadmin.entreprises'))->assertOk()->getContent();

        // La route se lie par l'uuid : le numéro donnait un 404.
        $this->assertStringContainsString("ouvrirAccesFne('" . $this->entreprise->uuid . "'", $page);
        $this->assertStringNotContainsString('ouvrirAccesFne(' . $this->entreprise->id . ',', $page);

        $this->actingAs($super)->postJson(route('superadmin.fne.voir_acces', $this->entreprise->uuid), ['mot_de_passe' => 'secret-de-test'])
            ->assertOk()->assertJson(['success' => true, 'mot_de_passe' => 'Mdp-Espace']);
    }

    public function test_le_tableau_de_bord_superadmin_nomme_les_modules(): void
    {
        $page = $this->actingAs($this->superadmin())->get(route('superadmin.tableau_de_bord'))->assertOk()->getContent();

        $this->assertStringNotContainsString('>Points_de_vente<', $page);
        $this->assertStringNotContainsString('{{ $mod }}', $page);
        $this->assertStringContainsString(Entreprise::libelleModule('points_de_vente'), $page);
    }

    // ── Points de vente ──

    public function test_sans_releve_du_portail_aucun_site_n_est_dit_inconnu(): void
    {
        $page = $this->enAdmin()->get(route('admin.pdv.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('inconnu(s) du portail', $page);
    }

    // ── Défauts laissés au lot 61, corrigés ensuite ──

    private function articleDansUneNouvelleCategorie(string $type, string $nom, string $categorie): Produit
    {
        $this->enAdmin()->post(route('admin.produits.creer'), [
            'nom' => $nom, 'type' => $type,
            'categorie_id' => 'nouvelle', 'nouvelle_categorie' => $categorie,
            'prix_achat' => 400, 'prix_vente' => 700, 'taux_tva' => 18,
            'stock_actuel' => 10, 'stock_minimum' => 2,
        ])->assertSessionHasNoErrors();

        return Produit::where('nom', $nom)->firstOrFail();
    }

    public function test_une_categorie_creee_depuis_la_fiche_article_recoit_ses_comptes_de_stock(): void
    {
        $farine = $this->articleDansUneNouvelleCategorie('matiere_premiere', 'Farine T55', 'Farines');
        $pain   = $this->articleDansUneNouvelleCategorie('produit_fini', 'Pain de mie', 'Boulangerie');

        $this->assertSame(['320000', '603200'], [$farine->categorieRelation->compte_stock, $farine->categorieRelation->compte_variation]);
        $this->assertSame(['360000', '736000'], [$pain->categorieRelation->compte_stock, $pain->categorieRelation->compte_variation]);

        // Le stock de départ passe donc en comptabilité : sans compte, il
        // n'écrivait rien, et rien ne le disait.
        $this->assertSame(1, EcritureComptable::withoutGlobalScopes()->where('compte_debit', '320000')
            ->where('reference_document', $farine->reference)->count());
    }

    public function test_un_article_sans_compte_de_stock_le_dit_a_l_enregistrement(): void
    {
        $this->entreprise->forceFill(['comptabilite_activee' => true])->save();
        $vieux = Categorie::create(['entreprise_id' => $this->entreprise->id, 'nom' => 'Rayon sans compte', 'prefixe' => 'RSC']);

        $this->enAdmin()->post(route('admin.produits.creer'), [
            'nom' => 'Sucre en morceaux', 'type' => 'marchandise', 'categorie_id' => (string) $vieux->id,
            'prix_achat' => 400, 'prix_vente' => 700, 'taux_tva' => 18,
            'stock_actuel' => 0, 'stock_minimum' => 2,
        ])->assertSessionHasNoErrors()->assertSessionHas('avertissement');
    }

    public function test_les_regimes_des_tiers_sont_ceux_de_l_entreprise(): void
    {
        $page = $this->enAdmin()->get(route('admin.clients.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString("Taxe sur l'Entreprise Employeuse", html_entity_decode($page, ENT_QUOTES));
        $this->assertStringNotContainsString('value="RS"', $page);
        $this->assertStringNotContainsString('value="Exonéré"', $page);
        foreach (array_keys(Entreprise::REGIMES_IMPOSITION) as $code) {
            $this->assertStringContainsString('value="' . $code . '"', $page);
        }

        // La validation suit la liste : un intitulé qui n'est pas un régime
        // est refusé, un ancien sigle se range sous son code.
        $this->enAdmin()->post(route('admin.clients.creer'), [
            'nom' => 'Société fictive', 'type_facturation' => 'B2C', 'regime_imposition' => 'Bénéfice forfaitaire',
        ])->assertSessionHasErrors('regime_imposition');

        $this->enAdmin()->post(route('admin.fournisseurs.creer'), [
            'nom' => 'Grossiste du Nord', 'type_facturation' => 'B2C', 'regime_imposition' => 'RS',
        ])->assertSessionHasNoErrors();
        $this->assertSame('RSI', Fournisseur::where('nom', 'Grossiste du Nord')->value('regime_imposition'));
    }

    public function test_une_fiche_de_tiers_a_l_ancien_regime_reste_modifiable(): void
    {
        $client = \App\Modules\Admin\Modeles\Client::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Coopérative', 'type_facturation' => 'B2B',
            'ncc' => '1904455B', 'regime_imposition' => 'Exonéré',
        ]);

        $this->enAdmin()->put(route('admin.clients.modifier', $client), [
            'nom' => 'Coopérative agricole', 'type_facturation' => 'B2B', 'ncc' => '1904455B', 'regime_imposition' => 'Exonéré',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Coopérative agricole', $client->fresh()->nom);
    }

    public function test_les_anciens_regimes_des_tiers_se_rangent_sous_leur_code(): void
    {
        $fiche = fn (string $nom, string $regime) => \App\Modules\Admin\Modeles\Client::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => $nom, 'type_facturation' => 'B2C', 'regime_imposition' => $regime,
        ]);
        $simplifie = $fiche('Ancien RS', 'RS');
        $minuscule = $fiche('Ancien rni', 'rni');
        $exonere   = $fiche('Ancien exonéré', 'Exonéré');

        (require base_path('app/Modules/Admin/Migrations/2026_10_09_000001_les_regimes_des_tiers.php'))->up();

        $this->assertSame('RSI', $simplifie->fresh()->regime_imposition);
        $this->assertSame('RNI', $minuscule->fresh()->regime_imposition);
        // Sans équivalent : gardé tel quel, et toléré par le formulaire.
        $this->assertSame('Exonéré', $exonere->fresh()->regime_imposition);
    }

    public function test_un_client_b2g_garde_son_ncc(): void
    {
        $this->enAdmin()->post(route('admin.clients.creer'), [
            'nom' => 'Mairie de Cocody', 'type_facturation' => 'B2G', 'ncc' => '0102030a',
        ])->assertSessionHasNoErrors();

        $mairie = \App\Modules\Admin\Modeles\Client::where('nom', 'Mairie de Cocody')->firstOrFail();
        $this->assertSame('0102030A', $mairie->ncc);

        $this->enAdmin()->put(route('admin.clients.modifier', $mairie), [
            'nom' => 'Mairie de Cocody', 'type_facturation' => 'B2G', 'ncc' => '0102030A', 'telephone' => '0707070707',
        ])->assertSessionHasNoErrors();
        $this->assertSame('0102030A', $mairie->fresh()->ncc);

        // Un particulier, lui, n'en garde pas.
        $this->enAdmin()->put(route('admin.clients.modifier', $mairie), [
            'nom' => 'Mairie de Cocody', 'type_facturation' => 'B2C', 'ncc' => '0102030A',
        ])->assertSessionHasNoErrors();
        $this->assertNull($mairie->fresh()->ncc);
    }

    public function test_un_bon_de_commande_d_achat_ne_reprend_pas_le_numero_d_une_vente(): void
    {
        $vente = NumerotationService::genererNumeroVente($this->entreprise->id, 'Bon de commande');
        $achat = NumerotationService::genererNumeroAchat($this->entreprise->id, 'Bon de commande');

        // Les deux étaient BC-jj-mm-aaaa-0001.
        $this->assertNotSame($vente, $achat);
        // `BC-` reste celui de la vente : la trésorerie et l'API l'y rattachent.
        $this->assertStringStartsWith('BC-', $vente);
        $this->assertStringStartsWith('BCF-', $achat);
    }
}
