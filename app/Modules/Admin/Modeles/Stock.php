<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    protected $table = 'stocks';

    protected $fillable = [
        'produit_id',
        'point_de_vente_id',
        'quantite_disponible',
        'cump',
        'stock_minimum',
        'stock_maximum',
        // L'article est-il référencé sur ce site (20/10/2026) ?
        'reference',
    ];

    /**
     * Précision des quantités physiques : le gramme sur le kilo, le millilitre
     * sur le litre. Un `integer` faisait entrer 12,5 kg de cacao pour 12.
     */
    public const DECIMALES = 3;

    /**
     * Précision du **CUMP** (Coût Unitaire Moyen Pondéré) : quatre décimales.
     *
     * Une de plus que les quantités. Le coût unitaire d'un article vendu au
     * gramme se joue au centime du millier, et arrondir le coût avant de le
     * multiplier par la quantité propage l'erreur sur toute la sortie.
     */
    public const DECIMALES_COUT = 4;

    /**
     * Les quantités sont lues en `float` et non en `decimal:3`.
     *
     * Le cast `decimal` de Laravel rend des chaînes — « 12.500 » — qui se
     * comparent mal, ne s'additionnent pas dans un `array_sum` et ressortent
     * telles quelles en JSON. Le `float` garde l'arithmétique naturelle ; la
     * dérive binaire est écartée autrement : la colonne est un `decimal(15,3)`
     * en base, elle fait foi, et toute écriture passe par `StockService` qui
     * arrondit à trois décimales. Aucun cumul ne se fait en mémoire d'une
     * opération à l'autre.
     */
    protected function casts(): array
    {
        return [
            'quantite_disponible' => 'float',
            'cump'                => 'float',
            'stock_minimum'       => 'float',
            'stock_maximum'       => 'float',
        ];
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }
}
