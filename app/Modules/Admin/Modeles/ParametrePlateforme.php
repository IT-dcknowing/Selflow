<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;

/**
 * Un réglage de toute la plateforme, saisi par le superadministrateur.
 */
class ParametrePlateforme extends Model
{
    protected $table = 'parametres_plateforme';

    protected $fillable = ['cle', 'valeur'];

    /** Le courriel et le téléphone de l'assistance technique. */
    public const ASSISTANCE_EMAIL = 'assistance_email';
    public const ASSISTANCE_TELEPHONE = 'assistance_telephone';

    /** Le service commercial, pour l'offre sur mesure (à défaut : l'assistance). */
    public const COMMERCIAL_EMAIL = 'commercial_email';
    public const COMMERCIAL_TELEPHONE = 'commercial_telephone';

    /**
     * Lire un réglage. Jamais d'exception : la page de maintenance l'appelle
     * alors que la base peut justement être en cause.
     */
    public static function lire(string $cle, ?string $parDefaut = null): ?string
    {
        try {
            $valeur = static::where('cle', $cle)->value('valeur');
        } catch (\Throwable) {
            return $parDefaut;
        }

        return filled($valeur) ? $valeur : $parDefaut;
    }

    public static function ecrire(string $cle, ?string $valeur): void
    {
        static::updateOrCreate(['cle' => $cle], ['valeur' => $valeur]);
    }
}
