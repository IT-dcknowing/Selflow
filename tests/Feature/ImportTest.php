<?php

namespace Tests\Feature;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Immobilisation;
use App\Modules\Admin\Modeles\Lot;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Les modèles d'importation.
 *
 * L'import existait pour cinq modules, et il portait quatre défauts dont deux
 * arrêtaient net une migration :
 *
 * - **une ligne plus longue que l'en-tête tuait tout le fichier.**
 *   `array_combine` lève une erreur dès que les deux tableaux n'ont pas la même
 *   taille, et un simple point-virgule en fin de ligne — que produisent Excel
 *   et LibreOffice — suffisait. Le fichier entier partait en « Erreur
 *   critique », sans dire quelle ligne ;
 * - **`firstOrCreate(['email' => …])` cherchait sur l'adresse seule**, sans
 *   borne d'entreprise. L'adresse est unique sur toute la plateforme : celle
 *   d'un autre inscrit faisait que rien n'était créé, et **la ligne comptait
 *   pour un succès**. C'était aussi un oracle d'existence silencieux ;
 * - **un service ne pouvait pas s'importer** : `prix_achat > 0` était exigé de
 *   tous. Un cabinet comptable, dont tous les articles sont des missions, ne
 *   passait aucune ligne ;
 * - **le modèle des articles portait douze colonnes** là où la fiche en compte
 *   deux fois plus. Le stock d'ouverture, les comptes de stock, le suivi par
 *   lot, la consignation : tout se ressaisissait fiche par fiche.
 */
class ImportTest extends TestCase
{
    use RefreshDatabase;

    private Entreprise $entreprise;
    private Utilisateur $admin;
    private PointDeVente $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entreprise = Entreprise::create([
            'nom'               => 'Quincaillerie du Bandama',
            'regime_imposition' => 'RNI',
            'adresse'           => 'Cocody, Abidjan',
            'rccm'              => 'CI-ABJ-2026-B-00555',
            'ncc'               => '2601234A',
            'gerant_fonction'   => 'Gérant',
            'secteur_activite'  => ['Commerce'],
            'modules_actifs'    => ['principal', 'ventes', 'achats', 'stock', 'produits', 'tiers', 'comptabilite'],
        ]);
        // Le parc d'immobilisations ne s'importe qu'à qui tient ses livres
        // (lot 42 pour l'écran, lot 47 pour le modèle). Le cas fermé est
        // éprouvé dans ModelesDImportTest.
        $this->entreprise->comptabilite_activee = true;
        $this->entreprise->save();

        $this->magasin = PointDeVente::create([
            'entreprise_id' => $this->entreprise->id,
            'nom' => 'Agence Cocody', 'ville' => 'Abidjan', 'commune' => 'Cocody',
        ]);

        CodeJournal::create([
            'entreprise_id' => $this->entreprise->id,
            'code' => 'OD', 'intitule' => 'Opérations diverses', 'type' => 'OD',
        ]);

        Categorie::create([
            'entreprise_id' => $this->entreprise->id, 'nom' => 'Fournitures', 'prefixe' => 'FOU',
        ]);

        $this->admin = Utilisateur::create([
            'nom' => 'Yao', 'prenom' => 'Adjoua', 'email' => 'adjoua@exemple.ci',
            'password' => bcrypt('secret-de-test'), 'role' => 'admin',
            'entreprise_id' => $this->entreprise->id,
            'point_de_vente_id' => $this->magasin->id,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['point_de_vente_actif_id' => $this->magasin->id]);
    }

    /**
     * Un CSV, tel qu'un tableur le produit : point-virgule et guillemets.
     *
     * @param  array<int, array<int, string>>  $lignes
     */
    private function csv(array $lignes): UploadedFile
    {
        $contenu = '';

        foreach ($lignes as $ligne) {
            $contenu .= implode(';', array_map(
                fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', $ligne
            )) . "\r\n";
        }

        $chemin = tempnam(sys_get_temp_dir(), 'import') . '.csv';
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, 'import.csv', 'text/csv', null, true);
    }

    private function importer(string $type, array $lignes)
    {
        return $this->post(route('admin.import.importer', ['type' => $type]), [
            'fichier' => $this->csv($lignes),
        ]);
    }

    // ══════════════ Ce qui tuait un fichier entier ══════════════

    public function test_une_ligne_plus_longue_que_l_entete_n_arrete_plus_l_import(): void
    {
        // **Un point-virgule en fin de ligne suffisait** — et Excel comme
        // LibreOffice en produisent. Le fichier entier partait en « Erreur
        // critique », sans dire quelle ligne.
        $reponse = $this->importer('points-de-vente', [
            ['nom', 'ville', 'commune', 'responsable', 'telephone', 'statut'],
            ['Agence Plateau', 'Abidjan', 'PLATEAU', 'Aya Boni', '0707000002', 'actif', ''],
        ]);

        $reponse->assertOk();
        $this->assertTrue($reponse->json('success'));
        $this->assertSame(1, $reponse->json('importes'));
        $this->assertSame(1, PointDeVente::where('nom', 'Agence Plateau')->count());
    }

    public function test_une_ligne_plus_courte_est_completee(): void
    {
        $reponse = $this->importer('points-de-vente', [
            ['nom', 'ville', 'commune', 'responsable', 'telephone', 'statut'],
            ['Agence Bouaké', 'Bouaké'],
        ]);

        $this->assertSame(1, $reponse->json('importes'));
    }

    public function test_les_entetes_se_lisent_quels_que_soient_la_casse_et_les_accents(): void
    {
        // Un fichier enregistre depuis Excel ecrit volontiers « Référence » la
        // ou le modele attend « reference ».
        $reponse = $this->importer('produits', [
            ['Nom', 'Type', 'Prix Achat', 'Prix Vente', 'Taux TVA', 'Référence'],
            ['Marteau', 'marchandise', '3000', '4500', '18', 'MART-001'],
        ]);

        $this->assertSame(1, $reponse->json('importes'), json_encode($reponse->json('erreurs')));
        $this->assertSame('Marteau', Produit::where('reference', 'MART-001')->value('nom'));
    }

    public function test_deux_colonnes_du_meme_nom_ne_se_recouvrent_pas(): void
    {
        // `array_combine` n'en garde silencieusement que la derniere.
        $reponse = $this->importer('points-de-vente', [
            ['nom', 'nom', 'ville', 'commune', 'responsable', 'telephone'],
            ['Agence San Pedro', 'ignoré', 'San Pedro', 'San Pedro', 'Koffi', '0700000000'],
        ]);

        $this->assertSame(1, $reponse->json('importes'));
        $this->assertSame(1, PointDeVente::where('nom', 'Agence San Pedro')->count());
    }

    // ══════════════ L'adresse déjà prise ══════════════

    public function test_une_adresse_deja_prise_ailleurs_est_refusee_et_non_comptee_pour_un_succes(): void
    {
        // **Le défaut principal côté sécurité.** L'adresse est unique sur toute
        // la plateforme : `firstOrCreate` sur l'adresse seule trouvait le
        // compte d'une autre entreprise, ne créait rien, et la ligne comptait
        // pour un succès. L'administrateur annonçait alors un accès à un
        // salarié qui ne pourrait jamais se connecter — et le compteur faisait
        // un oracle d'existence silencieux.
        $autre = Entreprise::create(['nom' => 'Quincaillerie rivale']);
        Utilisateur::create([
            'nom' => 'Rival', 'prenom' => 'Jean', 'email' => 'convoite@exemple.ci',
            'password' => bcrypt('x'), 'role' => 'admin', 'entreprise_id' => $autre->id,
        ]);

        $reponse = $this->importer('utilisateurs', [
            ['nom', 'prenom', 'email', 'role', 'point_de_vente', 'fonction', 'date_debut_contrat', 'statut'],
            ['Kouassi', 'Paul', 'convoite@exemple.ci', 'caissier', 'Agence Cocody', 'Caissier', '01/01/2026', 'actif'],
        ]);

        $this->assertSame(0, $reponse->json('importes'), 'La ligne ne doit pas compter pour un succès.');
        $this->assertCount(1, $reponse->json('erreurs'));

        $vole = Utilisateur::where('email', 'convoite@exemple.ci')->firstOrFail();

        $this->assertSame($autre->id, $vole->entreprise_id, 'Le compte reste chez son propriétaire.');
        $this->assertSame('Rival', $vole->nom, 'Ni son nom ni son rôle ne sont écrasés.');
        $this->assertSame('admin', $vole->role);
    }

    public function test_une_adresse_deja_inscrite_chez_soi_ne_leve_pas_d_erreur(): void
    {
        // Reimporter le meme fichier ne doit pas se plaindre : c'est le geste
        // le plus courant apres une correction partielle.
        $reponse = $this->importer('utilisateurs', [
            ['nom', 'prenom', 'email', 'role', 'point_de_vente', 'fonction', 'date_debut_contrat', 'statut'],
            ['Yao', 'Adjoua', 'adjoua@exemple.ci', 'caissier', 'Agence Cocody', 'Caissier', '01/01/2026', 'actif'],
        ]);

        $this->assertSame(1, $reponse->json('importes'));
        $this->assertEmpty($reponse->json('erreurs'));
        $this->assertSame('admin', $this->admin->fresh()->role, 'Le rôle en place n\'est pas rétrogradé.');
    }

    public function test_un_utilisateur_importe_doit_changer_son_mot_de_passe(): void
    {
        $this->importer('utilisateurs', [
            ['nom', 'prenom', 'email', 'role', 'point_de_vente', 'fonction', 'date_debut_contrat', 'statut'],
            ['Konan', 'Ama', 'ama@exemple.ci', 'caissier', 'Agence Cocody', 'Caissier', '01/01/2026', 'actif'],
        ]);

        $nouveau = Utilisateur::where('email', 'ama@exemple.ci')->firstOrFail();

        $this->assertTrue((bool) $nouveau->doit_changer_password);
        $this->assertSame($this->entreprise->id, $nouveau->entreprise_id);
    }

    public function test_un_role_hors_liste_retombe_sur_le_moins_puissant(): void
    {
        // Une tentative d'elevation par le fichier : « superadmin » n'est pas
        // dans la liste, et la ligne ne doit pas creer un compte privilegie.
        $this->importer('utilisateurs', [
            ['nom', 'prenom', 'email', 'role', 'point_de_vente', 'fonction', 'date_debut_contrat', 'statut'],
            ['Pirate', 'Jean', 'pirate@exemple.ci', 'superadmin', 'Agence Cocody', '', '', 'actif'],
        ]);

        $this->assertSame('caissier', Utilisateur::where('email', 'pirate@exemple.ci')->value('role'));
    }

    // ══════════════ Les articles ══════════════

    public function test_un_service_s_importe_desormais(): void
    {
        // `prix_achat > 0` etait exige de tous : un cabinet comptable, dont
        // tous les articles sont des missions, ne passait aucune ligne.
        $reponse = $this->importer('produits', [
            ['nom', 'type', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Mission de conseil', 'service', '', '25000', '18', 'PRESTA-001'],
        ]);

        $this->assertSame(1, $reponse->json('importes'), json_encode($reponse->json('erreurs')));
        $this->assertSame('service', Produit::where('reference', 'PRESTA-001')->value('type'));
    }

    public function test_une_marchandise_sans_prix_d_achat_reste_refusee(): void
    {
        $reponse = $this->importer('produits', [
            ['nom', 'type', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Marteau', 'marchandise', '0', '4500', '18', 'MART-002'],
        ]);

        $this->assertSame(0, $reponse->json('importes'));
        $this->assertSame(0, Produit::where('reference', 'MART-002')->count());
    }

    public function test_un_taux_hors_bareme_est_refuse_et_non_ramene_a_dix_huit(): void
    {
        // **Le taux ne se rattrape pas en silence.** La plateforme ne reçoit
        // pas un pourcentage mais un code, et un taux inconnu tombait sur
        // `TVA`, que la plateforme applique à 18 % : la facture certifiée
        // affichait alors un montant différent de celle établie ici.
        $reponse = $this->importer('produits', [
            ['nom', 'type', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Article douteux', 'marchandise', '1000', '2000', '5', 'DOUTE-001'],
        ]);

        $this->assertSame(0, $reponse->json('importes'));
        $this->assertSame(0, Produit::where('reference', 'DOUTE-001')->count());
        $this->assertStringContainsString('hors barème', $reponse->json('erreurs')[0]);
    }

    public function test_les_nombres_a_la_francaise_se_lisent(): void
    {
        // « 12 000,50 » : l'espace des milliers et la virgule decimale sont la
        // regle sur les fichiers ivoiriens, et `(float)` rendait 12.
        $this->importer('produits', [
            ['nom', 'type', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Riz parfumé 25kg', 'marchandise', '12 000', '15 000,50', '18', 'RIZ-001'],
        ]);

        $produit = Produit::where('reference', 'RIZ-001')->firstOrFail();

        $this->assertSame(12000.0, (float) $produit->prix_achat);
        $this->assertSame(15000.50, (float) $produit->prix_vente);
    }

    public function test_le_stock_d_ouverture_se_pose_dans_la_meme_feuille(): void
    {
        // Sans lui, un magasin qui migre saisit ses deux mille quantites a la
        // main apres l'import : c'est le moment ou l'on abandonne.
        $this->importer('produits', [
            ['nom', 'type', 'prix_achat', 'prix_vente', 'taux_tva', 'reference',
             'point_de_vente', 'stock_initial', 'cout_unitaire', 'stock_minimum'],
            ['Ciment CPJ 45', 'marchandise', '5000', '6500', '18', 'CIM-001',
             'Agence Cocody', '120', '5 200', '15'],
        ]);

        $produit = Produit::where('reference', 'CIM-001')->firstOrFail();

        $this->assertSame(120.0, $produit->stockActuel($this->magasin->id));
        $this->assertSame(5200.0, (float) Stock::where('produit_id', $produit->id)
            ->where('point_de_vente_id', $this->magasin->id)->value('cump'));
        $this->assertSame(15.0, (float) Stock::where('produit_id', $produit->id)
            ->where('point_de_vente_id', $this->magasin->id)->value('stock_minimum'));
    }

    public function test_le_stock_d_ouverture_passe_par_la_porte_unique(): void
    {
        // Un stock pose a cote n'aurait ni trace au journal, ni valeur au
        // bilan. Le motif est l'inventaire : une ouverture est un comptage,
        // pas une reception — il n'y a pas de fournisseur derriere.
        $this->importer('produits', [
            ['nom', 'type', 'prix_achat', 'prix_vente', 'taux_tva', 'reference', 'stock_initial'],
            ['Ciment CPJ 45', 'marchandise', '5000', '6500', '18', 'CIM-002', '120'],
        ]);

        $mouvement = MouvementStock::where('reference_document', 'OUVERTURE')->firstOrFail();

        $this->assertSame(MouvementStock::INVENTAIRE, $mouvement->sous_type);
        $this->assertSame(MouvementStock::ENTREE, $mouvement->type_mouvement);
        $this->assertSame(120.0, (float) $mouvement->quantite);
    }

    public function test_un_service_ne_recoit_pas_de_stock_d_ouverture(): void
    {
        $this->importer('produits', [
            ['nom', 'type', 'prix_achat', 'prix_vente', 'taux_tva', 'reference', 'stock_initial'],
            ['Mission', 'service', '', '25000', '18', 'PRESTA-002', '50'],
        ]);

        $this->assertSame(0, MouvementStock::count());
    }

    public function test_les_champs_ajoutes_depuis_se_reprennent(): void
    {
        // Le suivi par lot et la consignation, poses aux lots 6.3 et 6.5 : sans
        // eux, un depot de boissons ou une pharmacie devait rouvrir ses deux
        // mille fiches une a une apres l'import.
        $this->importer('produits', [
            ['nom', 'type', 'prix_achat', 'prix_vente', 'taux_tva', 'reference',
             'compte_stock', 'compte_variation', 'remise_taux',
             'date_peremption', 'suivi_par_lot', 'preavis_peremption',
             'prix_consignation', 'delai_retour_jours'],
            ['Paracétamol 500 mg', 'marchandise', '400', '600', '0', 'MED-001',
             '311000', '603100', '5',
             '31/12/2027', 'oui', '60',
             '2 000', '21'],
        ]);

        $produit = Produit::where('reference', 'MED-001')->firstOrFail();

        // Révisé au lot 47 (chantier 11.3) : aucun compte n'entre plus par
        // l'import, la fiche n'en demandant plus. Un ancien modèle qui les
        // porte encore ne les fait pas entrer.
        $this->assertNull($produit->compte_stock);
        $this->assertNull($produit->compte_variation);
        $this->assertSame(5.0, (float) $produit->remise_taux);
        $this->assertSame('2027-12-31', $produit->date_peremption?->toDateString());
        $this->assertTrue((bool) $produit->suivi_par_lot);
        $this->assertSame(60, $produit->preavis_peremption);
        $this->assertSame(2000.0, (float) $produit->prix_consignation);
        $this->assertSame(21, $produit->delai_retour_jours);
    }

    // ══════════════ Le stock d'ouverture, feuille à part ══════════════

    public function test_le_stock_d_ouverture_alimente_un_catalogue_deja_en_place(): void
    {
        $produit = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'BOIS-001',
            'nom' => 'Planche 4 m', 'type' => 'marchandise', 'unite' => 'pièce',
            'prix_achat' => 3000, 'prix_vente' => 4200,
        ]);

        $reponse = $this->importer('stock-initial', [
            ['reference', 'point_de_vente', 'quantite', 'cout_unitaire', 'stock_minimum', 'numero_lot', 'date_peremption'],
            ['BOIS-001', 'Agence Cocody', '80', '3 100', '10', '', ''],
        ]);

        $this->assertSame(1, $reponse->json('importes'), json_encode($reponse->json('erreurs')));
        $this->assertSame(80.0, $produit->fresh()->stockActuel($this->magasin->id));
    }

    public function test_le_stock_d_ouverture_pose_le_lot_quand_il_est_donne(): void
    {
        $produit = Produit::create([
            'entreprise_id' => $this->entreprise->id, 'reference' => 'MED-002',
            'nom' => 'Amoxicilline', 'type' => 'marchandise', 'unite' => 'boîte',
            'prix_achat' => 800, 'prix_vente' => 1200, 'suivi_par_lot' => true,
        ]);

        $this->importer('stock-initial', [
            ['reference', 'point_de_vente', 'quantite', 'cout_unitaire', 'stock_minimum', 'numero_lot', 'date_peremption'],
            ['MED-002', 'Agence Cocody', '300', '800', '50', 'L-2026-03', '31/12/2027'],
        ]);

        $lot = Lot::where('produit_id', $produit->id)->firstOrFail();

        $this->assertSame('L-2026-03', $lot->numero_lot);
        $this->assertSame(300.0, $lot->quantite);
        $this->assertSame('2027-12-31', $lot->date_peremption?->toDateString());
    }

    public function test_une_reference_inconnue_est_signalee_ligne_par_ligne(): void
    {
        $reponse = $this->importer('stock-initial', [
            ['reference', 'point_de_vente', 'quantite'],
            ['INEXISTANT', 'Agence Cocody', '80'],
        ]);

        $this->assertSame(0, $reponse->json('importes'));
        $this->assertStringContainsString('INEXISTANT', $reponse->json('erreurs')[0]);
    }

    public function test_le_stock_d_ouverture_d_un_article_d_une_autre_entreprise_est_refuse(): void
    {
        // La reference est le seul lien : sans borne d'entreprise, on
        // alimenterait le stock d'un concurrent.
        $autre = Entreprise::create(['nom' => 'Quincaillerie rivale']);
        $sienne = Produit::create([
            'entreprise_id' => $autre->id, 'reference' => 'RIVAL-001',
            'nom' => 'Article rival', 'type' => 'marchandise',
            'prix_achat' => 1000, 'prix_vente' => 1500,
        ]);

        $reponse = $this->importer('stock-initial', [
            ['reference', 'point_de_vente', 'quantite'],
            ['RIVAL-001', 'Agence Cocody', '999'],
        ]);

        $this->assertSame(0, $reponse->json('importes'));
        $this->assertSame(0.0, $sienne->fresh()->stockActuel($this->magasin->id));
        $this->assertSame(0, MouvementStock::count());
    }

    // ══════════════ Les immobilisations ══════════════

    public function test_un_parc_s_importe_avec_son_plan(): void
    {
        // Une entreprise qui migre a deja un parc, chacun avec son
        // anteriorite. Les ressaisir un a un decide du sort de la migration.
        $reponse = $this->importer('immobilisations', [
            ['code', 'libelle', 'point_de_vente', 'compte_immobilisation', 'compte_amortissement',
             'compte_dotation', 'date_acquisition', 'date_mise_en_service',
             'valeur_acquisition', 'valeur_residuelle', 'duree_mois'],
            ['IMM-001', 'Camion Mercedes 1113', 'Agence Cocody', '245100', '284500', '681300',
             '15/01/2026', '01/02/2026', '12 000 000', '0', '60'],
        ]);

        $this->assertSame(1, $reponse->json('importes'), json_encode($reponse->json('erreurs')));

        $bien = Immobilisation::where('code', 'IMM-001')->firstOrFail();

        $this->assertSame(12000000.0, $bien->valeur_acquisition);
        $this->assertSame('2026-02-01', $bien->date_mise_en_service->toDateString());
        $this->assertGreaterThan(0, $bien->dotations()->count(), 'Le plan est établi à l\'import.');
    }

    public function test_aucune_dotation_n_est_passee_en_comptabilite_a_l_import(): void
    {
        // **L'import etablit le plan ; c'est la cloture qui ecrit.** Passer ici
        // des dotations anterieures produirait des charges sur des exercices
        // deja arretes.
        $this->importer('immobilisations', [
            ['code', 'libelle', 'compte_immobilisation', 'compte_amortissement',
             'compte_dotation', 'date_acquisition', 'date_mise_en_service',
             'valeur_acquisition', 'valeur_residuelle', 'duree_mois'],
            ['IMM-002', 'Groupe électrogène', '241100', '284100', '681300',
             '03/06/2024', '03/06/2024', '3 500 000', '0', '84'],
        ]);

        $this->assertSame(0, \App\Modules\Admin\Modeles\EcritureComptable::count());
        $this->assertSame(0.0, Immobilisation::where('code', 'IMM-002')->firstOrFail()->cumulAmorti());
    }

    public function test_un_terrain_s_importe_sans_plan(): void
    {
        $this->importer('immobilisations', [
            ['code', 'libelle', 'compte_immobilisation', 'compte_amortissement',
             'compte_dotation', 'date_acquisition', 'date_mise_en_service',
             'valeur_acquisition', 'valeur_residuelle', 'duree_mois'],
            ['IMM-TERRAIN', 'Terrain de Yopougon', '222000', '282000', '681300',
             '20/11/2023', '20/11/2023', '25 000 000', '0', '0'],
        ]);

        $terrain = Immobilisation::where('code', 'IMM-TERRAIN')->firstOrFail();

        $this->assertSame(0.0, round((float) $terrain->dotations()->sum('dotation'), 2));
    }

    public function test_un_compte_manquant_est_refuse_plutot_que_devine(): void
    {
        // Un compte devine rendrait le bilan faux plutot qu'imprecis : les
        // marchandises vont en 31, les matieres en 32, les produits finis en 36.
        $reponse = $this->importer('immobilisations', [
            ['code', 'libelle', 'compte_immobilisation', 'compte_amortissement',
             'compte_dotation', 'date_acquisition', 'date_mise_en_service',
             'valeur_acquisition', 'duree_mois'],
            ['IMM-003', 'Photocopieuse', '', '284400', '681300',
             '01/01/2026', '01/01/2026', '800 000', '36'],
        ]);

        $this->assertSame(0, $reponse->json('importes'));
        $this->assertSame(0, Immobilisation::where('code', 'IMM-003')->count());
    }

    public function test_un_code_deja_pris_est_signale_sans_arreter_le_fichier(): void
    {
        $entetes = ['code', 'libelle', 'compte_immobilisation', 'compte_amortissement',
                    'compte_dotation', 'date_acquisition', 'date_mise_en_service',
                    'valeur_acquisition', 'duree_mois'];

        $this->importer('immobilisations', [$entetes,
            ['IMM-010', 'Camion', '245100', '284500', '681300', '01/01/2026', '01/01/2026', '5 000 000', '60'],
        ]);

        $reponse = $this->importer('immobilisations', [$entetes,
            ['IMM-010', 'Doublon', '245100', '284500', '681300', '01/01/2026', '01/01/2026', '9 000 000', '60'],
            ['IMM-011', 'Fourgon', '245100', '284500', '681300', '01/01/2026', '01/01/2026', '7 000 000', '60'],
        ]);

        $this->assertSame(1, $reponse->json('importes'));
        $this->assertCount(1, $reponse->json('erreurs'));
        $this->assertSame(5000000.0, Immobilisation::where('code', 'IMM-010')->value('valeur_acquisition'));
        $this->assertSame(1, Immobilisation::where('code', 'IMM-011')->count());
    }

    public function test_une_mise_en_service_anterieure_a_l_acquisition_est_refusee(): void
    {
        $reponse = $this->importer('immobilisations', [
            ['code', 'libelle', 'compte_immobilisation', 'compte_amortissement',
             'compte_dotation', 'date_acquisition', 'date_mise_en_service',
             'valeur_acquisition', 'duree_mois'],
            ['IMM-020', 'Camion', '245100', '284500', '681300', '01/06/2026', '01/01/2026', '5 000 000', '60'],
        ]);

        $this->assertSame(0, $reponse->json('importes'));
        $this->assertSame(0, Immobilisation::where('code', 'IMM-020')->count());
    }

    // ══════════════ Les modèles téléchargés ══════════════

    /**
     * @return array<int, array<int, string>>
     */
    public static function lesModules(): array
    {
        return [
            ['points-de-vente'], ['clients'], ['fournisseurs'],
            ['utilisateurs'], ['produits'], ['stock-initial'], ['immobilisations'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lesModules')]
    public function test_chaque_module_livre_son_modele(string $module): void
    {
        $this->get(route('admin.import.exemple', ['type' => $module]))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_le_modele_des_articles_porte_les_champs_ajoutes_depuis(): void
    {
        // Le modele portait douze colonnes la ou la fiche en compte deux fois
        // plus : tout se ressaisissait fiche par fiche apres l'import.
        $csv = $this->get(route('admin.import.exemple', ['type' => 'produits']))->getContent();

        foreach (['stock_initial', 'suivi_par_lot',
                  'prix_consignation', 'date_peremption', 'remise_taux'] as $colonne) {
            $this->assertStringContainsString($colonne, $csv, "Colonne manquante : {$colonne}");
        }

        // Les comptes en sont sortis au lot 47 : la fiche ne les propose plus.
        $this->assertStringNotContainsString('compte_', $csv);
    }

    public function test_un_module_inconnu_ne_livre_aucun_modele(): void
    {
        $this->get(route('admin.import.exemple', ['type' => 'ecritures-comptables']))
            ->assertNotFound();
    }

    // ══════════════ La famille et la sous-famille ══════════════

    public function test_la_sous_famille_du_fichier_arrive_sur_la_fiche(): void
    {
        // La colonne figurait au modèle et n'était **jamais lue**. Un catalogue
        // arrivait rangé par famille seulement, et la sous-famille se
        // ressaisissait fiche par fiche — c'est-à-dire jamais.
        $famille = Categorie::where('entreprise_id', $this->entreprise->id)
            ->where('nom', 'Fournitures')->firstOrFail();

        $sousFamille = \App\Modules\Admin\Modeles\SousCategorie::create([
            'categorie_id' => $famille->id, 'nom' => 'Papeterie',
        ]);

        $reponse = $this->importer('produits', [
            ['nom', 'type', 'categorie', 'sous_categorie', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Stylo bille bleu', 'marchandise', 'Fournitures', 'Papeterie', '150', '250', '18', 'STYL-001'],
        ]);

        $this->assertSame(1, $reponse->json('importes'));

        $produit = Produit::where('reference', 'STYL-001')->firstOrFail();

        $this->assertSame($famille->id, $produit->categorie_id);
        $this->assertSame($sousFamille->id, $produit->sous_categorie_id);
    }

    public function test_une_sous_famille_inconnue_refuse_la_ligne_au_lieu_de_l_ignorer(): void
    {
        // Ranger l'article « sans sous-famille » et compter la ligne pour un
        // succès ferait croire l'import complet : l'administrateur ne saurait
        // qu'à la première recherche au catalogue.
        $reponse = $this->importer('produits', [
            ['nom', 'type', 'categorie', 'sous_categorie', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Stylo bille bleu', 'marchandise', 'Fournitures', 'Bureautique', '150', '250', '18', 'STYL-002'],
        ]);

        $this->assertSame(0, $reponse->json('importes'));
        $this->assertStringContainsString('Bureautique', $reponse->json('erreurs.0'));
        $this->assertSame(0, Produit::where('reference', 'STYL-002')->count());
    }

    public function test_une_famille_inconnue_refuse_la_ligne(): void
    {
        $reponse = $this->importer('produits', [
            ['nom', 'type', 'categorie', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Ciment 50 kg', 'marchandise', 'Matériaux', '4000', '5000', '18', 'CIM-001'],
        ]);

        $this->assertSame(0, $reponse->json('importes'));
        $this->assertStringContainsString('Matériaux', $reponse->json('erreurs.0'));
    }

    public function test_une_colonne_de_famille_vide_reste_acceptee(): void
    {
        // Tout le monde ne range pas son catalogue. L'exigence porte sur ce qui
        // est écrit, pas sur le fait d'écrire.
        $reponse = $this->importer('produits', [
            ['nom', 'type', 'categorie', 'sous_categorie', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Ciment 50 kg', 'marchandise', '', '', '4000', '5000', '18', 'CIM-002'],
        ]);

        $this->assertSame(1, $reponse->json('importes'));
        $this->assertNull(Produit::where('reference', 'CIM-002')->firstOrFail()->sous_categorie_id);
    }

    public function test_la_sous_famille_d_une_autre_entreprise_reste_hors_de_portee(): void
    {
        // **Simulation d'attaque.** `sous_categories` ne porte pas
        // d'`entreprise_id` : elle ne tient à son entreprise que par sa
        // famille. Une recherche sur le seul nom aurait rattaché l'article à la
        // sous-famille d'un concurrent — et fait remonter le catalogue de
        // l'autre dans les listes déroulantes.
        $autre = Entreprise::create(['nom' => 'Quincaillerie rivale']);
        $familleRivale = Categorie::create([
            'entreprise_id' => $autre->id, 'nom' => 'Secrets maison', 'prefixe' => 'SEC',
        ]);
        $sousFamilleRivale = \App\Modules\Admin\Modeles\SousCategorie::create([
            'categorie_id' => $familleRivale->id, 'nom' => 'Formules',
        ]);

        $reponse = $this->importer('produits', [
            ['nom', 'type', 'sous_categorie', 'prix_achat', 'prix_vente', 'taux_tva', 'reference'],
            ['Article espion', 'marchandise', 'Formules', '100', '200', '18', 'ESP-001'],
        ]);

        $this->assertSame(0, $reponse->json('importes'));
        $this->assertSame(0, Produit::where('sous_categorie_id', $sousFamilleRivale->id)->count());
    }

    // ══════════════ Cloisonnement ══════════════

    public function test_l_import_rattache_toujours_a_l_entreprise_de_l_appelant(): void
    {
        // Le fichier ne choisit pas son entreprise : c'est la session qui la
        // dit, et rien dans le CSV ne peut la changer.
        $autre = Entreprise::create(['nom' => 'Quincaillerie rivale']);

        $this->importer('clients', [
            ['nom', 'entreprise_id', 'telephone'],
            ['Client injecté', (string) $autre->id, '0700000000'],
        ]);

        $client = \App\Modules\Admin\Modeles\Client::where('nom', 'Client injecté')->firstOrFail();

        $this->assertSame($this->entreprise->id, $client->entreprise_id);
    }
}
