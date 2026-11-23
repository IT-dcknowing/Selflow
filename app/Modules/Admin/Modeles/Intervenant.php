<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Celui ou celle qui réalise la prestation (coiffeur, esthéticienne…),
 * 23/11/2026. Module « prestataires » d'un espace sur mesure.
 */
class Intervenant extends Model
{
    protected $table = 'intervenants';

    protected $fillable = ['entreprise_id', 'nom', 'specialite', 'telephone', 'actif'];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public static function moduleActif(?Entreprise $entreprise): bool
    {
        return $entreprise
            && \App\Support\Structure::aTable('intervenants') && \App\Support\Structure::aColonne('vente_details', 'intervenant_id')
            && (bool) EspaceSurMesure::pourEntreprise((int) $entreprise->id)?->moduleActif('prestataires');
    }

    /** Les prestataires à proposer sur une ligne de vente. */
    public static function pourLaVente(?Entreprise $entreprise): Collection
    {
        return self::moduleActif($entreprise)
            ? self::where('entreprise_id', $entreprise->id)->where('actif', true)->orderBy('nom')->get(['id', 'nom', 'specialite'])
            : collect();
    }
}
