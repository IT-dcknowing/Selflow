<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une matière d'un ordre de production, figée à la création de l'ordre.
 *
 * `quantite_recette` et `unite_recette` disent ce que la recette demandait
 * pour une unité de produit fini ; `quantite_requise` est le besoin total de
 * l'ordre, ramené à l'unité de stock de l'ingrédient et arrondi à la précision
 * du stock — c'est cette quantité, et elle seule, que la validation sort.
 */
class OrdreProductionLigne extends Model
{
    protected $table = 'ordre_production_lignes';

    protected $fillable = [
        'ordre_production_id',
        'ingredient_id',
        'quantite_recette',
        'unite_recette',
        'quantite_requise',
    ];

    protected $casts = [
        'quantite_recette' => 'decimal:4',
        'quantite_requise' => 'decimal:3',
    ];

    public function ordre(): BelongsTo
    {
        return $this->belongsTo(OrdreProduction::class, 'ordre_production_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'ingredient_id');
    }
}
