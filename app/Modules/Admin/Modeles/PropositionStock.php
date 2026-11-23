<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Une entrée ou une sortie de stock proposée, en attente de l'utilisateur.
 *
 * Les factures reprises du portail FNE ne bougent pas le stock d'office : la
 * marchandise a pu sortir hors de Selflow, ou ne pas être suivie. Chaque
 * ligne stockable devient une proposition, validée ou refusée à la main.
 */
class PropositionStock extends Model
{
    protected $table = 'propositions_stock';

    public const PROPOSEE = 'proposee';
    public const VALIDEE  = 'validee';
    public const REFUSEE  = 'refusee';

    protected $fillable = [
        'entreprise_id', 'point_de_vente_id', 'produit_id', 'vente_id', 'vente_detail_id',
        'sens', 'quantite', 'origine', 'reference', 'statut', 'motif', 'traite_par', 'traite_le',
    ];

    protected function casts(): array
    {
        return ['quantite' => 'float', 'traite_le' => 'datetime'];
    }

    /** La table existe-t-elle ? Faux tant que la migration n'est pas jouée. */
    public static function disponible(): bool
    {
        static $existe = null;

        return $existe ??= \App\Support\Structure::aTable('propositions_stock');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }
}
