<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne de la configuration globale des comptes — voir la migration
 * `2026_10_06_000002`.
 */
class ConfigurationCompte extends Model
{
    protected $table = 'configurations_comptes';

    public const GENERAL   = 'general';
    public const CATEGORIE = 'categorie';
    public const TYPE      = 'type';

    protected $fillable = ['entreprise_id', 'portee', 'cle', 'compte_vente', 'compte_achat'];

    /**
     * La configuration d'une entreprise, lue une fois par requête.
     *
     * Une facture de cinquante lignes résout cinquante comptes : sans cette
     * mémoire, autant de requêtes. Elle vit dans le conteneur de
     * l'application, qui naît et meurt avec la requête — une mémoire
     * statique survivrait d'une épreuve à l'autre.
     *
     * @return array<string, array{compte_vente: ?string, compte_achat: ?string}>  clé « portee:cle »
     */
    public static function pour(int $entrepriseId): array
    {
        $memoire = app()->bound('selflow.config_comptes') ? app('selflow.config_comptes') : [];

        if (!array_key_exists($entrepriseId, $memoire)) {
            $memoire[$entrepriseId] = self::where('entreprise_id', $entrepriseId)->get()
                ->mapWithKeys(fn ($l) => [$l->portee . ':' . $l->cle => [
                    'compte_vente' => $l->compte_vente,
                    'compte_achat' => $l->compte_achat,
                ]])->all();
            app()->instance('selflow.config_comptes', $memoire);
        }

        return $memoire[$entrepriseId];
    }

    /** Oublier ce qui a été lu — après un enregistrement. */
    public static function oublier(): void
    {
        app()->forgetInstance('selflow.config_comptes');
    }
}
