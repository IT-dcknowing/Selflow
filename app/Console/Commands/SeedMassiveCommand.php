<?php

namespace App\Console\Commands;

use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Admin\Modeles\Stock;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\VenteDetail;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\AchatDetail;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\FicheTechnique;
use App\Modules\Admin\Modeles\FicheTechniqueDetail;
use App\Modules\Admin\Modeles\OrdreProduction;
use App\Modules\Admin\Modeles\TransfertStock;
use App\Modules\Admin\Modeles\BonLivraison;
use App\Modules\Admin\Modeles\BonLivraisonDetail;
use App\Modules\Admin\Modeles\TresorerieJournal;
use App\Modules\Admin\Modeles\FneCredential;
use App\Modules\Admin\Modeles\MouvementStock;
use App\Modules\Admin\Services\ComptabiliteService;
use App\Modules\Admin\Services\StockService;
use App\Modules\Admin\Services\NumerotationService;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Peuplement massif de données réalistes pour 2 entreprises, EN PASSANT PAR
 * LA VRAIE LOGIQUE MÉTIER (Eloquent + ComptabiliteService + NumerotationService)
 * plutôt que des insertions SQL brutes qui contournaient (et reproduisaient
 * les bugs corrigés de) l'application. Objectif explicite de l'utilisateur :
 * "que ce peuplement serve de test et en même temps corrige ce qui ne va pas".
 *
 * Remplace l'ancien SeedMassiveCommand.php (supprimé le 24/07/2026), qui
 * dupliquait manuellement la génération d'écritures comptables via des
 * DB::table()->insert() directs, réintroduisant plusieurs bugs déjà corrigés
 * cette session (411 débité sur vente comptant, code journal unique erroné,
 * ancien préfixe d'avoir AV- au lieu de AVO-VTE-/AVO-ACH-, montants d'avoir
 * stockés en négatif, numéro de saisie non généré via Operation, etc.).
 *
 * Usage :
 *   php artisan selflow:seed-massif                  (avec confirmation)
 *   php artisan selflow:seed-massif --force           (sans confirmation, pour CI/déploiement)
 */
class SeedMassiveCommand extends Command
{
    protected $signature = 'selflow:seed-massif {--force : Ne pas demander de confirmation avant de purger les données}';
    protected $description = 'Vide et repeuple intégralement les données des 2 entreprises de démonstration, via la vraie logique métier de l\'application.';

    private array $codesJournauxParEntreprise = [];
    private array $comptesVenteDisponibles = ['701000', '702000', '706000', '707000'];
    private array $comptesAchatDisponibles = ['601000', '602000', '604000', '605000'];

    public function handle(): int
    {
        // La purge efface toutes les entreprises, pas seulement celles de
        // démonstration. `--force` ne lève pas cette garde : sur un serveur de
        // production, rien ne justifie de tout effacer.
        if (app()->isProduction()) {
            $this->error('selflow:seed-massif refusé sur un serveur de production : la purge efface toutes les données.');

            return self::FAILURE;
        }

        if (!$this->option('force')) {
            $this->warn('⚠️  Cette commande va SUPPRIMER TOUTES les données des entreprises de démonstration et les repeupler entièrement.');
            if (!$this->confirm('Avez-vous fait une sauvegarde de la base si nécessaire ? Continuer ?')) {
                $this->info('Annulé.');
                return self::FAILURE;
            }
        }

        $debut = microtime(true);

        $this->info('🧹 Purge des données existantes...');
        $this->purgerDonnees();

        $this->info('🏢 Création des entreprises...');
        $entreprises = [
            $this->creerEntreprise('DIST-CI DISTRIBUTION SARL', 'distribution.ci', '0102030405', 'CI-ABJ-2019-B-45678', '1245789K'),
            $this->creerEntreprise('KIRÉNA AGRO-INDUSTRIE SARL', 'agroindustrie.ci', '0203040506', 'CI-ABJ-2020-B-56789', '2356891L'),
        ];

        foreach ($entreprises as $idx => $entrepriseId) {
            $this->info("\n════════════════════════════════════════════════════");
            $this->info("🏢 ENTREPRISE " . ($idx + 1) . " (id={$entrepriseId})");
            $this->info("════════════════════════════════════════════════════");

            $this->info('  📍 Points de vente...');
            $pdvIds = $this->creerPointsDeVente($entrepriseId, $idx);

            $this->info('  👤 Utilisateurs (admin, responsables, caissiers)...');
            [$adminId, $utilisateursParPdv] = $this->creerUtilisateurs($entrepriseId, $pdvIds, $idx);

            $this->info('  📊 Plan comptable SYSCOHADA...');
            $this->creerPlanComptable($entrepriseId);

            $this->info('  📓 Codes journaux (VTE, ACH, OD, CAI + banques + mobile money)...');
            $this->creerCodesJournaux($entrepriseId);

            $this->info('  🔑 Clé FNE de test...');
            $this->creerFneCredential($entrepriseId, $adminId);

            $this->info('  👥 Clients (100)...');
            $clients = $this->creerClients($entrepriseId, 100, $idx);

            $this->info('  🏭 Fournisseurs (100)...');
            $fournisseurs = $this->creerFournisseurs($entrepriseId, 100, $idx);

            $this->info('  📦 Catégories (100) + Produits (100/catégorie = 10 000)...');
            $produits = $this->creerCategoriesEtProduits($entrepriseId, 100, 100, $idx);
            $this->info('     → ' . count($produits) . ' produits créés.');

            $this->info('  📈 Stocks initiaux (par point de vente)...');
            $this->creerStocksInitiaux($produits, $pdvIds);

            $this->info('  🏗️  Fiches techniques (100) + Ordres de production (100)...');
            $this->creerProduction($entrepriseId, $pdvIds, $produits, $utilisateursParPdv, 100);

            $this->info('  🧾 Ventes (100 minimum : comptant total/partiel, crédit, banque total/partiel)...');
            $ventesCreees = $this->creerVentes($entrepriseId, $pdvIds, $clients, $produits, $utilisateursParPdv, 100);

            $this->info('  📥 Achats (100 minimum : mêmes modes de règlement)...');
            $achatsCreees = $this->creerAchats($entrepriseId, $pdvIds, $fournisseurs, $produits, $utilisateursParPdv, 100);

            $this->info('  🔄 Cycle Devis → Bon de Commande → Bon de Livraison → Facture (20)...');
            $this->creerCycleDocumentsVente($entrepriseId, $pdvIds, $clients, $produits, $utilisateursParPdv, 20);

            $this->info('  ↩️  Avoirs clients (15) et fournisseurs (15)...');
            $this->creerAvoirs($ventesCreees, $achatsCreees);

            $this->info('  💰 Règlements différés sur créances à crédit...');
            $this->creerReglementsDifferes($ventesCreees, $achatsCreees);

            $this->info('  🔀 Transferts internes (20), commandes fournisseur à réceptionner (15)...');
            $this->creerMouvementsDivers($entrepriseId, $pdvIds, $produits, $fournisseurs, $utilisateursParPdv);
        }

        $this->info("\n👑 Création du compte SuperAdmin en ligne...");
        $this->creerSuperAdmin();

        $duree = round(microtime(true) - $debut, 1);
        $this->info("\n✅ Peuplement terminé en {$duree}s.");
        $this->afficherAnomalies();

        return self::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════════
    // PURGE
    // ═══════════════════════════════════════════════════════════════

    private function purgerDonnees(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = [
            'ecritures_comptables', 'operations', 'tresorerie_journal', 'mouvements_stock',
            'transferts_stock', 'bon_livraison_details', 'bons_livraison',
            'vente_details', 'ventes', 'achat_details', 'achats',
            'fiche_technique_details', 'fiches_techniques', 'ordres_production',
            'stocks', 'produits', 'produit_details_libres', 'sous_categories', 'categories',
            'clients', 'fournisseurs', 'plan_comptable', 'codes_journaux', 'banques',
            'fne_credentials', 'b2b_negotiations',
            'points_de_vente', 'periodes',
        ];

        foreach ($tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        DB::table('utilisateurs')->where('role', '!=', 'superadmin')->delete();
        DB::table('utilisateurs')->where('email', 'superadmin@gmail.com')->delete();

        DB::table('entreprises')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    // ═══════════════════════════════════════════════════════════════
    // ENTREPRISE / POINTS DE VENTE / UTILISATEURS
    // ═══════════════════════════════════════════════════════════════

    private function creerEntreprise(string $nom, string $domaine, string $tel, string $rccm, string $ncc): int
    {
        $logoSeed = urlencode(substr($nom, 0, 2));
        $id = Entreprise::create([
            'nom' => $nom,
            'forme_juridique' => 'SARL',
            'gerant_nom' => 'KOUAME',
            'gerant_prenom' => 'Jean-Baptiste',
            'gerant_fonction' => 'Gérant',
            'adresse' => 'Zone Industrielle, Abidjan, Côte d\'Ivoire',
            'telephone' => '+225 ' . $tel,
            'email' => 'contact@' . $domaine,
            'rccm' => $rccm,
            'compte_contribuable' => $ncc,
            'ncc' => $ncc,
            'regime_imposition' => 'RSI',
            'centre_impots' => 'Centre des Impôts de Plateaux',
            'ref_bancaire' => 'CI93 CI001 01234567890123456789',
            'logo_path' => "https://ui-avatars.com/api/?name={$logoSeed}&background=0B2545&color=fff&size=256&bold=true",
            'quota_points_de_vente' => 10,
            'plan_abonnement' => 'Pro',
            'secteur_activite' => ['Commerce général', 'Distribution'],
            'modules_actifs' => ['ventes', 'achats', 'stock', 'production', 'comptabilite', 'tresorerie', 'b2b'],
        ])->id;

        return $id;
    }

    private function creerPointsDeVente(int $entrepriseId, int $idx): array
    {
        $villes = [
            ['Abidjan', 'Cocody', 'Siège Social'],
            ['Abidjan', 'Yopougon', 'Dépôt Yopougon'],
            ['Abidjan', 'Marcory', 'Magasin Marcory'],
            ['Bouaké', 'Centre', 'Agence Bouaké'],
        ];
        $responsables = ['TRAORE Aminata', 'KONE Ibrahim', 'BAMBA Fatou', 'OUATTARA Sékou'];

        $ids = [];
        foreach ($villes as $i => [$ville, $commune, $nom]) {
            $ids[] = PointDeVente::create([
                'entreprise_id' => $entrepriseId,
                'nom' => $nom,
                'ville' => $ville,
                'commune' => $commune,
                'responsable' => $responsables[$i],
                'telephone' => '+225 07' . str_pad((string) rand(10000000, 99999999), 8, '0', STR_PAD_LEFT),
                'statut' => 'actif',
            ])->id;
        }
        return $ids;
    }

    /**
     * @return array{0: int, 1: array<int,int>} [adminId, [pdvId => [caissierId, ...]]]
     */
    private function creerUtilisateurs(int $entrepriseId, array $pdvIds, int $idx): array
    {
        $habilitationsResponsable = [
            'tableau_de_bord_personnel', 'tableau_de_bord_general', 'nouvelle_vente', 'factures_vente',
            'nouvel_achat', 'factures_achat', 'catalogue_produits', 'stock_articles', 'stock_mouvements',
            'tiers_clients', 'tiers_fournisseurs', 'historique_ventes', 'historique_achats',
            'comptabilite_creances', 'tresorerie_journal', 'tresorerie_encaissements', 'tresorerie_decaissements',
            'gestion_pdv', 'rapports_analyse', 'production_ordres', 'production_recettes',
        ];
        $habilitationsCaissier = [
            'tableau_de_bord_personnel', 'nouvelle_vente', 'factures_vente', 'catalogue_produits',
            'tiers_clients', 'tresorerie_encaissements',
        ];

        // Comptes de demonstration : mot de passe publie lui aussi. Il est
        // desormais tire au hasard, ou impose par DEMO_PASSWORD.
        $motDePasseDemo = env('DEMO_PASSWORD') ?: \Illuminate\Support\Str::password(16);
        $motDePasse = Hash::make($motDePasseDemo);

        if (!env('DEMO_PASSWORD')) {
            $this->warn("    Mot de passe des comptes de démonstration : {$motDePasseDemo}");
        }

        $adminId = Utilisateur::create([
            'entreprise_id' => $entrepriseId,
            'point_de_vente_id' => null,
            'nom' => $idx === 0 ? 'YAO' : 'KOFFI',
            'prenom' => $idx === 0 ? 'Marie-Claire' : 'Adjoua',
            'email' => 'admin' . ($idx + 1) . '@selflow-demo.ci',
            'password' => $motDePasse,
            'role' => 'admin',
            'fonction' => 'Administrateur Général',
            'statut' => 'actif',
            'habilitations' => json_encode($habilitationsResponsable),
            'doit_changer_password' => false,
        ])->id;

        $prenomsResponsables = ['TRAORE Aminata', 'KONE Ibrahim', 'BAMBA Fatou', 'OUATTARA Sékou'];
        $prenomsCaissiers = [
            ['DIALLO Mariam', 'COULIBALY Awa'], ['SORO Zié', 'KOUASSI Affoué'],
            ['GNAHORE Paul', 'ADJOBI Chantal'], ['SANGARE Moussa', 'YEO Nathalie'],
        ];

        $utilisateursParPdv = [];
        foreach ($pdvIds as $i => $pdvId) {
            [$nomP, $prenomP] = array_pad(explode(' ', $prenomsResponsables[$i], 2), 2, 'Site');
            $responsableId = Utilisateur::create([
                'entreprise_id' => $entrepriseId,
                'point_de_vente_id' => $pdvId,
                'nom' => $nomP,
                'prenom' => $prenomP,
                'email' => 'responsable.pdv' . ($i + 1) . '.e' . ($idx + 1) . '@selflow-demo.ci',
                'password' => $motDePasse,
                'role' => 'caissier',
                'fonction' => 'Responsable de site',
                'statut' => 'actif',
                'habilitations' => json_encode($habilitationsResponsable),
                'doit_changer_password' => false,
            ])->id;

            $caissierIds = [$responsableId];
            foreach ($prenomsCaissiers[$i] as $nomComplet) {
                [$nomC, $prenomC] = array_pad(explode(' ', $nomComplet, 2), 2, 'Caissier');
                $caissierIds[] = Utilisateur::create([
                    'entreprise_id' => $entrepriseId,
                    'point_de_vente_id' => $pdvId,
                    'nom' => $nomC,
                    'prenom' => $prenomC,
                    'email' => 'caissier.' . strtolower($nomC) . '.e' . ($idx + 1) . '@selflow-demo.ci',
                    'password' => $motDePasse,
                    'role' => 'caissier',
                    'fonction' => 'Caissier(ère)',
                    'statut' => 'actif',
                    'habilitations' => json_encode($habilitationsCaissier),
                    'doit_changer_password' => false,
                ])->id;
            }
            $utilisateursParPdv[$pdvId] = $caissierIds;
        }

        return [$adminId, $utilisateursParPdv];
    }

    private function creerFneCredential(int $entrepriseId, int $adminId): void
    {
        FneCredential::create([
            'entreprise_id' => $entrepriseId,
            'cle_test' => 'fne_test_' . bin2hex(random_bytes(16)),
            'cle_test_ajoutee_at' => now()->subDays(rand(30, 90)),
            'cle_test_ajoutee_par' => $adminId,
            'statut' => 'test',
            'ncc_associe' => null,
            'notes_superadmin' => 'Clé de test générée automatiquement par le peuplement de démonstration — demande DGI simulée.',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // PLAN COMPTABLE / CODES JOURNAUX
    // ═══════════════════════════════════════════════════════════════

    private function creerPlanComptable(int $entrepriseId): void
    {
        $comptes = [
            ['311000', 'Marchandises'], ['321000', 'Matières premières'], ['351000', 'Produits finis'],
            ['401000', 'Fournisseurs'], ['411000', 'Clients'], ['421000', 'Personnel, rémunérations dues'],
            ['443100', 'État, TVA facturée sur ventes'], ['445200', 'État, TVA déductible sur achats'],
            ['445500', 'État, TVA collectée'], ['447000', 'État, autres impôts et taxes'],
            ['471000', 'Compte d\'attente COMPTAFLOW'],
            ['521000', 'Banques locales'], ['571000', 'Caisse siège'],
            ['601000', 'Achats de marchandises'], ['602000', 'Achats de matières premières'],
            ['604000', 'Achats de fournitures non stockées'], ['605000', 'Autres achats'],
            ['611000', 'Transports sur achats'], ['613000', 'Locations'], ['622000', 'Rémunérations d\'intermédiaires'],
            ['624000', 'Entretien, réparations et maintenance'], ['625000', 'Primes d\'assurance'],
            ['628000', 'Divers autres services extérieurs'], ['631000', 'Frais bancaires'],
            ['661000', 'Rémunérations directes versées au personnel'], ['664000', 'Charges sociales'],
            ['701000', 'Ventes de marchandises'], ['702000', 'Ventes de produits finis'],
            ['706000', 'Services vendus'], ['707000', 'Produits accessoires'],
            ['603200', 'Variation des stocks de matières premières'], ['731100', 'Production stockée'],
        ];

        $lignes = [];
        foreach ($comptes as [$numero, $libelle]) {
            $lignes[] = [
                'entreprise_id' => $entrepriseId,
                'numero' => $numero,
                'libelle' => $libelle,
                'source' => 'local',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('plan_comptable')->insert($lignes);
    }

    private function creerCodesJournaux(int $entrepriseId): void
    {
        $journaux = [
            ['VTE', 'Journal des Ventes', 'Vente', '701000'],
            ['ACH', 'Journal des Achats', 'Achat', '601000'],
            ['OD', 'Journal des Opérations Diverses', 'Autre', '471000'],
            ['CAI', 'Caisse Siège', 'Trésorerie', '571000'],
            ['BNI', 'BNI Côte d\'Ivoire', 'Banque', '521000'],
            ['SGBCI', 'Société Générale Côte d\'Ivoire', 'Banque', '521000'],
            ['OM', 'Orange Money', 'Banque', '521000'],
            ['MTN', 'MTN Mobile Money', 'Banque', '521000'],
            ['MOOV', 'Moov Money', 'Banque', '521000'],
            ['WAVE', 'Wave', 'Banque', '521000'],
        ];

        $lignes = [];
        foreach ($journaux as [$code, $intitule, $type, $compte]) {
            $lignes[] = [
                // Une insertion brute ne passe pas par le modèle : le crochet
                // qui pose l'identifiant opaque ne s'exécute pas.
                'uuid' => (string) Str::uuid(),
                'entreprise_id' => $entrepriseId,
                'code' => $code,
                'intitule' => $intitule,
                'type' => $type,
                'compte' => $compte,
                'source' => 'local',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('codes_journaux')->insert($lignes);

        $this->codesJournauxParEntreprise[$entrepriseId] = collect($journaux)
            ->filter(fn($j) => $j[2] === 'Banque')
            ->pluck(1)
            ->values()
            ->all();
    }

    // ═══════════════════════════════════════════════════════════════
    // CLIENTS / FOURNISSEURS
    // ═══════════════════════════════════════════════════════════════

    private const PRENOMS = ['Kouadio', 'Aya', 'Adama', 'Fatoumata', 'Yao', 'Akissi', 'Moussa', 'Aminata', 'Kouassi', 'Awa', 'Ibrahim', 'Affoué', 'Sékou', 'Nafissatou', 'Bakary', 'Chantal', 'Zié', 'Nathalie', 'Paul', 'Mariam'];
    private const NOMS = ['Koné', 'Traoré', 'Bamba', 'Ouattara', 'Diallo', 'Coulibaly', 'Soro', 'Gnahoré', 'Adjobi', 'Sangaré', 'Yéo', 'Touré', 'Kamagaté', 'Diomandé', 'Fofana'];
    private const RAISONS_SOCIALES = ['SARL', 'SUARL', 'SA', 'Établissements', 'Groupe', 'Ets'];
    private const SECTEURS_ENTREPRISE = [
        'BTP', 'Import-Export', 'Restauration', 'Textile', 'Quincaillerie', 'Agro-alimentaire',
        'Transport', 'Informatique', 'Pharmacie', 'Cosmétique', 'Automobile', 'Immobilier',
    ];

    private function nomAleatoire(): string
    {
        return self::PRENOMS[array_rand(self::PRENOMS)] . ' ' . self::NOMS[array_rand(self::NOMS)];
    }

    private function raisonSocialeAleatoire(): string
    {
        $secteur = self::SECTEURS_ENTREPRISE[array_rand(self::SECTEURS_ENTREPRISE)];
        $forme = self::RAISONS_SOCIALES[array_rand(self::RAISONS_SOCIALES)];
        $nom = self::NOMS[array_rand(self::NOMS)];
        return strtoupper($nom) . ' ' . $secteur . ' ' . $forme;
    }

    private function telephoneAleatoire(): string
    {
        $prefixes = ['01', '05', '07', '25'];
        return '+225 ' . $prefixes[array_rand($prefixes)] . ' ' . rand(10, 99) . ' ' . rand(10, 99) . ' ' . rand(10, 99) . ' ' . rand(10, 99);
    }

    private function nccAleatoire(): string
    {
        return rand(1000000, 9999999) . chr(rand(65, 90));
    }

    private function adresseAleatoire(): string
    {
        $communes = ['Cocody', 'Yopougon', 'Marcory', 'Treichville', 'Plateau', 'Adjamé', 'Koumassi', 'Abobo', 'Bingerville', 'Bouaké'];
        return 'Rue ' . rand(1, 40) . ', ' . $communes[array_rand($communes)] . ', Côte d\'Ivoire';
    }

    private function creerClients(int $entrepriseId, int $n, int $idx): array
    {
        $lignes = [];
        for ($i = 1; $i <= $n; $i++) {
            $estEntreprise = $i % 3 === 0;
            $numeroTiers = '411' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $lignes[] = [
                'uuid' => (string) Str::uuid(),
                'entreprise_id' => $entrepriseId,
                'nom' => $estEntreprise ? $this->raisonSocialeAleatoire() : $this->nomAleatoire(),
                'telephone' => $this->telephoneAleatoire(),
                'email' => 'client' . $i . '.e' . ($idx + 1) . '@exemple.ci',
                'adresse' => $this->adresseAleatoire(),
                'ncc' => $estEntreprise ? $this->nccAleatoire() : null,
                'regime_imposition' => $estEntreprise ? (rand(0, 1) ? 'RSI' : 'RNI') : null,
                'rccm' => $estEntreprise ? ('CI-ABJ-' . rand(2015, 2025) . '-B-' . rand(10000, 99999)) : null,
                'compte_comptable' => '411000',
                'numero_tiers' => $numeroTiers,
                'source' => 'local',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('clients')->insert($lignes);
        return Client::where('entreprise_id', $entrepriseId)->pluck('id')->all();
    }

    private function creerFournisseurs(int $entrepriseId, int $n, int $idx): array
    {
        $lignes = [];
        for ($i = 1; $i <= $n; $i++) {
            $numeroTiers = '401' . str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $lignes[] = [
                'uuid' => (string) Str::uuid(),
                'entreprise_id' => $entrepriseId,
                'nom' => $this->raisonSocialeAleatoire(),
                'telephone' => $this->telephoneAleatoire(),
                'email' => 'fournisseur' . $i . '.e' . ($idx + 1) . '@exemple.ci',
                'secteur' => self::SECTEURS_ENTREPRISE[array_rand(self::SECTEURS_ENTREPRISE)],
                'adresse' => $this->adresseAleatoire(),
                'ncc' => $this->nccAleatoire(),
                'regime_imposition' => rand(0, 1) ? 'RSI' : 'RNI',
                'rccm' => 'CI-ABJ-' . rand(2010, 2025) . '-B-' . rand(10000, 99999),
                'compte_comptable' => '401000',
                'numero_tiers' => $numeroTiers,
                'source' => 'local',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('fournisseurs')->insert($lignes);
        return Fournisseur::where('entreprise_id', $entrepriseId)->pluck('id')->all();
    }

    // ═══════════════════════════════════════════════════════════════
    // CATÉGORIES / PRODUITS
    // ═══════════════════════════════════════════════════════════════

    private const SECTEURS_CATEGORIES = [
        'Riz & Céréales', 'Boissons Gazeuses', 'Boissons Alcoolisées', 'Eaux Minérales',
        'Produits Laitiers', 'Épicerie Sucrée', 'Épicerie Salée', 'Légumineuses',
        'Huiles & Condiments', 'Conserves & Sauces', 'Produits Surgelés', 'Boulangerie',
        'Fruits & Légumes', 'Viandes & Charcuterie', 'Poissons & Fruits de Mer',
        'Cosmétique & Hygiène', 'Produits d\'Entretien', 'Quincaillerie Générale',
        'Peinture & Droguerie', 'Électroménager', 'Matériel Informatique',
        'Téléphonie & Accessoires', 'Papeterie & Fournitures', 'Textile & Habillement',
        'Chaussures', 'Bijouterie & Accessoires', 'Jouets & Loisirs', 'Articles de Sport',
        'Meubles & Ameublement', 'Literie', 'Vaisselle & Ustensiles', 'Pièces Détachées Auto',
        'Pneumatiques', 'Lubrifiants & Huiles Moteur', 'Matériaux de Construction',
        'Plomberie', 'Électricité (Matériel)', 'Outillage Professionnel', 'Agro-Fournitures',
        'Semences & Engrais', 'Aliments pour Bétail', 'Matériel Médical',
        'Produits Pharmaceutiques', 'Cahiers & Livres Scolaires', 'Instruments de Musique',
        'Matériel de Bureau', 'Emballages & Conditionnement', 'Produits Chimiques Industriels',
        'Câblerie & Connectique', 'Climatisation & Ventilation', 'Sanitaires',
    ];

    private const SUFFIXES_VARIANTE = ['Import', 'Local', 'Gros', 'Détail'];

    private const FORMATS = [
        '250g', '500g', '1kg', '2kg', '5kg', '10kg', '25kg', '50kg',
        '250ml', '500ml', '1L', '1.5L', '5L', '20L',
        'Unité', 'Paquet', 'Carton de 12', 'Carton de 24', 'Pack de 6', 'Sachet',
    ];

    private const QUALIFICATIFS = [
        'Premium', 'Classique', 'Économique', 'Extra', 'Super', 'Standard',
        'Format Familial', 'Édition Spéciale', 'Nouvelle Formule', 'Origine Contrôlée',
        'Qualité Export', 'Gamme Pro',
    ];

    private const CATEGORIES_SERVICE = [
        'Services de Transport', 'Services de Réparation', 'Services de Conseil',
        'Services Informatiques', 'Location de Matériel', 'Nettoyage & Blanchisserie',
        'Photographie & Impression', 'Services de Restauration', 'Maintenance Technique',
        'Formation Professionnelle',
    ];

    /**
     * @return array<int, array{id:int, nom:string, prix_vente:float, prix_achat:float, compte_vente:string, compte_achat:string, taux_tva:float, type:string}>
     */
    private function creerCategoriesEtProduits(int $entrepriseId, int $nbCategories, int $produitsParCategorie, int $idx): array
    {
        $nomsCategories = [];
        foreach (self::CATEGORIES_SERVICE as $c) {
            $nomsCategories[] = $c;
        }
        foreach (self::SECTEURS_CATEGORIES as $c) {
            $nomsCategories[] = $c;
        }
        $i = 0;
        while (count($nomsCategories) < $nbCategories) {
            $base = self::SECTEURS_CATEGORIES[$i % count(self::SECTEURS_CATEGORIES)];
            $suffixe = self::SUFFIXES_VARIANTE[intdiv($i, count(self::SECTEURS_CATEGORIES)) % count(self::SUFFIXES_VARIANTE)];
            $nomsCategories[] = $base . ' - ' . $suffixe;
            $i++;
        }
        $nomsCategories = array_slice($nomsCategories, 0, $nbCategories);

        $categorieLignes = [];
        $prefixesUtilises = [];
        foreach ($nomsCategories as $nom) {
            $cleanNom = preg_replace('/[^A-Za-z]/', '', $nom);
            $prefixe = strtoupper(substr($cleanNom, 0, 3)) ?: 'CAT';

            // La colonne 'prefixe' est UNIQUE par entreprise (contrainte découverte
            // le 27/07/2026 lors d'un test sur base réelle) — plusieurs catégories
            // peuvent partager les 3 mêmes premières lettres (ex: "Services de
            // Transport" et "Services de Réparation" donnent toutes deux "SER").
            // On garantit l'unicité en ajoutant un compteur numérique si collision,
            // même logique que celle déjà utilisée ailleurs dans l'application
            // (2026_06_29_000002_creer_categories_et_sous_categories.php).
            $prefixeOriginal = $prefixe;
            $compteur = 1;
            while (in_array($prefixe, $prefixesUtilises, true)) {
                $compteur++;
                $prefixe = substr($prefixeOriginal, 0, 3) . $compteur;
            }
            $prefixesUtilises[] = $prefixe;

            $categorieLignes[] = [
                'entreprise_id' => $entrepriseId,
                'nom' => $nom,
                'prefixe' => $prefixe,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('categories')->insert($categorieLignes);
        $categories = Categorie::where('entreprise_id', $entrepriseId)->get(['id', 'nom']);

        $produitsLignes = [];
        $compteurGlobal = 0;
        foreach ($categories as $categorie) {
            $estService = in_array($categorie->nom, self::CATEGORIES_SERVICE);
            $baseNom = preg_split('/[\s\-]/', $categorie->nom)[0];

            for ($p = 1; $p <= $produitsParCategorie; $p++) {
                $compteurGlobal++;
                $qualificatif = self::QUALIFICATIFS[array_rand(self::QUALIFICATIFS)];
                $format = self::FORMATS[array_rand(self::FORMATS)];
                $nomProduit = trim("{$baseNom} {$qualificatif} {$format}") . ' #' . $p;

                if ($estService) {
                    $type = 'service';
                    $prixVente = round(rand(2000, 150000) / 100) * 100;
                    $prixAchat = 0;
                } else {
                    $rand100 = rand(1, 100);
                    $type = match (true) {
                        $rand100 <= 65 => 'marchandise',
                        $rand100 <= 78 => 'matiere_premiere',
                        $rand100 <= 88 => 'produit_fini',
                        $rand100 <= 95 => 'consommable_stockable',
                        default => 'consommable_non_stockable',
                    };
                    $prixAchat = round(rand(500, 50000) / 50) * 50;
                    $prixVente = round($prixAchat * (1 + rand(15, 60) / 100) / 50) * 50;
                }

                $tauxTva = rand(1, 100) <= 90 ? 18.00 : 0.00;
                $compteVente = $this->comptesVenteDisponibles[array_rand($this->comptesVenteDisponibles)];
                $compteAchat = $this->comptesAchatDisponibles[array_rand($this->comptesAchatDisponibles)];

                $produitsLignes[] = [
                    'uuid' => (string) Str::uuid(),
                    'entreprise_id' => $entrepriseId,
                    'reference' => 'P' . ($idx + 1) . '-' . str_pad((string) $compteurGlobal, 6, '0', STR_PAD_LEFT),
                    'nom' => $nomProduit,
                    'type' => $type,
                    'categorie_id' => $categorie->id,
                    'unite' => 'Unité',
                    'prix_achat' => $prixAchat,
                    'prix_vente' => $prixVente,
                    'taux_tva' => $tauxTva,
                    'compte_vente' => $compteVente,
                    'compte_achat' => $compteAchat,
                    'photo' => "https://picsum.photos/seed/p{$entrepriseId}-{$compteurGlobal}/400/400",
                    'statut' => 'actif',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($produitsLignes) >= 500) {
                    DB::table('produits')->insert($produitsLignes);
                    $produitsLignes = [];
                }
            }
        }
        if (!empty($produitsLignes)) {
            DB::table('produits')->insert($produitsLignes);
        }

        return Produit::where('entreprise_id', $entrepriseId)
            ->get(['id', 'nom', 'prix_vente', 'prix_achat', 'compte_vente', 'compte_achat', 'taux_tva', 'type'])
            ->map(fn($p) => [
                'id' => $p->id, 'nom' => $p->nom,
                'prix_vente' => (float) $p->prix_vente, 'prix_achat' => (float) $p->prix_achat,
                'compte_vente' => $p->compte_vente, 'compte_achat' => $p->compte_achat,
                'taux_tva' => (float) $p->taux_tva, 'type' => $p->type,
            ])
            ->all();
    }

    private function creerStocksInitiaux(array $produits, array $pdvIds): void
    {
        $stockables = array_values(array_filter($produits, fn($p) => in_array($p['type'], Produit::TYPES_STOCKABLES)));
        $lignes = [];

        foreach ($stockables as $produit) {
            $nbPdv = rand(1, count($pdvIds));
            $pdvChoisis = (array) array_rand(array_flip($pdvIds), $nbPdv);
            if (!is_array($pdvChoisis)) $pdvChoisis = [$pdvChoisis];

            foreach ($pdvChoisis as $pdvId) {
                $rand20 = rand(1, 20);
                $quantite = match (true) {
                    $rand20 === 1 => 0,
                    $rand20 <= 3  => rand(1, 5),
                    default       => rand(10, 500),
                };

                $lignes[] = [
                    'produit_id' => $produit['id'],
                    'point_de_vente_id' => $pdvId,
                    'quantite_disponible' => $quantite,
                    'stock_minimum' => 10,
                    'stock_maximum' => 1000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (count($lignes) >= 500) {
                    DB::table('stocks')->insert($lignes);
                    $lignes = [];
                }
            }
        }
        if (!empty($lignes)) {
            DB::table('stocks')->insert($lignes);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // PRODUCTION
    // ═══════════════════════════════════════════════════════════════

    private function creerProduction(int $entrepriseId, array $pdvIds, array $produits, array $utilisateursParPdv, int $n): void
    {
        $matieresPremiere = array_values(array_filter($produits, fn($p) => $p['type'] === 'matiere_premiere'));
        $produitsFinis = array_values(array_filter($produits, fn($p) => $p['type'] === 'produit_fini'));

        if (empty($matieresPremiere) || empty($produitsFinis)) {
            $this->warn('    ⚠️  Pas assez de matières premières/produits finis pour générer la production — étape ignorée.');
            return;
        }

        // NB : fiches_techniques a une contrainte UNIQUE(entreprise_id, produit_fini_id) —
        // un seul "type de fiche" par produit fini. On crée donc D'ABORD un jeu de
        // fiches techniques (une par produit fini distinct, jusqu'à 100 ou moins
        // selon le nombre de produits finis disponibles), PUIS on génère $n ordres
        // de production qui piochent parmi ces fiches (plusieurs ordres peuvent
        // légitimement utiliser la même fiche/recette, comme dans la vraie vie).
        $nbFiches = min(100, count($produitsFinis));
        $produitsFinisChoisis = collect($produitsFinis)->random($nbFiches)->values();

        $fiches = []; // [produit_fini_id => ['fiche_id' => x, 'ingredients' => [[id, quantite, prix_achat], ...]]]

        foreach ($produitsFinisChoisis as $produitFini) {
            $ficheId = FicheTechnique::create([
                'entreprise_id' => $entrepriseId,
                'produit_fini_id' => $produitFini['id'],
                'description' => 'Recette de production — ' . $produitFini['nom'],
            ])->id;

            $nbIngredients = min(rand(2, 4), count($matieresPremiere));
            $ingredientsChoisis = collect($matieresPremiere)->random($nbIngredients)->values();

            $ingredientsFiche = [];
            foreach ($ingredientsChoisis as $mp) {
                $quantite = rand(1, 10);
                FicheTechniqueDetail::create([
                    'fiche_technique_id' => $ficheId,
                    'ingredient_id' => $mp['id'],
                    'quantite' => $quantite,
                    'unite' => 'Unité',
                ]);
                $ingredientsFiche[] = ['id' => $mp['id'], 'quantite_unitaire' => $quantite, 'prix_achat' => $mp['prix_achat']];
            }

            $fiches[$produitFini['id']] = ['fiche_id' => $ficheId, 'produit' => $produitFini, 'ingredients' => $ingredientsFiche];
        }

        $fichesDisponibles = array_values($fiches);

        for ($i = 1; $i <= $n; $i++) {
            $ficheChoisie = $fichesDisponibles[array_rand($fichesDisponibles)];
            $produitFini = $ficheChoisie['produit'];
            $pdvId = $pdvIds[array_rand($pdvIds)];

            $dateProduction = now()->subDays(rand(1, 300))->toDateString();
            $quantiteProduite = rand(5, 50);

            $ordre = OrdreProduction::create([
                'entreprise_id' => $entrepriseId,
                'point_de_vente_id' => $pdvId,
                'produit_fini_id' => $produitFini['id'],
                'code_ordre' => $this->genererCodeOrdreUnique($entrepriseId),
                'quantite_cible' => $quantiteProduite,
                'statut' => 'Terminé',
                'date_production' => $dateProduction,
            ]);

            // La porte unique du stock écrit elle-même l'inventaire permanent
            // depuis le lot 4.2 : appeler en plus une génération d'écritures
            // de production doublerait le coût de fabrication dans les données
            // de démonstration, et la balance de démonstration serait fausse.
            $coutMatieres = 0.0;

            foreach ($ficheChoisie['ingredients'] as $ing) {
                $qte = $ing['quantite_unitaire'] * $quantiteProduite;
                $produitModel = Produit::find($ing['id']);

                if ($produitModel && $produitModel->stockActuel($pdvId) >= $qte) {
                    $sortie = StockService::sortie($produitModel, (int) $pdvId, (float) $qte,
                        MouvementStock::PRODUCTION_CONSOMMATION,
                        ['piece' => $ordre, 'reference' => $ordre->code_ordre]);

                    if ($sortie) {
                        $coutMatieres += (float) $sortie->quantite * (float) ($sortie->cout_unitaire ?? 0);
                    }
                }
            }

            $produitFiniModel = Produit::find($produitFini['id']);
            if ($produitFiniModel) {
                StockService::entree($produitFiniModel, (int) $pdvId, (float) $quantiteProduite,
                    MouvementStock::PRODUCTION_ENTREE,
                    ['piece' => $ordre, 'reference' => $ordre->code_ordre,
                     'cout_unitaire' => $quantiteProduite > 0 ? $coutMatieres / $quantiteProduite : 0.0]);
            }
        }
    }

    /**
     * NumerotationService::genererNumeroOD() compte les numéros déjà utilisés
     * via ecritures_comptables.reference_document — or un ordre de production
     * sans consommation valide (genererEcritureProduction() retourne alors
     * sans rien écrire) ne laisse aucune trace dans ecritures_comptables. Le
     * compteur peut donc sous-estimer et régénérer un code déjà pris. Filet
     * de sécurité : on vérifie/réessaie directement contre ordres_production
     * (la vraie source de vérité pour ce cas d'usage précis) avant de retourner.
     */
    private function genererCodeOrdreUnique(int $entrepriseId): string
    {
        $tentatives = 0;
        do {
            $code = NumerotationService::genererNumeroOD($entrepriseId);
            if ($tentatives > 0) {
                $code .= '-' . $tentatives;
            }
            $existe = OrdreProduction::where('entreprise_id', $entrepriseId)->where('code_ordre', $code)->exists();
            $tentatives++;
        } while ($existe && $tentatives < 20);

        return $code;
    }

    // ═══════════════════════════════════════════════════════════════
    // VENTES
    // ═══════════════════════════════════════════════════════════════

    /**
     * @return array<int> IDs des ventes créées
     */
    private function creerVentes(int $entrepriseId, array $pdvIds, array $clients, array $produits, array $utilisateursParPdv, int $n): array
    {
        $produitsVendables = array_values(array_filter($produits, fn($p) => $p['type'] !== 'matiere_premiere'));
        $banques = $this->codesJournauxParEntreprise[$entrepriseId] ?? ['BNI'];
        $ventesIds = [];

        $repartition = [
            'comptant_total'   => (int) round($n * 0.35),
            'comptant_partiel' => (int) round($n * 0.15),
            'credit'           => (int) round($n * 0.20),
            'banque_totale'    => (int) round($n * 0.20),
            'banque_partielle' => (int) round($n * 0.10),
        ];

        foreach ($repartition as $modeCle => $nombre) {
            for ($i = 0; $i < $nombre; $i++) {
                $pdvId = $pdvIds[array_rand($pdvIds)];
                $utilisateurId = $utilisateursParPdv[$pdvId][array_rand($utilisateursParPdv[$pdvId])];
                $client = rand(1, 100) <= 70 ? $clients[array_rand($clients)] : null;
                $date = now()->subDays(rand(0, 360))->toDateString();

                $nbArticles = rand(1, 6);
                $articlesChoisis = collect($produitsVendables)->random(min($nbArticles, count($produitsVendables)))->values();

                $montantHt = 0;
                $montantTva = 0;
                $lignesDetail = [];
                foreach ($articlesChoisis as $art) {
                    $qte = rand(1, 20);
                    $ht = $qte * $art['prix_vente'];
                    $tva = $ht * ($art['taux_tva'] / 100);
                    $montantHt += $ht;
                    $montantTva += $tva;
                    $lignesDetail[] = ['produit' => $art, 'quantite' => $qte, 'ht' => $ht, 'tva' => $tva];
                }
                $remise = rand(1, 100) <= 15 ? round($montantHt * (rand(1, 10) / 100)) : 0;
                $montantTtc = $montantHt - $remise + $montantTva;

                $modePaiement = match ($modeCle) {
                    'comptant_total', 'comptant_partiel' => 'Espèces',
                    'credit' => 'Crédit',
                    default => 'Banque : ' . $banques[array_rand($banques)],
                };

                $numero = NumerotationService::genererNumeroVente($entrepriseId, 'Facture');

                $vente = Vente::create([
                    'point_de_vente_id' => $pdvId,
                    'utilisateur_id' => $utilisateurId,
                    'client_id' => $client,
                    'numero_facture' => $numero,
                    'date_vente' => $date,
                    'mode_paiement' => $modePaiement,
                    'moyen_bancaire' => str_starts_with($modePaiement, 'Banque') ? ['carte', 'virement', 'cheque'][array_rand(['carte', 'virement', 'cheque'])] : null,
                    'reference_paiement' => str_starts_with($modePaiement, 'Banque') ? 'REF' . rand(100000, 999999) : null,
                    'montant_ht' => $montantHt,
                    'montant_tva' => $montantTva,
                    'montant_ttc' => $montantTtc,
                    'remise' => $remise,
                    'statut' => match ($modeCle) {
                        'credit' => 'Crédit',
                        'comptant_partiel', 'banque_partielle' => 'Avance',
                        default => 'Payé',
                    },
                    'type_facture' => 'normale',
                    'etape' => 'Facture',
                    'normalise' => rand(1, 100) <= 70,
                    'archived' => false,
                ]);

                foreach ($lignesDetail as $ld) {
                    $detail = VenteDetail::create([
                        'vente_id' => $vente->id,
                        'produit_id' => $ld['produit']['id'],
                        'quantite' => $ld['quantite'],
                        'unite' => 'Unité',
                        'prix_unitaire' => $ld['produit']['prix_vente'],
                        'montant_tva' => $ld['tva'],
                        'montant_ttc' => $ld['ht'] + $ld['tva'],
                    ]);

                    $produitModel = Produit::find($ld['produit']['id']);
                    if ($produitModel && $produitModel->estStockable()) {
                        $stockDispo = $produitModel->stockActuel($pdvId);
                        if ($stockDispo >= $ld['quantite']) {
                            $produitModel->decrementStock($pdvId, $ld['quantite']);
                            $detail->update(['quantite_livree' => $ld['quantite']]);
                        }
                    }
                }

                $montantPaye = match ($modeCle) {
                    'comptant_total', 'banque_totale' => $montantTtc,
                    'comptant_partiel', 'banque_partielle' => round($montantTtc * (rand(30, 70) / 100)),
                    'credit' => 0,
                };

                ComptabiliteService::genererEcrituresVente($vente, $montantPaye, $modePaiement, $date);

                if ($montantPaye > 0) {
                    $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pdvId)->orderByDesc('created_at')->value('solde_resultat') ?? 0;
                    TresorerieJournal::create([
                        'point_de_vente_id' => $pdvId,
                        'date_operation' => $date,
                        'type_operation' => 'Encaissement',
                        'libelle' => ComptabiliteService::libelleTresorerieVente($vente),
                        'mode_paiement' => $modePaiement,
                        'montant_entree' => $montantPaye,
                        'montant_sortie' => 0,
                        'solde_resultat' => $soldeActuel + $montantPaye,
                        'reference_document' => $numero,
                    ]);
                }

                $ventesIds[] = $vente->id;
            }
        }

        return $ventesIds;
    }

    // ═══════════════════════════════════════════════════════════════
    // ACHATS
    // ═══════════════════════════════════════════════════════════════

    private function creerAchats(int $entrepriseId, array $pdvIds, array $fournisseurs, array $produits, array $utilisateursParPdv, int $n): array
    {
        $produitsAchetables = array_values(array_filter($produits, fn($p) => $p['type'] !== 'service'));
        $banques = $this->codesJournauxParEntreprise[$entrepriseId] ?? ['BNI'];
        $achatsIds = [];

        $repartition = [
            'comptant_total'   => (int) round($n * 0.30),
            'comptant_partiel' => (int) round($n * 0.10),
            'credit'           => (int) round($n * 0.30),
            'banque_totale'    => (int) round($n * 0.20),
            'banque_partielle' => (int) round($n * 0.10),
        ];

        foreach ($repartition as $modeCle => $nombre) {
            for ($i = 0; $i < $nombre; $i++) {
                $pdvId = $pdvIds[array_rand($pdvIds)];
                $utilisateurId = $utilisateursParPdv[$pdvId][array_rand($utilisateursParPdv[$pdvId])];
                $fournisseurId = $fournisseurs[array_rand($fournisseurs)];
                $date = now()->subDays(rand(0, 360))->toDateString();

                $nbArticles = rand(1, 8);
                $articlesChoisis = collect($produitsAchetables)->random(min($nbArticles, count($produitsAchetables)))->values();

                $montantHt = 0;
                $montantTva = 0;
                $lignesDetail = [];
                foreach ($articlesChoisis as $art) {
                    $qte = rand(5, 100);
                    $ht = $qte * $art['prix_achat'];
                    $tva = $ht * ($art['taux_tva'] / 100);
                    $montantHt += $ht;
                    $montantTva += $tva;
                    $lignesDetail[] = ['produit' => $art, 'quantite' => $qte, 'ht' => $ht, 'tva' => $tva];
                }
                $montantTtc = $montantHt + $montantTva;

                $modePaiement = match ($modeCle) {
                    'comptant_total', 'comptant_partiel' => 'Espèces',
                    'credit' => 'Crédit',
                    default => 'Banque : ' . $banques[array_rand($banques)],
                };

                $numero = NumerotationService::genererNumeroAchat($entrepriseId, 'Facture');

                $achat = Achat::create([
                    'point_de_vente_id' => $pdvId,
                    'utilisateur_id' => $utilisateurId,
                    'fournisseur_id' => $fournisseurId,
                    'numero_facture' => $numero,
                    'date_achat' => $date,
                    'mode_paiement' => $modePaiement,
                    'moyen_bancaire' => str_starts_with($modePaiement, 'Banque') ? ['carte', 'virement', 'cheque'][array_rand(['carte', 'virement', 'cheque'])] : null,
                    'reference_paiement' => str_starts_with($modePaiement, 'Banque') ? 'REF' . rand(100000, 999999) : null,
                    'montant_ht' => $montantHt,
                    'montant_tva' => $montantTva,
                    'montant_ttc' => $montantTtc,
                    'statut' => match ($modeCle) {
                        'credit' => 'Crédit',
                        'comptant_partiel', 'banque_partielle' => 'Avance',
                        default => 'Payé',
                    },
                    'type_facture' => 'normale',
                    'etape' => 'Facture',
                    'normalise' => rand(1, 100) <= 60,
                    'archived' => false,
                ]);

                foreach ($lignesDetail as $ld) {
                    AchatDetail::create([
                        'achat_id' => $achat->id,
                        'produit_id' => $ld['produit']['id'],
                        'quantite' => $ld['quantite'],
                        'unite' => 'Unité',
                        'prix_unitaire' => $ld['produit']['prix_achat'],
                        'montant_tva' => $ld['tva'],
                        'montant_ttc' => $ld['ht'] + $ld['tva'],
                    ]);

                    $produitModel = Produit::find($ld['produit']['id']);
                    if ($produitModel && $produitModel->estStockable()) {
                        $produitModel->incrementStock($pdvId, $ld['quantite']);
                    }
                }

                $montantPaye = match ($modeCle) {
                    'comptant_total', 'banque_totale' => $montantTtc,
                    'comptant_partiel', 'banque_partielle' => round($montantTtc * (rand(30, 70) / 100)),
                    'credit' => 0,
                };

                ComptabiliteService::genererEcrituresAchat($achat, $montantPaye, $modePaiement, $date);

                if ($montantPaye > 0) {
                    $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pdvId)->orderByDesc('created_at')->value('solde_resultat') ?? 0;
                    TresorerieJournal::create([
                        'point_de_vente_id' => $pdvId,
                        'date_operation' => $date,
                        'type_operation' => 'Décaissement',
                        'libelle' => ComptabiliteService::libelleTresorerieAchat($achat),
                        'mode_paiement' => $modePaiement,
                        'montant_entree' => 0,
                        'montant_sortie' => $montantPaye,
                        'solde_resultat' => $soldeActuel - $montantPaye,
                        'reference_document' => $numero,
                    ]);
                }

                $achatsIds[] = $achat->id;
            }
        }

        return $achatsIds;
    }

    /**
     * bons_livraison.numero_bl est UNIQUE GLOBALEMENT (pas par entreprise),
     * alors que NumerotationService::genererNumeroBL() ne compte que pour
     * l'entreprise courante — deux entreprises créant leur premier BL le
     * même jour généreraient la même chaîne. Même filet de sécurité que
     * pour genererCodeOrdreUnique().
     */
    private function genererNumeroBLUnique(int $entrepriseId): string
    {
        $tentatives = 0;
        do {
            $code = NumerotationService::genererNumeroBL($entrepriseId);
            if ($tentatives > 0) {
                $code .= '-' . $tentatives;
            }
            $existe = BonLivraison::where('numero_bl', $code)->exists();
            $tentatives++;
        } while ($existe && $tentatives < 20);

        return $code;
    }

    // ═══════════════════════════════════════════════════════════════
    // CYCLE DEVIS → BON DE COMMANDE → BON DE LIVRAISON → FACTURE
    // ═══════════════════════════════════════════════════════════════

    private function creerCycleDocumentsVente(int $entrepriseId, array $pdvIds, array $clients, array $produits, array $utilisateursParPdv, int $n): void
    {
        $produitsVendables = array_values(array_filter($produits, fn($p) => $p['type'] !== 'matiere_premiere'));

        for ($i = 0; $i < $n; $i++) {
            $pdvId = $pdvIds[array_rand($pdvIds)];
            $utilisateurId = $utilisateursParPdv[$pdvId][array_rand($utilisateursParPdv[$pdvId])];
            $clientId = $clients[array_rand($clients)];
            $dateDevis = now()->subDays(rand(10, 60));

            $nbArticles = rand(1, 4);
            $articlesChoisis = collect($produitsVendables)->random(min($nbArticles, count($produitsVendables)))->values();
            $montantHt = 0; $montantTva = 0; $lignesDetail = [];
            foreach ($articlesChoisis as $art) {
                $qte = rand(1, 10);
                $ht = $qte * $art['prix_vente'];
                $tva = $ht * ($art['taux_tva'] / 100);
                $montantHt += $ht; $montantTva += $tva;
                $lignesDetail[] = ['produit' => $art, 'quantite' => $qte, 'ht' => $ht, 'tva' => $tva];
            }
            $montantTtc = $montantHt + $montantTva;

            $devis = Vente::create([
                'point_de_vente_id' => $pdvId, 'utilisateur_id' => $utilisateurId, 'client_id' => $clientId,
                'numero_facture' => NumerotationService::genererNumeroVente($entrepriseId, 'Devis'),
                'date_vente' => $dateDevis->toDateString(), 'mode_paiement' => 'Crédit',
                'montant_ht' => $montantHt, 'montant_tva' => $montantTva, 'montant_ttc' => $montantTtc, 'remise' => 0,
                'statut' => 'Brouillon', 'type_facture' => 'normale', 'etape' => 'Devis', 'normalise' => false, 'archived' => false,
            ]);
            foreach ($lignesDetail as $ld) {
                VenteDetail::create([
                    'vente_id' => $devis->id, 'produit_id' => $ld['produit']['id'], 'quantite' => $ld['quantite'],
                    'unite' => 'Unité', 'prix_unitaire' => $ld['produit']['prix_vente'], 'montant_tva' => $ld['tva'], 'montant_ttc' => $ld['ht'] + $ld['tva'],
                ]);
            }

            if (rand(1, 100) > 30) {
                $bc = Vente::create([
                    'point_de_vente_id' => $pdvId, 'utilisateur_id' => $utilisateurId, 'client_id' => $clientId,
                    'numero_facture' => NumerotationService::genererNumeroVente($entrepriseId, 'Bon de commande'),
                    'date_vente' => $dateDevis->copy()->addDays(rand(1, 5))->toDateString(), 'mode_paiement' => 'Crédit',
                    'montant_ht' => $montantHt, 'montant_tva' => $montantTva, 'montant_ttc' => $montantTtc, 'remise' => 0,
                    'statut' => 'Confirmée', 'type_facture' => 'normale', 'etape' => 'Bon de commande',
                    'normalise' => false, 'archived' => false, 'parent_id' => $devis->id,
                ]);
                foreach ($lignesDetail as $ld) {
                    VenteDetail::create([
                        'vente_id' => $bc->id, 'produit_id' => $ld['produit']['id'], 'quantite' => $ld['quantite'],
                        'unite' => 'Unité', 'prix_unitaire' => $ld['produit']['prix_vente'], 'montant_tva' => $ld['tva'], 'montant_ttc' => $ld['ht'] + $ld['tva'],
                    ]);
                }

                if (rand(1, 100) > 40) {
                    $dateFacture = $dateDevis->copy()->addDays(rand(6, 15))->toDateString();
                    $numeroFacture = NumerotationService::genererNumeroVente($entrepriseId, 'Facture');

                    $facture = Vente::create([
                        'point_de_vente_id' => $pdvId, 'utilisateur_id' => $utilisateurId, 'client_id' => $clientId,
                        'numero_facture' => $numeroFacture, 'date_vente' => $dateFacture, 'mode_paiement' => 'Espèces',
                        'montant_ht' => $montantHt, 'montant_tva' => $montantTva, 'montant_ttc' => $montantTtc, 'remise' => 0,
                        'statut' => 'Payé', 'type_facture' => 'normale', 'etape' => 'Facture',
                        'normalise' => rand(1, 100) <= 70, 'archived' => false, 'parent_id' => $bc->id,
                    ]);

                    $bl = BonLivraison::create([
                        'numero_bl' => $this->genererNumeroBLUnique($entrepriseId),
                        'vente_id' => $bc->id, 'facture_vente_id' => $facture->id, 'point_de_vente_id' => $pdvId,
                        'client_id' => $clientId, 'created_by' => $utilisateurId,
                        'date_livraison' => $dateFacture, 'statut' => 'Livré', 'livraison_partielle' => false,
                    ]);

                    foreach ($lignesDetail as $ld) {
                        VenteDetail::create([
                            'vente_id' => $facture->id, 'produit_id' => $ld['produit']['id'], 'quantite' => $ld['quantite'],
                            'unite' => 'Unité', 'prix_unitaire' => $ld['produit']['prix_vente'], 'montant_tva' => $ld['tva'],
                            'montant_ttc' => $ld['ht'] + $ld['tva'], 'quantite_livree' => $ld['quantite'],
                        ]);
                        BonLivraisonDetail::create([
                            'bon_livraison_id' => $bl->id, 'produit_id' => $ld['produit']['id'],
                            'libelle' => $ld['produit']['nom'], 'unite' => 'Unité',
                            'qte_commandee' => $ld['quantite'], 'qte_livree' => $ld['quantite'],
                        ]);

                        $produitModel = Produit::find($ld['produit']['id']);
                        if ($produitModel && $produitModel->estStockable()) {
                            $stockDispo = $produitModel->stockActuel($pdvId);
                            if ($stockDispo >= $ld['quantite']) {
                                $produitModel->decrementStock($pdvId, $ld['quantite']);
                            }
                        }
                    }

                    ComptabiliteService::genererEcrituresVente($facture, $montantTtc, 'Espèces', $dateFacture);

                    $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pdvId)->orderByDesc('created_at')->value('solde_resultat') ?? 0;
                    TresorerieJournal::create([
                        'point_de_vente_id' => $pdvId, 'date_operation' => $dateFacture, 'type_operation' => 'Encaissement',
                        'libelle' => ComptabiliteService::libelleTresorerieVente($facture), 'mode_paiement' => 'Espèces',
                        'montant_entree' => $montantTtc, 'montant_sortie' => 0,
                        'solde_resultat' => $soldeActuel + $montantTtc, 'reference_document' => $numeroFacture,
                    ]);
                }
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // AVOIRS
    // ═══════════════════════════════════════════════════════════════

    private function creerAvoirs(array $ventesIds, array $achatsIds): void
    {
        $ventesEligibles = collect($ventesIds)->random(min(15, count($ventesIds)));
        foreach ($ventesEligibles as $venteId) {
            $vente = Vente::with('details.produit')->find($venteId);
            if (!$vente || $vente->details->isEmpty()) continue;

            $pourcentage = [0.2, 0.3, 0.5, 1.0][array_rand([0.2, 0.3, 0.5, 1.0])];
            $entrepriseId = $vente->pointDeVente->entreprise_id;

            $avoir = Vente::create([
                'point_de_vente_id' => $vente->point_de_vente_id,
                'utilisateur_id' => $vente->utilisateur_id,
                'client_id' => $vente->client_id,
                'numero_facture' => NumerotationService::genererNumeroVente($entrepriseId, 'Facture', 'avoir'),
                'date_vente' => now()->subDays(rand(0, 30))->toDateString(),
                'mode_paiement' => $vente->mode_paiement,
                'montant_ht' => round($vente->montant_ht * $pourcentage, 2),
                'montant_tva' => round($vente->montant_tva * $pourcentage, 2),
                'montant_ttc' => round($vente->montant_ttc * $pourcentage, 2),
                'remise' => 0,
                'statut' => 'Payé',
                'type_facture' => 'avoir',
                'etape' => 'Facture',
                'normalise' => false,
                'archived' => false,
                'parent_id' => $vente->id,
            ]);

            foreach ($vente->details as $detail) {
                $qteAvoir = max(1, (int) round($detail->quantite * $pourcentage));
                VenteDetail::create([
                    'vente_id' => $avoir->id, 'produit_id' => $detail->produit_id, 'quantite' => $qteAvoir,
                    'unite' => $detail->unite, 'prix_unitaire' => $detail->prix_unitaire,
                    'montant_tva' => round($detail->montant_tva * $pourcentage, 2),
                    'montant_ttc' => round(($detail->montant_ttc) * $pourcentage, 2),
                ]);
                if ($detail->produit && $detail->produit->estStockable()) {
                    $detail->produit->incrementStock($vente->point_de_vente_id, $qteAvoir);
                }
            }

            ComptabiliteService::genererEcritureAvoirVente($avoir);
        }

        $achatsEligibles = collect($achatsIds)->random(min(15, count($achatsIds)));
        foreach ($achatsEligibles as $achatId) {
            $achat = Achat::with('details.produit')->find($achatId);
            if (!$achat || $achat->details->isEmpty()) continue;

            $pourcentage = [0.2, 0.3, 0.5, 1.0][array_rand([0.2, 0.3, 0.5, 1.0])];
            $entrepriseId = $achat->pointDeVente->entreprise_id;

            $avoir = Achat::create([
                'point_de_vente_id' => $achat->point_de_vente_id,
                'utilisateur_id' => $achat->utilisateur_id,
                'fournisseur_id' => $achat->fournisseur_id,
                'numero_facture' => NumerotationService::genererNumeroAchat($entrepriseId, 'Facture', 'avoir'),
                'date_achat' => now()->subDays(rand(0, 30))->toDateString(),
                'mode_paiement' => $achat->mode_paiement,
                'montant_ht' => round($achat->montant_ht * $pourcentage, 2),
                'montant_tva' => round($achat->montant_tva * $pourcentage, 2),
                'montant_ttc' => round($achat->montant_ttc * $pourcentage, 2),
                'statut' => 'Payé',
                'type_facture' => 'avoir',
                'etape' => 'Facture',
                'normalise' => false,
                'archived' => false,
                'parent_id' => $achat->id,
            ]);

            foreach ($achat->details as $detail) {
                $qteAvoir = max(1, (int) round($detail->quantite * $pourcentage));
                AchatDetail::create([
                    'achat_id' => $avoir->id, 'produit_id' => $detail->produit_id, 'quantite' => $qteAvoir,
                    'unite' => $detail->unite, 'prix_unitaire' => $detail->prix_unitaire,
                    'montant_tva' => round($detail->montant_tva * $pourcentage, 2),
                    'montant_ttc' => round(($detail->montant_ttc) * $pourcentage, 2),
                ]);
                if ($detail->produit && $detail->produit->estStockable()) {
                    $stockDispo = $detail->produit->stockActuel($achat->point_de_vente_id);
                    if ($stockDispo >= $qteAvoir) {
                        $detail->produit->decrementStock($achat->point_de_vente_id, $qteAvoir);
                    }
                }
            }

            ComptabiliteService::genererEcritureAvoirAchat($avoir);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // RÈGLEMENTS DIFFÉRÉS
    // ═══════════════════════════════════════════════════════════════

    private function creerReglementsDifferes(array $ventesIds, array $achatsIds): void
    {
        $ventesCredit = Vente::whereIn('id', $ventesIds)->where('statut', 'Crédit')->get();
        foreach ($ventesCredit as $vente) {
            if (rand(1, 100) > 60) continue;
            $montantRegle = rand(1, 100) <= 50 ? $vente->montant_ttc : round($vente->montant_ttc * (rand(30, 80) / 100));
            $dateReglement = Carbon::parse($vente->date_vente)->addDays(rand(5, 45))->toDateString();
            ComptabiliteService::genererEcritureReglementVente($vente, $montantRegle, 'Espèces', $dateReglement);

            $pdvId = $vente->point_de_vente_id;
            $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pdvId)->orderByDesc('created_at')->value('solde_resultat') ?? 0;
            TresorerieJournal::create([
                'point_de_vente_id' => $pdvId, 'date_operation' => $dateReglement, 'type_operation' => 'Encaissement',
                'libelle' => 'Règlement créance — ' . $vente->numero_facture, 'mode_paiement' => 'Espèces',
                'montant_entree' => $montantRegle, 'montant_sortie' => 0,
                'solde_resultat' => $soldeActuel + $montantRegle, 'reference_document' => $vente->numero_facture,
            ]);
            $vente->update(['statut' => $montantRegle >= $vente->montant_ttc ? 'Payé' : 'Avance']);
        }

        $achatsCredit = Achat::whereIn('id', $achatsIds)->where('statut', 'Crédit')->get();
        foreach ($achatsCredit as $achat) {
            if (rand(1, 100) > 60) continue;
            $montantRegle = rand(1, 100) <= 50 ? $achat->montant_ttc : round($achat->montant_ttc * (rand(30, 80) / 100));
            $dateReglement = Carbon::parse($achat->date_achat)->addDays(rand(5, 45))->toDateString();
            ComptabiliteService::genererEcritureReglementAchat($achat, $montantRegle, 'Espèces', $dateReglement);

            $pdvId = $achat->point_de_vente_id;
            $soldeActuel = TresorerieJournal::where('point_de_vente_id', $pdvId)->orderByDesc('created_at')->value('solde_resultat') ?? 0;
            TresorerieJournal::create([
                'point_de_vente_id' => $pdvId, 'date_operation' => $dateReglement, 'type_operation' => 'Décaissement',
                'libelle' => 'Règlement dette — ' . $achat->numero_facture, 'mode_paiement' => 'Espèces',
                'montant_entree' => 0, 'montant_sortie' => $montantRegle,
                'solde_resultat' => $soldeActuel - $montantRegle, 'reference_document' => $achat->numero_facture,
            ]);
            $achat->update(['statut' => $montantRegle >= $achat->montant_ttc ? 'Payé' : 'Avance']);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // MOUVEMENTS DIVERS
    // ═══════════════════════════════════════════════════════════════

    private function creerMouvementsDivers(int $entrepriseId, array $pdvIds, array $produits, array $fournisseurs, array $utilisateursParPdv): void
    {
        $stockables = array_values(array_filter($produits, fn($p) => in_array($p['type'], Produit::TYPES_STOCKABLES)));

        for ($i = 0; $i < 20; $i++) {
            $produit = $stockables[array_rand($stockables)];
            $produitModel = Produit::find($produit['id']);
            [$source, $destination] = collect($pdvIds)->random(2)->values()->all();
            $stockSource = $produitModel->stockActuel($source);
            if ($stockSource < 2) continue;

            $qte = min($stockSource, rand(1, 30));
            $demandeurId = $utilisateursParPdv[$destination][array_rand($utilisateursParPdv[$destination])];
            $approbateurId = $utilisateursParPdv[$source][array_rand($utilisateursParPdv[$source])];

            TransfertStock::create([
                'produit_id' => $produit['id'],
                'point_de_vente_source_id' => $source,
                'point_de_vente_destination_id' => $destination,
                'quantite' => $qte,
                'statut' => 'Validé',
                'demandeur_id' => $demandeurId,
                'approbateur_id' => $approbateurId,
                'note' => 'Réapprovisionnement inter-site (démonstration)',
                'approuve_le' => now()->subDays(rand(0, 90)),
            ]);

            $produitModel->decrementStock($source, $qte);
            $produitModel->incrementStock($destination, $qte);
        }

        for ($i = 0; $i < 15; $i++) {
            $pdvId = $pdvIds[array_rand($pdvIds)];
            $utilisateurId = $utilisateursParPdv[$pdvId][array_rand($utilisateursParPdv[$pdvId])];
            $fournisseurId = $fournisseurs[array_rand($fournisseurs)];
            $nbArticles = rand(1, 5);
            $articlesChoisis = collect($stockables)->random(min($nbArticles, count($stockables)))->values();

            $montantHt = 0; $montantTva = 0;
            foreach ($articlesChoisis as $art) {
                $qte = rand(10, 100);
                $ht = $qte * $art['prix_achat'];
                $montantHt += $ht;
                $montantTva += $ht * ($art['taux_tva'] / 100);
            }
            $montantTtc = $montantHt + $montantTva;

            $bc = Achat::create([
                'point_de_vente_id' => $pdvId, 'utilisateur_id' => $utilisateurId, 'fournisseur_id' => $fournisseurId,
                'numero_facture' => NumerotationService::genererNumeroAchat($entrepriseId, 'Bon de commande'),
                'date_achat' => now()->subDays(rand(0, 20))->toDateString(), 'mode_paiement' => 'Crédit',
                'montant_ht' => $montantHt, 'montant_tva' => $montantTva, 'montant_ttc' => $montantTtc,
                'statut' => 'Crédit', 'type_facture' => 'normale', 'etape' => 'Bon de commande',
                'normalise' => false, 'archived' => false,
            ]);
            foreach ($articlesChoisis as $art) {
                AchatDetail::create([
                    'achat_id' => $bc->id, 'produit_id' => $art['id'], 'quantite' => rand(10, 100),
                    'unite' => 'Unité', 'prix_unitaire' => $art['prix_achat'],
                    'montant_tva' => 0, 'montant_ttc' => 0,
                ]);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // SUPERADMIN
    // ═══════════════════════════════════════════════════════════════

    /**
     * Le compte de la plateforme.
     *
     * Son mot de passe valait « 12345678SUPER@ », en clair dans ce fichier,
     * versionne — un avertissement le signalait meme, sans rien y changer.
     * Il vient desormais de `SUPERADMIN_PASSWORD`, ou est tire au hasard et
     * affiche une seule fois.
     */
    private function creerSuperAdmin(): void
    {
        $adresse = env('SUPERADMIN_EMAIL', 'superadmin@gmail.com');
        $motDePasse = env('SUPERADMIN_PASSWORD') ?: \Illuminate\Support\Str::password(20);

        $annoncer = function () use ($motDePasse) {
            if (!env('SUPERADMIN_PASSWORD')) {
                $this->warn("    Mot de passe tiré au hasard : {$motDePasse}");
                $this->warn('    Notez-le maintenant : il ne sera plus affiché.');
            }
        };

        $existant = Utilisateur::where('email', $adresse)->first();
        if ($existant) {
            $existant->update([
                'password' => Hash::make($motDePasse),
                'role' => 'superadmin', 'statut' => 'actif',
                'doit_changer_password' => true,
            ]);
            $this->warn('    ⚠️  Le compte superadmin existait déjà — mot de passe réinitialisé.');
            $annoncer();
            return;
        }

        Utilisateur::create([
            'entreprise_id' => null,
            'point_de_vente_id' => null,
            'nom' => 'ADMIN',
            'prenom' => 'Super',
            'email' => env('SUPERADMIN_EMAIL', 'superadmin@gmail.com'),
            'password' => Hash::make($motDePasse),
            'role' => 'superadmin',
            'fonction' => 'Super Administrateur',
            'statut' => 'actif',
            'habilitations' => json_encode([]),
            'doit_changer_password' => true,
        ]);

        $this->info('    ✅ Compte superadmin créé.');
        $annoncer();
        $this->warn('       Recommandé : le changer dès la première connexion, ou définir SUPERADMIN_PASSWORD en variable');
        $this->warn('       d\'environnement et l\'utiliser à la place d\'une valeur codée en dur, avant tout usage en production réelle.');
    }

    // ═══════════════════════════════════════════════════════════════
    // RAPPORT D'ANOMALIES
    // ═══════════════════════════════════════════════════════════════

    private function afficherAnomalies(): void
    {
        $this->info("\n📋 Points relevés pendant ce peuplement (à vérifier/tester manuellement) :");
        $lignes = [
            "1. Les montants payés partiels (statut 'Avance') sont volontairement inférieurs au TTC — vérifier que le solde restant apparaît correctement dans Comptabilité > Créances & Règlements.",
            "2. ~5% des lignes de stock sont volontairement à 0 (rupture) — utile pour tester les alertes de rupture et le blocage de vente.",
            "3. Les URLs d'images (produits: picsum.photos, logos: ui-avatars.com) sont des services externes de placeholder — à remplacer par de vraies images lors d'un usage réel, elles nécessitent une connexion internet pour s'afficher.",
            "4. Le mot de passe superadmin est en clair dans le code source du seeder (demande explicite) — à faire tourner uniquement sur un dépôt privé, ou migrer vers une variable d'environnement.",
            "5. La normalisation FNE est simulée directement en base (champ normalise=true/false aléatoire) et NE PASSE PAS par les Jobs réels (NormaliserFactureFne/NormaliserAchatBapaJob) — numero_fne/qr_code_data/signature_dgi restent donc vides même sur les documents marqués normalisés. Suffisant pour tester les pages KPI (Gestion FNE, Situation Générale), pas pour tester l'intégration DGI elle-même.",
            "6. Ce script peut prendre plusieurs minutes (environ 20 000 produits + centaines de ventes/achats passant par la vraie logique comptable) — ne pas interrompre en cours d'exécution.",
        ];
        foreach ($lignes as $l) {
            $this->line('   • ' . $l);
        }
    }
}
