<?php

namespace App\Modules\Admin\Modeles;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une ligne de la configuration comptable globale d'une entreprise.
 *
 * `cle` vaut `general` pour la configuration générale, ou `type:<code>` pour
 * un type d'article (`type:service`, `type:marchandise`, …). La
 * configuration par catégorie ne vit pas ici : elle est portée par
 * `categories.compte_*`, où le préparamétrage du métier la pose déjà.
 */
class ImputationGlobale extends Model
{
    protected $table = 'imputations_globales';

    protected $fillable = ['entreprise_id', 'cle', 'compte_vente', 'compte_achat'];

    public const GENERALE = 'general';

    /** Le nom sous lequel la lecture d'une requête est gardée dans le conteneur. */
    private const MEMOIRE = 'imputations.globales';

    public static function clePourType(string $type): string
    {
        return 'type:' . $type;
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class, 'entreprise_id');
    }

    protected static function booted(): void
    {
        // Une écriture de vente lit la configuration autant de fois qu'elle a
        // de lignes. La lecture est gardée le temps d'une requête — dans le
        // conteneur, et non dans une propriété statique, qui survivrait d'une
        // épreuve ou d'un travail de file à l'autre et servirait la
        // configuration d'hier. Une modification l'oublie aussitôt.
        $oublier = fn (self $ligne) => app()->forgetInstance(self::MEMOIRE);

        static::saved($oublier);
        static::deleted($oublier);
    }

    /**
     * La configuration d'une entreprise, indexée par clé.
     *
     * @return array<string, array{compte_vente: ?string, compte_achat: ?string}>
     */
    public static function pour(int $entrepriseId): array
    {
        // Liée en `scoped` par AppServiceProvider.
        $memoire = app(self::MEMOIRE);

        if (!isset($memoire[$entrepriseId])) {
            $memoire[$entrepriseId] = self::where('entreprise_id', $entrepriseId)
                ->get(['cle', 'compte_vente', 'compte_achat'])
                ->mapWithKeys(fn (self $l) => [$l->cle => [
                    'compte_vente' => $l->compte_vente,
                    'compte_achat' => $l->compte_achat,
                ]])
                ->all();
        }

        return $memoire[$entrepriseId];
    }
}
