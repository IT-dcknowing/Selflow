<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categorie extends Model
{
    protected $table = 'categories';

    protected $fillable = [
        'entreprise_id',
        // Les quatre comptes du rayon. C'est ici que se lit la regle metier :
        // « tout ce qui entre dans Boissons fraiches s'impute la ».
        'compte_vente',
        'compte_achat',
        'compte_stock',
        'compte_variation',
        'nom',
        'prefixe',
    ];

    /**
     * Le type d'article du référentiel qui correspond à chaque type stockable
     * du catalogue — l'inverse de `TypeArticle::typeProduit()`.
     */
    private const TYPE_REFERENTIEL = [
        'marchandise'           => 'MARCHANDISE',
        'matiere_premiere'      => 'MATIERE_PREMIERE',
        'produit_fini'          => 'PRODUIT_FINI',
        'consommable_stockable' => 'CONSOMMABLE',
    ];

    /**
     * Les racines du référentiel (`types_articles.json`), pour le cas où sa
     * table ne serait pas chargée : marchandises en 31 / 6031, matières en
     * 32 / 6032, consommables en 33 / 6033, produits finis en 36 / 736.
     */
    private const COMPTES_DE_STOCK_PAR_DEFAUT = [
        'marchandise'           => ['compte_stock' => '310000', 'compte_variation' => '603100'],
        'matiere_premiere'      => ['compte_stock' => '320000', 'compte_variation' => '603200'],
        'consommable_stockable' => ['compte_stock' => '330000', 'compte_variation' => '603300'],
        'produit_fini'          => ['compte_stock' => '360000', 'compte_variation' => '736000'],
    ];

    /**
     * Les comptes de stock et de variation qu'un rayon reçoit à sa naissance,
     * d'après le type de l'article qui le fait naître (recette du 08/10/2026).
     *
     * Un rayon créé depuis la fiche article n'en recevait aucun : ses
     * articles n'écrivaient rien à l'inventaire permanent, sans que rien ne
     * le dise. Les comptes viennent du type d'article du référentiel — celui
     * qui donne les leurs aux familles de la souscription. Un type qui ne se
     * stocke pas n'en reçoit pas.
     *
     * @return array{compte_stock: ?string, compte_variation: ?string}
     */
    public static function comptesDeStockPourType(?string $type): array
    {
        if (!$type || !isset(self::TYPE_REFERENTIEL[$type])) {
            return ['compte_stock' => null, 'compte_variation' => null];
        }

        $duReferentiel = Referentiel\TypeArticle::where('code', self::TYPE_REFERENTIEL[$type])->first();

        if ($duReferentiel && trim((string) $duReferentiel->compte_stock) !== '' && trim((string) $duReferentiel->compte_variation) !== '') {
            return ['compte_stock' => $duReferentiel->compte_stock, 'compte_variation' => $duReferentiel->compte_variation];
        }

        return self::COMPTES_DE_STOCK_PAR_DEFAUT[$type];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function sousCategories(): HasMany
    {
        return $this->hasMany(SousCategorie::class, 'categorie_id');
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class, 'categorie_id');
    }
}
