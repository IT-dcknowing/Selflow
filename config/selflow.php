<?php

/**
 * Configuration des liaisons externes Selflow.
 * Ces valeurs sont surchargées par les variables d'environnement du fichier .env.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | URL de l'API COMPTAFLOW
    |--------------------------------------------------------------------------
    | URL de base de l'application COMPTAFLOW pour les appels API inter-apps.
    | En local : http://127.0.0.1:8002  (selon le port de php artisan serve)
    | En production : https://comptaflow.example.com
    */
    'comptaflow_api_url' => env('COMPTAFLOW_API_URL', 'http://127.0.0.1:8000'),

    /*
    |--------------------------------------------------------------------------
    | Adresse publique de COMPTAFLOW
    |--------------------------------------------------------------------------
    | Celle qu'on donne à l'utilisateur pour aller consulter sa comptabilité.
    | Distincte de l'API : celle-ci peut pointer sur un hôte interne, alors que
    | l'adresse ci-dessous doit s'ouvrir dans le navigateur du client. L'écran
    | de liaison n'en donnait aucune — l'entreprise apprenait qu'elle avait un
    | dossier comptable sans savoir où le consulter.
    */
    // `https`, et non `http` : cette adresse part dans un courriel et dans un
    // lien que l'utilisateur suivra pour y saisir son mot de passe. En clair,
    // il voyagerait lisible sur le réseau qu'il traverse — et un navigateur
    // qui suit d'abord `http` laisse la place à une interception avant même la
    // redirection.
    'comptaflow_app_url' => env('COMPTAFLOW_APP_URL', 'https://comptaflow.dc-knowing.com/'),

    /*
    |--------------------------------------------------------------------------
    | Secret partagé API
    |--------------------------------------------------------------------------
    | Clé secrète partagée entre Selflow et COMPTAFLOW pour authentifier
    | les appels API locaux. Doit être identique dans les deux .env.
    */
    // Secret partage avec Comptaflow. Aucune valeur de repli : un secret ecrit
    // dans le code est un secret public des que le depot l'est, et ces routes
    // creent des entreprises. Sans EXTERNAL_SYNC_SECRET, la synchronisation
    // refuse tout appel plutot que d'en accepter avec un secret connu de tous.
    'comptaflow_api_secret' => env('EXTERNAL_SYNC_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Timeout des requêtes HTTP sortantes (secondes)
    |--------------------------------------------------------------------------
    */
    'api_timeout' => env('EXTERNAL_API_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Plan comptable par défaut (SYSCOHADA révisé - Côte d'Ivoire)
    |--------------------------------------------------------------------------
    | Comptes génériques utilisés en fallback par ComptabiliteService lorsque
    | l'entreprise n'a pas paramétré de compte spécifique. Centralisés ici
    | pour éviter les valeurs codées en dur dispersées dans le code métier :
    | un seul endroit à modifier pour changer la convention de numérotation.
    |
    | Convention : racine SYSCOHADA sans subdivision par défaut (PPP000).
    | Une entreprise qui veut des sous-comptes plus fins (ex: 401110 par
    | fournisseur type) peut le faire via son propre plan comptable, ces
    | valeurs ne servent que de filet de sécurité.
    */
    /*
    |--------------------------------------------------------------------------
    | Stickers FNE
    |--------------------------------------------------------------------------
    |
    | Prix unitaire d'un sticker de certification, en francs CFA. La plateforme
    | ne le transmet nulle part : elle ne renvoie qu'un nombre de vignettes
    | restantes. Ce tarif sert uniquement a convertir un solde en valeur pour
    | l'alerte de reapprovisionnement — il n'entre dans aucun montant de
    | facture. A ajuster ici si la DGI le revise, plutot qu'en dur dans le code.
    |
    */
    'sticker_prix_unitaire' => env('SELFLOW_STICKER_PRIX', 20),

    /*
    | Seuil d'alerte par defaut, en nombre de stickers. Chaque entreprise peut
    | le redefinir dans ses parametres (`entreprises.sticker_solde_alerte`).
    */
    'sticker_seuil_alerte_defaut' => env('SELFLOW_STICKER_SEUIL', 5),

    'plan_comptable_defaut' => [
        'client_collectif'      => '411000', // Clients
        'fournisseur_collectif' => '401000', // Fournisseurs
        'vente_defaut'          => '701000', // Ventes (compte générique si le produit n'a pas de compte dédié)
        'achat_defaut'          => '601000', // Achats (compte générique si le produit n'a pas de compte dédié)
        // La TVA collectée se range selon ce qui a été vendu : la marchandise
        // et le produit fini en 4431, la prestation de services en 4432, les
        // travaux en 4433. SYSCOHADA les distingue, et la déclaration aussi ;
        // tout verser en 4431 rendait illisible le chiffre d'affaires de
        // service d'une entreprise mixte. Le compte est choisi d'après la
        // racine du compte de produit de chaque ligne — voir
        // ComptabiliteService::compteTvaCollectee().
        'tva_collectee'         => '443100', // État, TVA facturée sur ventes
        'tva_collectee_services' => '443200', // État, TVA facturée sur prestations de services
        'tva_collectee_travaux'  => '443300', // État, TVA facturée sur travaux
        // La TVA déductible se range selon la nature de la charge, comme la
        // collectée se range selon la nature du produit. Tout verser en 4452
        // rendait faux l'état de TVA déductible d'une entreprise dont les
        // charges ne sont pas des achats — un cabinet, l'essentiel de ses
        // charges en 62 et 63. Voir ComptabiliteService::compteTvaDeductible().
        'tva_deductible'        => '445200', // État, TVA récupérable sur achats
        'tva_deductible_transport'       => '445300', // … sur transports
        'tva_deductible_services'        => '445400', // … sur services extérieurs et autres charges
        'tva_deductible_immobilisations' => '445100', // … sur immobilisations
        // Droit de timbre de quittance (article 873 du CGI). Encaissé du
        // client pour le compte de l'État : une dette, jamais un produit. Il
        // n'entrait dans aucune écriture — la caisse était donc débitée de
        // moins que ce que le client avait réellement payé.
        'timbre_quittance'      => '447800', // État, autres impôts et contributions
        // Taxes parafiscales collectées pour le compte de l'État (GRA, AIRSI,
        // DTD…) : une dette envers l'État, jamais du chiffre d'affaires.
        'taxes_collectees'      => '447000', // État, autres impôts et taxes
        'caisse'                => '571000', // Caisse
        'banque_defaut'         => '521000', // Banque (si aucun journal banque dédié trouvé)
    ],

    /*
    |--------------------------------------------------------------------------
    | FNE — Facture Normalisée Électronique (DGI Côte d'Ivoire)
    |--------------------------------------------------------------------------
    | Chaque entreprise a sa PROPRE clé (voir table fne_credentials), gérée
    | exclusivement par le superadmin. Ces URLs sont communes à toutes les
    | entreprises (seule la clé change), donc centralisées ici.
    */
    /*
    | Adresse de l'environnement de TEST, telle que publiee par la DGI dans la
    | procedure d'interfacage : « URL test : http://54.247.95.108/ws ». La
    | valeur inscrite ici auparavant, https://fne-sandbox.dgi.gouv.ci, ne
    | figure dans aucun document — un environnement neuf, sans variable
    | d'environnement, appelait donc un hote inexistant.
    */
    'fne_api_url_sandbox'    => env('FNE_API_URL_SANDBOX', 'http://54.247.95.108/ws'),

    /*
    | L'adresse de PRODUCTION n'est pas publique : la DGI la transmet a chaque
    | entreprise apres validation de ses specimens de factures. Elle doit donc
    | etre renseignee dans l'environnement, faute de quoi la normalisation
    | continuerait de viser le bac a sable sans le dire.
    */
    'fne_api_url_production' => env('FNE_API_URL_PRODUCTION', ''),

    /*
    |--------------------------------------------------------------------------
    | Relevés du portail FNE
    |--------------------------------------------------------------------------
    | Dossier où sont déposés les relevés du portail de la DGI, nommés
    | `<login>_<date>.json` (fiche entreprise) et `<login>_<date>.xlsx` (points
    | de facturation). `ImportPortailFneService` le parcourt, range ce qu'il y
    | lit, et ne touche à rien d'autre : ces relevés sont un constat, pas un
    | paramétrage.
    |
    | Ces fichiers portent des données fiscales nominatives. Le dossier par
    | défaut est sous `storage/app`, hors de `public/` : un dossier servi par
    | le serveur web les exposerait à qui en devine le nom.
    */
    'portail_fne' => [
        'dossier_import' => env('PORTAIL_FNE_DOSSIER_IMPORT', storage_path('app/portail-fne')),

        /*
        | Au-delà de ce délai, une demande de relevé n'attend plus : elle traîne.
        | Vingt-quatre heures parce qu'un relevé se produit au mieux une fois par
        | jour ; passer la journée sans réponse veut dire que le scraper ne
        | tourne pas, que le dépôt se fait ailleurs, ou que le login est faux.
        | Aucune de ces trois causes ne se corrige toute seule.
        */
        'delai_alerte_heures' => (int) env('PORTAIL_FNE_DELAI_ALERTE_HEURES', 24),

        /*
        | Selflow renomme-t-il seul un point de vente d'apres le portail, et
        | renvoie-t-il les pieces que ce nom faisait refuser ?
        |
        | Demande par le proprietaire du projet le 29/08/2026 : le cas « la DGI
        | refuse sur un ecart de nom » se refermait en deux clics, il se referme
        | desormais tout seul. Allume par defaut, donc.
        |
        | Un seul champ est concerne — le nom du point de vente, un libelle dont
        | le portail est la source de verite. Les trois champs de la fiche qui
        | commandent le comportement fiscal (timbre de quittance, bordereau
        | d'achat, solde d'alerte des stickers) restent montres et jamais
        | appliques : la regle d'or ne bouge pas.
        |
        | L'interrupteur existe pour reprendre la main sans livrer une version.
        */
        'correction_auto' => filter_var(env('PORTAIL_FNE_CORRECTION_AUTO', true), FILTER_VALIDATE_BOOL),

        /*
        | Le scraper : ce qui va chercher les relevés sur le portail de la DGI.
        |
        | Selflow ne dépend toujours pas de lui — il lit un dossier, et ce qui
        | dépose dans ce dossier ne le regarde pas. Ces réglages ne servent qu'à
        | le lancer depuis le planificateur déjà en place, plutôt que de créer
        | une seconde tâche système que personne ne pensera à surveiller.
        |
        | `actif` est faux par défaut, et c'est délibéré : sans identifiants.json
        | rempli, chaque passage échouerait et remplirait le journal d'erreurs
        | qui ne veulent rien dire. On l'allume quand le scraper est prêt.
        */
        'scraper' => [
            'actif' => filter_var(env('PORTAIL_FNE_SCRAPER_ACTIF', false), FILTER_VALIDATE_BOOL),

            // Chemin absolu de préférence : la tâche planifiée de Windows n'a
            // pas le PATH d'un terminal ouvert à la main, et « node » seul peut
            // très bien y être introuvable.
            'node' => env('PORTAIL_FNE_NODE', 'node'),

            'script' => env('PORTAIL_FNE_SCRAPER_SCRIPT', base_path('SCRAPER-PORTAIL-FNE/fne.js')),

            // Le passage qui sert la file, décalé après le ramassage (:00) et
            // le rapprochement (:10) : ce qu'il dépose est rangé à l'heure
            // suivante, et diagnostiqué dix minutes après.
            'minute_horaire' => (int) env('PORTAIL_FNE_SCRAPER_MINUTE', 40),

            // Le passage complet, qui relève tous les logins connus sans
            // attendre qu'une pièce soit refusée. La file dit ce qui est
            // urgent, pas ce qui est permis.
            'heure_nocturne' => env('PORTAIL_FNE_SCRAPER_HEURE_NUIT', '02:30'),

            /*
            | Le relevé des factures reçues n'a pas de réglage à lui : `fne.js`
            | le fait dans la session qu'il vient d'ouvrir, à chaque passage.
            | Une connexion au portail de la DGI est ce qui coûte, et il n'y a
            | aucune raison d'en payer deux. `achats.js` reste lançable seul.
            */

            /*
            | Le relevé à l'ouverture de Selflow.
            |
            | Le passage horaire ne va au portail que si une pièce a été
            | refusée, et le passage complet n'a lieu qu'à 02:30 : une
            | modification faite au portail dans la journée n'était visible que
            | le lendemain. Quand quelqu'un ouvre Selflow et que le dernier
            | relevé date de plus de `fraicheur_heures`, le scraper part en
            | arrière-plan — une fois, verrou de cache à l'appui, quel que soit
            | le nombre d'employés qui se connectent.
            */
            'releve_a_la_connexion' => filter_var(env('PORTAIL_FNE_RELEVE_A_LA_CONNEXION', true), FILTER_VALIDATE_BOOL),

            'fraicheur_heures' => (int) env('PORTAIL_FNE_FRAICHEUR_HEURES', 12),

            /*
            | Le relevé qui part dès qu'une pièce est refusée par la DGI.
            |
            | Demandé par le propriétaire du projet le 31/08/2026 : « dès qu'il
            | y a une erreur le scraper se met en action ». Un refus signifie
            | que le portail dit autre chose que la pièce ; aller le lire est
            | exactement ce qu'il faut faire, et attendre le passage horaire
            | (:40) faisait perdre jusqu'à une heure sur un geste que
            | l'utilisateur vient de faire.
            |
            | Le délai est un verrou, pas une temporisation : une normalisation
            | par lot peut consigner vingt refus en dix secondes, et vingt
            | navigateurs ouverts sur le portail de la DGI seraient vingt
            | connexions avec le mot de passe du client. Un relevé par login et
            | par tranche suffit — les vingt refus ont la même cause.
            |
            | Court, parce qu'un utilisateur qui corrige son portail puis
            | relance doit obtenir un relevé neuf, pas celui d'avant.
            */
            'delai_apres_rejet_minutes' => (int) env('PORTAIL_FNE_DELAI_APRES_REJET_MINUTES', 2),
        ],
    ],

];
