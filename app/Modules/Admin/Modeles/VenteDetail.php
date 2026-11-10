<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VenteDetail extends Model
{
    protected $table = 'vente_details';
    protected $fillable = ['vente_id', 'produit_id', 'reference_produit', 'libelle_virtuel', 'quantite', 'quantite_livree', 'unite', 'prix_unitaire', 'remise_taux', 'montant_tva', 'montant_ttc', 'fne_invoice_item_id'];

    protected function casts(): array
    {
        return [
            'remise_taux' => 'decimal:2',
            // Quantités en float : le stock se compte aussi en kilos et en
            // litres, et la ligne de vente ne doit pas tronquer avant lui.
            'quantite'        => 'float',
            'quantite_livree' => 'float',
        ];
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class, 'vente_id');
    }

    /**
     * Taxes personnalisées de la ligne (→ `items[].customTaxes` du payload FNE).
     */
    public function taxes(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VenteDetailTaxe::class, 'vente_detail_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
