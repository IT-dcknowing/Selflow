<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvoirBapaLigne extends Model
{
    protected $table = 'avoir_bapa_lignes';

    protected $fillable = [
        'avoir_bapa_id', 'achat_detail_id', 'produit_id', 'libelle',
        'quantite', 'prix_unitaire', 'montant', 'retour_stock',
    ];

    protected function casts(): array
    {
        return ['quantite' => 'float', 'prix_unitaire' => 'float', 'montant' => 'float', 'retour_stock' => 'boolean'];
    }

    public function avoir(): BelongsTo
    {
        return $this->belongsTo(AvoirBapa::class, 'avoir_bapa_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
