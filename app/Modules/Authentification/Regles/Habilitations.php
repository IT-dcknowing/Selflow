<?php

namespace App\Modules\Authentification\Regles;

/**
 * Quelle habilitation chaque route exige.
 *
 * ## Le défaut que ce fichier corrige
 *
 * Le dictionnaire vivait dans le middleware, et la vérification s'écrivait :
 *
 *     if (isset($correspondances[$route])) { … }
 *
 * **Une route absente du dictionnaire passait donc sans contrôle.** Ce n'était
 * pas une décision, c'était un oubli qui s'aggravait à chaque lot : au moment
 * de l'audit, **quatre-vingt-huit routes** n'y figuraient pas — la balance, le
 * grand livre, le lettrage, l'inventaire, les immobilisations, les
 * consignations, et **l'import**, par lequel on crée des comptes utilisateurs.
 *
 * Le sens est désormais inversé : **ce qui n'est pas classé est refusé.**
 * Oublier une route ferme une porte au lieu d'en ouvrir une, et le test
 * `HabilitationsTest` échoue tant qu'une route nouvelle n'a pas été rangée.
 *
 * ## Les trois catégories
 *
 * - `PAR_ROUTE` — la route exige une habilitation nommée ;
 * - `OUVERTES` — la route est accessible à tout utilisateur admis dans
 *   l'espace, parce qu'elle ne donne accès à rien de plus que ce que
 *   l'utilisateur possède déjà : son profil, la période active, une liste
 *   déroulante. Chaque entrée porte sa raison ;
 * - **le reste** — refusé.
 *
 * Les noms de routes sont donnés sous leur forme `admin.` ; les routes
 * `caissier.` sont ramenées à cette forme avant la recherche, les deux espaces
 * partageant les mêmes écrans.
 */
class Habilitations
{
    /**
     * Les habilitations d'administration de la plateforme.
     *
     * **C'est ce qui remplace l'adresse écrite en dur.** Le middleware laissait
     * passer `superadmin@gmail.com` sans rien vérifier : le privilège tenait à
     * une chaîne dans le dépôt, et le dépôt publiait ainsi l'identité du compte
     * le plus puissant de la plateforme. Le privilège est désormais une donnée
     * — explicite, portée par la fiche, et qu'on peut retirer.
     *
     * @var array<int, string>
     */
    public const PLATEFORME = [
        'tableau_de_bord_superadmin',
        'gestion_entreprises',
        'gestion_comptaflow',
        'gestion_fne',
        'administration_interne',
        'gestion_secteurs_modules',
        'gestion_vitrine',
    ];

    /**
     * Les routes qui exigent une habilitation nommée.
     *
     * @var array<string, string>
     */
    public const PAR_ROUTE = [
        'admin.tableau_de_bord'          => 'tableau_de_bord_personnel',
        'admin.tableau_de_bord_general'  => 'tableau_de_bord_general',

        // ── Ventes ──
        'admin.ventes.nouvelle'                      => 'nouvelle_vente',
        'admin.ventes.enregistrer'                   => 'nouvelle_vente',
        'admin.ventes.factures'                      => 'factures_vente',
        'admin.ventes.historique'                    => 'historique_ventes',
        'admin.ventes.imprimer'                      => 'factures_vente',
        'admin.ventes.ticket'                        => 'factures_vente',
        'admin.ventes.avoir'                         => 'factures_vente',
        'admin.ventes.avoir.creer_nouveau'           => 'factures_vente',
        'admin.ventes.normaliser'                    => 'factures_vente',
        'admin.ventes.modifier'                      => 'factures_vente',
        'admin.ventes.modifier.enregistrer'          => 'factures_vente',
        'admin.ventes.supprimer'                     => 'factures_vente',
        'admin.ventes.confirmer'                     => 'factures_vente',
        'admin.ventes.facturer'                      => 'factures_vente',
        'admin.ventes.envoyer'                       => 'factures_vente',
        'admin.ventes.convertir.commande'            => 'factures_vente',
        'admin.ventes.convertir.facture'             => 'factures_vente',
        'admin.ventes.convertir_piece'               => 'factures_vente',
        'admin.ventes.accepter'                      => 'factures_vente',
        'admin.ventes.prolonger'                     => 'factures_vente',
        'admin.ventes.factures.details'              => 'factures_vente',
        'admin.ventes.factures.rechercher'           => 'factures_vente',
        'admin.ventes.factures.produits_categories'  => 'nouvelle_vente',
        'admin.ventes.livraison.creer'               => 'stock_articles',
        'admin.ventes.livraison.enregistrer'         => 'stock_articles',
        'admin.ventes.livraison.facturer'            => 'factures_vente',
        'admin.ventes.livraison.voir'                => 'stock_articles',
        'admin.ventes.livraison.livrer'              => 'stock_articles',
        'admin.ventes.livraisons'                    => 'stock_articles',

        // ── Achats ──
        'admin.achats.nouveau'                       => 'nouvel_achat',
        'admin.achats.enregistrer'                   => 'nouvel_achat',
        'admin.achats.factures'                      => 'factures_achat',
        'admin.achats.historique'                    => 'historique_achats',
        'admin.achats.imprimer'                      => 'factures_achat',
        'admin.achats.bapa'                          => 'factures_achat',
        'admin.achats.avoir'                         => 'factures_achat',
        'admin.achats.avoir.creer_nouveau'           => 'factures_achat',
        'admin.achats.normaliser'                    => 'factures_achat',
        'admin.achats.modifier'                      => 'factures_achat',
        'admin.achats.modifier.enregistrer'          => 'factures_achat',
        'admin.achats.supprimer'                     => 'factures_achat',
        'admin.achats.confirmer'                     => 'factures_achat',
        'admin.achats.facturer'                      => 'factures_achat',
        'admin.achats.factures.details'              => 'factures_achat',
        'admin.achats.factures.rechercher'           => 'factures_achat',
        'admin.achats.factures.produits_categories'  => 'nouvel_achat',
        // Rattacher une pièce à la plateforme touche une donnée fiscale.
        'admin.achats.fne.attacher'                  => 'factures_achat',
        // Les factures relevées sur le portail sont des factures fournisseurs :
        // elles se lisent et se rattachent avec les autres. Le rattachement
        // n'écrit que dans `portail_fne_factures_recues.achat_id` — il ne crée
        // aucun achat et ne touche à aucune colonne gelée.
        'admin.achats.factures_recues'               => 'factures_achat',
        'admin.achats.factures_recues.rattacher'     => 'factures_achat',
        'admin.achats.factures_recues.detacher'      => 'factures_achat',
        'admin.achats.factures_recues.ecarter'       => 'factures_achat',
        'admin.achats.transmettre_b2b'               => 'nouvel_achat',

        // ── Stock ──
        'admin.stock.index'                  => 'stock_articles',
        'admin.stock.mouvements'             => 'stock_mouvements',
        'admin.stock.rebut'                  => 'stock_articles',
        'admin.stock.rebut.retirer'          => 'stock_articles',
        'admin.stock.inventaire'             => 'stock_articles',
        'admin.stock.inventaire.enregistrer' => 'stock_articles',
        'admin.stock.transferts.index'       => 'stock_articles',
        'admin.stock.transferts.creer'       => 'stock_articles',
        'admin.stock.transferts.valider'     => 'stock_articles',
        'admin.stock.transferts.rejeter'     => 'stock_articles',
        'admin.stock.receptions'             => 'stock_articles',
        'admin.stock.receptions.fiche'       => 'stock_articles',
        'admin.stock.receptions.valider'     => 'stock_articles',
        'admin.stock.livraisons'             => 'stock_articles',
        'admin.stock.livraisons.fiche'       => 'stock_articles',
        'admin.stock.livraisons.valider'     => 'stock_articles',

        // ── Emballages consignés ──
        // Une consignation est une dette de l'entreprise : la constater ou la
        // solder engage le bilan.
        'admin.consignations.index'       => 'stock_articles',
        'admin.consignations.enregistrer' => 'stock_articles',
        'admin.consignations.rendre'      => 'stock_articles',
        'admin.consignations.non_retour'  => 'stock_articles',

        // ── Trésorerie ──
        'admin.tresorerie.encaissements'          => 'tresorerie_encaissements',
        'admin.tresorerie.decaissements'          => 'tresorerie_decaissements',
        'admin.tresorerie.journal'                => 'tresorerie_journal',
        'admin.tresorerie.codes_journaux'         => 'tresorerie_codes_journaux',
        'admin.tresorerie.creer_code_journal'     => 'tresorerie_codes_journaux',
        'admin.tresorerie.poser_journaux_defaut' => 'tresorerie_codes_journaux',
        'admin.tresorerie.supprimer_code_journal' => 'tresorerie_codes_journaux',
        'admin.banques.creer'                     => 'nouvelle_vente',

        // ── Comptabilité ──
        'admin.comptabilite.globale'                => 'comptabilite_globale',
        'admin.comptabilite.creances'               => 'comptabilite_creances',
        'admin.comptabilite.releve_tiers'           => 'comptabilite_creances',
        'admin.comptabilite.reglement'              => 'comptabilite_creances',
        'admin.comptabilite.enregistrer_reglement'  => 'comptabilite_creances',
        'admin.comptabilite.plan_comptable'         => 'comptabilite_plan_comptable',
        'admin.comptabilite.creer_compte_comptable' => 'comptabilite_plan_comptable',
        'admin.comptabilite.poser_plan_defaut'      => 'comptabilite_plan_comptable',
        // La balance et le grand livre montrent le résultat de l'entreprise
        // entière : ils relèvent de la comptabilité globale, non d'un écran de
        // caisse.
        'admin.comptabilite.balance'          => 'comptabilite_globale',
        'admin.comptabilite.grand_livre'      => 'comptabilite_globale',
        'admin.comptabilite.lettrage'         => 'comptabilite_creances',
        'admin.comptabilite.lettrer'          => 'comptabilite_creances',
        'admin.comptabilite.delettrer'        => 'comptabilite_creances',
        // Une écriture manuelle écrit directement au grand livre.
        'admin.comptabilite.ecriture_manuelle' => 'comptabilite_globale',
        // Les gabarits de libellé décident de ce que **toutes** les écritures
        // futures porteront au journal : c'est un réglage de comptabilité, pas
        // un écran de consultation.
        'admin.comptabilite.libelles'             => 'comptabilite_globale',
        'admin.comptabilite.libelles.enregistrer' => 'comptabilite_globale',
        'admin.comptabilite.libelles.apercu'      => 'comptabilite_globale',
        // La ventilation analytique compare les sites entre eux : elle montre
        // le résultat de l'entreprise entière, site par site.
        'admin.comptabilite.analytique' => 'comptabilite_globale',

        // ── Immobilisations ──
        // Le parc et son amortissement portent l'actif du bilan et une charge
        // déductible : cela relève de la comptabilité, non de la caisse.
        'admin.immobilisations.index'       => 'comptabilite_globale',
        'admin.immobilisations.creer'       => 'comptabilite_globale',
        'admin.immobilisations.enregistrer' => 'comptabilite_globale',
        'admin.immobilisations.fiche'       => 'comptabilite_globale',
        'admin.immobilisations.modifier'    => 'comptabilite_globale',
        'admin.immobilisations.ceder'       => 'comptabilite_globale',
        'admin.immobilisations.dotation'    => 'comptabilite_globale',
        'admin.immobilisations.cloturer'    => 'comptabilite_globale',

        // ── Points de vente ──
        'admin.pdv.index'             => 'gestion_pdv',
        'admin.pdv.creer'             => 'gestion_pdv',
        'admin.pdv.modifier'          => 'gestion_pdv',
        'admin.pdv.activer'           => 'gestion_pdv',
        'admin.pdv.activer_apercu'    => 'gestion_pdv',
        'admin.pdv.desactiver_apercu' => 'gestion_pdv',
        'admin.pdv.relever_le_portail'  => 'gestion_pdv',
        'admin.pdv.etat_du_portail'     => 'gestion_pdv',
        'admin.pdv.importer_du_portail' => 'gestion_pdv',
        'admin.pdv.load_file_fne'       => 'gestion_pdv',

        // ── Personnel ──
        'admin.personnel.index'     => 'gestion_personnel',
        'admin.personnel.creer'     => 'gestion_personnel',
        'admin.personnel.details'   => 'gestion_personnel',
        'admin.personnel.modifier'  => 'gestion_personnel',
        'admin.personnel.statut'    => 'gestion_personnel',
        'admin.personnel.supprimer' => 'gestion_personnel',

        // ── Catalogue ──
        'admin.produits.index'             => 'catalogue_produits',
        'admin.produits.creer'             => 'catalogue_produits',
        'admin.produits.modifier'          => 'catalogue_produits',
        'admin.produits.fiche'             => 'catalogue_produits',
        'admin.produits.archiver'          => 'catalogue_produits',
        'admin.produits.description'       => 'catalogue_produits',
        'admin.produits.photo'             => 'catalogue_produits',
        'admin.produits.details.ajouter'   => 'catalogue_produits',
        'admin.produits.details.supprimer' => 'catalogue_produits',
        'admin.produits.calculer_reference' => 'catalogue_produits',

        // ── Production ──
        'admin.production.fiches_techniques.index'               => 'production_recettes',
        'admin.production.fiches_techniques.creer'               => 'production_recettes',
        'admin.production.fiches_techniques.enregistrer'         => 'production_recettes',
        'admin.production.fiches_techniques.modifier'            => 'production_recettes',
        'admin.production.fiches_techniques.modifier.enregistrer' => 'production_recettes',
        'admin.production.fiches_techniques.supprimer'           => 'production_recettes',
        'admin.production.ordres.index'                          => 'production_ordres',
        'admin.production.ordres.creer'                          => 'production_ordres',
        'admin.production.ordres.enregistrer'                    => 'production_ordres',
        'admin.production.ordres.valider'                        => 'production_ordres',

        // ── B2B ──
        'admin.b2b.negociations.client'      => 'nouvel_achat',
        'admin.b2b.negociations.fournisseur' => 'nouvelle_vente',
        'admin.b2b.rfq.creer'                => 'nouvel_achat',
        'admin.b2b.negociation.proposer'     => 'nouvel_achat',
        'admin.b2b.negociation.stock'        => 'nouvelle_vente',
        'admin.b2b.negociation.finaliser'    => 'nouvelle_vente',
        'admin.b2b.achat.accepter'           => 'nouvel_achat',

        // ── Tiers ──
        'admin.clients.index'         => 'tiers_clients',
        'admin.clients.creer'         => 'tiers_clients',
        'admin.clients.modifier'      => 'tiers_clients',
        'admin.clients.supprimer'     => 'tiers_clients',
        'admin.fournisseurs.index'    => 'tiers_fournisseurs',
        'admin.fournisseurs.creer'    => 'tiers_fournisseurs',
        'admin.fournisseurs.modifier' => 'tiers_fournisseurs',
        'admin.fournisseurs.supprimer' => 'tiers_fournisseurs',

        'admin.rapports.analyse_activite' => 'rapports_analyse',

        // ── Import ──
        // **L'import ne figurait nulle part.** Il crée des comptes
        // utilisateurs, des articles, du stock d'ouverture et des
        // immobilisations : c'est le geste le plus lourd de l'application, et
        // il passait sans aucun contrôle d'habilitation.
        'admin.import.exemple'  => 'gestion_personnel',
        'admin.import.preview'  => 'gestion_personnel',
        'admin.import.importer' => 'gestion_personnel',

        // ── Paramètres de l'entreprise ──
        // Ils portent le NCC, le RCCM et le régime d'imposition : ce que la
        // plateforme lit sur chaque facture.
        'admin.entreprise.parametres'             => 'gestion_pdv',
        'admin.entreprise.parametres.enregistrer' => 'gestion_pdv',
        'admin.entreprise.periodes.creer'         => 'comptabilite_globale',
        'admin.entreprise.periodes.cloturer'      => 'comptabilite_globale',
        'admin.entreprise.fne.tester_connexion'   => 'gestion_pdv',
        'admin.entreprise.comptaflow.demander'    => 'comptabilite_globale',
        'admin.entreprise.comptaflow.sync_real'   => 'comptabilite_globale',

        // ── FNE ──
        // Les écrans fiscaux : la normalisation engage l'entreprise devant la
        // DGI, et l'achat de stickers engage sa trésorerie.
        'admin.fne.factures'            => 'factures_vente',
        'admin.fne.factures.donnees'    => 'factures_vente',
        'admin.fne.gestion'             => 'factures_vente',
        'admin.fne.gestion.donnees'     => 'factures_vente',
        'admin.fne.situation'           => 'factures_vente',
        'admin.fne.situation.donnees'   => 'factures_vente',
        'admin.fne.rechercher'          => 'factures_vente',
        'admin.fne.batch_normaliser'    => 'factures_vente',
        'admin.fne.batch_status'        => 'factures_vente',
        'admin.fne.schedule_batch'      => 'factures_vente',
        'admin.fne.config'              => 'gestion_pdv',
        'admin.fne.config.sauvegarder'  => 'gestion_pdv',
        'admin.fne.stickers'            => 'tresorerie_journal',
        'admin.fne.stickers.acheter'    => 'tresorerie_journal',
        // Les pièces refusées par la plateforme se lisent avec les factures.
        'admin.fne.rejets'              => 'factures_vente',
        'admin.fne.rejets.diagnostiquer'=> 'factures_vente',
        'admin.fne.rejets.resoudre'     => 'factures_vente',
        // Sauf « appliquer » et « corriger-maintenant », qui renomment ou
        // rattachent un point de vente. Les ranger avec les factures ouvrirait à
        // qui saisit des ventes une porte latérale vers le renommage des points
        // de vente de l'entreprise.
        'admin.fne.rejets.appliquer'          => 'gestion_pdv',
        'admin.fne.rejets.corriger_maintenant'=> 'gestion_pdv',
        'admin.fne.rejets.corriger_avec'      => 'gestion_pdv',
        'admin.fne.rejets.statut_scraping'    => 'factures_vente',

        // ── Souscription ──
        'admin.souscription.index'       => 'gestion_pdv',
        'admin.souscription.enregistrer' => 'gestion_pdv',
    ];

    /**
     * Les routes ouvertes à tout utilisateur admis dans l'espace.
     *
     * Chacune porte sa raison : sans raison, elle n'a rien à faire ici.
     *
     * @var array<string, string>
     */
    public const OUVERTES = [
        'admin.mon_profil'            => 'Son propre profil : il n\'y a rien de plus à protéger.',
        'admin.mon_profil.enregistrer' => 'Idem — modifier ses propres coordonnées.',
        'admin.periods.switch'        => 'Choisir la période affichée ne donne accès à aucun écran de plus : chaque écran garde son propre contrôle.',
        'admin.onboarding.entreprise_nom' => 'Étape de la première configuration, avant qu\'aucune habilitation ne soit posée.',
        'admin.visite.terminer'       => 'Fermer la visite guidée.',
        'admin.visite.reprendre'      => 'Rouvrir la visite guidée.',
        'admin.visite.rejouer'        => 'Rejouer la visite guidée.',
        'admin.produits.photo.voir'   => 'La photo d\'un article de son entreprise, servie quand `public/storage` n\'est pas posé. Le caissier voit ces images sur son écran de vente sans tenir le catalogue : exiger `catalogue_produits` rendrait 403 (Forbidden — accès interdit) sur chaque carte. Le contrôleur vérifie l\'appartenance à l\'entreprise, et l\'image ne dit rien que la carte ne montre déjà.',
    ];

    /**
     * L'habilitation exigée par une route, ou `null` si la route est ouverte.
     *
     * @throws \RuntimeException si la route n'est classée nulle part
     */
    public static function pour(string $nomRoute): ?string
    {
        $normalisee = self::normaliser($nomRoute);

        if (array_key_exists($normalisee, self::PAR_ROUTE)) {
            return self::PAR_ROUTE[$normalisee];
        }

        if (array_key_exists($normalisee, self::OUVERTES)) {
            return null;
        }

        throw new \RuntimeException("Route non classée : {$normalisee}");
    }

    /**
     * La route est-elle classée, d'une façon ou d'une autre ?
     */
    public static function estClassee(string $nomRoute): bool
    {
        $normalisee = self::normaliser($nomRoute);

        return array_key_exists($normalisee, self::PAR_ROUTE)
            || array_key_exists($normalisee, self::OUVERTES);
    }

    /**
     * Les routes `caissier.` sont les mêmes écrans que les routes `admin.`.
     */
    public static function normaliser(string $nomRoute): string
    {
        return str_starts_with($nomRoute, 'caissier.')
            ? 'admin.' . substr($nomRoute, strlen('caissier.'))
            : $nomRoute;
    }
}
