<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Entreprise extends Model
{
    use IdentifiantOpaque;

    protected $table = 'entreprises';

    protected $fillable = [
        'nom',
        'forme_juridique',
        'gerant_nom',
        'gerant_prenom',
        'gerant_fonction',
        'adresse',
        'telephone',
        'email',
        'rccm',
        'compte_contribuable',
        'ncc',
        'regime_imposition',
        'centre_impots',
        'ref_bancaire',
        'logo_path',
        'logo_fne_path',
        'quota_points_de_vente',
        'plan_abonnement',
        'secteur_activite',
        'modules_actifs',
        'modules_autorises',
        // La case que l'entreprise coche elle-même dans ses paramètres.
        'comptabilite_activee',
        // `attributions` ne figure PAS ici, et c'est délibéré : c'est ce que
        // le superadministrateur accorde. Assignable en masse, le formulaire
        // des paramètres aurait permis à une entreprise de s'accorder
        // elle-même ce qui ne lui revient pas — le défaut exact de la clé de
        // liaison Comptaflow, corrigé au lot 15.
        'souscription_etape',
        'souscription_terminee_le',
        'activite_autre',
        // La clé de liaison Comptaflow ne figure PAS ici, et c'est délibéré :
        // elle était un champ du formulaire des paramètres, et coller celle
        // d'une autre entreprise ouvrait la liaison vers ses livres. Elle
        // s'écrit désormais par `LiaisonComptaflowService`, qui la reçoit de
        // Comptaflow, et par lui seul. Voir la migration
        // `liaison_comptaflow_delivree_et_non_saisie`.
        'comptaflow_sync_status',
        'comptaflow_last_sync_at',
        'comptaflow_company_id',
        'comptaflow_demande_statut',
        'comptaflow_demande_le',
        'comptaflow_demande_par',
        'comptaflow_refus_motif',
        'comptaflow_liee_le',
        'comptaflow_revoquee_le',
        'comptaflow_cle_indice',
        'comptaflow_cle_tournee_le',
        'comptaflow_rotation_echouee_le',
        // Champs DGI / Fiscal
        'idu',
        'reference_cadastrale',
        'proprietaire_local',
        'commune',
        'quartier',
        'sticker_solde_alerte',
        'fne_sticker_balance',
        // Mode de facturation constaté chez la DGI ('stickers' | 'provision')
        // et solde correspondant. Voir FneService::enregistrerSoldeFne().
        'fne_mode_facturation',
        'fne_solde_provision',
        'fne_solde_maj_at',
        // null tant que la question n'a pas ete posee (voir migration).
        'possede_compte_fne',
        // Certifier des l'emission, ou a la main apres verification. Separes :
        // une boutique peut vouloir verifier ses factures et laisser partir ses
        // tickets de caisse tout seuls.
        'normalisation_auto_factures',
        'normalisation_auto_recus',
        // La forme des numeros de tiers : 411001 ou 411KONE. Chaque cabinet
        // comptable a la sienne.
        'numerotation_tiers',
        'timbre_quittance',
        'bapa',
        'pied_de_page_facture',
        'facture_autres_mentions',
    ];

    protected $casts = [
        'secteur_activite'   => 'array',
        'modules_actifs'     => 'array',
        'modules_autorises'  => 'array',
        'comptabilite_activee' => 'boolean',
        'attributions'       => 'array',
        'souscription_terminee_le' => 'datetime',
        'timbre_quittance'   => 'boolean',
        'bapa'               => 'boolean',
        'sticker_solde_alerte' => 'integer',
        'fne_sticker_balance' => 'integer',
        'fne_solde_provision' => 'decimal:2',
        'fne_solde_maj_at'    => 'datetime',
        'possede_compte_fne'  => 'boolean',
        'normalisation_auto_factures' => 'boolean',
        'normalisation_auto_recus'    => 'boolean',

        // Chiffrée en base : une sauvegarde égarée, ou un accès en lecture à
        // la table, livrait toutes les clés en clair — donc l'écriture dans
        // les livres de chaque entreprise.
        'comptaflow_sync_key'  => 'encrypted',
        'comptaflow_demande_le' => 'datetime',
        'comptaflow_liee_le'    => 'datetime',
        'comptaflow_revoquee_le' => 'datetime',
        'comptaflow_cle_tournee_le' => 'datetime',
        'comptaflow_rotation_echouee_le' => 'datetime',
        'comptaflow_last_sync_at' => 'datetime',
    ];

    // ── La liaison Comptaflow ────────────────────────────────────────

    /** L'entreprise a demandé un dossier comptable ; le superadmin n'a pas tranché. */
    public const DEMANDE_EN_ATTENTE = 'en_attente';

    /** Le superadmin a validé : la clé est délivrée, la liaison ouverte. */
    public const DEMANDE_VALIDEE = 'validee';

    /** Le superadmin a refusé, avec un motif que l'entreprise voit. */
    public const DEMANDE_REFUSEE = 'refusee';

    public function liaisonComptaflowActive(): bool
    {
        return $this->comptaflow_sync_status === 'active'
            && filled($this->comptaflow_sync_key)
            && $this->comptaflow_revoquee_le === null;
    }

    public function demandeComptaflowEnAttente(): bool
    {
        return $this->comptaflow_demande_statut === self::DEMANDE_EN_ATTENTE;
    }

    /**
     * De quoi reconnaître une clé sans la donner.
     *
     * Le superadministrateur doit pouvoir distinguer deux liaisons ; aucun
     * écran ne doit afficher une clé entière, ni pouvoir la copier.
     */
    public function indiceCleComptaflow(): ?string
    {
        return $this->comptaflow_cle_indice ? '••••' . $this->comptaflow_cle_indice : null;
    }

    /**
     * Cette pièce doit-elle être certifiée dès son émission ?
     *
     * Le réglage est distinct pour la facture et pour le reçu. Le défaut est
     * l'automatique, qui était le seul comportement possible jusqu'ici.
     */
    public function normaliseAutomatiquement(\App\Modules\Admin\Modeles\Vente $vente): bool
    {
        $colonne = $vente->estRecu()
            ? 'normalisation_auto_recus'
            : 'normalisation_auto_factures';

        // `null` sur une base dont la migration vient de passer : on retient le
        // comportement d'avant, pour ne rien changer sans qu'on l'ait demandé.
        return $this->{$colonne} ?? true;
    }

    public function pointsDeVente(): HasMany
    {
        return $this->hasMany(PointDeVente::class, 'entreprise_id');
    }

    public function utilisateurs(): HasMany
    {
        return $this->hasMany(\App\Modules\Authentification\Modeles\Utilisateur::class, 'entreprise_id');
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class, 'entreprise_id');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class, 'entreprise_id');
    }

    public function fournisseurs(): HasMany
    {
        return $this->hasMany(Fournisseur::class, 'entreprise_id');
    }

    public function fneCredential(): HasOne
    {
        return $this->hasOne(FneCredential::class, 'entreprise_id');
    }

    /**
     * Les régimes d'imposition, et leur libellé.
     *
     * La liste vivait en dur dans **quatre écrans**, avec quatre contenus
     * différents — et le régime n'est pas une étiquette : `deduireCodeTva()` le
     * compare aux régimes d'exonération légale pour choisir entre TVAC et TVAD.
     * Un sigle qui n'est pas celui du référentiel ne correspond à rien.
     *
     * L'écart le plus coûteux était celui de l'écran du superadministrateur :
     * il proposait « Réel Normal », « Bénéfice Forfaitaire », « Exonéré »… des
     * intitulés que rien ne reconnaît. Une entreprise créée par cette voie et
     * enregistrée « Exonéré » voyait ses lignes à 0 % partir en exonération
     * conventionnelle, quel que soit son régime réel.
     *
     * `REGIMES_EXONERATION_LEGALE`, sur `Produit`, reste gelé : cette liste-ci
     * n'y touche pas, elle fait seulement en sorte que les écrans proposent des
     * valeurs qu'il puisse reconnaître.
     */
    public const REGIMES_IMPOSITION = [
        'TEE' => "TEE — Taxe d'État de l'Entreprenant",
        'TCE' => "TCE — Taxe Communale de l'Entreprenant",
        'RME' => 'RME — Régime des Microentreprises',
        'RNE' => "RNE — Régime du Négoce et de l'Exportation",
        'RSI' => "RSI — Régime Simplifié d'Imposition",
        'RNI' => "RNI — Régime Normal d'Imposition",
    ];

    /**
     * Ce que chaque régime veut dire, en une phrase.
     *
     * L'écran d'inscription portait ces définitions dans son JavaScript, pour
     * quatre régimes sur six. Elles vivent ici pour que les deux écrans de
     * création les affichent, et n'en affichent qu'une version.
     */
    public const REGIMES_NOTICES = [
        'TEE' => "Taxe d'État de l'Entreprenant : pour les très petites entreprises et les auto-entrepreneurs. Taux fixe annuel, pas d'obligation de TVA.",
        'TCE' => "Taxe Communale de l'Entreprenant : la part communale du régime de l'entreprenant, pour les plus petites activités.",
        'RME' => "Régime des Microentreprises : impôt assis sur le chiffre d'affaires, comptabilité allégée.",
        'RNE' => "Régime du Négoce et de l'Exportation : impôt sur le bénéfice, comptabilité simplifiée.",
        'RSI' => "Régime Simplifié d'Imposition : pour les entreprises moyennes. TVA sur option, comptabilité standard.",
        'RNI' => "Régime Normal d'Imposition : TVA obligatoire, comptabilité complète SYSCOHADA.",
    ];

    /**
     * Les régimes qu'un formulaire peut accepter pour CETTE entreprise : le
     * référentiel, plus ce qu'elle porte déjà.
     *
     * Sans ce second terme, une entreprise enregistrée sous l'ancienne liste
     * — « Réel Normal », par exemple — ne pourrait plus enregistrer aucune
     * modification, même sans toucher à son régime. Même raisonnement que
     * `Categorie::domainesAcceptesPour()`.
     *
     * @return array<int, string>
     */
    public static function regimesAcceptesPour(?self $entreprise = null): array
    {
        $codes = array_keys(self::REGIMES_IMPOSITION);

        if ($entreprise?->regime_imposition) {
            $codes[] = $entreprise->regime_imposition;
        }

        return array_values(array_unique($codes));
    }

    /**
     * Les informations que la plateforme FNE exige de l'entreprise.
     *
     * **C'est tout ce que l'entreprise a à fournir.** Les clés d'API et la
     * configuration de la plateforme relèvent du superadministrateur seul ;
     * l'entreprise, elle, déclare si elle a déjà un compte et reporte les
     * informations de son espace — ou rassemble celles qu'il faut pour
     * l'ouvrir.
     *
     * La liste vit ici, et non dans une vue, parce que **deux écrans la
     * lisent** : les paramètres de l'entreprise, qui la font remplir, et le
     * tableau FNE du superadministrateur, qui doit voir d'un coup d'œil à qui
     * il manque quoi avant de configurer une clé. Écrite deux fois, elle aurait
     * divergé au premier champ ajouté.
     *
     * @return array<int, array{champ: string, valeur: ?string, note: string}>
     */
    public function informationsFne(): array
    {
        return [
            ['champ' => 'Raison sociale',
             'valeur' => $this->nom !== '[PENDING_ONBOARDING]' ? $this->nom : null,
             'note' => 'Transmise comme « établissement » à chaque certification.'],
            ['champ' => 'NCC — Numéro de Compte Contribuable',
             'valeur' => $this->ncc,
             'note' => 'Identifie l\'entreprise auprès de la plateforme. Sans lui, rien n\'est certifié.'],
            ['champ' => 'Régime d\'imposition',
             'valeur' => $this->regime_imposition,
             'note' => 'Détermine le code de TVA appliqué aux articles exonérés (TVAC ou TVAD).'],
            ['champ' => 'RCCM',
             'valeur' => $this->rccm,
             'note' => 'Registre du Commerce et du Crédit Mobilier, exigé à l\'inscription.'],
            ['champ' => 'Centre des impôts',
             'valeur' => $this->centre_impots,
             'note' => 'Celui dont dépend l\'entreprise ; figure sur vos documents fiscaux.'],
            ['champ' => 'Adresse de l\'établissement',
             'valeur' => $this->adresse,
             'note' => 'Adresse physique du siège, telle que déclarée à la DGI.'],
            ['champ' => 'Téléphone',
             'valeur' => $this->telephone,
             'note' => 'Contact de l\'entreprise.'],
            ['champ' => 'Adresse e-mail',
             'valeur' => $this->email,
             'note' => 'Reçoit les notifications de la plateforme à chaque facture émise.'],
            ['champ' => 'Gérant : nom, prénom et fonction',
             'valeur' => trim(($this->gerant_nom ?? '') . ' ' . ($this->gerant_prenom ?? '')) ?: null,
             'note' => 'Représentant légal déclaré.'],
            ['champ' => 'Points de vente',
             'valeur' => $this->pointsDeVente()->count() > 0
                 ? $this->pointsDeVente()->count() . ' déclaré(s)'
                 : null,
             'note' => 'Leur nom doit être identique des deux côtés : la FNE refuse une facture dont le point de vente lui est inconnu.'],
        ];
    }

    /**
     * Les champs que le relevé du portail FNE ramène, et que Selflow reprend
     * pour une entreprise qui a déjà un compte (propriétaire, 08/10/2026 :
     * « tout doit être synchronisé »). Ils se grisent à l'écran : l'entreprise
     * n'a pas à les ressaisir.
     *
     * Le seuil d'alerte des stickers se saisit aussi à l'écran : il se grise
     * de même.
     */
    public const CHAMPS_REPRIS_DU_PORTAIL_FNE = [
        'email', 'telephone', 'adresse', 'commune', 'quartier', 'reference_cadastrale',
        'idu', 'proprietaire_local', 'ref_bancaire', 'pied_de_page_facture', 'facture_autres_mentions',
        'sticker_solde_alerte',
    ];

    /**
     * Les options de l'espace FNE que Selflow reprend aussi (propriétaire,
     * 08/10/2026) : c'est l'espace FNE qui fait foi. Le timbre de quittance
     * est requis pour normaliser une facture réglée en espèces ; le BAPA ne
     * gêne pas une entreprise qui n'en fait pas. Elles ne se saisissent pas à
     * l'écran : l'écran dit seulement ce que l'espace FNE a coché.
     */
    public const OPTIONS_REPRISES_DU_PORTAIL_FNE = ['timbre_quittance', 'bapa'];

    /**
     * Où en est la connexion de l'entreprise à la plateforme FNE
     * (propriétaire, 08/10/2026).
     *
     * | Code | Quand |
     * |---|---|
     * | `etablie` | la clé de production est posée et active |
     * | `test` | la clé de test est posée |
     * | `en_cours` | « J'ai déjà un compte », NCC et mot de passe fournis |
     * | `creation_en_cours` | « Je n'en ai pas encore », informations fiscales complètes |
     * | `acces_a_fournir` | « J'ai déjà un compte », NCC ou mot de passe manquant |
     * | `informations_a_completer` | « Je n'en ai pas encore », il manque des informations |
     * | `a_choisir` | la question n'a pas encore reçu de réponse |
     *
     * Les trois derniers appellent une saisie ; les quatre premiers sont des
     * états, que l'écran affiche sans redemander quoi que ce soit.
     *
     * @return array{code: string, libelle: string, detail: string, couleur: string, a_saisir: bool}
     */
    public function etatConnexionFne(): array
    {
        $acces = $this->fneCredential;

        $etat = function (string $code, string $libelle, string $detail, string $couleur, bool $aSaisir = false) {
            return ['code' => $code, 'libelle' => $libelle, 'detail' => $detail, 'couleur' => $couleur, 'a_saisir' => $aSaisir];
        };

        if ($acces && $acces->statut === 'validee' && filled($acces->cle_reelle)) {
            return $etat('etablie', 'Connexion FNE établie',
                'Vos factures sont certifiées par la plateforme de la DGI.', 'vert');
        }

        if ($acces && filled($acces->cle_test)) {
            return $etat('test', 'Connexion FNE en test',
                'Vos factures passent par l\'environnement de test de la DGI, en attendant la mise en production.', 'bleu');
        }

        if ($this->possede_compte_fne === true) {
            $accesConnu = $acces && filled($acces->ncc_associe) && $acces->acces_fourni_at;

            return $accesConnu
                ? $etat('en_cours', 'Connexion FNE en cours',
                    'Vos accès sont reçus. La connexion de test, puis de production, est en cours de mise en place.', 'orange')
                : $etat('acces_a_fournir', 'Accès FNE à renseigner',
                    'Indiquez le NCC et le mot de passe de votre espace FNE.', 'orange', true);
        }

        if ($this->possede_compte_fne === false) {
            return $this->informationsFneManquantes() === 0
                ? $etat('creation_en_cours', 'Création du compte FNE en cours',
                    'Vos informations fiscales sont reçues. Nous ouvrons votre compte auprès de la DGI.', 'orange')
                : $etat('informations_a_completer', 'Informations fiscales à renseigner',
                    'Renseignez vos informations fiscales : elles servent à ouvrir votre compte FNE.', 'orange', true);
        }

        return $etat('a_choisir', 'Compte FNE à préciser',
            'Dites-nous si vous avez déjà un compte sur la plateforme FNE.', 'gris', true);
    }

    /** Combien de ces informations manquent encore. */
    public function informationsFneManquantes(): int
    {
        return collect($this->informationsFne())
            ->filter(fn ($i) => blank($i['valeur']))
            ->count();
    }

    public function estInscriptionComplete(): bool
    {
        return $this->elementsInscriptionManquants() === [];
    }

    /**
     * Ce qui manque pour qu'une pièce puisse partir à la DGI, et où le régler.
     *
     * ── Pourquoi une liste, et non un booléen ──
     *
     * L'écran de blocage disait « Terminer votre inscription avant de
     * continuer. Vous devez renseigner toutes les informations réglementaires »
     * — sans jamais dire **lesquelles**. L'utilisateur arrivait sur une page de
     * paramètres de trois écrans de haut et cherchait ce qui n'allait pas.
     *
     * ── Le point de vente, ajouté à la demande du propriétaire ──
     *
     * Il n'y figurait pas, et c'est le plus déterminant de tous. **Le nom du
     * point de vente est transmis tel quel à la plateforme de la DGI**, qui
     * refuse la facture s'il ne correspond à aucun site déclaré sur l'espace
     * FNE. Une entreprise sans point de vente ne peut donc rien certifier — et
     * l'application le lui laissait découvrir au premier encaissement.
     *
     * Ce qui manquait auparavant se comblait tout seul : la caisse créait un
     * « Siège » à Abidjan, commune Cocody, responsable « Superviseur ». Trois
     * informations inventées, sous un nom qui n'était pas celui de l'espace
     * FNE. La création d'office est retirée ; la réclamation la remplace.
     *
     * @return array<int, array{cle: string, libelle: string, ou: string}>
     */
    public function elementsInscriptionManquants(): array
    {
        // Le nom temporaire de l'inscription par Google : tant qu'il est là,
        // rien d'autre ne vaut la peine d'être demandé.
        if ($this->nom === '[PENDING_ONBOARDING]') {
            return [['cle' => 'nom', 'libelle' => 'Le nom de votre entreprise', 'ou' => 'identite']];
        }

        $manquants = [];

        $aRenseigner = [
            'nom'               => ['Le nom de votre entreprise', 'identite'],
            'gerant_fonction'   => ['La fonction du gérant', 'identite'],
            'adresse'           => ['L\'adresse de l\'entreprise', 'identite'],
            'ncc'               => ['Le NCC — sans lui, aucune pièce n\'est certifiée', 'fiscal'],
            'rccm'              => ['Le RCCM', 'fiscal'],
            // Le compte contribuable (CC) a été retiré des paramètres : il
            // désignait le même numéro que le NCC, saisi deux fois pour rien.
            'regime_imposition' => ['Le régime d\'imposition', 'fiscal'],
        ];

        foreach ($aRenseigner as $champ => [$libelle, $ou]) {
            if (blank($this->{$champ})) {
                $manquants[] = ['cle' => $champ, 'libelle' => $libelle, 'ou' => $ou];
            }
        }

        if (!is_array($this->secteur_activite) || count($this->secteur_activite) === 0) {
            $manquants[] = [
                'cle'     => 'secteur_activite',
                'libelle' => 'Votre domaine d\'activité',
                'ou'      => 'parcours',
            ];
        }

        // Compté à la demande : la question ne se pose qu'aux écrans de
        // blocage, et une requête de plus sur chaque page ne se justifie pas.
        if ($this->exists && $this->pointsDeVente()->count() === 0) {
            $manquants[] = [
                'cle'     => 'point_de_vente',
                'libelle' => 'Au moins un point de vente — son nom part à la DGI avec chaque facture',
                'ou'      => 'points_de_vente',
            ];
        }

        return $manquants;
    }

    /**
     * Profils d'activité auxquels cette entreprise a souscrit.
     *
     * Une activité mixte en cumule plusieurs : une quincaillerie qui livre des
     * chantiers souscrit au profil commerce et au profil BTP.
     */
    public function profils(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Modules\Admin\Modeles\Referentiel\Profil::class,
            'entreprise_profils',
            'entreprise_id',
            'profil_id'
        )->withPivot(['familles_creees', 'articles_crees', 'souscrit_le'])->withTimestamps();
    }

    /**
     * Ce que le superadministrateur peut ouvrir à une entreprise donnée.
     *
     * La clé est ce qui se range dans `attributions`, la valeur ce que l'écran
     * en dit. Un seul élément aujourd'hui ; la liste est faite pour grossir.
     */
    public const ATTRIBUTIONS = [
        'comptabilite'         => 'Comptabilité (base) — plan comptable, journaux, créances, configuration',
        'encaissements'        => 'Encaissements — gestion des encaissements de trésorerie',
        'decaissements'        => 'Décaissements — gestion des décaissements de trésorerie',
        'comptabilite_globale' => 'Opération & écriture globale — saisie et écritures générales',
        'grand_livre'          => 'Grand livre — consultation du grand livre comptable',
        'lettrage'             => 'Lettrage — lettrage des comptes et factures',
        'balance'              => 'Balance de contrôle — accordée à part : la balance se tient dans Comptaflow',
    ];

    /** La balance de contrôle est-elle accordée à cette entreprise ? */
    public function balanceAccordee(): bool
    {
        return $this->aAttribution('balance');
    }

    /** Les encaissements sont-ils accordés par le superadmin ? */
    public function encaissementsAccordes(): bool
    {
        return $this->aAttribution('encaissements');
    }

    /** Les décaissements sont-ils accordés par le superadmin ? */
    public function decaissementsAccordes(): bool
    {
        return $this->aAttribution('decaissements');
    }

    /** L'opération & écriture globale est-elle accordée par le superadmin ? */
    public function ecritureGlobaleAccordee(): bool
    {
        return $this->aAttribution('comptabilite_globale');
    }

    /** Le grand livre est-il accordé par le superadmin ? */
    public function grandLivreAccorde(): bool
    {
        return $this->aAttribution('grand_livre');
    }

    /** Le lettrage est-il accordé par le superadmin ? */
    public function lettrageAccorde(): bool
    {
        return $this->aAttribution('lettrage');
    }

    /**
     * L'entreprise voit-elle ses écrans comptables ?
     *
     * Deux chemins, et un seul suffit :
     *
     * 1. **Elle l'a demandé** — la case « Activer la comptabilité » de ses
     *    paramètres ;
     * 2. **Le superadministrateur le lui a accordé** — l'écran Attributions,
     *    qui passe outre son réglage.
     *
     * Le second ne se déduit pas du premier, et c'est le point : une
     * entreprise qui décoche sa case n'annule pas ce qu'on lui a accordé, et
     * elle ne peut pas s'accorder elle-même ce qui ne lui revient pas.
     *
     * Par défaut, **non**. Selflow est vente, achat, facturation, stock : les
     * numéros de compte, les codes journaux et le plan comptable ne sont pas
     * nécessaires pour établir une facture. La comptabilité intervient pour
     * ceux qui la veulent — et ceux-là ont Comptaflow, qui tient les livres.
     */
    public function comptabiliteOuverte(): bool
    {
        return (bool) $this->comptabilite_activee || $this->aAttribution('comptabilite');
    }

    /**
     * Le superadministrateur a-t-il accordé cette chose à cette entreprise ?
     */
    public function aAttribution(string $attribution): bool
    {
        $accordees = $this->attributions;

        if (is_string($accordees)) {
            $accordees = json_decode($accordees, true);
        }

        return is_array($accordees) && in_array($attribution, $accordees, true);
    }

    /**
     * Modules que l'entreprise a le droit d'activer.
     *
     * C'est le superadmin qui en décide, et il ouvre tout par défaut : une
     * entreprise sans restriction explicite peut tout activer.
     */
    public function modulesAutorises(): array
    {
        $autorises = $this->modules_autorises;

        if (is_string($autorises)) {
            $autorises = json_decode($autorises, true);
        }

        return is_array($autorises) && $autorises !== [] ? $autorises : self::TOUS_LES_MODULES;
    }

    /**
     * Un module ne peut être actif que s'il est autorisé.
     *
     * Les deux notions vivaient dans un seul tableau : personne ne savait si un
     * module absent venait d'un abonnement restreint ou d'une préférence.
     */
    public function moduleEstActif(string $module): bool
    {
        $actifs = $this->modules_actifs;

        if (is_string($actifs)) {
            $actifs = json_decode($actifs, true);
        }

        if (!is_array($actifs) || $actifs === []) {
            $actifs = $this->modulesAutorises();
        }

        return in_array($module, $actifs, true)
            && in_array($module, $this->modulesAutorises(), true);
    }

    /**
     * Ce que tout le monde reçoit, quel que soit le métier.
     *
     * Cette liste vivait **en double** — dans `SouscriptionControleur` pour
     * afficher les cases à cocher, et dans `SouscriptionProfilService` pour
     * écrire `modules_actifs`. Les deux copies avaient dérivé : `points_de_vente`
     * manquait aux deux, et la section disparaissait de la barre latérale
     * sitôt la souscription enregistrée, sans que rien ne l'explique. Une seule
     * liste, désormais.
     */
    public const MODULES_SOCLE = [
        'principal', 'points_de_vente', 'ventes', 'achats',
        'tiers', 'produits', 'rapports', 'comptabilite',
    ];

    /**
     * Ceux qu'on ne décoche pas.
     *
     * `principal` porte le socle. `points_de_vente` porte les sites, **le
     * personnel et les habilitations** : le décocher retirerait à
     * l'administrateur l'écran où il gère ses propres utilisateurs et leurs
     * droits. Personne ne fait ce choix en connaissance de cause.
     */
    public const MODULES_STRUCTURELS = ['principal', 'points_de_vente'];

    public const TOUS_LES_MODULES = [
        'principal', 'ventes', 'achats', 'stock', 'production', 'chantiers',
        'cycles', 'comptabilite', 'points_de_vente', 'produits', 'tiers',
        'rapports', 'b2b', 'fne',
    ];

    /**
     * Le nom que l'utilisateur lit dans sa barre latérale.
     *
     * Les écrans de configuration fabriquaient le leur à partir du code :
     * `ucfirst(str_replace('_', ' ', $module))`. Cela donnait « Comptabilite »
     * sans accent, « Points de vente » par chance, et « Fne » pour la section
     * que le menu appelle « Fiscalité & DGI ». L'utilisateur devait deviner que
     * la case qu'il cochait commandait la section qu'il voyait.
     *
     * Cette liste est la copie de celle des `nav-section` du gabarit. Elles
     * doivent rester d'accord ; une épreuve le vérifie.
     */
    public const LIBELLES_MODULES = [
        'principal'       => 'Tableau de bord',
        'ventes'          => 'Ventes',
        'achats'          => 'Achats',
        'stock'           => 'Stock',
        'production'      => 'Production',
        'chantiers'       => 'Chantiers',
        'cycles'          => 'Cycles agricoles',
        'comptabilite'    => 'Comptabilité',
        'points_de_vente' => 'Points de vente',
        'produits'        => 'Produits',
        'tiers'           => 'Tiers',
        'rapports'        => 'Rapports',
        'b2b'             => 'B2B',
        'fne'             => 'Fiscalité & DGI',
    ];

    public static function libelleModule(string $module): string
    {
        return self::LIBELLES_MODULES[$module] ?? ucfirst(str_replace('_', ' ', $module));
    }
}
