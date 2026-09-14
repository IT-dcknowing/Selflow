<?php

namespace App\Modules\Admin\Modeles;

use App\Modules\Admin\Modeles\Concerns\IdentifiantOpaque;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fournisseur extends Model
{
    use IdentifiantOpaque;

    protected $table = 'fournisseurs';
    protected $fillable = ['entreprise_id', 'type_facturation', 'nom', 'telephone', 'email', 'secteur', 'adresse', 'ncc', 'regime_imposition', 'rccm', 'compte_comptable', 'numero_tiers', 'source', 'numero_original'];

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    public function achats(): HasMany
    {
        return $this->hasMany(Achat::class, 'fournisseur_id');
    }

    /** Le numéro de tiers du fournisseur occasionnel. */
    public const NUMERO_DIVERS = '400000';

    /**
     * Le fournisseur occasionnel — le pendant du client de passage.
     *  
     * Un achat au comptoir, sans fiche fournisseur, laissait `compte_tiers`
     * vide et tout retombait sur le collectif `401000`.
     */
    public static function divers(Entreprise $entreprise): self
    {
        return self::firstOrCreate(
            ['entreprise_id' => $entreprise->id, 'numero_tiers' => self::NUMERO_DIVERS],
            [
                'nom' => 'Fournisseur divers',
                'type_facturation' => 'B2C',
                'compte_comptable' => config('selflow.plan_comptable_defaut.fournisseur_collectif'),
                'source' => 'systeme',
            ]
        );
    }

    public static function obtenirFournisseursPrioritaires($entrepriseId)
    {
        $hasComptaflow = self::where('entreprise_id', $entrepriseId)->where('source', 'comptaflow')->exists();
        if ($hasComptaflow) {
            return self::where('entreprise_id', $entrepriseId)->where('source', 'comptaflow')->orderBy('nom')->get();
        }
        return self::where('entreprise_id', $entrepriseId)->orderBy('nom')->get();
    }
}
