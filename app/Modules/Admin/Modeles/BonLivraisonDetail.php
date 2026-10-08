<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonLivraisonDetail extends Model
{
    protected $table = 'bon_livraison_details';

    protected $fillable = [
        'bon_livraison_id',
        'vente_detail_id',
        'produit_id',
        'libelle',
        'unite',
        'qte_commandee',
        'qte_livree',
    ];

    protected $appends = ['reliquat'];

    protected function casts(): array
    {
        return [
            // En décimales, comme la ligne de vente : 2,5 kg livrés ne
            // s'arrondissent ni à 2 ni à 3.
            'qte_commandee' => 'float',
            'qte_livree'    => 'float',
        ];
    }

    // ── Relations ──────────────────────────────────────────────────────────────

    public function bonLivraison(): BelongsTo
    {
        return $this->belongsTo(BonLivraison::class, 'bon_livraison_id');
    }

    /** La ligne de commande d'où vient cette ligne livrée. */
    public function venteDetail(): BelongsTo
    {
        return $this->belongsTo(VenteDetail::class, 'vente_detail_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class, 'produit_id');
    }

    // ── Accesseurs ─────────────────────────────────────────────────────────────

    /** Quantité restante à livrer */
    public function getReliquatAttribute(): float
    {
        return max(0.0, (float) $this->qte_commandee - (float) $this->qte_livree);
    }
}
