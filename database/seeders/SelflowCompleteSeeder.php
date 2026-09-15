<?php

namespace Database\Seeders;

use App\Modules\Admin\Modeles\Categorie;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\PointDeVente;
use App\Modules\Admin\Modeles\Produit;
use App\Modules\Authentification\Modeles\Utilisateur;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SelflowCompleteSeeder extends Seeder
{
    const MODULES = ['principal','ventes','achats','stocks','comptabilite',
                     'points_de_vente','produits','tiers','rapports','b2b',
                     'fne','production','tresorerie'];

    public function run(): void
    {
        // Ce jeu commence par VIDER les tables. Lancé par `deploy-production.sh`
        // à chaque mise en ligne, il effaçait la production entière. Il refuse
        // désormais une base qui porte déjà des entreprises, sauf accord écrit
        // dans l'environnement — et jamais sur un serveur de production.
        if (app()->isProduction() || (DB::table('entreprises')->exists() && env('SELFLOW_PURGE_AUTORISEE') !== 'oui')) {
            throw new \RuntimeException(
                'SelflowCompleteSeeder refusé : il vide toutes les tables, et cette base porte des entreprises '
                . (app()->isProduction() ? '(serveur de production).' : '. Posez SELFLOW_PURGE_AUTORISEE=oui pour une base de développement.')
            );
        }

        $this->command->info('🧹 Nettoyage de la base...');
        $this->wipe();

        $this->command->info('👤 Création du SuperAdmin...');
        $this->creerSuperAdmin();

        $this->command->info('🏢 Création de DC-KNOWING...');
        [$e1, $pdvs1, $users1] = $this->creerEntrepriseDCKnowing();

        $this->command->info('🏢 Création de B-HOME SARL...');
        [$e2, $pdvs2, $users2] = $this->creerEntrepriseBHome();

        $this->command->info('📚 Plan comptable...');
        $this->creerPlanComptable($e1);
        $this->creerPlanComptable($e2);

        $this->command->info('📦 Catégories & Produits DC-KNOWING...');
        $produits1 = $this->creerCategsEtProduits($e1, $pdvs1);

        $this->command->info('📦 Catégories & Produits B-HOME...');
        $produits2 = $this->creerCategsEtProduits($e2, $pdvs2);

        $this->command->info('👥 Tiers DC-KNOWING...');
        [$clients1, $fournisseurs1] = $this->creerTiers($e1);

        $this->command->info('👥 Tiers B-HOME...');
        [$clients2, $fournisseurs2] = $this->creerTiers($e2);

        $this->command->info('🧾 Ventes DC-KNOWING...');
        $this->creerVentes($e1, $pdvs1, $clients1, $produits1, $users1);

        $this->command->info('🧾 Ventes B-HOME...');
        $this->creerVentes($e2, $pdvs2, $clients2, $produits2, $users2);

        $this->command->info('🛒 Achats DC-KNOWING...');
        $this->creerAchats($e1, $pdvs1, $fournisseurs1, $produits1, $users1);

        $this->command->info('🛒 Achats B-HOME...');
        $this->creerAchats($e2, $pdvs2, $fournisseurs2, $produits2, $users2);

        $this->command->info('🏣 Production DC-KNOWING...');
        $this->creerProduction($e1, $pdvs1, $produits1);

        $this->command->info('🏣 Production B-HOME...');
        $this->creerProduction($e2, $pdvs2, $produits2);

        $this->command->info('📊 Mouvements stock...');
        $this->creerMouvements($e1, $pdvs1, $produits1, $users1);
        $this->creerMouvements($e2, $pdvs2, $produits2, $users2);

        $this->command->info('✅ Seed terminé !');
        $this->command->table(
            ['Rôle', 'Email', 'Mot de passe'],
            [
                ['SuperAdmin',      env('SUPERADMIN_EMAIL', 'superadmin@gmail.com'), 'voir ci-dessus'],
                ['Admin DC-KNOWING','dcknowing@gmail.com',  'voir ci-dessus'],
                ['Admin B-HOME',    'bhome@gmail.com',      'voir ci-dessus'],
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────
    // WIPE
    // ──────────────────────────────────────────────────────────────
    private function wipe(): void
    {
        Schema::disableForeignKeyConstraints();
        $tables = [
            'sticker_transactions','tax_configurations',
            'bons_livraison_details','bons_livraison',
            'operations','fne_credentials',
            'b2b_negociations',
            'ordre_production_ingredients','ordres_production',
            'fiches_techniques_ingredients','fiches_techniques',
            'transferts_stock','mouvements_stock','stocks',
            'ecritures_comptables','tresorerie_journal',
            'vente_details','ventes',
            'achat_details','achats',
            'sous_categories','categories',
            'produits','clients','fournisseurs',
            'plan_comptable','periodes',
            'utilisateurs','points_de_vente',
            'journal_audit','entreprises',
        ];
        foreach ($tables as $t) {
            if (Schema::hasTable($t)) DB::table($t)->truncate();
        }
        Schema::enableForeignKeyConstraints();
    }

    // ──────────────────────────────────────────────────────────────
    // SUPERADMIN
    // ──────────────────────────────────────────────────────────────
    /**
     * Le compte de la plateforme.
     *
     * Son mot de passe ne figure plus dans ce fichier. Il valait
     * « 12345678SUPER@ », en clair, versionné sur un dépôt distant, et le
     * compte partait avec `doit_changer_password => false` : rien n'obligeait
     * jamais à le changer. Un déploiement qui exécutait ce seeder livrait donc
     * à quiconque a lu le dépôt un accès à **toutes les entreprises de la
     * plateforme** — leurs chiffres d'affaires, leurs clients, leurs clés FNE.
     *
     * Désormais : le mot de passe vient de `SUPERADMIN_PASSWORD`, ou est tiré
     * au hasard et affiché **une seule fois**, à l'exécution. Et le compte
     * doit le changer à la première connexion, quoi qu'il arrive.
     */
    /** Mot de passe des comptes de démonstration : tiré une fois, annoncé une fois. */
    private ?string $motDePasseDemo = null;

    private function motDePasseDemo(): string
    {
        if ($this->motDePasseDemo === null) {
            $this->motDePasseDemo = env('DEMO_PASSWORD') ?: Str::password(16);

            if (!env('DEMO_PASSWORD')) {
                $this->command->warn("Mot de passe des comptes de démonstration : {$this->motDePasseDemo}");
            }
        }

        return $this->motDePasseDemo;
    }

    private function creerSuperAdmin(): void
    {
        $motDePasse = env('SUPERADMIN_PASSWORD') ?: Str::password(20);

        Utilisateur::create([
            'entreprise_id'         => null,
            'nom'                   => 'SUPERADMIN',
            'prenom'                => 'Selflow',
            'email'                 => env('SUPERADMIN_EMAIL', 'superadmin@gmail.com'),
            'password'              => Hash::make($motDePasse),
            'role'                  => 'superadmin',
            'statut'                => 'actif',
            'doit_changer_password' => true,
            // Le privilège est une donnée, non une adresse écrite en dur dans
            // le middleware : il se lit sur la fiche, s'audite et se retire.
            'habilitations'         => \App\Modules\Authentification\Regles\Habilitations::PLATEFORME,
        ]);

        if (!env('SUPERADMIN_PASSWORD')) {
            $this->command->warn("Mot de passe superadmin tiré au hasard : {$motDePasse}");
            $this->command->warn('Notez-le maintenant : il ne sera plus affiché.');
        }
    }

    // ──────────────────────────────────────────────────────────────
    // ENTREPRISE 1 — DC-KNOWING
    // ──────────────────────────────────────────────────────────────
    private function creerEntrepriseDCKnowing(): array
    {
        $e = Entreprise::create([
            'nom'                   => 'DC-KNOWING',
            'email'                 => 'dcknowing@gmail.com',
            'telephone'             => '+225 27 22 23 72',
            'adresse'               => '2 PLATEAUX VALLONS, ABIDJAN, CÔTE D\'IVOIRE',
            'rccm'                  => 'CI-ABJ-2019-B-18764',
            'compte_contribuable'   => '1864699 A',
            'ncc'                   => '1864699 A',
            'regime_imposition'     => 'TEE',
            'centre_impots'         => '2 PLATEAUX 3',
            'forme_juridique'       => 'SARL',
            'gerant_nom'            => 'AGNIMEL',
            'gerant_prenom'         => 'MELEDJE',
            'gerant_fonction'       => 'Gérant',
            'secteur_activite'      => ['Commercial', 'Industriel', 'Services'],
            'modules_actifs'        => self::MODULES,
            'quota_points_de_vente' => 10,
            'plan_abonnement'       => 'Pro',
            'statut'                => 'actif',
        ]);

        $pdvs = [];
        foreach ([
            ['Siège DC-KNOWING',   'Abidjan', '2 PLATEAUX', 'MELEDJE AGNIMEL',  '+225 27 22 23 72'],
            ['Agence Plateau',     'Abidjan', 'PLATEAU',    'KOUASSI PAUL',     '+225 27 20 00 11'],
            ['Agence Cocody',      'Abidjan', 'COCODY',     'BAMBA AMINATA',    '+225 07 11 22 33'],
            ['Agence Yopougon',    'Abidjan', 'YOPOUGON',   'TRAORÉ IBRAHIMA',  '+225 05 44 55 66'],
        ] as [$nom, $ville, $commune, $resp, $tel]) {
            $pdvs[] = PointDeVente::create([
                'entreprise_id' => $e->id,
                'nom'           => $nom,
                'ville'         => $ville,
                'commune'       => $commune,
                'responsable'   => $resp,
                'telephone'     => $tel,
                'statut'        => 'actif',
            ]);
        }

        $admin = Utilisateur::create([
            'entreprise_id'         => $e->id,
            'point_de_vente_id'     => $pdvs[0]->id,
            'nom'                   => 'AGNIMEL',
            'prenom'                => 'MELEDJE',
            'email'                 => 'dcknowing@gmail.com',
            'password'              => Hash::make($this->motDePasseDemo()),
            'doit_changer_password' => true,
            'role'                  => 'admin',
            'fonction'              => 'Gérant',
            'statut'                => 'actif',
            'doit_changer_password' => false,
        ]);

        $users = [$admin];
        foreach ([
            ['KOUASSI','PAUL',       'paul.kouassi@dcknowing.ci',    'responsable_pdv', $pdvs[1]->id, 'Responsable Plateau'],
            ['BAMBA',  'AMINATA',    'aminata.bamba@dcknowing.ci',   'responsable_pdv', $pdvs[2]->id, 'Responsable Cocody'],
            ['TRAORÉ', 'IBRAHIMA',   'ibrahima.traore@dcknowing.ci', 'responsable_pdv', $pdvs[3]->id, 'Responsable Yopougon'],
            ['KONÉ',   'KARIM',      'karim.kone@dcknowing.ci',      'caissier',        $pdvs[0]->id, 'Caissier Principal'],
            ['DIALLO', 'FATOUMATA',  'fatoumata.diallo@dcknowing.ci','caissier',        $pdvs[1]->id, 'Caissière'],
            ['N\'GUESSAN','ANGE',   'ange.nguessan@dcknowing.ci',   'caissier',        $pdvs[2]->id, 'Caissier'],
            ['SORO',   'ABDOULAYE', 'abdoulaye.soro@dcknowing.ci',  'caissier',        $pdvs[3]->id, 'Caissier'],
        ] as [$nom, $prenom, $email, $role, $pdvId, $fonction]) {
            $users[] = Utilisateur::create([
                'entreprise_id'         => $e->id,
                'point_de_vente_id'     => $pdvId,
                'nom'                   => $nom,
                'prenom'                => $prenom,
                'email'                 => $email,
                'password'              => Hash::make($this->motDePasseDemo()),
                'doit_changer_password' => true,
                'role'                  => $role,
                'fonction'              => $fonction,
                'statut'                => 'actif',
                'doit_changer_password' => false,
            ]);
        }

        return [$e, $pdvs, $users];
    }

    // ──────────────────────────────────────────────────────────────
    // ENTREPRISE 2 — B-HOME SARL
    // ──────────────────────────────────────────────────────────────
    private function creerEntrepriseBHome(): array
    {
        $e = Entreprise::create([
            'nom'                   => 'B-HOME SARL',
            'email'                 => 'bhome@gmail.com',
            'telephone'             => '0709767690',
            'adresse'               => 'COCODY CITÉ DES CADRES, ABIDJAN',
            'rccm'                  => 'CI-ABJ-2021-B-09876',
            'compte_contribuable'   => '2178453 B',
            'ncc'                   => '2178453 B',
            'regime_imposition'     => 'RNI',
            'centre_impots'         => 'COCODY 1',
            'forme_juridique'       => 'SARL',
            'gerant_nom'            => 'KOFFI',
            'gerant_prenom'         => 'KONE',
            'gerant_fonction'       => 'Directeur Général',
            'secteur_activite'      => ['Commercial', 'Industriel', 'Services'],
            'modules_actifs'        => self::MODULES,
            'quota_points_de_vente' => 10,
            'plan_abonnement'       => 'Pro',
            'statut'                => 'actif',
        ]);

        $pdvs = [];
        foreach ([
            ['Siège B-HOME Cocody',   'Abidjan', 'COCODY',      'KOFFI KONE',       '0709767690'],
            ['Boutique Marcory',      'Abidjan', 'MARCORY',     'ASSANE DIOP',       '0707112233'],
            ['Boutique Abobo',        'Abidjan', 'ABOBO',       'CISSÉ MARIAM',      '0505778899'],
            ['Show-room Bingerville', 'Abidjan', 'BINGERVILLE', 'ETTIEN AMOIN',      '0101223344'],
        ] as [$nom, $ville, $commune, $resp, $tel]) {
            $pdvs[] = PointDeVente::create([
                'entreprise_id' => $e->id,
                'nom'           => $nom,
                'ville'         => $ville,
                'commune'       => $commune,
                'responsable'   => $resp,
                'telephone'     => $tel,
                'statut'        => 'actif',
            ]);
        }

        $admin = Utilisateur::create([
            'entreprise_id'         => $e->id,
            'point_de_vente_id'     => $pdvs[0]->id,
            'nom'                   => 'KOFFI',
            'prenom'                => 'KONE',
            'email'                 => 'bhome@gmail.com',
            'password'              => Hash::make($this->motDePasseDemo()),
            'doit_changer_password' => true,
            'role'                  => 'admin',
            'fonction'              => 'Directeur Général',
            'statut'                => 'actif',
            'doit_changer_password' => false,
        ]);

        $users = [$admin];
        foreach ([
            ['ASSANE',    'DIOP',     'assane.diop@bhome.ci',    'responsable_pdv', $pdvs[1]->id, 'Responsable Marcory'],
            ['CISSÉ',     'MARIAM',   'mariam.cisse@bhome.ci',   'responsable_pdv', $pdvs[2]->id, 'Responsable Abobo'],
            ['ETTIEN',    'AMOIN',    'amoin.ettien@bhome.ci',   'responsable_pdv', $pdvs[3]->id, 'Responsable Bingerville'],
            ['COULIBALY', 'LASSINA',  'lassina.coul@bhome.ci',   'caissier',        $pdvs[0]->id, 'Caissier'],
            ['YAPI',      'SANDRA',   'sandra.yapi@bhome.ci',    'caissier',        $pdvs[1]->id, 'Caissière'],
            ['BROU',      'THÉODORE', 'theodore.brou@bhome.ci',  'caissier',        $pdvs[2]->id, 'Caissier'],
            ['KONAN',     'AKISSI',   'akissi.konan@bhome.ci',   'caissier',        $pdvs[3]->id, 'Caissière'],
        ] as [$nom, $prenom, $email, $role, $pdvId, $fonction]) {
            $users[] = Utilisateur::create([
                'entreprise_id'         => $e->id,
                'point_de_vente_id'     => $pdvId,
                'nom'                   => $nom,
                'prenom'                => $prenom,
                'email'                 => $email,
                'password'              => Hash::make($this->motDePasseDemo()),
                'doit_changer_password' => true,
                'role'                  => $role,
                'fonction'              => $fonction,
                'statut'                => 'actif',
                'doit_changer_password' => false,
            ]);
        }

        return [$e, $pdvs, $users];
    }

    // ──────────────────────────────────────────────────────────────
    // PLAN COMPTABLE (SYSCOHADA)
    // ──────────────────────────────────────────────────────────────
    private function creerPlanComptable(Entreprise $e): void
    {
        if (!Schema::hasTable('plan_comptable')) return;
        // Vraies colonnes : id, entreprise_id, numero, numero_original, libelle, source
        $comptes = [
            ['10','Capital social'],['101','Capital souscrit'],
            ['12','Report à nouveau'],['13','Résultat net de l\'exercice'],
            ['21','Immobilisations incorporelles'],['23','Bâtiments et aménagements'],
            ['24','Matériels et mobiliers'],['28','Amortissements'],
            ['31','Marchandises'],['32','Matières premières'],
            ['35','Produits finis'],['37','Stocks en cours de production'],
            ['40','Fournisseurs et comptes rattachés'],['401','Fournisseurs locaux'],
            ['401001','Fournisseur — CDCI Distribution'],['401002','Fournisseur — SIPRA CI'],
            ['401003','Fournisseur — SIR'],
            ['41','Clients et comptes rattachés'],['411','Clients locaux'],
            ['411001','Client — Commerce Général CI'],['411002','Client — BTP Ivoire'],
            ['411003','Client — Groupe Hôtelier Ivoire'],
            ['42','Personnel'],['44','État et collectivités'],
            ['441','TVA collectée 18%'],['4441','TVA déductible'],
            ['447','Autres impôts et taxes'],
            ['51','Banques'],['511','BNI — Compte courant'],
            ['512','SGBCI — Compte courant'],['513','Ecobank — Compte courant'],
            ['52','Mobile Money'],['521','Orange Money'],
            ['522','MTN MoMo'],['523','Wave'],
            ['57','Caisse'],['571','Caisse principale'],['572','Caisse Agence'],
            ['60','Achats et variations de stocks'],['601','Achats de marchandises'],
            ['601001','Achats fournitures bureau'],['601002','Achats matières premières'],
            ['61','Transports et déplacements'],['62','Services extérieurs A'],
            ['63','Services extérieurs B'],['64','Impôts et taxes'],
            ['65','Autres charges'],['66','Charges financières'],['68','Dotations'],
            ['70','Ventes de marchandises'],['701','Ventes locales'],
            ['701001','Ventes — Commerce général'],['701002','Ventes — Services'],
            ['701003','Ventes — Produits fabriqués'],
            ['71','Productions vendues'],['75','Autres produits'],
            ['77','Produits financiers'],
        ];
        $cols = Schema::getColumnListing('plan_comptable');
        foreach ($comptes as [$num, $libelle]) {
            $data = [
                'entreprise_id' => $e->id,
                'numero'        => $num,
                'libelle'       => $libelle,
                'source'        => 'syscohada',
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
            if (in_array('numero_original', $cols)) $data['numero_original'] = $num;
            DB::table('plan_comptable')->insertOrIgnore($data);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // CATÉGORIES & PRODUITS (vraies colonnes)
    // ──────────────────────────────────────────────────────────────
    private function creerCategsEtProduits(Entreprise $e, array $pdvs): array
    {
        $produitsCols = Schema::getColumnListing('produits');
        $stocksCols   = Schema::getColumnListing('stocks');

        $catalogue = [
            'Informatique & Bureautique' => [
                ['Ordinateur portable HP EliteBook 840 G9','marchandise',350000,420000,18,'pièce',150],
                ['Ordinateur portable Dell Latitude 5420','marchandise',320000,390000,18,'pièce',80],
                ['Imprimante HP LaserJet Pro M404','marchandise',180000,220000,18,'pièce',40],
                ['Imprimante Epson EcoTank L3250','marchandise',90000,115000,18,'pièce',25],
                ['Clavier sans fil Logitech K380','marchandise',18000,28000,18,'pièce',60],
                ['Souris ergonomique Logitech MX Master','marchandise',28000,42000,18,'pièce',45],
                ['Écran 24" Samsung F24T450','marchandise',95000,130000,18,'pièce',30],
                ['Switch réseau TP-Link 8 ports','marchandise',22000,35000,18,'pièce',20],
                ['Câble RJ45 Cat6 (bobine 100m)','marchandise',18000,28000,18,'bobine',15],
                ['Clé USB SanDisk 64Go','consommable_stockable',4500,8000,18,'pièce',200],
            ],
            'Fournitures de bureau' => [
                ['Ramette papier A4 80g','consommable_stockable',2200,3500,0,'ramette',500],
                ['Stylo bille Bic cristal (boite 50)','consommable_stockable',2500,4000,0,'boite',100],
                ['Cahier grand format 200p','consommable_stockable',800,1500,0,'pièce',300],
                ['Agrafeuse de bureau Rapid','consommable_stockable',3500,6000,0,'pièce',50],
                ['Chemise plastique A4 (lot 10)','consommable_stockable',900,1500,0,'lot',200],
                ['Ruban adhésif Scotch 33m (lot 6)','consommable_stockable',1800,3000,0,'lot',150],
                ['Correcteur liquide BIC','consommable_stockable',350,700,0,'pièce',200],
                ['Marqueur permanent Edding','consommable_stockable',500,900,0,'pièce',300],
            ],
            'Mobilier & Aménagement' => [
                ['Bureau directionnel en bois massif','marchandise',280000,380000,18,'pièce',10],
                ['Chaise ergonomique de bureau','marchandise',85000,120000,18,'pièce',30],
                ['Armoire de classement 4 tiroirs','marchandise',145000,195000,18,'pièce',15],
                ['Table de réunion 12 places','marchandise',320000,450000,18,'pièce',5],
                ['Tableau blanc magnétique 120x90','marchandise',35000,55000,18,'pièce',20],
                ['Canapé d\'accueil 3 places','marchandise',175000,240000,18,'pièce',8],
                ['Étagère métallique industrielle','marchandise',65000,95000,18,'pièce',25],
            ],
            'Produits alimentaires' => [
                ['Riz parfumé Thai (sac 25kg)','marchandise',12500,16000,0,'sac',200],
                ['Huile de palme raffinée (bidon 20L)','marchandise',18000,24000,0,'bidon',80],
                ['Farine de blé (sac 50kg)','marchandise',18000,23000,0,'sac',120],
                ['Sucre cristallisé (sac 25kg)','marchandise',14000,18500,0,'sac',150],
                ['Lait en poudre Nestlé (boite 900g)','marchandise',7500,11000,9,'boite',300],
                ['Café soluble Nescafé (pot 200g)','marchandise',3800,6000,9,'pot',200],
                ['Eau minérale (carton 12x1.5L)','marchandise',3600,6000,0,'carton',500],
                ['Jus de mangue local (carton 24x25cl)','marchandise',4800,8000,9,'carton',400],
                ['Tomate concentrée (carton 24 boites)','marchandise',9600,15000,0,'carton',300],
            ],
            'Matériaux de construction' => [
                ['Ciment CPA 325 (sac 50kg)','marchandise',5500,7500,0,'sac',1000],
                ['Barre à béton 12mm (barres 12m)','marchandise',8500,12000,0,'barre',500],
                ['Carrelage grès cérame 60x60 (m²)','marchandise',9500,15000,18,'m²',300],
                ['Peinture acrylique blanche (seau 20L)','marchandise',28000,40000,18,'seau',80],
                ['Tôle ondulée galvanisée 0.5mm','marchandise',12000,18000,18,'pièce',200],
                ['Câble électrique 2.5mm² (100m)','marchandise',32000,48000,18,'bobine',50],
                ['Robinet mitigeur lavabo','marchandise',15000,24000,18,'pièce',40],
            ],
            'Téléphonie & Accessoires' => [
                ['Smartphone Samsung Galaxy A54','marchandise',195000,255000,18,'pièce',50],
                ['Smartphone iPhone 14 128Go','marchandise',480000,580000,18,'pièce',20],
                ['Chargeur rapide USB-C 65W','consommable_stockable',8500,15000,18,'pièce',100],
                ['Écouteurs sans fil AirPods Pro','marchandise',95000,135000,18,'pièce',30],
                ['Coque protection Samsung A54','consommable_stockable',2000,4500,18,'pièce',200],
            ],
            'Services & Prestations' => [
                ['Installation réseau LAN/WiFi','service',50000,95000,18,'forfait',0],
                ['Maintenance informatique mensuelle','service',35000,65000,18,'mois',0],
                ['Formation bureautique (journée)','service',25000,50000,18,'journée',0],
                ['Développement site web vitrine','service',150000,280000,18,'forfait',0],
                ['Audit informatique & sécurité','service',80000,150000,18,'forfait',0],
                ['Gardiennage mensuel','service',45000,70000,0,'mois',0],
                ['Nettoyage industriel','service',30000,55000,0,'forfait',0],
            ],
            'Hygiène & Nettoyage' => [
                ['Savon liquide mains (bidon 5L)','consommable_stockable',3500,6000,0,'bidon',200],
                ['Détergent Pril (1L)','consommable_stockable',1200,2000,0,'pièce',300],
                ['Eau de Javel (bidon 5L)','consommable_stockable',1500,2800,0,'bidon',150],
                ['Papier hygiénique (lot 6 rouleaux)','consommable_stockable',1800,3000,0,'lot',400],
                ['Désinfectant spray multi-surfaces','consommable_stockable',2200,3800,0,'pièce',200],
            ],
            'Électroménager' => [
                ['Climatiseur split 1.5CV TCL','marchandise',175000,240000,18,'pièce',20],
                ['Réfrigérateur Samsung 300L','marchandise',295000,380000,18,'pièce',15],
                ['Micro-ondes Hisense 25L','marchandise',65000,95000,18,'pièce',25],
                ['Machine à laver LG 7kg','marchandise',250000,330000,18,'pièce',10],
                ['Télévision LG 55" 4K Smart','marchandise',380000,490000,18,'pièce',12],
            ],
            'Énergie & Outillage' => [
                ['Groupe électrogène 5KVA silencieux','marchandise',850000,1100000,18,'pièce',5],
                ['Onduleur 1KVA Eaton','marchandise',145000,195000,18,'pièce',15],
                ['Batterie solaire 200Ah','marchandise',95000,135000,18,'pièce',20],
                ['Perceuse-visseuse Makita 18V','marchandise',85000,125000,18,'pièce',30],
                ['Meuleuse Bosch 125mm','marchandise',45000,72000,18,'pièce',25],
                ['Extension électrique 10m 5 prises','consommable_stockable',8500,15000,18,'pièce',100],
            ],
        ];

        $tous = [];
        $catCols = Schema::getColumnListing('categories');
        foreach ($catalogue as $catNom => $items) {
            $catData = [
                'entreprise_id' => $e->id,
                'nom'           => $catNom,
            ];
            if (in_array('description', $catCols)) $catData['description'] = 'Catégorie ' . $catNom;
            if (in_array('couleur', $catCols)) $catData['couleur'] = $this->couleurRandom();
            if (in_array('prefixe', $catCols)) $catData['prefixe'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $catNom), 0, 3));
            $cat = Categorie::create($catData);

            foreach ($items as $item) {
                [$nom, $type, $prixAchat, $prixVente, $tva, $unite, $qte] = $item;
                $ref = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $nom), 0, 4))
                     . '-' . strtoupper(Str::random(3)) . '-E' . $e->id;

                $prodData = [
                    'entreprise_id' => $e->id,
                    'nom'           => $nom,
                    'type'          => $type,
                    'reference'     => $ref,
                    'unite'         => $unite,
                    'prix_achat'    => $prixAchat,
                    'prix_vente'    => $prixVente,
                    'taux_tva'      => $tva,
                    'statut'        => 'actif',
                ];
                if (in_array('categorie_id', $produitsCols)) $prodData['categorie_id'] = $cat->id;
                if (in_array('compte_vente', $produitsCols)) $prodData['compte_vente'] = '701001';
                if (in_array('compte_achat', $produitsCols)) $prodData['compte_achat'] = '601001';

                $p = Produit::create($prodData);

                // Stocks par PDV
                if ($type !== 'service' && $qte > 0) {
                    foreach ($pdvs as $pdv) {
                        $qtePdv = intval($qte / count($pdvs));
                        $stockData = [
                            'produit_id'         => $p->id,
                            'point_de_vente_id'  => $pdv->id,
                            'created_at'         => now(),
                            'updated_at'         => now(),
                        ];
                        if (in_array('entreprise_id', $stocksCols)) $stockData['entreprise_id'] = $e->id;
                        if (in_array('quantite_disponible', $stocksCols)) $stockData['quantite_disponible'] = $qtePdv;
                        if (in_array('quantite', $stocksCols)) $stockData['quantite'] = $qtePdv;
                        if (in_array('stock_minimum', $stocksCols)) $stockData['stock_minimum'] = max(2, intval($qtePdv * 0.1));
                        if (in_array('stock_maximum', $stocksCols)) $stockData['stock_maximum'] = $qte;
                        if (in_array('quantite_min', $stocksCols)) $stockData['quantite_min'] = max(2, intval($qtePdv * 0.1));
                        DB::table('stocks')->insertOrIgnore($stockData);
                    }
                }

                $tous[] = $p;
            }
        }

        return $tous;
    }

    // ──────────────────────────────────────────────────────────────
    // TIERS
    // ──────────────────────────────────────────────────────────────
    private function creerTiers(Entreprise $e): array
    {
        $clientCols = Schema::getColumnListing('clients');
        $fourn4Cols = Schema::getColumnListing('fournisseurs');

        $clients = [];
        $clientsData = [
            ['SARL Commerce Général CI','B2B','0708001001','commerce@cgci.ci','Plateau, Abidjan','2301456T','RNI','CI-ABJ-2020-B-00110'],
            ['BTP Ivoire SA','B2B','0702002002','btp@btp-ivoire.ci','Zone 4, Marcory','1956234N','RSI','CI-ABJ-2018-B-05432'],
            ['Groupe Hôtelier Ivoire','B2B','0700003003','info@hotelieri.ci','Cocody, Abidjan','2015789H','RNI','CI-ABJ-2017-B-07800'],
            ['SOCIMAT CI','B2B','0709004004','achats@socimat.ci','Vridi, Port-Bouët','1894567S','RSI','CI-ABJ-2019-B-02341'],
            ['Pharmacie Centrale Abidjan','B2B','0705005005','pharma@pca.ci','Plateau, Abidjan','2205634P','RNI','CI-ABJ-2021-B-00876'],
            ['Restaurant Le Terroir','B2B','0701006006','resto@leterroir.ci','Cocody, Abidjan','','TEE',''],
            ['Koné Alphonse','B2C','0707007007','kone.alphonse@gmail.com','Yopougon','','',''],
            ['Coulibaly Mariam','B2C','0706008008','mariam.coul@gmail.com','Abobo','','',''],
            ['Diabaté Paul','B2C','0703009009','diabate.paul@yahoo.fr','Treichville','','',''],
            ['Traoré Ibrahim','B2C','0504010010','traore.ib@gmail.com','Adjamé','','',''],
            ['SOFAIM Abidjan','B2B','0720011011','contact@sofaim.ci','Zone Industrielle','1723456F','RSI','CI-ABJ-2016-B-11234'],
            ['N\'Guessan Ange Marie','B2C','0101012012','ange@gmail.com','Bingerville','','',''],
            ['Administratif MENET','B2G','2721013013','menet@gouv.ci','Plateau','','RNI',''],
            ['Mairie de Cocody','B2G','2720014014','mairie@cocody.ci','Cocody','','RNI',''],
            ['CIDT Ferké','B2B','0785015015','cidt@cidt.ci','Korhogo','1834567C','RSI','CI-KOR-2015-B-00123'],
            ['PALMAFRIQUE Bonoua','B2B','0786016016','palm@palmafrique.ci','Bonoua','1945678P','RSI','CI-GH-2017-B-00456'],
            ['Soro Fatou','B2C','0797017017','soro.fatou@gmail.com','Daloa','','',''],
            ['Boni Aya','B2C','0598018018','boni.aya@gmail.com','San Pedro','','',''],
            ['AFRIQUE ENERGIE SA','B2B','2721019019','info@afriqueenergie.ci','Zone Industrie','2056789A','RNI','CI-ABJ-2020-B-00321'],
            ['SYSCOM Technologies','B2B','0720020020','syscom@syscom.ci','Cocody Danga','2178456S','RNI','CI-ABJ-2021-B-00432'],
            ['Assane Diop','B2C','0701021021','assane.diop@gmail.com','Marcory','','',''],
            ['Doukouré Awa','B2C','0702022022','dawa@outlook.fr','Yopougon','','',''],
            ['Kouamé Jean-Baptiste','B2C','0703023023','jb.kouame@gmail.com','Adjamé','','',''],
            ['FINCA Côte d\'Ivoire','B2B','2722024024','finca@finca.ci','Plateau','2289012F','RNI','CI-ABJ-2022-B-00543'],
            ['Établissements MORY','B2B','0745025025','mory@etabmory.ci','Treichville','1967890M','RSI','CI-ABJ-2019-B-03456'],
        ];

        foreach ($clientsData as $i => $row) {
            [$nom,$type,$tel,$email,$adresse,$ncc,$regime,$rccm] = $row;
            $data = [
                'entreprise_id' => $e->id,
                'nom'           => $nom,
                'telephone'     => $tel,
                'email'         => $email,
                'adresse'       => $adresse,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
            if (in_array('type_facturation', $clientCols)) $data['type_facturation'] = $type;
            if (in_array('ncc', $clientCols)) $data['ncc'] = $ncc ?: null;
            if (in_array('regime_imposition', $clientCols)) $data['regime_imposition'] = $regime ?: null;
            if (in_array('rccm', $clientCols)) $data['rccm'] = $rccm ?: null;
            if (in_array('numero_tiers', $clientCols)) $data['numero_tiers'] = 'C' . str_pad($i + 1, 3, '0', STR_PAD_LEFT) . '-E' . $e->id;
            if (in_array('source', $clientCols)) $data['source'] = 'selflow';
            $clients[] = Client::create($data);
        }

        $fournisseurs = [];
        $fourniData = [
            ['CDCI Distribution','0720001001','cdci@cdci.ci','Distribution','Zone 4, Marcory','2169728N','RSI','CI-ABJ-2018-B-12001'],
            ['SIPRA-CI SA','0720002002','sipra@sipra.ci','Industrie alimentaire','Yopougon','1834512S','RNI','CI-ABJ-2016-B-02300'],
            ['SIR (Société Ivoire Raffinage)','2720003003','sir@sir.ci','Pétrochimie','Abidjan','1756345R','RNI','CI-ABJ-2005-B-00001'],
            ['IMAX Technologies','0720004004','imax@imax.ci','Informatique','Cocody Danga','2056789I','RSI','CI-ABJ-2020-B-03412'],
            ['CFAO Équipements','2721005005','cfao@cfao.ci','Équipements','Plateau','1623478C','RNI','CI-ABJ-2010-B-00123'],
            ['SONEF SA','0786006006','sonef@sonef.ci','Fournitures Bureau','Vridi','1989234S','RSI','CI-ABJ-2019-B-05678'],
            ['Nestlé Côte d\'Ivoire','2722007007','nestle@nestle.ci','Alimentaire','Yopougon','1500123N','RNI','CI-ABJ-2000-B-00001'],
            ['SOCOGIM Abidjan','0720008008','socogim@socogim.ci','Construction','Zone Industrie','1856789S','RSI','CI-ABJ-2017-B-07890'],
            ['ORANGE Côte d\'Ivoire','2721009009','orange@orange.ci','Télécoms','Plateau','1245678O','RNI','CI-ABJ-2002-B-00010'],
            ['TOTAL Énergies CI','2722010010','total@total.ci','Énergie','Vridi','1134567T','RNI','CI-ABJ-2001-B-00002'],
            ['Quincaillerie Moderne','0701011011','qm@quincail.ci','Quincaillerie','Adjamé','','TEE',''],
            ['Imprimerie EDISCO','0705012012','edisco@edisco.ci','Imprimerie','Cocody','2045678E','RSI','CI-ABJ-2020-B-02345'],
            ['Transport CAMICO','0786013013','camico@camico.ci','Transport','Yopougon','1923456C','RSI','CI-ABJ-2018-B-04321'],
            ['Agence COMSTAR','0702014014','comstar@comstar.ci','Communication','Plateau','2134567C','RSI','CI-ABJ-2021-B-01234'],
            ['SGBCI Banque','2720015015','sgbci@sgbci.ci','Banque','Plateau','1001234S','RNI','CI-ABJ-1995-B-00001'],
        ];

        foreach ($fourniData as $i => $row) {
            [$nom,$tel,$email,$secteur,$adresse,$ncc,$regime,$rccm] = $row;
            $data = [
                'entreprise_id' => $e->id,
                'nom'           => $nom,
                'telephone'     => $tel,
                'email'         => $email,
                'adresse'       => $adresse,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
            if (in_array('type_facturation', $fourn4Cols)) $data['type_facturation'] = 'B2B';
            if (in_array('secteur', $fourn4Cols)) $data['secteur'] = $secteur;
            if (in_array('ncc', $fourn4Cols)) $data['ncc'] = $ncc ?: null;
            if (in_array('regime_imposition', $fourn4Cols)) $data['regime_imposition'] = $regime ?: null;
            if (in_array('rccm', $fourn4Cols)) $data['rccm'] = $rccm ?: null;
            if (in_array('numero_tiers', $fourn4Cols)) $data['numero_tiers'] = 'F' . str_pad($i + 1, 3, '0', STR_PAD_LEFT) . '-E' . $e->id;
            if (in_array('source', $fourn4Cols)) $data['source'] = 'selflow';
            $fournisseurs[] = Fournisseur::create($data);
        }

        return [$clients, $fournisseurs];
    }

    // ──────────────────────────────────────────────────────────────
    // VENTES (vraies colonnes)
    // ──────────────────────────────────────────────────────────────
    private function creerVentes(Entreprise $e, array $pdvs, array $clients, array $produits, array $users): void
    {
        $venteCols   = Schema::getColumnListing('ventes');
        $detailCols  = Schema::getColumnListing('vente_details');
        $tresoCols   = Schema::getColumnListing('tresorerie_journal');

        $modes   = ['especes','especes','mobile_money','virement','cheque','especes'];
        $etapes  = ['devis','bon_commande','bon_livraison','facture','facture','facture'];
        $statuts = ['valide','valide','valide','partiel','avoir','valide'];

        for ($i = 0; $i < 30; $i++) {
            $date   = Carbon::now()->subDays(rand(1, 300))->subHours(rand(0, 8));
            $client = $clients[array_rand($clients)];
            $pdv    = $pdvs[array_rand($pdvs)];
            $user   = $users[array_rand($users)];
            $mode   = $modes[array_rand($modes)];
            $etape  = $etapes[$i % count($etapes)];
            $ref    = 'VTE-' . $date->format('Y') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);

            $selP  = array_values(array_slice($produits, rand(0, max(0, count($produits) - 5)), rand(2, 5)));
            $htTotal = 0; $tvaTotal = 0;
            foreach ($selP as $p) {
                $qte = rand(1, 10);
                $pu  = $p->prix_vente;
                $tva = $pu * $qte * $p->taux_tva / 100;
                $htTotal  += $pu * $qte;
                $tvaTotal += $tva;
            }
            $ttcTotal = $htTotal + $tvaTotal;

            $venteData = [
                // Une insertion brute ne passe pas par le modèle : le crochet
                // qui pose l'identifiant opaque ne s'exécute pas, et la pièce
                // se retrouve sans adresse publique. Voir `IdentifiantOpaque`.
                'uuid'              => (string) Str::uuid(),
                'utilisateur_id'    => $user->id,
                'point_de_vente_id' => $pdv->id,
                'date_vente'        => $date->toDateTimeString(),
                'mode_paiement'     => $mode,
                'montant_ht'        => $htTotal,
                'montant_tva'       => $tvaTotal,
                'montant_ttc'       => $ttcTotal,
                'statut'            => 'valide',
                'etape'             => $etape,
                'normalise'         => 0,
                'created_at'        => $date->toDateTimeString(),
                'updated_at'        => $date->toDateTimeString(),
            ];
            if (in_array('client_id', $venteCols)) $venteData['client_id'] = $client->id;
            if (in_array('numero_facture', $venteCols)) $venteData['numero_facture'] = $ref;
            if (in_array('reference', $venteCols)) $venteData['reference'] = $ref;

            $venteId = DB::table('ventes')->insertGetId($venteData);

            foreach ($selP as $p) {
                $qte = rand(1, 10);
                $pu  = $p->prix_vente;
                $tva = $pu * $qte * $p->taux_tva / 100;
                $detailData = [
                    'vente_id'      => $venteId,
                    'produit_id'    => $p->id,
                    'quantite'      => $qte,
                    'prix_unitaire' => $pu,
                    'montant_tva'   => $tva,
                    'montant_ttc'   => $pu * $qte + $tva,
                    'created_at'    => $date->toDateTimeString(),
                    'updated_at'    => $date->toDateTimeString(),
                ];
                if (in_array('unite', $detailCols)) $detailData['unite'] = $p->unite ?? 'pièce';
                DB::table('vente_details')->insert($detailData);
            }

            // Trésorerie
            if ($ttcTotal > 0) {
                $tresoData = [
                    'point_de_vente_id' => $pdv->id,
                    'utilisateur_id'    => $user->id,
                    'date_operation'    => $date->toDateTimeString(),
                    'mode_paiement'     => $mode,
                    'montant_entree'    => $ttcTotal,
                    'montant_sortie'    => 0,
                    'reference_document'=> $ref,
                    'created_at'        => $date->toDateTimeString(),
                    'updated_at'        => $date->toDateTimeString(),
                ];
                if (in_array('type_operation', $tresoCols)) $tresoData['type_operation'] = 'encaissement';
                if (in_array('libelle', $tresoCols)) $tresoData['libelle'] = 'Vente ' . $ref;
                DB::table('tresorerie_journal')->insert($tresoData);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    // ACHATS (vraies colonnes)
    // ──────────────────────────────────────────────────────────────
    private function creerAchats(Entreprise $e, array $pdvs, array $fournisseurs, array $produits, array $users): void
    {
        $achatCols  = Schema::getColumnListing('achats');
        $detailCols = Schema::getColumnListing('achat_details');
        $tresoCols  = Schema::getColumnListing('tresorerie_journal');

        $modes  = ['especes','virement','cheque','mobile_money','virement'];
        $etapes = ['bon_commande','bon_reception','facture','facture','facture'];

        for ($i = 0; $i < 30; $i++) {
            $date  = Carbon::now()->subDays(rand(1, 300))->subHours(rand(0, 8));
            $fourn = $fournisseurs[array_rand($fournisseurs)];
            $user  = $users[array_rand($users)];
            $pdv   = $pdvs[array_rand($pdvs)];
            $mode  = $modes[array_rand($modes)];
            $etape = $etapes[$i % count($etapes)];
            $ref   = 'ACH-' . $date->format('Y') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT);

            $selP    = array_values(array_slice($produits, rand(0, max(0, count($produits) - 5)), rand(2, 5)));
            $htTotal = 0; $tvaTotal = 0;
            foreach ($selP as $p) {
                $qte = rand(5, 50);
                $pu  = $p->prix_achat;
                $htTotal  += $pu * $qte;
                $tvaTotal += $pu * $qte * $p->taux_tva / 100;
            }
            $ttcTotal = $htTotal + $tvaTotal;

            $achatData = [
                'uuid'              => (string) Str::uuid(),
                'point_de_vente_id' => $pdv->id,
                'utilisateur_id'    => $user->id,
                'fournisseur_id'    => $fourn->id,
                'date_achat'        => $date->toDateTimeString(),
                'mode_paiement'     => $mode,
                'montant_ht'        => $htTotal,
                'montant_tva'       => $tvaTotal,
                'montant_ttc'       => $ttcTotal,
                'statut'            => 'valide',
                'etape'             => $etape,
                'normalise'         => 0,
                'created_at'        => $date->toDateTimeString(),
                'updated_at'        => $date->toDateTimeString(),
            ];
            if (in_array('numero_facture', $achatCols)) $achatData['numero_facture'] = $ref;
            if (in_array('reference', $achatCols)) $achatData['reference'] = $ref;

            $achatId = DB::table('achats')->insertGetId($achatData);

            foreach ($selP as $p) {
                $qte = rand(5, 50);
                $pu  = $p->prix_achat;
                $tva = $pu * $qte * $p->taux_tva / 100;
                $detailData = [
                    'achat_id'      => $achatId,
                    'produit_id'    => $p->id,
                    'quantite'      => $qte,
                    'prix_unitaire' => $pu,
                    'montant_tva'   => $tva,
                    'montant_ttc'   => $pu * $qte + $tva,
                    'created_at'    => $date->toDateTimeString(),
                    'updated_at'    => $date->toDateTimeString(),
                ];
                if (in_array('unite', $detailCols)) $detailData['unite'] = $p->unite ?? 'pièce';
                DB::table('achat_details')->insert($detailData);
            }

            // Trésorerie décaissement
            if ($ttcTotal > 0) {
                $tresoData = [
                    'point_de_vente_id'  => $pdv->id,
                    'utilisateur_id'     => $user->id,
                    'date_operation'     => $date->toDateTimeString(),
                    'mode_paiement'      => $mode,
                    'montant_entree'     => 0,
                    'montant_sortie'     => $ttcTotal,
                    'reference_document' => $ref,
                    'created_at'         => $date->toDateTimeString(),
                    'updated_at'         => $date->toDateTimeString(),
                ];
                if (in_array('type_operation', $tresoCols)) $tresoData['type_operation'] = 'decaissement';
                if (in_array('libelle', $tresoCols)) $tresoData['libelle'] = 'Paiement achat ' . $ref;
                DB::table('tresorerie_journal')->insert($tresoData);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    // PRODUCTION
    // ──────────────────────────────────────────────────────────────
    private function creerProduction(Entreprise $e, array $pdvs, array $produits): void
    {
        if (!Schema::hasTable('fiches_techniques')) return;

        $ficheCols = Schema::getColumnListing('fiches_techniques');
        // Vraies colonnes fiches_techniques : entreprise_id, produit_fini_id, description
        // Vraies colonnes ordres_production  : entreprise_id, point_de_vente_id, produit_fini_id,
        //                                      code_ordre, quantite_cible, statut, date_production

        $produitsPhysiques = array_values(array_filter($produits, fn($p) => $p->type !== 'service'));
        if (empty($produitsPhysiques)) return;

        $descriptions = [
            'Assemblage PC Tower complet avec installation OS et logiciels',
            'Poste bureautique complet livré et configuré',
            'Kit réseau LAN/WiFi pour PME — câblage + bornes',
            'Pack sécurité vidéo 4 caméras HD installées',
            'Meuble bureau sur mesure — bois massif verni',
            'Étagère industrielle 5 niveaux 300 kg/niveau',
            'Panier alimentaire mensuel pour ménage de 5 personnes',
            'Colis Ramadan spécial famille 25 kg',
            'Coffret électrique 6 disjoncteurs + différentiel',
            'Kit éclairage LED complet 500m²',
            'Pack hygiène entreprise pour 50 personnes/mois',
            'Kit nettoyage industriel usage intensif',
            'Installation téléphonie IP 10 postes',
            'Terminal PDV complet — écran, imprimante, tiroir',
            'Installation climatisation split réversible 1.5CV',
            'Maintenance préventive parc informatique trimestrielle',
            'Kit démarrage entreprise — 5 postes configurés',
            'Bureau complet 1 poste livré et installé',
            'Installation groupe électrogène 5KVA silencieux',
            'Kit solaire autonome 3KW — panneaux + batteries',
            'Fournitures scolaires complètes — kit élève',
            'Pack rentrée bureautique — réseau + ordinateurs',
            'Kit soudure électrique professionnel',
            'Pack outillage atelier mécanique général',
            'Serveur NAS 4 baies configuré en RAID 5',
            'Surveillance CCTV 8 caméras + DVR 30 jours',
            'Réseau fibre optique — backbone entreprise',
            'Formation bureautique 5 stagiaires — 3 jours',
            'Atelier couture — 10 machines Brother + tables',
            'Fenêtres aluminium double vitrage sur mesure',
        ];

        for ($i = 0; $i < 30; $i++) {
            $produitFini  = $produitsPhysiques[$i % count($produitsPhysiques)];
            $description  = $descriptions[$i] ?? 'Fiche de fabrication n°' . ($i + 1);
            $dateCreation = Carbon::now()->subDays(rand(30, 200));

            // ── Fiche technique ────────────────────────────────────────────
            $ficheData = [
                'uuid'           => (string) Str::uuid(),
                'entreprise_id'  => $e->id,
                'produit_fini_id'=> $produitFini->id,
                'description'    => $description,
                'created_at'     => $dateCreation->toDateTimeString(),
                'updated_at'     => now()->toDateTimeString(),
            ];
            $ficheId = DB::table('fiches_techniques')->insertGetId($ficheData);

            // ── Ordre de production ────────────────────────────────────────
            if (Schema::hasTable('ordres_production')) {
                $dateOrdre = Carbon::now()->subDays(rand(1, 180));
                $pdvOrdre  = $pdvs[array_rand($pdvs)] ?? null;
                $ordreData = [
                    'uuid'            => (string) Str::uuid(),
                    'entreprise_id'   => $e->id,
                    'produit_fini_id' => $produitFini->id,
                    'code_ordre'      => 'OP-' . $dateOrdre->format('Y') . '-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                    'quantite_cible'  => rand(5, 50),
                    'statut'          => ['en_cours', 'termine', 'planifie'][rand(0, 2)],
                    'date_production' => $dateOrdre->toDateString(),
                    'created_at'      => $dateOrdre->toDateTimeString(),
                    'updated_at'      => now()->toDateTimeString(),
                ];
                if ($pdvOrdre && in_array('point_de_vente_id', Schema::getColumnListing('ordres_production'))) {
                    $ordreData['point_de_vente_id'] = $pdvOrdre->id;
                }
                DB::table('ordres_production')->insert($ordreData);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    // MOUVEMENTS STOCK (vraies colonnes)
    // ──────────────────────────────────────────────────────────────
    private function creerMouvements(Entreprise $e, array $pdvs, array $produits, array $users): void
    {
        if (!Schema::hasTable('mouvements_stock')) return;
        $cols = Schema::getColumnListing('mouvements_stock');

        $typesMouv = ['entree','sortie','transfert','ajustement'];
        $sousTypes = ['achat','vente','production','inventaire','transfert_interne'];

        for ($i = 0; $i < 30; $i++) {
            $produit = $produits[array_rand($produits)];
            $pdv     = $pdvs[array_rand($pdvs)];
            $user    = $users[array_rand($users)];
            $type    = $typesMouv[rand(0, count($typesMouv) - 1)];
            $date    = Carbon::now()->subDays(rand(1, 200));

            $data = [
                'produit_id'        => $produit->id,
                'point_de_vente_id' => $pdv->id,
                'quantite'          => rand(1, 50),
                'created_at'        => $date->toDateTimeString(),
                'updated_at'        => $date->toDateTimeString(),
            ];
            if (in_array('type_mouvement', $cols)) $data['type_mouvement'] = $type;
            if (in_array('sous_type', $cols)) $data['sous_type'] = $sousTypes[rand(0, count($sousTypes) - 1)];
            if (in_array('stock_avant', $cols)) $data['stock_avant'] = rand(10, 200);
            if (in_array('stock_apres', $cols)) $data['stock_apres'] = rand(10, 200);
            if (in_array('reference_document', $cols)) $data['reference_document'] = strtoupper($type) . '-' . $date->format('Ymd') . '-' . ($i + 1);
            if (in_array('utilisateur_id', $cols)) $data['utilisateur_id'] = $user->id;

            DB::table('mouvements_stock')->insert($data);
        }

        // Transferts inter-PDV
        if (!Schema::hasTable('transferts_stock') || count($pdvs) < 2) return;
        $trCols = Schema::getColumnListing('transferts_stock');
        for ($i = 0; $i < 10; $i++) {
            $produit = $produits[array_rand($produits)];
            $pdvSrc  = $pdvs[0];
            $pdvDst  = $pdvs[1 + ($i % (count($pdvs) - 1))];
            $date    = Carbon::now()->subDays(rand(1, 90));
            $trData  = [
                'uuid'                    => (string) Str::uuid(),
                'produit_id'              => $produit->id,
                'quantite'                => rand(5, 30),
                'statut'                  => ['en_attente','approuve','rejete'][rand(0, 2)],
                'created_at'              => $date->toDateTimeString(),
                'updated_at'              => now()->toDateTimeString(),
            ];
            if (in_array('point_de_vente_source_id', $trCols)) $trData['point_de_vente_source_id'] = $pdvSrc->id;
            if (in_array('point_de_vente_destination_id', $trCols)) $trData['point_de_vente_destination_id'] = $pdvDst->id;
            if (in_array('note', $trCols)) $trData['note'] = 'Transfert interne — réapprovisionnement';
            if (in_array('demandeur_id', $trCols)) $trData['demandeur_id'] = $users[0]->id;
            DB::table('transferts_stock')->insert($trData);
        }
    }

    private function couleurRandom(): string
    {
        $c = ['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#14B8A6','#F97316','#6366F1','#84CC16'];
        return $c[array_rand($c)];
    }
}
