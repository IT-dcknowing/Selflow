<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Le journal des mouvements de stock.
 *
 * **Il ne s'efface pas.** La sortie de dix sacs a eu lieu ; si elle était
 * erronée, c'est une entrée de dix sacs qui la corrige, et les deux lignes
 * restent lisibles. `VenteControleur` supprimait les mouvements d'une vente
 * qu'on modifiait — le stock revenait juste, mais l'histoire disparaissait, et
 * avec elle toute chance d'expliquer un écart d'inventaire six mois plus tard.
 *
 * Le modèle refuse donc la suppression et la modification des colonnes qui font
 * foi. Ce n'est pas une précaution de style : le contrôleur fautif est encore
 * dans le dépôt, et rien d'autre ne l'empêcherait de recommencer.
 */
class MouvementStock extends Model
{
    protected $table = 'mouvements_stock';

    /** Sens du mouvement. L'écran compare la chaîne exacte, accent compris. */
    public const ENTREE = 'Entrée';
    public const SORTIE = 'Sortie';

    /**
     * Les portes par lesquelles le stock bouge.
     *
     * En minuscules et sans accent : l'écran des mouvements comparait déjà en
     * minuscules, les requêtes de filtre comparaient la casse d'origine, et
     * `ProductionControleur` écrivait `production_consommation` quand
     * `StockControleur` écrivait `Reception`. Une seule forme, décidée ici.
     */
    public const RECEPTION              = 'reception';
    public const RETOUR_CLIENT          = 'retour_client';
    public const RETOUR_FOURNISSEUR     = 'retour_fournisseur';
    public const LIVRAISON              = 'livraison';
    public const TRANSFERT              = 'transfert';
    public const REBUT                  = 'rebut';
    public const INVENTAIRE             = 'inventaire';
    public const PRODUCTION_ENTREE      = 'production_entree';
    public const PRODUCTION_CONSOMMATION = 'production_consommation';
    public const CONTREPASSATION        = 'contrepassation';
    /**
     * Ce qu'il y avait en rayon le jour où l'article est entré au catalogue.
     *
     * Cette quantité était écrite **directement** dans `stocks`, sans passer
     * par le service : aucun mouvement ne la portait, le journal ne la montrait
     * pas, et le CUMP (Coût Unitaire Moyen Pondéré) restait à zéro — si bien
     * que la première sortie valorisait la marchandise à rien.
     */
    public const STOCK_INITIAL          = 'stock_initial';

    /** @var array<int, string> */
    public const MOTIFS = [
        self::RECEPTION, self::RETOUR_CLIENT, self::RETOUR_FOURNISSEUR,
        self::LIVRAISON, self::TRANSFERT, self::REBUT,
        self::INVENTAIRE, self::PRODUCTION_ENTREE, self::PRODUCTION_CONSOMMATION,
        self::CONTREPASSATION, self::STOCK_INITIAL,
    ];

    protected $fillable = [
        'produit_id',
        'point_de_vente_id',
        'type_mouvement',
        'sous_type',
        'point_de_vente_contrepartie_id',
        'utilisateur_id',
        'fournisseur_id',
        'client_id',
        'quantite',
        'stock_avant',
        'stock_apres',
        'cout_unitaire',
        'cump_apres',
        'reference_document',
        'piece_type',
        'piece_id',
        'contrepasse_id',
    ];

    /**
     * Quantités en `float` : mêmes raisons que sur `Stock`, la colonne
     * `decimal(15,3)` faisant foi en base.
     */
    protected function casts(): array
    {
        return [
            'quantite'    => 'float',
            'stock_avant' => 'float',
            'stock_apres' => 'float',
            'cout_unitaire' => 'float',
            'cump_apres'    => 'float',
        ];
    }

    /**
     * Les colonnes qui font foi : une fois écrites, elles ne bougent plus.
     *
     * `reference_document` n'en fait pas partie — c'est un libellé d'affichage,
     * qu'on peut vouloir corriger sans réécrire l'histoire. `contrepasse_id`
     * non plus : il se pose au moment où la contre-passation est créée.
     *
     * @var array<int, string>
     */
    private const IMMUABLES = [
        'produit_id', 'point_de_vente_id', 'type_mouvement', 'sous_type',
        'quantite', 'stock_avant', 'stock_apres', 'piece_type', 'piece_id',
        'cout_unitaire', 'cump_apres',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $mouvement) {
            throw new \LogicException(
                "Un mouvement de stock ne se supprime pas (mouvement #{$mouvement->id}). "
                . 'Utilisez StockService::contrePasser() : la ligne fautive reste, '
                . 'et une écriture de sens inverse la corrige.'
            );
        });

        static::updating(function (self $mouvement) {
            $touchees = array_intersect(array_keys($mouvement->getDirty()), self::IMMUABLES);

            if ($touchees !== []) {
                throw new \LogicException(
                    "Un mouvement de stock ne se réécrit pas (mouvement #{$mouvement->id}, "
                    . 'colonnes ' . implode(', ', $touchees) . '). '
                    . 'Utilisez StockService::contrePasser().'
                );
            }
        });
    }

    /**
     * La pièce qui a produit le mouvement : vente, achat, ordre de production,
     * transfert. Nulle pour un ajustement d'inventaire, qui n'a d'autre pièce
     * que lui-même.
     */
    public function piece(): MorphTo
    {
        return $this->morphTo('piece');
    }

    /** Le mouvement que celui-ci annule. */
    public function contrepasse(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contrepasse_id');
    }

    /** La contre-passation qui annule celui-ci, s'il y en a une. */
    public function contrepassePar()
    {
        return $this->hasOne(self::class, 'contrepasse_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    /**
     * Les arrivages que ce mouvement a touchés.
     *
     * Le mouvement reste **un seul mouvement** — la comptabilité, le CUMP
     * (Coût Unitaire Moyen Pondéré) et le journal ne changent pas. Une sortie
     * de trente unités prise sur deux arrivages écrit donc une ligne de journal
     * et deux lignes ici. C'est ce qui permet de répondre à un rappel du
     * fabricant sans réécrire l'inventaire permanent.
     */
    public function lots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MouvementLot::class, 'mouvement_stock_id');
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }

    /**
     * L'autre site du mouvement, sur un transfert.
     *
     * La colonne s'appelait `point_de_vente_source_id` et recevait la
     * destination sur la sortie, la source sur l'entrée : elle a toujours porté
     * la contrepartie, jamais la source.
     */
    public function pointDeVenteContrepartie(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_contrepartie_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
