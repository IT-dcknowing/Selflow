<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Produit extends Model
{
    use IdentifiantOpaque;

    protected $table = 'produits';

    /**
     * Les 6 types d'articles et leurs libellés affichés.
     */
    public const TYPES = [
        'marchandise'               => 'Marchandise (Stockable)',
        'matiere_premiere'          => 'Matière Première',
        'produit_fini'              => 'Produit Fini (Fabriqué)',
        'consommable_stockable'     => 'Consommable Stockable',
        'consommable_non_stockable' => 'Consommable Non Stockable',
        'service'                   => 'Service (Non Physique)',
    ];

    /**
     * Types pour lesquels le stock est géré (décrémenté à la vente / incrémenté à l'achat).
     */
    public const TYPES_STOCKABLES = [
        'marchandise',
        'matiere_premiere',
        'produit_fini',
        'consommable_stockable',
    ];

    /**
     * Unités proposées à la saisie.
     *
     * Ce n'est pas une liste fermée : le champ reste libre et cette énumération
     * n'est qu'une suggestion. Une liste déroulante obligerait à prévoir tous
     * les métiers — le tâcheron facture au « voyage », le vétérinaire à la
     * « tête », l'école à l'« élève » — et le premier conditionnement absent
     * bloquerait la fiche. Ces valeurs sont celles que le référentiel emploie
     * le plus, complétées à l'affichage par celles déjà saisies par
     * l'entreprise.
     */
    public const UNITES_COURANTES = [
        'pièce', 'kg', 'g', 'tonne', 'litre', 'm', 'm²', 'm³',
        'sac', 'carton', 'paquet', 'boîte', 'sachet', 'bouteille', 'bidon',
        'flacon', 'pot', 'rouleau', 'barre', 'lot', 'kit', 'pack', 'casier',
        'heure', 'jour', 'mois', 'an', 'forfait', 'séance', 'intervention',
        'dossier', 'acte', 'examen', 'course', 'voyage', 'page', 'tête',
    ];

    /**
     * L'état rendu par `etatStock()` pour un article qui n'a pas de stock du
     * tout. Il n'est ni « Rupture », ni « Normal » : la question ne se pose pas.
     */
    public const ETAT_SANS_STOCK = 'Sans stock';

    /**
     * Indique si ce produit gère un stock physique.
     */
    public function estStockable(): bool
    {
        return in_array($this->type, self::TYPES_STOCKABLES);
    }

    /**
     * Le même renseignement, lisible depuis une vue ou sérialisé en JSON sans
     * avoir à appeler la méthode. Les écrans de vente en ont besoin sur chaque
     * carte produit.
     */
    public function getEstStockableAttribute(): bool
    {
        return $this->estStockable();
    }

    /**
     * Restreint une requête aux seuls articles qui ont un stock. Les écrans de
     * stock listaient tout le catalogue, services compris.
     */
    public function scopeStockables($query)
    {
        return $query->whereIn('type', self::TYPES_STOCKABLES);
    }

    /**
     * Libellé affiché du type.
     */
    public function libelleType(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    /**
     * Codes TVA de la FNE et leur taux légal
     * (Procedure_technique_integration_API, Annexe 1 : Lexique).
     */
    public const CODES_TVA = [
        'TVA'  => ['taux' => 18.0, 'libelle' => 'TVA — Taux normal 18 %'],
        'TVAB' => ['taux' => 9.0,  'libelle' => 'TVAB — Taux réduit 9 %'],
        'TVAC' => ['taux' => 0.0,  'libelle' => 'TVAC — Exonération conventionnelle 0 %'],
        'TVAD' => ['taux' => 0.0,  'libelle' => 'TVAD — Exonération légale 0 % (TEE, TCE, microentreprise)'],
    ];

    /**
     * Régimes d'imposition pour lesquels une exonération à 0 % relève de
     * l'exonération LÉGALE (TVAD) et non conventionnelle (TVAC).
     *
     * La liste vient de la DGI elle-même. Le lexique de la procédure
     * d'interfaçage décrit TVAD comme « TVA exec leg de 0% pour TEE et RME »,
     * et une facture certifiée le libelle ainsi, mot pour mot :
     *
     *     TVA exo.lég - Pas de TVA sur HT 00,00% - D (TEE, TCE, Microentreprise)
     *
     * Trois régimes, donc : TEE, TCE et le régime des microentreprises (RME).
     * Le code retenait « RNE », qui ne figure dans aucun des deux — vraisem-
     * blablement une confusion avec le reçu normalisé électronique, qui porte
     * le même sigle. Le montant ne changeait pas (les deux codes valent 0 %),
     * mais la facture partait sous une qualification d'exonération que la DGI
     * ne reconnaît pas pour ce régime.
     */
    public const REGIMES_EXONERATION_LEGALE = ['TEE', 'TCE', 'RME'];

    /**
     * Code TVA DGI à transmettre à la FNE pour ce produit.
     *
     * Deux modes, au choix de l'utilisateur sur la fiche produit :
     *  - MANUEL (`code_tva_manuel` = true) : le code saisi fait foi ;
     *  - AUTOMATIQUE (défaut) : le code est déduit du taux de TVA du produit,
     *    et pour un taux à 0 % du régime d'imposition de l'entreprise — un
     *    taux nul ne permettant pas à lui seul de distinguer TVAC de TVAD.
     */
    public function codeTvaFne(?string $regimeImposition = null): string
    {
        if ($this->code_tva_manuel && !empty($this->code_tva)) {
            return array_key_exists($this->code_tva, self::CODES_TVA) ? $this->code_tva : 'TVA';
        }

        $regime = $regimeImposition ?? $this->entreprise?->regime_imposition;

        return self::deduireCodeTva((float) $this->taux_tva, $regime);
    }

    /**
     * Les seuls taux de TVA que la facture normalisée sait représenter.
     *
     * La FNE ne transmet pas un pourcentage mais un code, et la plateforme
     * applique elle-même le taux attaché à ce code. Un taux hors de cette
     * liste — 5 % par exemple — n'a donc aucun code où se ranger.
     */
    public const TAUX_TVA_DGI = [18.0, 9.0, 0.0];

    /**
     * Le taux est-il représentable par un code DGI ?
     *
     * À vérifier avant toute normalisation : sans ce contrôle, un taux
     * inconnu tombait sur le code `TVA`, que la plateforme applique à 18 %.
     * La facture certifiée affichait alors un montant différent de celle
     * établie dans Selflow, sans que rien ne le signale.
     */
    public static function estTauxTvaReconnu(float $taux): bool
    {
        // Le taux d'une ligne est reconstitué depuis les montants enregistrés
        // (TVA / HT), qui sont arrondis au centime. Une tolérance d'un dixième
        // de point absorbe cette dérive sans laisser passer un vrai 5 %.
        foreach (self::TAUX_TVA_DGI as $tauxDgi) {
            if (abs($taux - $tauxDgi) <= 0.1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Déduction automatique du code TVA depuis un taux et un régime.
     *
     * Un taux non reconnu retombe sur `TVA` faute de mieux : c'est un repli
     * d'affichage, jamais une autorisation à transmettre. La conformité se
     * contrôle en amont avec estTauxTvaReconnu().
     */
    public static function deduireCodeTva(float $taux, ?string $regime = null): string
    {
        return match (round($taux, 2)) {
            18.0 => 'TVA',
            9.0  => 'TVAB',
            0.0  => in_array($regime, self::REGIMES_EXONERATION_LEGALE, true) ? 'TVAD' : 'TVAC',
            default => 'TVA',
        };
    }

    protected $fillable = [
        'entreprise_id',
        'reference',
        'nom',
        'type',
        'categorie_id',
        'sous_categorie_id',
        'unite',
        'prix_achat',
        'prix_vente',
        'taux_tva',
        // Champs FNE (DGI)
        'remise_taux',      // remise par défaut du produit, en %
        'code_tva',         // TVA | TVAB | TVAC | TVAD
        'code_tva_manuel',  // false = code déduit du taux et du régime
        'compte_vente',
        'compte_achat',
        'compte_stock',
        'compte_variation',
        // Phase 1 — catalogue enrichi
        'photo',
        'date_arrivee',
        'date_peremption',
        // Le suivi par lot : tous les articles ne le demandent pas. Un sac de
        // ciment n'a pas de date, et imposer un numéro de lot à sa réception
        // ferait perdre du temps sans rien apporter.
        'suivi_par_lot',
        'preavis_peremption',
        // L'emballage consigné : le prix auquel l'article se prête, et le délai
        // au-delà duquel il est réputé perdu.
        'prix_consignation',
        'delai_retour_jours',
        'provenance',
        'description_inventaire',
        'statut',
    ];

    protected function casts(): array
    {
        return [
            'prix_achat'              => 'decimal:2',
            'prix_vente'              => 'decimal:2',
            'taux_tva'                => 'decimal:2',
            'remise_taux'             => 'decimal:2',
            'code_tva_manuel'         => 'boolean',
            'categorie_id'            => 'integer',
            'sous_categorie_id'       => 'integer',
            'date_arrivee'            => 'date',
            'date_peremption'         => 'date',
            'suivi_par_lot'           => 'boolean',
            'preavis_peremption'      => 'integer',
            'prix_consignation'       => 'float',
            'delai_retour_jours'      => 'integer',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($produit) {
            if (empty($produit->reference)) {
                $produit->reference = self::genererReference($produit->entreprise_id, $produit->categorie_id);
            }
        });
    }

    public static function genererReference($entrepriseId, $categorieId): string
    {
        $categorie = Categorie::find($categorieId);
        $prefixe = $categorie ? strtoupper($categorie->prefixe) : 'PROD';
        
        $count = self::where('entreprise_id', $entrepriseId)
            ->where('categorie_id', $categorieId)
            ->count();

        $sequence = $count + 1;

        do {
            $reference = $prefixe . '-' . str_pad($sequence, 5, '0', STR_PAD_LEFT);
            $exists = self::where('entreprise_id', $entrepriseId)
                ->where('reference', $reference)
                ->exists();
            if (!$exists) {
                return $reference;
            }
            $sequence++;
        } while (true);
    }

    // ─── Scopes Phase 1 ──────────────────────────────────────────────────────

    public function scopeActifs($query)
    {
        return $query->where('statut', 'actif');
    }

    public function scopeArchives($query)
    {
        return $query->where('statut', 'archive');
    }

    /**
     * Les articles qu'on peut porter sur une pièce à établir.
     *
     * Archiver un article, c'est dire « je ne le vends plus ». Le mot n'avait
     * pourtant d'effet que sur l'écran du catalogue : la caisse, l'achat, la
     * production, la consignation et le B2B continuaient de le proposer, et
     * l'utilisateur le retrouvait sur une facture après l'avoir rangé.
     *
     * Un seul nom pour la règle, et non `actifs()` répété à dix endroits :
     * quand une raison de plus d'écarter un article apparaîtra — un article
     * périmé, un article suspendu —, elle se posera ici.
     */
    public function scopeSelectionnables($query)
    {
        return $query->where('statut', 'actif');
    }

    /**
     * Les articles qu'un écran de stock doit montrer.
     *
     * Un article archivé qui porte encore de la marchandise n'a pas quitté le
     * magasin. Le taire ferait tomber la valeur de l'inventaire sans que rien
     * ne l'explique, et la quantité resterait dans les écritures : on
     * afficherait un stock qui ne vaut plus ce que la comptabilité en dit.
     * Il reste donc visible tant qu'il n'est pas à zéro.
     */
    public function scopeVisiblesEnStock($query)
    {
        return $query->where(function ($q) {
            $q->where('statut', 'actif')
              ->orWhereHas('stocks', fn ($s) => $s->where('quantite_disponible', '>', 0));
        });
    }

    // ─── Accessor Photo ──────────────────────────────────────────────────────

    public function getPhotoUrlAttribute(): string
    {
        // L'image d'attente était un sac gris, le même sous chaque article :
        // trente cartes identiques où seul le texte distinguait un sac de riz
        // d'une prestation de conseil. À défaut de photo, on montre au moins
        // de quelle nature d'article il s'agit.
        return $this->photoReelle() ?? $this->illustration();
    }

    /**
     * Le dessin qui tient lieu d'image quand l'article n'a pas de photo.
     *
     * Ce n'est pas une photo et cela ne prétend pas l'être — voir
     * `IllustrationArticleService`.
     */
    public function illustration(): string
    {
        return \App\Modules\Admin\Services\IllustrationArticleService::pour($this);
    }

    /**
     * L'adresse de la photo **réelle** de l'article, ou `null` s'il n'en a pas.
     *
     * `photo_url` rend toujours quelque chose — l'image d'attente au besoin —
     * et c'est ce qu'il faut pour une vignette : une case vide serait pire.
     * Mais la carte de l'écran de vente affiche l'image **en arrière-plan** :
     * y poser l'image d'attente couvrirait toutes les cartes d'un même
     * placeholder gris, ce qui n'apprendrait rien et brouillerait le texte.
     * L'appelant doit donc pouvoir distinguer les deux cas.
     */
    public function photoReelle(): ?string
    {
        if (!$this->photo) {
            return null;
        }

        if (str_starts_with($this->photo, 'http://') || str_starts_with($this->photo, 'https://')) {
            return $this->photo;
        }

        if (!\Illuminate\Support\Facades\Storage::disk('public')->exists($this->photo)) {
            return null;
        }

        // `asset('storage/…')` ne vaut que si `public/storage` existe — le lien
        // que pose `php artisan storage:link`. Sans lui, le fichier est bien là
        // sur le disque, `exists()` répond oui, et l'adresse rendue tombe en
        // 404 (Not Found — introuvable). La vignette d'un article ne le montrait
        // pas : son `onerror` basculait sur l'image d'attente, et l'écran
        // paraissait normal. Le fond de carte, lui, n'a pas d'`onerror` : une
        // image de fond introuvable ne laisse rien, sans un mot. C'est
        // exactement ce qui a été constaté — le fond posé au lot 12.4 restait
        // invisible pendant que la liste des articles semblait aller bien.
        //
        // On sert donc par la route quand le lien manque. Elle passe par PHP,
        // donc elle coûte plus cher : on ne l'emprunte que faute de mieux.
        //
        // L'adresse est **relative à la racine** et non bâtie par `asset()` :
        // celui-ci la fabrique depuis `APP_URL`, et une `APP_URL` restée sur
        // l'adresse de développement faisait pointer la photo vers la machine
        // du développeur. Le navigateur, lui, résout `/storage/…` sur l'hôte
        // qu'il consulte.
        return self::lienDeStockagePose()
            ? '/storage/' . ltrim($this->photo, '/')
            : route('admin.produits.photo.voir', $this);
    }

    /**
     * Le lien `public/storage` est-il en place ?
     *
     * Retenu pour la durée de la requête : l'écran de vente pose la question
     * une fois par article, et un accès disque par carte n'apprendrait rien de
     * neuf.
     */
    private static ?bool $lienDeStockage = null;

    private static function lienDeStockagePose(): bool
    {
        return self::$lienDeStockage ??= file_exists(public_path('storage'));
    }

    /**
     * Oublier ce qu'on croit savoir du lien de stockage.
     *
     * La réponse est retenue pour la durée de la requête ; les épreuves, elles,
     * posent et retirent le lien dans un même processus. Sans cette porte, la
     * première d'entre elles fixerait la réponse pour toutes les suivantes.
     */
    public static function oublierLeLienDeStockage(): void
    {
        self::$lienDeStockage = null;
    }

    // ─── Helpers Phase 1 ─────────────────────────────────────────────────────

    public function estArchive(): bool
    {
        return $this->statut === 'archive';
    }

    /**
     * La date portée par la fiche article a-t-elle été dépassée ?
     *
     * **Cette date-là est celle de l'article, non celle d'un arrivage.** Une
     * pharmacie qui reçoit trois lots de paracétamol n'en enregistre qu'un ici :
     * la saisie du troisième écrase les deux premiers. Le suivi juste passe par
     * `lots` et `LotService` ; ces deux méthodes restent pour les articles qui
     * ne sont pas suivis par lot, où une date unique suffit.
     *
     * Le jour de péremption est compris : un produit au 30 se vend le 30, comme
     * le dit la mention « à consommer jusqu'au ».
     */
    public function estPerime(): bool
    {
        return $this->date_peremption !== null && $this->date_peremption->endOfDay()->isPast();
    }

    /**
     * La date approche-t-elle ?
     *
     * **Le calcul d'origine était faux :**
     *
     *     $this->date_peremption->diffInDays(now()) <= $joursAlerte
     *
     * `diffInDays()` rend une différence **signée** — une date future donne un
     * nombre négatif. `-200 <= 30` étant vrai, la comparaison l'était pour
     * *toutes* les dates à venir, quelle que soit leur distance : l'écran des
     * rebuts annonçait le catalogue entier comme proche de la péremption. Une
     * alerte qui crie tout le temps ne se lit plus, et les vraies échéances
     * passaient avec les autres.
     */
    public function bientotPerime(?int $joursAlerte = null): bool
    {
        if ($this->date_peremption === null || $this->estPerime()) {
            return false;
        }

        $preavis = $joursAlerte ?? (int) ($this->preavis_peremption ?? 30);

        return now()->startOfDay()->diffInDays($this->date_peremption->startOfDay(), false) <= $preavis;
    }

    /**
     * Les arrivages de cet article, tous sites confondus.
     */
    public function lots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Lot::class, 'produit_id');
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function detailsLibres(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProduitDetailLibre::class, 'produit_id')->orderBy('ordre');
    }

    /**
     * Taxes personnalisées du produit (GRA, AIRSI...) transmises à la FNE
     * dans `items[].customTaxes`.
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(ProduitTaxe::class, 'produit_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Categorie::class, 'categorie_id');
    }

    /**
     * Catégorie du produit.
     *
     * Le suffixe est nécessaire : une colonne `categorie` — l'ancien libellé
     * libre — porte déjà ce nom, et une relation homonyme serait masquée par
     * elle sans que rien ne le signale.
     */
    public function categorieRelation(): BelongsTo
    {
        return $this->belongsTo(Categorie::class, 'categorie_id');
    }

    public function sousCategorieRelation(): BelongsTo
    {
        return $this->belongsTo(SousCategorie::class, 'sous_categorie_id');
    }

    /**
     * Accesseurs de compatibilité pour l'ancienne valeur texte
     */
    public function getCategorieAttribute(): string
    {
        return $this->category ? $this->category->nom : '';
    }

    public function getSousCategorieAttribute(): string
    {
        return $this->sousCategorieRelation ? $this->sousCategorieRelation->nom : '';
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'produit_id');
    }

    /**
     * Quantité disponible de l'article sur un site donné.
     *
     * Lue depuis la collection déjà chargée quand elle l'est, pour qu'un
     * tableau de deux cents lignes ne déclenche pas deux cents requêtes. Un
     * article sans fiche sur ce site vaut zéro : l'absence de fiche est une
     * absence de stock, pas une erreur.
     */
    public function stockSur(int $pointDeVenteId): float
    {
        $fiche = $this->relationLoaded('stocks')
            ? $this->stocks->firstWhere('point_de_vente_id', $pointDeVenteId)
            : $this->stocks()->where('point_de_vente_id', $pointDeVenteId)->first();

        return (float) ($fiche->quantite_disponible ?? 0);
    }

    public function venteDetails(): HasMany
    {
        return $this->hasMany(VenteDetail::class, 'produit_id');
    }

    public function achatDetails(): HasMany
    {
        return $this->hasMany(AchatDetail::class, 'produit_id');
    }

    public function mouvementsStock(): HasMany
    {
        return $this->hasMany(MouvementStock::class, 'produit_id');
    }

    public function ficheTechnique(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(FicheTechnique::class, 'produit_fini_id');
    }

    /**
     * Obtenir la quantité disponible pour un point de vente donné.
     *
     * En `float` : les quantités se comptent en kilos, en litres et en mètres
     * carrés autant qu'en pièces. Le type de retour était `int`, et PHP
     * tronquait 12,5 kg à 12 au passage — silencieusement, à la lecture, même
     * quand la base tenait la bonne valeur.
     */
    public function stockActuel($pointDeVenteId): float
    {
        $stock = $this->stocks->where('point_de_vente_id', $pointDeVenteId)->first();
        return (float) ($stock->quantite_disponible ?? 0);
    }

    /**
     * Obtenir le stock minimum pour un point de vente donné.
     */
    public function stockMinimum($pointDeVenteId): float
    {
        $stock = $this->stocks->where('point_de_vente_id', $pointDeVenteId)->first();
        return (float) ($stock->stock_minimum ?? 5);
    }

    /**
     * Obtenir le stock maximum pour un point de vente donné.
     */
    public function stockMaximum($pointDeVenteId): float
    {
        $stock = $this->stocks->where('point_de_vente_id', $pointDeVenteId)->first();
        return (float) ($stock->stock_maximum ?? 100);
    }

    /**
     * Accesseurs dynamiques de compatibilité basés sur le point de vente actif.
     */
    public static function getActivePdvId(): ?int
    {
        if (session()->has('point_de_vente_actif_id')) {
            return session('point_de_vente_actif_id');
        }
        if (auth()->check()) {
            if (auth()->user()->point_de_vente_id) {
                session([
                    'point_de_vente_actif_id' => auth()->user()->point_de_vente_id,
                    'point_de_vente_actif_nom' => optional(auth()->user()->pointDeVente)->nom ?? 'Siège'
                ]);
                return auth()->user()->point_de_vente_id;
            }
            $pdv = \App\Modules\Admin\Modeles\PointDeVente::where('entreprise_id', auth()->user()->entreprise_id)
                ->where('nom', 'Siège')
                ->first() 
                ?? \App\Modules\Admin\Modeles\PointDeVente::where('entreprise_id', auth()->user()->entreprise_id)->first();
            if ($pdv) {
                session([
                    'point_de_vente_actif_id' => $pdv->id,
                    'point_de_vente_actif_nom' => $pdv->nom
                ]);
                return $pdv->id;
            }
        }
        return null;
    }

    public function getStockActuelAttribute(): float
    {
        $pdvId = self::getActivePdvId();
        if (!$pdvId) {
            $stock = $this->stocks->first();
            return (float) ($stock->quantite_disponible ?? 0);
        }
        return $this->stockActuel($pdvId);
    }

    public function getStockMinimumAttribute(): float
    {
        $pdvId = self::getActivePdvId();
        if (!$pdvId) {
            $stock = $this->stocks->first();
            return (float) ($stock->stock_minimum ?? 5);
        }
        return $this->stockMinimum($pdvId);
    }

    public function setStockActuelAttribute($value): void
    {
        $pdvId = self::getActivePdvId();
        if ($pdvId) {
            Stock::updateOrCreate([
                'produit_id'        => $this->id,
                'point_de_vente_id' => $pdvId,
            ], [
                'quantite_disponible' => $value,
            ]);
        }
    }

    public function setStockMinimumAttribute($value): void
    {
        $pdvId = self::getActivePdvId();
        if ($pdvId) {
            Stock::updateOrCreate([
                'produit_id'        => $this->id,
                'point_de_vente_id' => $pdvId,
            ], [
                'stock_minimum' => $value,
            ]);
        }
    }

    /**
     * Incrémenter le stock pour un point de vente donné.
     *
     * @deprecated Passez par `StockService::entree()`.
     *
     * Cette méthode touche la fiche sans écrire au journal et sans verrou :
     * c'est la moitié d'un geste qui doit en compter trois. Les douze copies
     * du couple « décrémenter puis journaliser » qui parsemaient les
     * contrôleurs partaient d'ici. Elle ne subsiste que pour le jeu de données
     * de démonstration, qui pose des stocks de départ sans histoire à raconter.
     */
    public function incrementStock($pointDeVenteId, $quantite): void
    {
        $stock = Stock::firstOrCreate([
            'produit_id'        => $this->id,
            'point_de_vente_id' => $pointDeVenteId,
        ], [
            'quantite_disponible' => 0,
            'stock_minimum'       => 5,
            'stock_maximum'       => 100,
        ]);

        $stock->increment('quantite_disponible', $quantite);
    }

    /**
     * Décrémenter le stock pour un point de vente donné.
     *
     * @deprecated Passez par `StockService::sortie()`. Voir `incrementStock()`.
     */
    public function decrementStock($pointDeVenteId, $quantite): void
    {
        $stock = Stock::firstOrCreate([
            'produit_id'        => $this->id,
            'point_de_vente_id' => $pointDeVenteId,
        ], [
            'quantite_disponible' => 0,
            'stock_minimum'       => 5,
            'stock_maximum'       => 100,
        ]);

        $stock->decrement('quantite_disponible', $quantite);
    }

    /**
     * Quantité engagée par des bons de commande client, sur un site.
     *
     * Déduite des lignes, jamais stockée. Une colonne
     * `produits.quantite_commandee` existait, s'affichait sur trois écrans et
     * entrait dans le prévisionnel — mais **rien ne l'écrivait jamais**. Un
     * compteur dénormalisé doit être incrémenté à la commande, décrémenté à la
     * livraison, corrigé à l'annulation, à la modification et à l'avoir : cinq
     * occasions de dériver, aucune traitée. Une valeur déduite ne dérive pas.
     *
     * Un devis n'engage rien : c'est une proposition, le client peut ne jamais
     * la retourner. Seul le bon de commande engage.
     */
    public function quantiteCommandee(?int $pointDeVenteId = null): float
    {
        return (float) $this->venteDetails()
            ->whereColumn('quantite', '>', 'quantite_livree')
            ->whereHas('vente', function ($q) use ($pointDeVenteId) {
                $q->where('etape', 'Bon de commande')
                  ->when($pointDeVenteId, fn ($r) => $r->where('point_de_vente_id', $pointDeVenteId));
            })
            ->sum(DB::raw('quantite - quantite_livree'));
    }

    /**
     * Quantité attendue de fournisseurs, sur un site. Voir
     * `quantiteCommandee()` : même raisonnement, sens inverse.
     */
    public function quantiteAReceptionner(?int $pointDeVenteId = null): float
    {
        return (float) $this->achatDetails()
            ->whereColumn('quantite', '>', 'quantite_receptionnee')
            ->whereHas('achat', function ($q) use ($pointDeVenteId) {
                $q->where('etape', 'Bon de commande')
                  ->when($pointDeVenteId, fn ($r) => $r->where('point_de_vente_id', $pointDeVenteId));
            })
            ->sum(DB::raw('quantite - quantite_receptionnee'));
    }

    public function getQuantiteCommandeeAttribute(): float
    {
        return $this->quantiteCommandee(self::getActivePdvId());
    }

    public function getQuantiteAReceptionnerAttribute(): float
    {
        return $this->quantiteAReceptionner(self::getActivePdvId());
    }

    /**
     * Ce dont on disposera une fois les engagements dénoués :
     * disponible − commandé par les clients + attendu des fournisseurs.
     */
    public function getPrevisionAttribute(): float
    {
        return $this->stock_actuel - $this->quantite_commandee + $this->quantite_a_receptionner;
    }

    /**
     * Détermine l'état du stock de l'article.
     *
     * Un service ou un consommable non stockable n'a pas de stock : il ne peut
     * donc pas être en rupture. L'écran de vente affichait pourtant « Rupture
     * de stock » sous chaque prestation, et ouvrait une alerte à chaque ligne —
     * un cabinet, qui ne vend que des services, voyait son catalogue entier en
     * rouge et une modale par article. Le contrôle serveur, lui, écartait déjà
     * ces articles : rien n'était jamais bloqué, mais rien ne le disait.
     */
    public function etatStock(): string
    {
        if (!$this->estStockable()) {
            return self::ETAT_SANS_STOCK;
        }
        if ($this->stock_actuel <= 0) {
            return 'Rupture';
        }
        if ($this->stock_actuel <= $this->stock_minimum) {
            return 'Faible';
        }
        return 'Normal';
    }
}
