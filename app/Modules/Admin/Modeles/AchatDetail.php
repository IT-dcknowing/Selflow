<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AchatDetail extends Model
{
    protected $table = 'achat_details';
    protected $fillable = ['achat_id', 'produit_id', 'reference_produit', 'libelle_virtuel', 'quantite', 'quantite_receptionnee', 'unite', 'prix_unitaire', 'remise_taux', 'montant_tva', 'montant_ttc'];

    protected function casts(): array
    {
        return [
            'remise_taux' => 'decimal:2',
            // Voir VenteDetail : une réception de 12,5 kg ne s'arrondit pas.
            'quantite'              => 'float',
            'quantite_receptionnee' => 'float',
        ];
    }

    public function achat(): BelongsTo
    {
        return $this->belongsTo(Achat::class, 'achat_id');
    }

    // `taxes()` vivait ici — retirée le 24/08/2026 avec sa table. Elle n'a
    // jamais rien porté : `enregistrerTaxesDeLigne()` n'est appelée que depuis
    // la vente, et le payload du bordereau d'achat ne transmet aucune taxe.

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }
}
