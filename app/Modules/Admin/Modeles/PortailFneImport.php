<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un fichier relevé au portail FNE, lu et conservé.
 *
 * La ligne existe même quand la lecture a échoué (`statut = 'erreur'`) : un
 * fichier illisible qui ne laisse aucune trace se redépose indéfiniment sans
 * que personne ne comprenne pourquoi rien n'arrive.
 */
class PortailFneImport extends Model
{
    protected $table = 'portail_fne_imports';

    public const TYPE_FICHE  = 'fiche';
    public const TYPE_POINTS = 'points';

    public const STATUT_IMPORTE = 'importe';
    public const STATUT_ERREUR  = 'erreur';

    protected $fillable = [
        'entreprise_id',
        'login',
        'date_scraping',
        'type',
        'fichier_nom',
        'fichier_empreinte',
        'contenu_empreinte',
        'donnees_brutes',
        'statut',
        'message',
        'lignes_importees',
        'importe_at',
        'dernier_releve_le',
        'releves',
    ];

    protected function casts(): array
    {
        return [
            'date_scraping'     => 'date',
            'donnees_brutes'    => 'array',
            'lignes_importees'  => 'integer',
            'importe_at'        => 'datetime',
            'dernier_releve_le' => 'date',
            'releves'           => 'integer',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function fiche(): HasOne
    {
        return $this->hasOne(PortailFneFiche::class, 'import_id');
    }

    public function pointsFacturation(): HasMany
    {
        return $this->hasMany(PortailFnePointFacturation::class, 'import_id');
    }

    /**
     * Le relevé n'a pas pu être rattaché à une entreprise connue de Selflow.
     */
    public function estOrphelin(): bool
    {
        return $this->entreprise_id === null;
    }
}
