<?php

use App\Modules\Admin\Controleurs\AdminControleur;
use App\Modules\Admin\Controleurs\VenteControleur;
use App\Modules\Admin\Controleurs\AchatControleur;
use App\Modules\Admin\Controleurs\StockControleur;
use App\Modules\Admin\Controleurs\TresorerieControleur;
use App\Modules\Admin\Controleurs\PointDeVenteControleur;
use App\Modules\Admin\Controleurs\ProduitControleur;
use App\Modules\Admin\Controleurs\ClientControleur;
use App\Modules\Admin\Controleurs\FournisseurControleur;
use App\Modules\Admin\Controleurs\PersonnelControleur;
use App\Modules\Admin\Controleurs\EntrepriseControleur;
use App\Modules\Admin\Controleurs\RapportControleur;
use App\Modules\Admin\Controleurs\TransfertStockControleur;
use App\Modules\Admin\Controleurs\ConsignationControleur;
use App\Modules\Admin\Controleurs\ImmobilisationControleur;
use App\Modules\Admin\Controleurs\ProductionControleur;
use App\Modules\Admin\Controleurs\B2bControleur;
use App\Modules\Admin\Controleurs\BonLivraisonControleur;
use App\Modules\Admin\Controleurs\SuperadminLiaisonControleur;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->middleware(['auth', 'role:admin', 'habilitation', 'apercu.readonly', 'periode'])
    ->name('admin.')
    ->group(function () {

        // Tableau de bord
        Route::get('/', [AdminControleur::class, 'tableauDeBord'])->name('tableau_de_bord');
        Route::get('/general', [AdminControleur::class, 'tableauDeBordGeneral'])->name('tableau_de_bord_general');

        // ── Ventes ──
        Route::prefix('ventes')->name('ventes.')->middleware(['modules:ventes', 'inscription.complete'])->group(function () {
            Route::get('/nouvelle', [VenteControleur::class, 'nouvelle'])->name('nouvelle');
            Route::post('/enregistrer', [VenteControleur::class, 'enregistrer'])->name('enregistrer');
            Route::get('/factures', [VenteControleur::class, 'factures'])->name('factures');
            Route::get('/factures/rechercher', [VenteControleur::class, 'rechercherFacturesPourAvoir'])->name('factures.rechercher');
            Route::get('/facture-details/{vente}', [VenteControleur::class, 'detailsFacturePourAvoir'])->name('factures.details');
            Route::get('/factures/produits-categories', [VenteControleur::class, 'produitsParCategorie'])->name('factures.produits_categories');
            Route::post('/avoir/creer-nouveau', [VenteControleur::class, 'creerAvoirNouveau'])->name('avoir.creer_nouveau');

            Route::get('/facture/{vente}', [VenteControleur::class, 'imprimer'])->name('imprimer');
            Route::get('/facture/{vente}/ticket', [VenteControleur::class, 'imprimerTicket'])->name('ticket');
            // Conversion reçu <-> facture, dans les deux sens
            Route::post('/{vente}/convertir-piece', [VenteControleur::class, 'convertirPiece'])->name('convertir_piece');
            Route::get('/{vente}/modifier', [VenteControleur::class, 'modifierFormulaire'])->name('modifier');
            Route::put('/{vente}/modifier', [VenteControleur::class, 'enregistrerModification'])->name('modifier.enregistrer');
            Route::post('/{vente}/confirmer', [VenteControleur::class, 'confirmerCommande'])->name('confirmer');
            Route::post('/{vente}/facturer', [VenteControleur::class, 'facturer'])->name('facturer');
            Route::post('/{vente}/avoir', [VenteControleur::class, 'creerAvoir'])->name('avoir');
            Route::post('/{vente}/normaliser', [VenteControleur::class, 'normaliser'])->name('normaliser');
            // Workflow Devis → Commande → Facture
            Route::post('/{vente}/envoyer', [VenteControleur::class, 'envoyer'])->name('envoyer');
            Route::post('/{vente}/convertir-commande', [VenteControleur::class, 'convertirEnCommande'])->name('convertir.commande');
            Route::post('/{vente}/convertir-facture', [VenteControleur::class, 'convertirEnFacture'])->name('convertir.facture');
            // Ce qui rend un devis opposable : son terme, et l'accord du client
            Route::post('/{vente}/accepter', [VenteControleur::class, 'accepterOffre'])->name('accepter');
            Route::post('/{vente}/prolonger', [VenteControleur::class, 'prolongerOffre'])->name('prolonger');
            Route::delete('/{vente}/supprimer', [VenteControleur::class, 'supprimer'])->name('supprimer');
            // Bon de Livraison
            Route::get('/{vente}/livraison/creer', [BonLivraisonControleur::class, 'creerDepuisBC'])->name('livraison.creer');
            Route::post('/{vente}/livraison/enregistrer', [BonLivraisonControleur::class, 'enregistrer'])->name('livraison.enregistrer');
            Route::get('/livraisons', [BonLivraisonControleur::class, 'index'])->name('livraisons');
            Route::get('/livraison/{bl}', [BonLivraisonControleur::class, 'imprimer'])->name('livraison.voir');
            Route::post('/livraison/{bl}/livrer', [BonLivraisonControleur::class, 'marquerLivre'])->name('livraison.livrer');
            Route::post('/livraison/{bl}/facturer', [BonLivraisonControleur::class, 'convertirEnFacture'])->name('livraison.facturer');
        });

        // ── Achats ──
        Route::prefix('achats')->name('achats.')->middleware(['modules:achats', 'inscription.complete'])->group(function () {

            Route::get('/nouveau', [AchatControleur::class, 'nouveau'])->name('nouveau');
            Route::post('/enregistrer', [AchatControleur::class, 'enregistrer'])->name('enregistrer');
            Route::get('/factures', [AchatControleur::class, 'factures'])->name('factures');
            Route::get('/factures/rechercher', [AchatControleur::class, 'rechercherFacturesPourAvoir'])->name('factures.rechercher');
            Route::get('/facture-details/{achat}', [AchatControleur::class, 'detailsFacturePourAvoir'])->name('factures.details');
            Route::get('/factures/produits-categories', [AchatControleur::class, 'produitsParCategorie'])->name('factures.produits_categories');
            Route::post('/avoir/creer-nouveau', [AchatControleur::class, 'creerAvoirNouveau'])->name('avoir.creer_nouveau');

            Route::get('/facture/{achat}', [AchatControleur::class, 'imprimer'])->name('imprimer');
            Route::get('/facture/{achat}/bapa', [AchatControleur::class, 'imprimerBapa'])->name('bapa');
            Route::post('/{achat}/confirmer', [AchatControleur::class, 'confirmerCommande'])->name('confirmer');
            Route::post('/{achat}/facturer', [AchatControleur::class, 'facturer'])->name('facturer');
            Route::post('/{achat}/avoir', [AchatControleur::class, 'creerAvoir'])->name('avoir');
            Route::post('/{achat}/normaliser', [AchatControleur::class, 'normaliser'])->name('normaliser');
            Route::post('/{achat}/fne', [\App\Modules\Admin\Controleurs\FneControleur::class, 'attacherFneAchat'])->name('fne.attacher');
            Route::post('/{achat}/transmettre-b2b', [AchatControleur::class, 'transmettreB2b'])->name('transmettre_b2b');

            // ── Factures reçues du portail FNE ──
            //
            // Sous `achats` et non sous `fne` : ce sont des factures d'achat, et
            // c'est là qu'on les cherche. Le groupe FNE exige `modules:comptabilite`
            // — une entreprise qui achète sans tenir sa comptabilité dans Selflow
            // se serait vu refuser l'écran de ses propres factures fournisseurs.
            //
            // Rien ici ne crée d'achat ni n'écrit dans les colonnes gelées
            // d'`achats` : `rattacher` pose un lien vers un achat qui existe déjà,
            // dans `portail_fne_factures_recues.achat_id`.
            Route::get('/factures-recues', [\App\Modules\Admin\Controleurs\FactureRecueControleur::class, 'index'])->name('factures_recues');
            // La pièce vue comme un document : une copie d'après le relevé, que
            // son bandeau distingue de l'original certifié du fournisseur.
            Route::get('/factures-recues/{facture}/imprimer', [\App\Modules\Admin\Controleurs\FactureRecueControleur::class, 'imprimer'])->name('factures_recues.imprimer');
            // Le document de la DGI lui-même, rapporté par le scraper. Il passe
            // par le contrôleur et non par une URL publique : le dossier d'import
            // est hors de `public/`, et ces pièces portent des données fiscales
            // nominatives qu'une adresse devinée ne doit pas rendre.
            Route::get('/factures-recues/{facture}/document', [\App\Modules\Admin\Controleurs\FactureRecueControleur::class, 'pdf'])->name('factures_recues.pdf');
            Route::post('/factures-recues/{facture}/rattacher', [\App\Modules\Admin\Controleurs\FactureRecueControleur::class, 'rattacher'])->name('factures_recues.rattacher');
            Route::post('/factures-recues/{facture}/detacher', [\App\Modules\Admin\Controleurs\FactureRecueControleur::class, 'detacher'])->name('factures_recues.detacher');
            Route::post('/factures-recues/{facture}/ecarter', [\App\Modules\Admin\Controleurs\FactureRecueControleur::class, 'ecarter'])->name('factures_recues.ecarter');
            // Le site d'une facture reçue est une décision de l'entreprise : le
            // portail ne le dit pas, son `clientPointOfSale` décrivant l'émetteur.
            Route::post('/factures-recues/{facture}/affecter', [\App\Modules\Admin\Controleurs\FactureRecueControleur::class, 'affecter'])->name('factures_recues.affecter');
            // La porte de retour d'`ecarter`, qui n'en avait aucune : une pièce
            // écartée disparaissait de tous les écrans sans moyen de revenir.
            Route::post('/factures-recues/{facture}/reintegrer', [\App\Modules\Admin\Controleurs\FactureRecueControleur::class, 'reintegrer'])->name('factures_recues.reintegrer');
        });

        // ── FNE / DGI Stub (Lot I) ──
        Route::prefix('fne')->name('fne.')->group(function () {
            Route::post('/rechercher', [\App\Modules\Admin\Controleurs\FneControleur::class, 'rechercherDocumentFiscal'])->name('rechercher');
        });

        // ── Stock ──
        Route::prefix('stock')->name('stock.')->middleware('modules:stock')->group(function () {
            Route::get('/', [StockControleur::class, 'index'])->name('index');
            Route::get('/mouvements', [StockControleur::class, 'mouvements'])->name('mouvements');
            Route::get('/rebut', [StockControleur::class, 'rebut'])->name('rebut');

            // Inventaire physique : le comptage, et l'ecart qu'il produit.
            Route::get('/inventaire', [StockControleur::class, 'inventaire'])->name('inventaire');
            Route::post('/inventaire', [StockControleur::class, 'enregistrerInventaire'])->name('inventaire.enregistrer');
            Route::post('/rebut', [StockControleur::class, 'retirerRebut'])->name('rebut.retirer');

            // Réceptions (Achats)
            Route::get('/receptions', [StockControleur::class, 'receptions'])->name('receptions');
            Route::get('/receptions/{achat}', [StockControleur::class, 'ficheReception'])->name('receptions.fiche');
            Route::post('/receptions/{achat}/valider', [StockControleur::class, 'validerReception'])->name('receptions.valider');

            // Livraisons (Ventes)
            Route::get('/livraisons', [StockControleur::class, 'livraisons'])->name('livraisons');
            Route::get('/livraisons/{vente}', [StockControleur::class, 'ficheLivraison'])->name('livraisons.fiche');
            Route::post('/livraisons/{vente}/valider', [StockControleur::class, 'validerLivraison'])->name('livraisons.valider');

            // Transferts internes
            Route::get('/transferts', [TransfertStockControleur::class, 'index'])->name('transferts.index');
            Route::post('/transferts', [TransfertStockControleur::class, 'creer'])->name('transferts.creer');
            Route::post('/transferts/{transfert}/valider', [TransfertStockControleur::class, 'valider'])->name('transferts.valider');
            Route::post('/transferts/{transfert}/rejeter', [TransfertStockControleur::class, 'rejeter'])->name('transferts.rejeter');
        });

        // ── Trésorerie ──
        Route::prefix('tresorerie')->name('tresorerie.')->middleware('modules:comptabilite')->group(function () {
            Route::get('/encaissements', [TresorerieControleur::class, 'encaissements'])->name('encaissements');
            Route::get('/decaissements', [TresorerieControleur::class, 'decaissements'])->name('decaissements');
            Route::get('/journal', [TresorerieControleur::class, 'journal'])->name('journal');
            Route::get('/codes-journaux', [TresorerieControleur::class, 'codesJournaux'])->name('codes_journaux');
            Route::post('/codes-journaux', [TresorerieControleur::class, 'creerCodeJournal'])->name('creer_code_journal');
            Route::delete('/codes-journaux/{code}', [TresorerieControleur::class, 'supprimerCodeJournal'])->name('supprimer_code_journal');
            // Le trousseau se posait a la creation de l'entreprise, et jamais
            // plus : une entreprise creee avant qu'un journal soit ajoute au
            // referentiel ne l'obtenait plus par aucun chemin.
            Route::post('/codes-journaux/poser-le-defaut', [TresorerieControleur::class, 'poserLesJournauxParDefaut'])->name('poser_journaux_defaut');
        });

        // ── Comptabilité ──
        Route::prefix('comptabilite')->name('comptabilite.')->middleware('modules:comptabilite')->group(function () {
            Route::get('/globale', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'globale'])->name('globale');
            Route::get('/creances', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'creances'])->name('creances');
            Route::get('/tiers/{type}/{id}', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'releveTiers'])->name('releve_tiers');
            Route::post('/reglement', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'enregistrerReglement'])->name('enregistrer_reglement');
            Route::get('/plan-comptable', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'planComptable'])->name('plan_comptable');
            Route::post('/plan-comptable', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'creerCompteComptable'])->name('creer_compte_comptable');
            Route::post('/plan-comptable/poser-le-defaut', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'poserLePlanParDefaut'])->name('poser_plan_defaut');
            Route::post('/ecritures/manuelle', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'creerEcritureManuelle'])->name('ecriture_manuelle');

            // Balance de controle : ce qui permet a un client sans abonnement
            // Comptaflow de verifier ce que Selflow a ecrit.
            Route::get('/balance', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'balance'])->name('balance');

            // Grand livre : la balance dit combien un compte a bouge, le grand
            // livre dit pourquoi.
            Route::get('/grand-livre', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'grandLivre'])->name('grand_livre');

            // Le resultat site par site : l'axe analytique que l'application
            // renseigne reellement.
            Route::get('/analytique', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'analytique'])->name('analytique');

            // Les libelles d'ecriture : ce que le journal dit d'une operation,
            // au lieu de repeter l'intitule du compte mouvemente.
            Route::get('/libelles', [\App\Modules\Admin\Controleurs\ModeleLibelleControleur::class, 'index'])->name('libelles');
            Route::put('/libelles', [\App\Modules\Admin\Controleurs\ModeleLibelleControleur::class, 'enregistrer'])->name('libelles.enregistrer');
            Route::post('/libelles/apercu', [\App\Modules\Admin\Controleurs\ModeleLibelleControleur::class, 'apercu'])->name('libelles.apercu');

            // Lettrage : rapprocher une facture du reglement qui la solde.
            Route::get('/lettrage', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'lettrage'])->name('lettrage');
            Route::post('/lettrage', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'lettrer'])->name('lettrer');
            Route::delete('/lettrage/{lettrage}', [\App\Modules\Admin\Controleurs\ComptabiliteControleur::class, 'delettrer'])->name('delettrer');
        });

        // ── Module Fiscalité & DGI (Gestion FNE) ──
        Route::prefix('fne')->name('fne.')->middleware('modules:comptabilite')->group(function () {
            Route::get('/gestion', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'gestion'])->name('gestion');
            Route::get('/gestion/donnees', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'gestionJson'])->name('gestion.donnees');
            Route::get('/situation', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'situation'])->name('situation');
            Route::get('/situation/donnees', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'situationJson'])->name('situation.donnees');
            Route::get('/factures', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'factures'])->name('factures');
            Route::get('/factures/donnees', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'facturesJson'])->name('factures.donnees');
            // ── Gestion des Stickers ──
            Route::get('/stickers', [\App\Modules\Admin\Controleurs\StickerControleur::class, 'index'])->name('stickers');
            // Un achat de stickers engage la trésorerie de l'entreprise et
            // appelle la plateforme : le répéter coûte de l'argent.
            Route::post('/stickers/acheter', [\App\Modules\Admin\Controleurs\StickerControleur::class, 'acheter'])->middleware('throttle:plateforme')->name('stickers.acheter');
            // ── Configuration Fiscale (TVA / TSE / TDT) ──
            Route::get('/config', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'obtenirConfig'])->name('config');
            Route::post('/config', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'sauvegarderConfig'])->name('config.sauvegarder');
            // ── Traitement par Lot et Planification ──
            // **La normalisation par lot appelle la plateforme de la DGI.** La
            // marteler expose l'entreprise à voir sa propre clé ralentie ou
            // coupée : la conséquence est chez elle, pas chez nous.
            Route::post('/batch-normaliser', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'batchNormaliser'])->middleware('throttle:plateforme')->name('batch_normaliser');
            Route::post('/schedule-batch', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'scheduleBatch'])->middleware('throttle:plateforme')->name('schedule_batch');
            Route::get('/batch-status', [\App\Modules\Admin\Controleurs\FneDashboardControleur::class, 'batchStatus'])->name('batch_status');

            // ── Pièces refusées par la plateforme ──
            // Le rapprochement écrivait déjà son constat chaque heure ; il
            // n'était lisible nulle part. `appliquer` renomme un point de vente
            // pour l'aligner sur ce que le portail déclare — jamais un champ
            // fiscal, qui reste montré et non recopié.
            Route::get('/rejets', [\App\Modules\Admin\Controleurs\RejetFneControleur::class, 'index'])->name('rejets');
            Route::post('/rejets/{rejet}/diagnostiquer', [\App\Modules\Admin\Controleurs\RejetFneControleur::class, 'diagnostiquer'])->name('rejets.diagnostiquer');
            Route::post('/rejets/{rejet}/appliquer', [\App\Modules\Admin\Controleurs\RejetFneControleur::class, 'appliquer'])->name('rejets.appliquer');
            // Le « tout en un clic » du pop-up : ranger le relevé, rapprocher, corriger.
            Route::post('/rejets/{rejet}/corriger-maintenant', [\App\Modules\Admin\Controleurs\RejetFneControleur::class, 'corrigerMaintenant'])->name('rejets.corriger_maintenant');
            Route::post('/rejets/{rejet}/corriger-avec/{rang}', [\App\Modules\Admin\Controleurs\RejetFneControleur::class, 'corrigerAvec'])->name('rejets.corriger_avec');
            Route::get('/rejets/{rejet}/statut-scraping', [\App\Modules\Admin\Controleurs\RejetFneControleur::class, 'statutScraping'])->name('rejets.statut_scraping');
            Route::post('/rejets/{rejet}/resoudre', [\App\Modules\Admin\Controleurs\RejetFneControleur::class, 'resoudre'])->name('rejets.resoudre');
        });

        // ── Points de vente ──
        Route::prefix('points-de-vente')->name('pdv.')->group(function () {
            Route::get('/', [PointDeVenteControleur::class, 'index'])->name('index');
            Route::post('/', [PointDeVenteControleur::class, 'creer'])->name('creer');
            Route::put('/{pdv}', [PointDeVenteControleur::class, 'modifier'])->name('modifier');
            Route::post('/activer/{pdv}', [PointDeVenteControleur::class, 'activerSession'])->name('activer');
            Route::post('/activer-apercu/{pdv}', [PointDeVenteControleur::class, 'activerApercu'])->name('activer_apercu');
            Route::post('/desactiver-apercu', [PointDeVenteControleur::class, 'desactiverApercu'])->name('desactiver_apercu');

            // Reprendre dans Selflow les points de facturation que le portail
            // FNE déclare. Le sens ne s'inverse jamais : rien n'est créé au
            // portail depuis Selflow.
            Route::post('/relever-le-portail', [PointDeVenteControleur::class, 'releverLePortail'])->name('relever_le_portail');
            Route::get('/etat-du-portail', [PointDeVenteControleur::class, 'etatDuPortail'])->name('etat_du_portail');
            Route::post('/importer-du-portail', [PointDeVenteControleur::class, 'importerDuPortail'])->name('importer_du_portail');
            // Charger un relevé à la main, quand le scraper ne peut pas tourner :
            // .json (fiche) ou .xlsx / .xls (points de facturation).
            Route::post('/charger-fichier-fne', [PointDeVenteControleur::class, 'chargerFichierFne'])->name('load_file_fne');
        });

        // ── Import CSV ──
        //
        // Un fichier de cinq mégaoctets, lu ligne à ligne, qui écrit des
        // articles, du stock, des immobilisations et des comptes utilisateurs.
        // Le répéter suffit à occuper le serveur.
        Route::prefix('import')->name('import.')->middleware('throttle:import')->group(function () {
            Route::get('/{type}/exemple', [\App\Modules\Admin\Controleurs\ImportControleur::class, 'telechargerExemple'])->name('exemple');
            Route::post('/{type}/preview', [\App\Modules\Admin\Controleurs\ImportControleur::class, 'preview'])->name('preview');
            Route::post('/{type}', [\App\Modules\Admin\Controleurs\ImportControleur::class, 'importer'])->name('importer');
        });

        // ── Gestion Personnel ──
        Route::prefix('personnel')->name('personnel.')->group(function () {
            Route::get('/', [PersonnelControleur::class, 'index'])->name('index');
            Route::post('/', [PersonnelControleur::class, 'creer'])->name('creer');
            Route::get('/{personnel}', [PersonnelControleur::class, 'details'])->name('details');
            Route::put('/{personnel}', [PersonnelControleur::class, 'modifier'])->name('modifier');
            Route::post('/{personnel}/statut', [PersonnelControleur::class, 'changerStatut'])->name('statut');
            Route::delete('/{personnel}', [PersonnelControleur::class, 'supprimer'])->name('supprimer');
        });

        // ── Gestion catalogue ──
        Route::prefix('produits')->name('produits.')->group(function () {
            Route::get('/calculer-reference', [ProduitControleur::class, 'calculerReference'])->name('calculer_reference');
            Route::get('/', [ProduitControleur::class, 'index'])->name('index');
            Route::post('/', [ProduitControleur::class, 'creer'])->name('creer');
            Route::get('/{produit}/fiche', [ProduitControleur::class, 'fiche'])->name('fiche');
            Route::put('/{produit}', [ProduitControleur::class, 'modifier'])->name('modifier');
            Route::patch('/{produit}/archiver', [ProduitControleur::class, 'archiver'])->name('archiver');
            Route::patch('/{produit}/description', [ProduitControleur::class, 'description'])->name('description');
            Route::post('/{produit}/photo', [ProduitControleur::class, 'uploaderPhoto'])->name('photo');
            // Le recours quand `public/storage` n'est pas posé — voir
            // `Produit::photoReelle()`.
            Route::get('/{produit}/photo', [ProduitControleur::class, 'voirPhoto'])->name('photo.voir');
            Route::post('/{produit}/details', [ProduitControleur::class, 'ajouterDetails'])->name('details.ajouter');
            Route::delete('/details/{detail}', [ProduitControleur::class, 'supprimerDetail'])->name('details.supprimer');
        });

        // ── Production ──
        Route::prefix('production')->name('production.')->middleware('modules:production')->group(function () {
            Route::prefix('fiches-techniques')->name('fiches_techniques.')->group(function () {
                Route::get('/', [ProductionControleur::class, 'indexFichesTechniques'])->name('index');
                Route::get('/creer', [ProductionControleur::class, 'creerFicheTechnique'])->name('creer');
                Route::post('/creer', [ProductionControleur::class, 'enregistrerFicheTechnique'])->name('enregistrer');
                Route::get('/{fiche}/modifier', [ProductionControleur::class, 'modifierFicheTechnique'])->name('modifier');
                Route::put('/{fiche}/modifier', [ProductionControleur::class, 'enregistrerModificationFicheTechnique'])->name('modifier.enregistrer');
                Route::delete('/{fiche}', [ProductionControleur::class, 'supprimerFicheTechnique'])->name('supprimer');
            });

            Route::prefix('ordres')->name('ordres.')->group(function () {
                Route::get('/', [ProductionControleur::class, 'indexOrdres'])->name('index');
                Route::get('/creer', [ProductionControleur::class, 'creerOrdre'])->name('creer');
                Route::post('/creer', [ProductionControleur::class, 'enregistrerOrdre'])->name('enregistrer');
                Route::post('/{ordre}/valider', [ProductionControleur::class, 'validerOrdre'])->name('valider');
            });
        });

        // ── Emballages consignés ──
        //
        // La consignation reçue est une dette, non un produit : une caisse
        // consignée 2 000 francs gonflait le chiffre d'affaires de 2 000 francs
        // que l'entreprise devra rendre.
        Route::prefix('consignations')->name('consignations.')->middleware('modules:stock')->group(function () {
            Route::get('/', [ConsignationControleur::class, 'index'])->name('index');
            Route::post('/', [ConsignationControleur::class, 'enregistrer'])->name('enregistrer');
            Route::post('/{consignation}/rendre', [ConsignationControleur::class, 'rendre'])->name('rendre');
            Route::post('/{consignation}/non-rendue', [ConsignationControleur::class, 'constaterLeNonRetour'])->name('non_retour');
        });

        // ── Immobilisations et amortissements ──
        //
        // Rien n'existait : un camion, un four, un ordinateur passaient en
        // charge de l'exercice, ou ne passaient nulle part. Le bilan ne portait
        // pas trace de l'actif immobilisé, et la charge d'amortissement,
        // déductible, n'était pas prise.
        Route::prefix('immobilisations')->name('immobilisations.')->middleware('modules:comptabilite')->group(function () {
            Route::get('/', [ImmobilisationControleur::class, 'index'])->name('index');
            Route::get('/creer', [ImmobilisationControleur::class, 'creer'])->name('creer');
            Route::post('/creer', [ImmobilisationControleur::class, 'enregistrer'])->name('enregistrer');
            Route::post('/cloturer', [ImmobilisationControleur::class, 'cloturerLExercice'])->name('cloturer');
            Route::get('/{bien}', [ImmobilisationControleur::class, 'fiche'])->name('fiche');
            Route::put('/{bien}', [ImmobilisationControleur::class, 'modifier'])->name('modifier');
            Route::post('/{bien}/ceder', [ImmobilisationControleur::class, 'ceder'])->name('ceder');
            Route::post('/dotation/{dotation}', [ImmobilisationControleur::class, 'passerLaDotation'])->name('dotation');
        });

        // ── Communication B2B ──
        Route::prefix('b2b')->name('b2b.')->group(function () {
            Route::get('/negociations/client', [B2bControleur::class, 'negociationsClient'])->name('negociations.client');
            Route::get('/negociations/fournisseur', [B2bControleur::class, 'negociationsFournisseur'])->name('negociations.fournisseur');
            Route::post('/rfq', [B2bControleur::class, 'creerRfq'])->name('rfq.creer');
            Route::post('/negociation/{negociation}/proposer', [B2bControleur::class, 'proposerPrix'])->name('negociation.proposer');
            Route::get('/negociation/{negociation}/stock', [B2bControleur::class, 'verifierStock'])->name('negociation.stock');
            Route::post('/negociation/{negociation}/finaliser', [B2bControleur::class, 'finaliserB2b'])->name('negociation.finaliser');
            Route::post('/achat/{achat}/accepter', [B2bControleur::class, 'accepterAchatB2b'])->name('achat.accepter');
        });

        Route::prefix('clients')->name('clients.')->group(function () {
            Route::get('/', [ClientControleur::class, 'index'])->name('index');
            Route::post('/', [ClientControleur::class, 'creer'])->name('creer');
            Route::put('/{client}', [ClientControleur::class, 'modifier'])->name('modifier');
            Route::delete('/{client}', [ClientControleur::class, 'supprimer'])->name('supprimer');
        });

        Route::prefix('fournisseurs')->name('fournisseurs.')->group(function () {
            Route::get('/', [FournisseurControleur::class, 'index'])->name('index');
            Route::post('/', [FournisseurControleur::class, 'creer'])->name('creer');
            Route::put('/{fournisseur}', [FournisseurControleur::class, 'modifier'])->name('modifier');
            Route::delete('/{fournisseur}', [FournisseurControleur::class, 'supprimer'])->name('supprimer');
        });

        // ── Banques ──
        Route::post('/banques/creer', [TresorerieControleur::class, 'creerBanqueAjax'])->name('banques.creer');

        // ── Profil utilisateur ──
        Route::get('/mon-profil', [AdminControleur::class, 'monProfil'])->name('mon_profil');
        Route::match(['post', 'put'], '/mon-profil', [AdminControleur::class, 'enregistrerProfil'])->name('mon_profil.enregistrer');


        // ── Paramètres entreprise ──
        Route::get('/entreprise/parametres', [EntrepriseControleur::class, 'parametres'])->name('entreprise.parametres');
        Route::put('/entreprise/parametres', [EntrepriseControleur::class, 'enregistrerParametres'])->name('entreprise.parametres.enregistrer');
        // Ces trois boutons appellent un service extérieur — la plateforme de
        // la DGI, puis Comptaflow. Les marteler use un quota qui n'est pas le
        // nôtre.
        Route::post('/entreprise/fne/tester-connexion', [EntrepriseControleur::class, 'testerConnexionFne'])->middleware('throttle:plateforme')->name('entreprise.fne.tester_connexion');
        // `sync-simulation` annoncait une synchronisation reussie sans qu'aucun
        // appel ne parte. Elle est remplacee par la demande de dossier, qui
        // n'annonce que ce qu'elle fait.
        Route::post('/entreprise/comptaflow/demander', [EntrepriseControleur::class, 'demanderComptaflow'])->middleware('throttle:plateforme')->name('entreprise.comptaflow.demander');
        Route::post('/entreprise/comptaflow/sync', [EntrepriseControleur::class, 'synchroniserComptaflow'])->middleware('throttle:plateforme')->name('entreprise.comptaflow.sync_real');
        Route::post('/entreprise/onboarding/entreprise-nom', [EntrepriseControleur::class, 'enregistrerNomOnboarding'])->name('onboarding.entreprise_nom');



        // ── Périodes / Exercices ──
        Route::post('/periods/switch', [EntrepriseControleur::class, 'switchPeriode'])->name('periods.switch');
        Route::post('/entreprise/periodes', [EntrepriseControleur::class, 'creerPeriode'])->name('entreprise.periodes.creer');
        Route::post('/entreprise/periodes/{periode}/cloturer', [EntrepriseControleur::class, 'cloturerPeriode'])->name('entreprise.periodes.cloturer');

        // ── Rapports ──
        Route::prefix('rapports')->name('rapports.')->group(function () {
            Route::get('/analyse-activite', [RapportControleur::class, 'analyseActivite'])->name('analyse_activite');
        });
    });

// ── Visite guidee de premiere utilisation ──
Route::prefix('admin/visite')
    ->middleware(['auth'])
    ->name('admin.visite.')
    ->group(function () {
        Route::post('/terminer', [\App\Modules\Admin\Controleurs\VisiteGuideeControleur::class, 'terminer'])->name('terminer');
        Route::post('/rejouer', [\App\Modules\Admin\Controleurs\VisiteGuideeControleur::class, 'rejouer'])->name('rejouer');
    });

// ───────────────────────────────────────────────────────────────────────
// Parcours de configuration de l'entreprise
//
// Il vit hors du groupe « modules » : une entreprise qui n'a encore rien
// configure n'a aucun module actif, et le middleware la rejetterait de son
// propre parcours de configuration.
// ───────────────────────────────────────────────────────────────────────
Route::prefix('admin/souscription')
    ->middleware(['auth', 'role:admin'])
    ->name('admin.souscription.')
    ->group(function () {
        Route::get('/', [\App\Modules\Admin\Controleurs\SouscriptionControleur::class, 'index'])->name('index');
        Route::post('/{etape}', [\App\Modules\Admin\Controleurs\SouscriptionControleur::class, 'enregistrer'])
            ->whereNumber('etape')->name('enregistrer');
    });

// ───────────────────────────────────────────────────────────────────────
// Routes pour l'interface SuperAdmin
// ───────────────────────────────────────────────────────────────────────
Route::prefix('superadmin')
    ->middleware(['auth', 'role:superadmin', 'habilitation'])
    ->name('superadmin.')
    ->group(function () {
        Route::get('/', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'tableauDeBord'])->name('tableau_de_bord');
        Route::get('/entreprises', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'entreprises'])->name('entreprises');
        Route::get('/entreprises/creer', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'creerFormulaire'])->name('entreprises.creer');
        Route::post('/entreprises/creer', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'creer'])->name('entreprises.creer.enregistrer');
        Route::get('/entreprises/{entreprise}/modifier', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'modifierFormulaire'])->name('entreprises.modifier');
        Route::put('/entreprises/{entreprise}/modifier', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'modifier'])->name('entreprises.modifier.enregistrer');
        Route::post('/entreprises/{entreprise}/toggle-status', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'toggleStatus'])->name('entreprises.toggle_status');
        Route::delete('/entreprises/{entreprise}', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'supprimer'])->name('entreprises.supprimer');
        Route::get('/utilisateurs', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'utilisateurs'])->name('utilisateurs');
        Route::put('/utilisateurs/{utilisateur}', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'modifierUtilisateur'])->name('utilisateurs.modifier');

        // ── Administration interne (Superadmins) ──
        Route::prefix('admins')->name('admins.')->group(function () {
            Route::get('/', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'admins'])->name('index');
            Route::get('/creer', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'creerAdmin'])->name('creer');
            Route::post('/creer', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'enregistrerAdmin'])->name('enregistrer');
            Route::get('/{utilisateur}/modifier', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'modifierAdmin'])->name('modifier');
            Route::put('/{utilisateur}/modifier', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'mettreAJourAdmin'])->name('mettre_a_jour');
            Route::delete('/{utilisateur}', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'supprimerAdmin'])->name('supprimer');
        });

        // ── Referentiel de preparametrage (consultation seule) ──
        Route::prefix('referentiel')->name('referentiel.')->group(function () {
            Route::get('/', [\App\Modules\Admin\Controleurs\SuperadminReferentielControleur::class, 'index'])->name('index');
            Route::get('/{code}', [\App\Modules\Admin\Controleurs\SuperadminReferentielControleur::class, 'profil'])->name('profil');
        });

        // ── Liaisons SELFLOW ↔ COMPTAFLOW ──
        Route::prefix('liaisons')->name('liaisons.')->group(function () {
            Route::get('/', [SuperadminLiaisonControleur::class, 'index'])->name('index');
            // `lier` pointait sur une methode absente du controleur — le
            // formulaire tombait en 500 depuis un renommage. `creerComptaflow`
            // demandait au superadministrateur de choisir le mot de passe du
            // compte d'un client. Les deux chemins sont remplaces par une file
            // de demandes : la cle est delivree par Comptaflow, jamais saisie.
            Route::post('/{entreprise}/valider', [SuperadminLiaisonControleur::class, 'validerDemande'])->middleware('throttle:plateforme')->name('valider');
            Route::post('/{entreprise}/refuser', [SuperadminLiaisonControleur::class, 'refuserDemande'])->name('refuser');
            // Le renouvellement a la main, sans attendre le premier du mois :
            // un prestataire qui part, un journal retrouve sur un poste
            // partage, un doute. Limite : c'est un appel a Comptaflow.
            Route::post('/{entreprise}/renouveler-cle', [SuperadminLiaisonControleur::class, 'renouvelerLaCle'])->middleware('throttle:plateforme')->name('renouveler_cle');
            Route::delete('/{entreprise}/delierEntreprise', [SuperadminLiaisonControleur::class, 'delierEntreprise'])->name('delierEntreprise');
            Route::post('/{entreprise}/verifier', [SuperadminLiaisonControleur::class, 'verifierLiaison'])->middleware('throttle:plateforme')->name('verifier');
        });

        // ── Gestion des clés FNE (DGI) ──
        Route::prefix('fne')->name('fne.')->group(function () {
            Route::get('/', [\App\Modules\Admin\Controleurs\SuperadminFneControleur::class, 'index'])->name('index');
            Route::post('/{entreprise}/cle-test', [\App\Modules\Admin\Controleurs\SuperadminFneControleur::class, 'ajouterCleTest'])->name('cle_test');
            Route::post('/{entreprise}/cle-reelle', [\App\Modules\Admin\Controleurs\SuperadminFneControleur::class, 'ajouterCleReelle'])->name('cle_reelle');
            Route::post('/{entreprise}/voir-cle', [\App\Modules\Admin\Controleurs\SuperadminFneControleur::class, 'voirCle'])->name('voir_cle');
            Route::delete('/{entreprise}/cle', [\App\Modules\Admin\Controleurs\SuperadminFneControleur::class, 'supprimerCle'])->name('supprimer_cle');
            Route::post('/{entreprise}/notes', [\App\Modules\Admin\Controleurs\SuperadminFneControleur::class, 'mettreAJourNotes'])->name('notes');
            // Le timbre de quittance est un réglage de la plateforme, non un
            // choix de l'entreprise : il se reporte ici, avec les clés.
            Route::post('/{entreprise}/timbre', [\App\Modules\Admin\Controleurs\SuperadminFneControleur::class, 'basculerTimbre'])->name('timbre');
        });

        // ── Vitrine publique (contenu de la page de presentation) ──
        Route::prefix('vitrine')->name('vitrine.')->group(function () {
            Route::get('/', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'index'])->name('index');

            Route::post('/sections', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'creerSection'])->name('sections.creer');
            Route::put('/sections/{section}', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'modifierSection'])->name('sections.modifier');
            Route::post('/sections/{section}/basculer', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'basculerSection'])->name('sections.basculer');
            Route::delete('/sections/{section}', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'supprimerSection'])->name('sections.supprimer');

            Route::post('/sections/{section}/cartes', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'creerCarte'])->name('cartes.creer');
            Route::post('/cartes/{carte}', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'modifierCarte'])->name('cartes.modifier');
            Route::post('/cartes/{carte}/basculer', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'basculerCarte'])->name('cartes.basculer');
            Route::delete('/cartes/{carte}', [\App\Modules\Admin\Controleurs\VitrineControleur::class, 'supprimerCarte'])->name('cartes.supprimer');
        });

        // ── Secteurs & Modules (Configuration plateforme) ──
        Route::prefix('secteurs-modules')->name('secteurs_modules.')->group(function () {
            Route::get('/', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'secteursModules'])->name('index');
            Route::post('/', [\App\Modules\Admin\Controleurs\SuperadminControleur::class, 'sauvegarderSecteursModules'])->name('sauvegarder');
        });
    });

// ───────────────────────────────────────────────────────────────────────
// Routes pour l'interface Caissier (Point de Vente)
// ───────────────────────────────────────────────────────────────────────
Route::prefix('caissier')
    ->middleware(['auth', 'role:admin,caissier', 'caissier.acces', 'habilitation', 'apercu.readonly', 'periode'])
    ->name('caissier.')
    ->group(function () {
        // Le tableau de bord du caissier est l'écran de caisse/nouvelle vente
        Route::get('/', [VenteControleur::class, 'nouvelle'])->name('tableau_de_bord');

        Route::prefix('ventes')->name('ventes.')->group(function () {
            Route::get('/nouvelle', [VenteControleur::class, 'nouvelle'])->name('nouvelle');
            Route::post('/enregistrer', [VenteControleur::class, 'enregistrer'])->name('enregistrer');
            Route::get('/factures', [VenteControleur::class, 'factures'])->name('factures');

            Route::get('/facture/{vente}', [VenteControleur::class, 'imprimer'])->name('imprimer');
            Route::get('/facture/{vente}/ticket', [VenteControleur::class, 'imprimerTicket'])->name('ticket');
            // Conversion reçu <-> facture, dans les deux sens
            Route::post('/{vente}/convertir-piece', [VenteControleur::class, 'convertirPiece'])->name('convertir_piece');
            Route::get('/{vente}/modifier', [VenteControleur::class, 'modifierFormulaire'])->name('modifier');
            Route::put('/{vente}/modifier', [VenteControleur::class, 'enregistrerModification'])->name('modifier.enregistrer');
            Route::post('/{vente}/normaliser', [VenteControleur::class, 'normaliser'])->name('normaliser');
            // Workflow Devis → Commande → Facture
            Route::post('/{vente}/envoyer', [VenteControleur::class, 'envoyer'])->name('envoyer');
            Route::post('/{vente}/convertir-commande', [VenteControleur::class, 'convertirEnCommande'])->name('convertir.commande');
            Route::post('/{vente}/convertir-facture', [VenteControleur::class, 'convertirEnFacture'])->name('convertir.facture');
            // Ce qui rend un devis opposable : son terme, et l'accord du client
            Route::post('/{vente}/accepter', [VenteControleur::class, 'accepterOffre'])->name('accepter');
            Route::post('/{vente}/prolonger', [VenteControleur::class, 'prolongerOffre'])->name('prolonger');
            Route::delete('/{vente}/supprimer', [VenteControleur::class, 'supprimer'])->name('supprimer');
            // Bon de Livraison
            Route::get('/{vente}/livraison/creer', [BonLivraisonControleur::class, 'creerDepuisBC'])->name('livraison.creer');
            Route::post('/{vente}/livraison/enregistrer', [BonLivraisonControleur::class, 'enregistrer'])->name('livraison.enregistrer');
            Route::get('/livraisons', [BonLivraisonControleur::class, 'index'])->name('livraisons');
            Route::get('/livraison/{bl}', [BonLivraisonControleur::class, 'imprimer'])->name('livraison.voir');
            Route::post('/livraison/{bl}/livrer', [BonLivraisonControleur::class, 'marquerLivre'])->name('livraison.livrer');
            Route::post('/livraison/{bl}/facturer', [BonLivraisonControleur::class, 'convertirEnFacture'])->name('livraison.facturer');
        });

        Route::prefix('stock')->name('stock.')->group(function () {
            Route::get('/', [StockControleur::class, 'index'])->name('index');
            Route::get('/mouvements', [StockControleur::class, 'mouvements'])->name('mouvements');
        });

        Route::prefix('tresorerie')->name('tresorerie.')->group(function () {
            Route::get('/encaissements', [TresorerieControleur::class, 'encaissements'])->name('encaissements');
        });

        // ── Banques ──
        Route::post('/banques/creer', [TresorerieControleur::class, 'creerBanqueAjax'])->name('banques.creer');
    });

