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

    protected $fillable = ['entreprise_id', 'portee', 'cle', 'compte_vente', 'compte_achat', 'compte_variation'];

    /**
     * Les comptes que chaque type d'article **propose** (26/10/2026), tirés du
     * référentiel (`database/data/referentiel/types_articles.json`) : une
     * marchandise se vend en 701 et s'achète en 601, un produit fini se vend
     * en 702 et varie en 736… Ils s'affichent en « Proposé » ; seul un compte
     * **personnalisé** s'impose à tous les articles du type.
     *
     * @var array<string, array{compte_vente: ?string, compte_achat: ?string, compte_variation: ?string}>
     */
    public const PROPOSES_PAR_TYPE = [
        'marchandise'               => ['compte_vente' => '701000', 'compte_achat' => '601000', 'compte_variation' => '603100'],
        'matiere_premiere'          => ['compte_vente' => null,     'compte_achat' => '602000', 'compte_variation' => '603200'],
        'produit_fini'              => ['compte_vente' => '702000', 'compte_achat' => null,     'compte_variation' => '736000'],
        'consommable_stockable'     => ['compte_vente' => null,     'compte_achat' => '604000', 'compte_variation' => '603300'],
        'consommable_non_stockable' => ['compte_vente' => null,     'compte_achat' => '604000', 'compte_variation' => null],
        'service'                   => ['compte_vente' => '706000', 'compte_achat' => null,     'compte_variation' => null],
    ];

    /** La colonne de la variation est-elle en base ? (migration `2026_10_26_000004`) */
    public static function porteLaVariation(): bool
    {
        return app()->bound('selflow.config_comptes_variation')
            ? app('selflow.config_comptes_variation')
            : tap(\Illuminate\Support\Facades\Schema::hasColumn('configurations_comptes', 'compte_variation'),
                fn ($oui) => app()->instance('selflow.config_comptes_variation', $oui));
    }

    /**
     * La configuration d'une entreprise, lue une fois par requête.
     *
     * Une facture de cinquante lignes résout cinquante comptes : sans cette
     * mémoire, autant de requêtes. Elle vit dans le conteneur de
     * l'application, qui naît et meurt avec la requête — une mémoire
     * statique survivrait d'une épreuve à l'autre.
     *
     * @return array<string, array{compte_vente: ?string, compte_achat: ?string, compte_variation: ?string}>  clé « portee:cle »
     */
    public static function pour(int $entrepriseId): array
    {
        $memoire = app()->bound('selflow.config_comptes') ? app('selflow.config_comptes') : [];

        if (!array_key_exists($entrepriseId, $memoire)) {
            $memoire[$entrepriseId] = self::where('entreprise_id', $entrepriseId)->get()
                ->mapWithKeys(fn ($l) => [$l->portee . ':' . $l->cle => [
                    'compte_vente'     => $l->compte_vente,
                    'compte_achat'     => $l->compte_achat,
                    'compte_variation' => $l->compte_variation ?? null,
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
