<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une demande de comptabilité des exercices antérieurs : l'entreprise
 * demande, le superadministrateur accorde tout ou partie des années, ou
 * refuse (propriétaire, 08/10/2026).
 */
class DemandeExercicesAnterieurs extends Model
{
    public const EN_ATTENTE = 'en_attente';
    public const VALIDEE    = 'validee';
    public const REFUSEE    = 'refusee';

    protected $table = 'demandes_exercices_anterieurs';

    protected $fillable = [
        'entreprise_id', 'annees_demandees', 'annees_accordees', 'statut',
        'demandee_par', 'traitee_par', 'traitee_at', 'motif_refus',
    ];

    protected function casts(): array
    {
        return [
            'annees_demandees' => 'array',
            'annees_accordees' => 'array',
            'traitee_at'       => 'datetime',
        ];
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'demandee_par');
    }

    /**
     * Les exercices antérieurs accordés à une entreprise, toutes demandes
     * confondues.
     *
     * @return array<int, int>
     */
    public static function anneesAccordees(int $entrepriseId): array
    {
        return self::where('entreprise_id', $entrepriseId)->where('statut', self::VALIDEE)
            ->pluck('annees_accordees')
            ->flatten()->map(fn ($a) => (int) $a)->unique()->sort()->values()->all();
    }

    /** @return array<int, int> les années d'une demande encore en attente */
    public static function anneesEnAttente(int $entrepriseId): array
    {
        return self::where('entreprise_id', $entrepriseId)->where('statut', self::EN_ATTENTE)
            ->pluck('annees_demandees')
            ->flatten()->map(fn ($a) => (int) $a)->unique()->sort()->values()->all();
    }
}
