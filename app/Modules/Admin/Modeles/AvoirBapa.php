<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L'avoir interne d'un BAPA — non certifié par la DGI, qui ne le normalise
 * pas. Il ne vaut que pour la comptabilité et le stock. Voir AvoirBapaService.
 */
class AvoirBapa extends Model
{
    use IdentifiantOpaque;

    protected $table = 'avoirs_bapa';

    protected $fillable = [
        'entreprise_id', 'achat_id', 'point_de_vente_id', 'utilisateur_id',
        'numero', 'date_avoir', 'motif', 'montant_ttc',
    ];

    protected function casts(): array
    {
        return ['date_avoir' => 'date', 'montant_ttc' => 'float'];
    }

    public function bapa(): BelongsTo
    {
        return $this->belongsTo(Achat::class, 'achat_id');
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class, 'point_de_vente_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'utilisateur_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(AvoirBapaLigne::class, 'avoir_bapa_id');
    }
}
