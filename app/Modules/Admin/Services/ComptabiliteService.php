<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Vente;
use App\Modules\Admin\Modeles\Achat;
use App\Modules\Admin\Modeles\Client;
use App\Modules\Admin\Modeles\EcritureComptable;
use App\Modules\Admin\Modeles\CodeJournal;
use App\Modules\Admin\Modeles\Entreprise;
use App\Modules\Admin\Modeles\Fournisseur;
use App\Modules\Admin\Modeles\Operation;
use Illuminate\Support\Facades\DB;

/**
 * ComptabiliteService — Moteur d'écritures comptables SYSCOHADA révisé (Côte d'Ivoire)
 *
 * Comptes de référence utilisés (voir config('selflow.plan_comptable_defaut') pour les
 * valeurs par défaut, centralisées et modifiables à un seul endroit) :
 *  411xxx  Clients (compte collectif générique)
 *  401xxx  Fournisseurs (compte collectif générique)
 *  70xxxx  Produits des activités ordinaires (Classe 7)
 *  60xxxx  Achats de marchandises / matières (Classe 6)
 *  443100  État, TVA facturée sur ventes (collectée)
 *  445200  État, TVA déductible sur achats courants
 *  521xxx  Banques (établissements de crédit)
 *  571xxx  Caisse
 *  731100  Variation des stocks de produits fabriqués (production)
 *  603200  Variation des stocks de matières premières
 *
 * ─────────────────────────────────────────────────────────────────────────
 * RÈGLE FONDAMENTALE (corrigée le 22/07/2026 suite audit) :
 *
 * Une vente/achat réglé(e) INTÉGRALEMENT ET IMMÉDIATEMENT au moment de la
 * facturation ne transite JAMAIS par le compte 411/401. Utiliser
 * genererEcrituresVente()/genererEcrituresAchat(), qui décident seules.
 *
 * Chaque écriture générée est rattachée à une Operation (numero_saisie =
 * simple compteur séquentiel par entreprise), et le compte tiers individuel
 * est stocké dans la colonne dédiée compte_tiers — jamais à la place du
 * compte général.
 * ─────────────────────────────────────────────────────────────────────────
 * RÈGLE CODE JOURNAL PAR LIGNE (corrigée le 23/07/2026) :
 *
 * Une vente/achat COMPTANT reste UNE SEULE Opération (même N° Saisie pour
 * toutes ses lignes), mais chaque LIGNE porte le code journal du registre
 * SYSCOHADA auquel elle appartient réellement :
 *   - Lignes Produit(s) + TVA  -> journal VENTE (VTE) / ACHAT (ACH)
 *   - Ligne Caisse/Banque      -> journal de TRÉSORERIE (CAI/BQE...)
 * Avant ce correctif, TOUTES les lignes d'une vente comptant portaient à
 * tort le code du journal de caisse, y compris les lignes de vente/TVA —
 * on ne voyait donc jamais le code VTE sur une vente réglée comptant.
 * ─────────────────────────────────────────────────────────────────────────
 * RÈGLE LIBELLÉS (corrigée le 23/07/2026) :
 *
 * Pour chaque compte mouvementé par le détail d'une facture :
 *   - 1 à 3 produits distincts sur ce compte -> libellé = noms réels des
 *     produits ("Riz Parfumé 25kg, Huile Dinor 5L").
 *   - Plus de 3 produits distincts -> libellé court = intitulé SYSCOHADA du
 *     compte + "..." (ex: "Vente de marchandises..."), et la liste complète
 *     des produits est stockée dans la colonne 'description' de la ligne
 *     (affichable au clic sur les points de suspension côté vue).
 * ─────────────────────────────────────────────────────────────────────────
 */
class ComptabiliteService
{
    // ─────────────────────────────────────────────────────────────────
    // VENTES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Point d'entrée unique pour la facturation d'une vente.
     *
     * **Toute vente passe par le compte client**, qu'elle soit réglée au
     * comptant ou à crédit. Deux faits distincts se sont produits — une
     * créance est née, puis elle a été éteinte — et les confondre en une
     * seule opération « caisse contre produits » coûte trois choses :
     *
     * - le compte du client ne bouge jamais sur ses achats comptant, donc son
     *   relevé ne dit pas ce qu'il a acheté, seulement ce qu'il doit encore ;
     * - le tiers n'est transmis à Comptaflow sur aucune vente comptant, et
     *   l'écriture y retombe sur le seul compte collectif ;
     * - le journal des ventes ne contient pas les ventes comptant, alors que
     *   c'est lui qui justifie le chiffre d'affaires en cas de contrôle.
     *
     * L'écriture de facturation porte donc, au débit, le **net à payer** —
     * TTC fiscal, taxes parafiscales et droit de timbre compris — et le
     * règlement, s'il y en a un, fait l'objet d'une seconde opération.
     */
    public static function genererEcrituresVente(
        Vente $vente,
        float $montantPaye,
        string $modePaiement,
        ?string $date = null,
        ?string $moyenBancaire = null,
        ?string $referencePaiement = null
    ): void {
        $entrepriseId = $vente->pointDeVente->entreprise_id;
        $pdvId = $vente->point_de_vente_id;
        $date = $date ?? ($vente->date_vente ? $vente->date_vente->toDateString() : now()->toDateString());
        $refDoc = $vente->numero_facture;

        // Ce que le client règle réellement : le TTC fiscal, augmente des
        // taxes parafiscales collectees pour l'Etat et du droit de timbre de
        // quittance. `montant_ttc` reste le TTC au sens fiscal — c'est lui qui
        // sert au payload FNE, et il ne doit pas etre confondu avec la somme
        // encaissee.
        $autresTaxes = (float) ($vente->montant_autres_taxes ?? 0);
        $timbre      = round((float) $vente->timbre_quittance, 2);
        $netAPayer   = (float) $vente->montant_ttc + $autresTaxes + $timbre;
        $montantPaye = max(0, min($montantPaye, $netAPayer));

        $codeJournalVente = self::codeJournal($entrepriseId, 'Vente', 'VTE');

        $ventilation = self::ventilationVente($vente);

        // Le libellé ne se construit plus en dur : il vient du gabarit de
        // l'entreprise, dont le défaut reproduit exactement l'ancien texte.
        $jetons = self::jetonsVente($vente, $refDoc, $codeJournalVente, $date, $ventilation);
        $libelleGeneral = LibelleEcritureService::operation($entrepriseId, 'FactureVente', $jetons);

        DB::transaction(function () use (
            $vente, $entrepriseId, $pdvId, $date, $refDoc, $netAPayer, $montantPaye,
            $codeJournalVente, $ventilation, $libelleGeneral, $jetons, $modePaiement,
            $autresTaxes, $timbre, $moyenBancaire, $referencePaiement
        ) {
            $libelle = fn (string $role) => LibelleEcritureService::ligne(
                $entrepriseId, 'FactureVente', $role, $jetons
            );
            $compteClientGeneral = $vente->client?->compte_comptable ?? config('selflow.plan_comptable_defaut.client_collectif');
            $compteClientTiers = self::tiersClient($vente->client, $entrepriseId);

            $opFacture = Operation::creer(
                $entrepriseId, $pdvId, $date, 'FactureVente',
                $codeJournalVente, $refDoc, $libelleGeneral
            );

            self::ligne($opFacture, $entrepriseId, $pdvId, $date, $refDoc, $codeJournalVente,
                $libelle('Facturation Vente'), $compteClientGeneral, null, $compteClientTiers, $netAPayer, 0);

            foreach ($ventilation['comptes'] as $compte => $detailCompte) {
                [$libelleDetail, $description] = self::libelleEtDescriptionDetailCompte(
                    $compte, $detailCompte['produits'], self::TABLE_SYSCOHADA_VENTE, 'Vente suivant détail'
                );
                self::ligne($opFacture, $entrepriseId, $pdvId, $date, $refDoc, $codeJournalVente,
                    $libelle($libelleDetail), null, $compte, null, 0, $detailCompte['montant'], $description);
            }

            foreach ($ventilation['tva'] as $compteTva => $montantTva) {
                self::ligne($opFacture, $entrepriseId, $pdvId, $date, $refDoc, $codeJournalVente,
                    $libelle('TVA Collectée Vente'), null, $compteTva, null, 0, $montantTva);
            }

            if ($autresTaxes > 0) {
                self::ligne($opFacture, $entrepriseId, $pdvId, $date, $refDoc, $codeJournalVente,
                    $libelle('Taxes collectées pour l\'État'), null, config('selflow.plan_comptable_defaut.taxes_collectees'), null, 0, $autresTaxes);
            }

            if ($timbre > 0) {
                self::ligne($opFacture, $entrepriseId, $pdvId, $date, $refDoc, $codeJournalVente,
                    $libelle('Droit de timbre de quittance'), null, config('selflow.plan_comptable_defaut.timbre_quittance'), null, 0, $timbre);
            }

            $opFacture->cloturerEquilibre();

            // ── Le règlement, s'il y en a un : opération distincte ──
            if ($montantPaye > 0) {
                self::genererEcritureReglementVente(
                    $vente, $montantPaye, $modePaiement, $date, $moyenBancaire, $referencePaiement,
                    $montantPaye >= $netAPayer ? 'Règlement à la facturation' : 'Acompte à la facturation'
                );
            }
        });
    }

    /**
     * Génère l'écriture de règlement client pour un encaissement DIFFÉRÉ
     * (postérieur à la facturation) ou pour un acompte encaissé en même
     * temps qu'une facturation à crédit partielle.
     * Débit Caisse/Banque (Montant) vs Crédit Client (Montant)
     */
    public static function genererEcritureReglementVente(
        Vente $vente,
        float $montant,
        string $modePaiement,
        ?string $date = null,
        ?string $moyenBancaire = null,
        ?string $referencePaiement = null,
        ?string $contexte = null
    ): void {
        if ($montant <= 0) return;

        $entrepriseId = $vente->pointDeVente->entreprise_id;
        $pdvId = $vente->point_de_vente_id;
        $date = $date ?? now()->toDateString();
        $refDoc = $vente->numero_facture;

        [$compteFinancier, $codeJournal] = self::compteEtJournalFinancier($entrepriseId, $modePaiement);

        $compteClientGeneral = $vente->client?->compte_comptable ?? config('selflow.plan_comptable_defaut.client_collectif');
        $compteClientTiers = self::tiersClient($vente->client, $entrepriseId);

        [$libelleProduits, $descriptionProduits] = self::libelleEtDescriptionProduits($vente->loadMissing('details.produit')->details);
        $refPaiement = $referencePaiement ?? $vente->reference_paiement;

        // `array_merge` et non `+` : l'union de tableaux garde la valeur de
        // gauche sur une clé déjà présente, et `reference` y vaut `null`. La
        // référence du règlement n'aurait jamais atteint le libellé.
        $jetons = array_merge(
            self::jetonsVente($vente, $refDoc, $codeJournal, $date),
            ['produits' => $libelleProduits, 'reference' => $refPaiement],
        );
        $libellePaiement = LibelleEcritureService::ligne($entrepriseId, 'ReglementVente', '', $jetons);

        // `$contexte` prime quand il est donné : « Acompte à la facturation »
        // dit quelque chose qu'aucun jeton ne saurait produire — le règlement
        // ne solde pas la pièce.
        $libelleOperation = $contexte
            ?? LibelleEcritureService::operation($entrepriseId, 'ReglementVente', $jetons);

        DB::transaction(function () use (
            $entrepriseId, $pdvId, $date, $refDoc, $codeJournal, $compteFinancier,
            $compteClientGeneral, $compteClientTiers, $libellePaiement, $descriptionProduits, $montant, $libelleOperation
        ) {
            $operation = Operation::creer(
                $entrepriseId, $pdvId, $date, 'ReglementVente',
                $codeJournal, $refDoc, $libelleOperation
            );

            self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                $libellePaiement, $compteFinancier, null, null, $montant, 0, $descriptionProduits);

            self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                $libellePaiement, null, $compteClientGeneral, $compteClientTiers, 0, $montant, $descriptionProduits);

            $operation->cloturerEquilibre();

            // Si l'encaissement eteint la creance, le lettrage se pose de
            // lui-meme. Le demander en seconde manipulation reviendrait a ne
            // jamais l'obtenir, et le compte client redeviendrait illisible en
            // trois mois. Un reglement partiel ne lettre rien : le service s'en
            // abstient plutot que de mal faire.
            LettrageService::lettrerLaPiece($entrepriseId, $compteClientGeneral, $refDoc);
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // ACHATS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Point d'entrée unique pour la facturation d'un achat.
     * Symétrique de genererEcrituresVente(), y compris dans sa forme :
     *
     *   1. une opération de facturation au journal des achats, qui crédite
     *      TOUJOURS le compte du fournisseur du montant dû ;
     *   2. une opération de règlement distincte, s'il y a décaissement.
     *
     * L'achat comptant écrivait auparavant une seule opération, « caisse contre
     * charges », sans aucune ligne 401.
     */
    public static function genererEcrituresAchat(
        Achat $achat,
        float $montantPaye,
        string $modePaiement,
        ?string $date = null,
        ?string $moyenBancaire = null,
        ?string $referencePaiement = null
    ): void {
        $entrepriseId = $achat->pointDeVente->entreprise_id;
        $pdvId = $achat->point_de_vente_id;
        $date = $date ?? ($achat->date_achat ? $achat->date_achat->toDateString() : now()->toDateString());
        $refDoc = $achat->numero_facture;
        $ttc = (float) $achat->montant_ttc;
        $montantPaye = max(0, min($montantPaye, $ttc));

        // Le compte et le journal financiers sont désormais résolus par
        // `genererEcritureReglementAchat()`, qui porte seul le décaissement.
        $codeJournalAchat = self::codeJournal($entrepriseId, 'Achat', 'ACH');

        $ventilation = self::ventilationAchat($achat);

        $jetons = self::jetonsAchat($achat, $refDoc, $codeJournalAchat, $date, $ventilation);
        $libelleGeneral = LibelleEcritureService::operation($entrepriseId, 'FactureAchat', $jetons);

        DB::transaction(function () use (
            $achat, $entrepriseId, $pdvId, $date, $refDoc, $ttc, $montantPaye,
            $codeJournalAchat, $ventilation, $libelleGeneral, $jetons, $modePaiement,
            $moyenBancaire, $referencePaiement
        ) {
            $libelle = fn (string $role) => LibelleEcritureService::ligne(
                $entrepriseId, 'FactureAchat', $role, $jetons
            );
            // ── La facturation passe TOUJOURS par le compte du fournisseur ──
            // L'achat comptant écrivait « caisse contre charges », sans aucune
            // ligne 401 : le compte du fournisseur ne bougeait jamais sur ce
            // qu'on lui payait au comptant, son numéro de tiers n'était
            // transmis à Comptaflow sur aucun de ces achats, et le journal des
            // achats ne les contenait pas.
            $compteFournisseurGeneral = $achat->fournisseur?->compte_comptable ?? config('selflow.plan_comptable_defaut.fournisseur_collectif');
            $compteFournisseurTiers = self::tiersFournisseur($achat->fournisseur, $entrepriseId);

            $opFacture = Operation::creer(
                $entrepriseId, $pdvId, $date, 'FactureAchat',
                $codeJournalAchat, $refDoc, $libelleGeneral
            );

            foreach ($ventilation['comptes'] as $compte => $detailCompte) {
                [$libelleDetail, $description] = self::libelleEtDescriptionDetailCompte(
                    $compte, $detailCompte['produits'], self::TABLE_SYSCOHADA_ACHAT, 'Achat suivant détail'
                );
                self::ligne($opFacture, $entrepriseId, $pdvId, $date, $refDoc, $codeJournalAchat,
                    $libelle($libelleDetail), $compte, null, null, $detailCompte['montant'], 0, $description);
            }

            foreach ($ventilation['tva'] as $compteTva => $montantTva) {
                self::ligne($opFacture, $entrepriseId, $pdvId, $date, $refDoc, $codeJournalAchat,
                    $libelle('TVA Déductible Achat'), $compteTva, null, null, $montantTva, 0);
            }

            self::ligne($opFacture, $entrepriseId, $pdvId, $date, $refDoc, $codeJournalAchat,
                $libelle('Facturation Achat'), null, $compteFournisseurGeneral, $compteFournisseurTiers, 0, $ttc);

            $opFacture->cloturerEquilibre();

            // ── Le règlement, s'il y en a un : opération distincte ──
            if ($montantPaye > 0) {
                self::genererEcritureReglementAchat(
                    $achat, $montantPaye, $modePaiement, $date, $moyenBancaire, $referencePaiement,
                    $montantPaye >= $ttc ? 'Règlement à la facturation' : 'Acompte à la facturation'
                );
            }
        });
    }

    /**
     * Génère l'écriture de règlement fournisseur pour un décaissement
     * DIFFÉRÉ ou un acompte encaissé en même temps qu'une facturation
     * à crédit partielle.
     * Débit Fournisseur (Montant) vs Crédit Caisse/Banque (Montant)
     */
    public static function genererEcritureReglementAchat(
        Achat $achat,
        float $montant,
        string $modePaiement,
        ?string $date = null,
        ?string $moyenBancaire = null,
        ?string $referencePaiement = null,
        ?string $contexte = null
    ): void {
        if ($montant <= 0) return;

        $entrepriseId = $achat->pointDeVente->entreprise_id;
        $pdvId = $achat->point_de_vente_id;
        $date = $date ?? now()->toDateString();
        $refDoc = $achat->numero_facture;

        [$compteFinancier, $codeJournal] = self::compteEtJournalFinancier($entrepriseId, $modePaiement);

        $compteFournisseurGeneral = $achat->fournisseur?->compte_comptable ?? config('selflow.plan_comptable_defaut.fournisseur_collectif');
        $compteFournisseurTiers = self::tiersFournisseur($achat->fournisseur, $entrepriseId);

        [$libelleProduits, $descriptionProduits] = self::libelleEtDescriptionProduits($achat->loadMissing('details.produit')->details);
        $refPaiement = $referencePaiement ?? $achat->reference_paiement;

        // `array_merge` et non `+` : l'union de tableaux garde la valeur de
        // gauche sur une clé déjà présente, et `reference` y vaut `null`. La
        // référence du règlement n'aurait jamais atteint le libellé.
        $jetons = array_merge(
            self::jetonsAchat($achat, $refDoc, $codeJournal, $date),
            ['produits' => $libelleProduits, 'reference' => $refPaiement],
        );
        $libellePaiement = LibelleEcritureService::ligne($entrepriseId, 'ReglementAchat', '', $jetons);

        $libelleOperation = $contexte
            ?? LibelleEcritureService::operation($entrepriseId, 'ReglementAchat', $jetons);

        DB::transaction(function () use (
            $entrepriseId, $pdvId, $date, $refDoc, $codeJournal, $compteFinancier,
            $compteFournisseurGeneral, $compteFournisseurTiers, $libellePaiement, $descriptionProduits, $montant, $libelleOperation
        ) {
            $operation = Operation::creer(
                $entrepriseId, $pdvId, $date, 'ReglementAchat',
                $codeJournal, $refDoc, $libelleOperation
            );

            self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                $libellePaiement, $compteFournisseurGeneral, null, $compteFournisseurTiers, $montant, 0, $descriptionProduits);

            self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                $libellePaiement, null, $compteFinancier, null, 0, $montant, $descriptionProduits);

            $operation->cloturerEquilibre();

            // Symetrique du reglement client : si le decaissement eteint la
            // dette, le lettrage se pose de lui-meme.
            LettrageService::lettrerLaPiece($entrepriseId, $compteFournisseurGeneral, $refDoc);
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // PRODUCTION
    // ─────────────────────────────────────────────────────────────────
    //
    // **`genererEcritureProduction()` a été retirée.**
    //
    // Elle écrivait, pour chaque matière consommée, D 603200 / C 311000, et
    // pour le produit fini D 351100 / C 731100. Depuis le lot 4.2, la porte
    // unique du stock écrit déjà ces deux paires : `StockService` appelle
    // `InventairePermanentService` à chaque mouvement. Les deux se cumulaient
    // donc, et **le coût de production ressortait au double** — les matières
    // deux fois en charge, le stock crédité deux fois pour une seule sortie.
    // Sur un atelier qui produit tous les jours, le compte de stock de matières
    // partait en négatif au bilan sans que rien ne le signale.
    //
    // Deux autres défauts partaient avec elle :
    //
    // - **les comptes étaient en dur** — `311000` et `351100` quelle que soit
    //   la famille de l'article. Une brasserie et une boulangerie imputaient au
    //   même compte. `ImputationService` lit désormais la chaîne article →
    //   rayon → défaut ;
    // - **le produit fini entrait à son propre `prix_achat`** — le prix d'achat
    //   d'une chose qu'on ne rachète pas, presque toujours nul. La fabrication
    //   apparaissait en perte sèche : les matières sortaient en charge, et rien
    //   n'entrait en face. Il entre maintenant au coût de ce qui l'a fabriqué,
    //   somme des sorties valorisées au CUMP (Coût Unitaire Moyen Pondéré).

    // ─────────────────────────────────────────────────────────────────
    // AVOIRS
    // ─────────────────────────────────────────────────────────────────

    /**
     * Génère les écritures comptables SYSCOHADA pour un avoir client.
     * Inverse la facturation d'origine : Crédit Client / Débit Vente / Débit TVA.
     */
    public static function genererEcritureAvoirVente(Vente $avoir): void
    {
        $entrepriseId = $avoir->pointDeVente->entreprise_id;
        $pdvId = $avoir->point_de_vente_id;
        $date = $avoir->date_vente ? $avoir->date_vente->toDateString() : now()->toDateString();
        $refDoc = $avoir->numero_facture;
        $codeJournal = self::codeJournal($entrepriseId, 'Vente', 'VTE');

        $compteClientGeneral = $avoir->client?->compte_comptable ?? config('selflow.plan_comptable_defaut.client_collectif');
        $compteClientTiers = self::tiersClient($avoir->client, $entrepriseId);

        $ventilation = self::ventilationVente($avoir);
        $jetons = self::jetonsVente($avoir, $refDoc, $codeJournal, $date, $ventilation);
        $libelleGeneral = LibelleEcritureService::operation($entrepriseId, 'AvoirVente', $jetons);

        DB::transaction(function () use (
            $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
            $compteClientGeneral, $compteClientTiers, $ventilation, $avoir, $jetons, $libelleGeneral
        ) {
            $libelle = fn (string $role) => LibelleEcritureService::ligne(
                $entrepriseId, 'AvoirVente', $role, $jetons
            );

            $operation = Operation::creer(
                $entrepriseId, $pdvId, $date, 'AvoirVente',
                $codeJournal, $refDoc, $libelleGeneral
            );

            self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                $libelle('Facturation Avoir Client'), null, $compteClientGeneral, $compteClientTiers, 0, $avoir->montant_ttc);

            foreach ($ventilation['comptes'] as $compte => $detailCompte) {
                [$libelleDetail, $description] = self::libelleEtDescriptionDetailCompte(
                    $compte, $detailCompte['produits'], self::TABLE_SYSCOHADA_VENTE, 'Avoir Vente'
                );
                self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                    $libelle($libelleDetail), $compte, null, null, $detailCompte['montant'], 0, $description);
            }

            foreach ($ventilation['tva'] as $compteTva => $montantTva) {
                self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                    $libelle('Annulation TVA Collectée'), $compteTva, null, null, $montantTva, 0);
            }

            $operation->cloturerEquilibre();
        });
    }

    /**
     * Génère les écritures comptables SYSCOHADA pour un avoir fournisseur.
     * Inverse la facturation d'origine : Débit Fournisseur / Crédit Achat / Crédit TVA.
     */
    public static function genererEcritureAvoirAchat(Achat $avoir): void
    {
        $entrepriseId = $avoir->pointDeVente->entreprise_id;
        $pdvId = $avoir->point_de_vente_id;
        $date = $avoir->date_achat ? $avoir->date_achat->toDateString() : now()->toDateString();
        $refDoc = $avoir->numero_facture;
        $codeJournal = self::codeJournal($entrepriseId, 'Achat', 'ACH');

        $compteFournisseurGeneral = $avoir->fournisseur?->compte_comptable ?? config('selflow.plan_comptable_defaut.fournisseur_collectif');
        $compteFournisseurTiers = self::tiersFournisseur($avoir->fournisseur, $entrepriseId);

        $ventilation = self::ventilationAchat($avoir);
        $jetons = self::jetonsAchat($avoir, $refDoc, $codeJournal, $date, $ventilation);
        $libelleGeneral = LibelleEcritureService::operation($entrepriseId, 'AvoirAchat', $jetons);

        DB::transaction(function () use (
            $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
            $compteFournisseurGeneral, $compteFournisseurTiers, $ventilation, $avoir, $jetons, $libelleGeneral
        ) {
            $libelle = fn (string $role) => LibelleEcritureService::ligne(
                $entrepriseId, 'AvoirAchat', $role, $jetons
            );

            $operation = Operation::creer(
                $entrepriseId, $pdvId, $date, 'AvoirAchat',
                $codeJournal, $refDoc, $libelleGeneral
            );

            self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                $libelle('Facturation Avoir Fournisseur'), $compteFournisseurGeneral, null, $compteFournisseurTiers, $avoir->montant_ttc, 0);

            foreach ($ventilation['comptes'] as $compte => $detailCompte) {
                [$libelleDetail, $description] = self::libelleEtDescriptionDetailCompte(
                    $compte, $detailCompte['produits'], self::TABLE_SYSCOHADA_ACHAT, 'Avoir Achat'
                );
                self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                    $libelle($libelleDetail), null, $compte, null, 0, $detailCompte['montant'], $description);
            }

            // L'avoir annule la TVA sur les mêmes comptes que la facture : sans
            // cela, une charge de service verrait sa TVA débitée en 4454 et
            // recréditée en 4452, et les deux comptes dériveraient.
            foreach ($ventilation['tva'] as $compteTva => $montantTva) {
                self::ligne($operation, $entrepriseId, $pdvId, $date, $refDoc, $codeJournal,
                    $libelle('Annulation TVA Déductible'), null, $compteTva, null, 0, $montantTva);
            }

            $operation->cloturerEquilibre();
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // HELPERS PUBLICS — libellés pour la trésorerie (appelés par les contrôleurs)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Libellé informatif pour une ligne de TresorerieJournal liée à une vente
     * (remplace l'ancien texte fixe répété identiquement "Vente — Facture X"
     * par un intitulé dérivé du SYSCOHADA, plus parlant).
     */
    public static function libelleTresorerieVente(Vente $vente): string
    {
        $ventilation = self::ventilationVente($vente);
        $libelleGeneral = self::libelleGeneralVente($ventilation);
        return $libelleGeneral . ' — ' . $vente->numero_facture;
    }

    /**
     * Équivalent pour un achat.
     */
    public static function libelleTresorerieAchat(Achat $achat): string
    {
        $ventilation = self::ventilationAchat($achat);
        $libelleGeneral = self::libelleGeneralAchat($ventilation);
        return $libelleGeneral . ' — ' . $achat->numero_facture;
    }

    // ─────────────────────────────────────────────────────────────────
    // HELPERS INTERNES
    // ─────────────────────────────────────────────────────────────────

    /**
     * Le compte de tiers d'un client, ou celui du client de passage.
     *
     * **Une vente sans client nommé laissait `compte_tiers` vide.** Tout se
     * rangeait sur le collectif `411000`, et le grand livre ne distinguait
     * plus les ventes de comptoir des créances d'un client identifié.
     *
     * Le tiers retourné n'est jamais le compte collectif : ce sont deux
     * notions différentes, et les confondre fait remonter, dans le relevé d'un
     * client, le solde de tous les autres.
     */
    private static function tiersClient(?Client $client, int $entrepriseId): ?string
    {
        if ($client?->numero_tiers) {
            return $client->numero_tiers;
        }

        $entreprise = Entreprise::find($entrepriseId);

        return $entreprise ? Client::divers($entreprise)->numero_tiers : null;
    }

    /**
     * Le compte de tiers d'un fournisseur, ou celui du fournisseur occasionnel.
     */
    private static function tiersFournisseur(?Fournisseur $fournisseur, int $entrepriseId): ?string
    {
        if ($fournisseur?->numero_tiers) {
            return $fournisseur->numero_tiers;
        }

        $entreprise = Entreprise::find($entrepriseId);

        return $entreprise ? Fournisseur::divers($entreprise)->numero_tiers : null;
    }

    private const TABLE_SYSCOHADA_VENTE = [
        '701' => 'Vente de marchandises',
        '702' => 'Vente de produits finis',
        '703' => "Vente de produits intermédiaires et résiduels",
        '704' => 'Travaux facturés',
        '705' => 'Services vendus',
        '706' => 'Travaux, services vendus',
        '707' => 'Produits accessoires',
    ];

    private const TABLE_SYSCOHADA_ACHAT = [
        '601' => 'Achat de marchandises',
        '602' => 'Achat de matières premières',
        '604' => 'Achat de fournitures non stockées',
        '605' => 'Autres achats',
        '606' => 'Fournitures non stockables',
    ];

    /**
     * Crée une ligne d'écriture rattachée à une opération.
     * Un seul et unique endroit du code écrit dans ecritures_comptables :
     * garantit que operation_id et compte_tiers sont toujours renseignés
     * cohéremment (jamais de compte tiers écrit à la place du compte général).
     *
     * @param string|null $description Détail complet (ex: liste de tous les
     *   produits) quand le libellé court a été tronqué avec "..." — affiché
     *   côté vue au clic sur le libellé/les points de suspension.
     */
    private static function ligne(
        Operation $operation,
        int $entrepriseId,
        ?int $pdvId,
        string $date,
        ?string $refDoc,
        string $codeJournal,
        string $libelle,
        ?string $compteDebit,
        ?string $compteCredit,
        ?string $compteTiers,
        float $debit,
        float $credit,
        ?string $description = null
    ): void {
        EcritureComptable::create([
            'operation_id'       => $operation->id,
            'entreprise_id'      => $entrepriseId,
            'point_de_vente_id'  => $pdvId,
            'date_ecriture'      => $date,
            'libelle'            => $libelle,
            'description'        => $description,
            'reference_document' => $refDoc,
            'code_journal'       => $codeJournal,
            'compte_debit'       => $compteDebit,
            'compte_credit'      => $compteCredit,
            'compte_tiers'       => $compteTiers,
            'debit'              => $debit,
            'credit'             => $credit,
        ]);
    }

    private static function codeJournal(int $entrepriseId, string $type, string $fallback): string
    {
        return CodeJournal::where('entreprise_id', $entrepriseId)
            ->where('type', $type)
            ->value('code') ?? $fallback;
    }

    /**
     * Détermine le compte financier (caisse/banque) et le code journal
     * correspondant au mode de paiement donné.
     * @return array{0: string, 1: string} [compteFinancier, codeJournal]
     */
    private static function compteEtJournalFinancier(int $entrepriseId, string $modePaiement): array
    {
        $isBanque = str_starts_with(strtolower($modePaiement), 'banque');
        if (!$isBanque) {
            return [config('selflow.plan_comptable_defaut.caisse'), 'CAI'];
        }

        $parts = explode(' : ', $modePaiement);
        $intitule = isset($parts[1]) ? trim($parts[1]) : '';
        $journalObj = CodeJournal::where('entreprise_id', $entrepriseId)
            ->where('type', 'Banque')
            ->where('intitule', $intitule)
            ->first();

        if ($journalObj) {
            return [$journalObj->compte, $journalObj->code];
        }
        // « BQE », comme le journal que toute entreprise recoit a sa creation :
        // un repli qui inventerait un autre code produirait des ecritures
        // rattachees a un journal inexistant.
        return [config('selflow.plan_comptable_defaut.banque_defaut'), 'BQE'];
    }

    /**
     * Le compte de TVA collectée qui correspond à un compte de produit.
     *
     * SYSCOHADA range la TVA facturée selon ce qui a été vendu : la
     * marchandise et le produit fini en 4431, la prestation de services en
     * 4432, les travaux en 4433. La déclaration reprend cette distinction ;
     * tout verser en 4431 la rendait fausse pour une entreprise mixte — un
     * garage qui vend des pièces et facture de la main-d'œuvre, par exemple.
     */
    private static function compteTvaCollectee(string $compteProduit): string
    {
        return match (substr($compteProduit, 0, 3)) {
            '705'   => config('selflow.plan_comptable_defaut.tva_collectee_travaux'),
            '706'   => config('selflow.plan_comptable_defaut.tva_collectee_services'),
            default => config('selflow.plan_comptable_defaut.tva_collectee'),
        };
    }

    /**
     * Le compte de TVA déductible qui correspond à un compte de charge.
     *
     * Symétrique de `compteTvaCollectee()`, et faux de la même façon : tout
     * partait en 4452, « TVA récupérable sur achats », y compris la TVA d'un
     * loyer, d'honoraires ou d'un billet de transport. SYSCOHADA distingue :
     *
     *   445100  sur immobilisations        (comptes 2x)
     *   445200  sur achats                 (comptes 60x)
     *   445300  sur transports             (comptes 61x)
     *   445400  sur services extérieurs et autres charges  (62x, 63x, 64x…)
     *
     * L'état de TVA déductible reprend cette ventilation. Une entreprise qui
     * n'achète que des marchandises ne voyait pas la différence ; un cabinet,
     * dont l'essentiel des charges est en 62 et 63, la voyait entièrement.
     */
    private static function compteTvaDeductible(string $compteCharge): string
    {
        return match (substr($compteCharge, 0, 2)) {
            '60'    => config('selflow.plan_comptable_defaut.tva_deductible'),
            '61'    => config('selflow.plan_comptable_defaut.tva_deductible_transport'),
            '20', '21', '22', '23', '24' => config('selflow.plan_comptable_defaut.tva_deductible_immobilisations'),
            default => config('selflow.plan_comptable_defaut.tva_deductible_services'),
        };
    }

    /**
     * Ventile les lignes d'une vente par compte de produit, avec application
     * de la remise globale au prorata, ventile la TVA par compte de collecte,
     * et conserve la liste des produits par compte (nécessaire pour les
     * libellés intelligents).
     *
     * @return array{comptes: array<string, array{montant: float, produits: array<string>}>, tva: array<string, float>}
     */
    private static function ventilationVente(Vente $vente): array
    {
        // L'imputation lit le rayon de l'article : sans ce chargement, une
        // facture de cinquante lignes declenche cinquante requetes.
        $vente->loadMissing('details.produit.categorieRelation');

        $pourcentageRemise = ($vente->remise > 0 && $vente->montant_ht > 0)
            ? ($vente->remise / $vente->montant_ht)
            : 0;

        $comptes = [];
        $tva = [];
        foreach ($vente->details as $detail) {
            // La remise de ligne s'applique avant la remise globale : c'est
            // l'ordre retenu à la saisie et celui du récapitulatif de la FNE.
            $remiseLigne = (float) ($detail->remise_taux ?? 0);
            $ht = $detail->quantite * $detail->prix_unitaire * (1 - $remiseLigne / 100);
            if ($pourcentageRemise > 0) {
                $ht = $ht - ($ht * $pourcentageRemise);
            }
            // Chaine article -> rayon -> defaut : le rayon manquait, et
            // un article cree apres la souscription tombait sur le
            // compte generique 701000. La balance d'un magasin qui a
            // reparti ses rayons n'avait alors qu'une ligne de ventes.
            $compte = ImputationService::compteVente($detail->produit);

            if ($ht > 0) {
                if (!isset($comptes[$compte])) {
                    $comptes[$compte] = ['montant' => 0, 'produits' => []];
                }
                $comptes[$compte]['montant'] += $ht;
                $nom = $detail->libelle_virtuel ?? $detail->produit?->nom;
                if ($nom) $comptes[$compte]['produits'][] = $nom;
            }

            $tvaLigne = (float) ($detail->montant_tva ?? 0);
            if ($tvaLigne > 0) {
                $compteTva = self::compteTvaCollectee($compte);
                $tva[$compteTva] = ($tva[$compteTva] ?? 0) + $tvaLigne;
            }
        }

        return ['comptes' => $comptes, 'tva' => self::accorderTva($tva, (float) ($vente->montant_tva ?? 0))];
    }

    /**
     * Fait coïncider la TVA ventilée ligne à ligne avec le total porté par la
     * pièce.
     *
     * `montant_tva` est ce que la facture annonce et ce que le payload FNE
     * transmet : c'est lui qui fait foi. La somme des lignes peut s'en écarter
     * de quelques centimes — arrondis, remise globale répartie au prorata — et
     * un écart de deux francs suffit à déséquilibrer l'opération, donc à faire
     * échouer sa clôture. L'écart est reporté sur le compte le plus chargé,
     * là où il est proportionnellement le plus faible.
     *
     * @param  array<string, float>  $ventilee
     * @return array<string, float>
     */
    private static function accorderTva(array $ventilee, float $total, ?string $compteDeRepli = null): array
    {
        $total = round($total, 2);

        if ($total <= 0) {
            return [];
        }

        if ($ventilee === []) {
            // Aucune ligne ne porte de TVA alors que la pièce en annonce :
            // le total part au compte générique, à défaut de savoir mieux.
            return [$compteDeRepli ?? config('selflow.plan_comptable_defaut.tva_collectee') => $total];
        }

        $ventilee = array_map(fn ($m) => round($m, 2), $ventilee);
        $ecart = round($total - array_sum($ventilee), 2);

        if (abs($ecart) >= 0.01) {
            arsort($ventilee);
            $principal = array_key_first($ventilee);
            $ventilee[$principal] = round($ventilee[$principal] + $ecart, 2);
        }

        return array_filter($ventilee, fn ($m) => $m > 0);
    }

    /**
     * Ventile les lignes d'un achat par compte de charge, ventile la TVA par
     * compte de déduction, et conserve la liste des produits par compte.
     * @return array{comptes: array<string, array{montant: float, produits: array<string>}>, tva: array<string, float>}
     */
    private static function ventilationAchat(Achat $achat): array
    {
        $achat->loadMissing('details.produit.categorieRelation');

        // Un bordereau d'achat constate un achat aupres d'un tiers **non
        // immatricule** : il ne facture aucune TVA, et il n'y a donc rien a
        // deduire. Le taux du catalogue s'appliquait pourtant ici, comme il
        // s'appliquait autrefois au document imprime — corrige au lot 1 — et au
        // payload FNE. L'ecriture creditait donc un compte 445 de TVA
        // deductible sur une taxe que personne n'avait payee : ce n'est pas une
        // imprecision comptable, c'est une deduction indue.
        $sansTva = $achat->type_facture === 'bapa';

        $comptes = [];
        $tva = [];
        foreach ($achat->details as $detail) {
            $remiseLigne = (float) ($detail->remise_taux ?? 0);
            $ht = $detail->quantite * $detail->prix_unitaire * (1 - $remiseLigne / 100);
            if ($ht > 0) {
                $compte = ImputationService::compteAchat($detail->produit);
                if (!isset($comptes[$compte])) {
                    $comptes[$compte] = ['montant' => 0, 'produits' => []];
                }
                $comptes[$compte]['montant'] += $ht;
                $nom = $detail->libelle_virtuel ?? $detail->produit?->nom;
                if ($nom) $comptes[$compte]['produits'][] = $nom;

                $tauxTva = $sansTva ? 0 : ($detail->produit?->taux_tva ?? 0);
                if ($tauxTva > 0) {
                    // La TVA déductible suit la nature de la charge, comme la
                    // collectée suit la nature du produit : voir
                    // `compteTvaDeductible()`.
                    $compteTva = self::compteTvaDeductible($compte);
                    $tva[$compteTva] = ($tva[$compteTva] ?? 0) + round($ht * ($tauxTva / 100), 2);
                }
            }
        }

        // Le repli sur `montant_tva` ne doit pas rattraper ce que l'on vient
        // d'ecarter : un bordereau dont la piece porterait une TVA — saisie a
        // tort, ou heritee d'une conversion — la verrait revenir ici.
        $total = $sansTva ? 0.0 : (array_sum($tva) > 0 ? array_sum($tva) : (float) ($achat->montant_tva ?? 0));

        return [
            'comptes' => $comptes,
            'tva'     => self::accorderTva(
                $sansTva ? [] : $tva,
                $total,
                config('selflow.plan_comptable_defaut.tva_deductible')
            ),
        ];
    }

    /**
     * Construit le libellé (court) et la description (détail complet, ou
     * null si inutile) d'une ligne d'écriture portant sur un compte donné :
     *   - 1 à 3 produits distincts  -> libellé = noms réels, pas de description
     *   - 4 produits distincts ou plus -> libellé = intitulé SYSCOHADA + "...",
     *     description = liste complète des produits (affichable au clic)
     *   - Aucun nom de produit disponible -> libellé générique fourni en repli
     * @return array{0: string, 1: ?string}
     */
    private static function libelleEtDescriptionDetailCompte(string $compte, array $nomsProduits, array $tableSyscohada, string $libelleReplis): array
    {
        $noms = array_values(array_unique(array_filter($nomsProduits)));

        if (count($noms) === 0) {
            return [$libelleReplis, null];
        }
        if (count($noms) <= 3) {
            return [implode(', ', $noms), null];
        }

        $prefixe3 = substr($compte, 0, 3);
        $syscohada = $tableSyscohada[$prefixe3] ?? $libelleReplis;
        return [$syscohada . '...', implode(', ', $noms)];
    }

    /**
     * Dérive un libellé général d'opération à partir des comptes de vente
     * mouvementés (classe SYSCOHADA), au lieu d'un texte fixe générique.
     */
    private static function libelleGeneralVente(array $ventilation): string
    {
        return self::libelleGeneralDepuisComptes(array_keys($ventilation['comptes']), self::TABLE_SYSCOHADA_VENTE, 'Vente de marchandises et services');
    }

    /**
     * Les jetons d'une pièce de vente, tels que le gabarit de l'entreprise les
     * attend. `{produits}` et `{reference}` ne sont posés ici que si la
     * ventilation est fournie — le règlement les remplace par les siens, qui
     * portent la liste complète des articles et la référence du paiement.
     *
     * @param  array{comptes: array<string, array{montant: float, produits: array<int, string>}>, tva: array<string, float>}|null  $ventilation
     * @return array<string, string|null>
     */
    private static function jetonsVente(Vente $vente, ?string $refDoc, string $codeJournal, string $date, ?array $ventilation = null): array
    {
        [$produits] = self::libelleEtDescriptionProduits($vente->loadMissing('details.produit')->details);

        return [
            'piece'          => $refDoc,
            'tiers'          => $vente->client?->nom,
            'produits'       => $produits,
            'point_de_vente' => $vente->pointDeVente?->nom,
            'date'           => self::dateLisible($date),
            'nature'         => $ventilation ? self::libelleGeneralVente($ventilation) : null,
            'journal'        => $codeJournal,
            'reference'      => null,
        ];
    }

    /**
     * @param  array{comptes: array<string, array{montant: float, produits: array<int, string>}>, tva: array<string, float>}|null  $ventilation
     * @return array<string, string|null>
     */
    private static function jetonsAchat(Achat $achat, ?string $refDoc, string $codeJournal, string $date, ?array $ventilation = null): array
    {
        [$produits] = self::libelleEtDescriptionProduits($achat->loadMissing('details.produit')->details);

        return [
            'piece'          => $refDoc,
            'tiers'          => $achat->fournisseur?->nom,
            'produits'       => $produits,
            'point_de_vente' => $achat->pointDeVente?->nom,
            'date'           => self::dateLisible($date),
            'nature'         => $ventilation ? self::libelleGeneralAchat($ventilation) : null,
            'journal'        => $codeJournal,
            'reference'      => null,
        ];
    }

    /**
     * Une date d'écriture est stockée en aaaa-mm-jj ; personne ne lit un
     * journal dans ce format. Une valeur illisible est rendue telle quelle
     * plutôt que d'interrompre l'enregistrement d'une vente pour un libellé.
     */
    private static function dateLisible(string $date): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($date)->format('d/m/Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    private static function libelleGeneralAchat(array $ventilation): string
    {
        return self::libelleGeneralDepuisComptes(array_keys($ventilation['comptes']), self::TABLE_SYSCOHADA_ACHAT, 'Achats divers');
    }

    private static function libelleGeneralDepuisComptes(array $comptes, array $table, string $fallbackMixte): string
    {
        if (empty($comptes)) {
            return $fallbackMixte;
        }

        $prefixes = array_unique(array_map(fn($c) => substr((string) $c, 0, 3), $comptes));

        if (count($prefixes) === 1 && isset($table[$prefixes[0]])) {
            return $table[$prefixes[0]];
        }

        return $fallbackMixte;
    }

    /**
     * Construit le libellé (court) et la description (détail complet, ou
     * null) pour une écriture de règlement portant sur plusieurs produits.
     * Même logique de seuil que libelleEtDescriptionDetailCompte() (3 max).
     * @return array{0: string, 1: ?string}
     */
    private static function libelleEtDescriptionProduits($details): array
    {
        $produits = [];
        foreach ($details as $detail) {
            $nom = $detail->libelle_virtuel ?? $detail->produit?->nom;
            if ($nom) $produits[] = $nom;
        }
        $noms = array_values(array_unique($produits));

        if (count($noms) === 0) {
            return ['Marchandises', null];
        }
        if (count($noms) <= 3) {
            return [implode(', ', $noms), null];
        }
        return [count($noms) . ' articles...', implode(', ', $noms)];
    }


    // ─────────────────────────────────────────────────────────────────
    // COMPTAFLOW
    // ─────────────────────────────────────────────────────────────────
    //
    // `synchroniserDepuisComptaflow()` vivait ici. Elle appelait Comptaflow,
    // **recevait** son plan comptable, ses codes journaux et ses tiers, les
    // recopiait dans Selflow, puis **supprimait** toute ligne Selflow marquée
    // `source = comptaflow` absente de la réponse.
    //
    // C'était le contraire de l'architecture voulue : Selflow ne se construit
    // pas sur Comptaflow, il y déverse. Une entreprise dont le comptable
    // n'avait pas encore rempli son plan se retrouvait dépouillée du sien.
    //
    // Le déversement vit désormais dans `DeversementReferentielService`.

}
