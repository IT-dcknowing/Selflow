<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une vente en brouillon (24/11/2026) : ni facture, ni stock, ni FNE. */
class BrouillonVente extends Model
{
    protected $table = 'brouillons_vente';

    protected $fillable = ['entreprise_id', 'point_de_vente_id', 'utilisateur_id', 'libelle', 'total', 'lignes', 'donnees'];

    protected function casts(): array
    {
        return ['donnees' => 'array', 'total' => 'float'];
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function pointDeVente(): BelongsTo
    {
        return $this->belongsTo(PointDeVente::class);
    }
}
