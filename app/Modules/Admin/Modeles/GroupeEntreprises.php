<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Authentification\Modeles\Utilisateur;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Un groupe d'entreprises liées par le superadministrateur (23/11/2026).
 */
class GroupeEntreprises extends Model
{
    protected $table = 'groupes_entreprises';

    protected $fillable = ['nom', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function entreprises(): BelongsToMany
    {
        return $this->belongsToMany(Entreprise::class, 'groupe_entreprise', 'groupe_id', 'entreprise_id')->withTimestamps();
    }

    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(Utilisateur::class, 'groupe_utilisateur', 'groupe_id', 'utilisateur_id')->withTimestamps();
    }

    /** Faux tant que la migration n'est pas jouée : rien ne doit tomber avant. */
    public static function disponible(): bool
    {
        static $existe = null;

        return $existe ??= Schema::hasTable('groupes_entreprises');
    }

    /**
     * Les entreprises vers lesquelles cet utilisateur peut basculer : celles
     * des groupes **actifs** où il est autorisé et qui contiennent son
     * entreprise du moment. Administrateurs seulement.
     */
    public static function entreprisesAccessibles(?Utilisateur $utilisateur): Collection
    {
        if (!$utilisateur || $utilisateur->role !== 'admin' || !$utilisateur->entreprise_id || !self::disponible()) {
            return collect();
        }

        return self::query()
            ->where('actif', true)
            ->whereHas('utilisateurs', fn ($q) => $q->whereKey($utilisateur->id))
            ->whereHas('entreprises', fn ($q) => $q->whereKey($utilisateur->entreprise_id))
            ->with('entreprises:id,uuid,nom')
            ->get()
            ->flatMap(fn (self $g) => $g->entreprises)
            ->unique('id')
            ->sortBy('nom')
            ->values();
    }
}
