<?php

namespace App\Modules\Admin\Services;

/**
 * Ramener la quantité d'une recette à l'unité dans laquelle l'ingrédient est
 * stocké.
 *
 * La recette se rédige dans l'unité du métier — 200 g de farine, 15 ml
 * d'extrait —, le stock se tient dans l'unité d'achat — le kilo, le litre. La
 * validation d'un ordre de production retranchait jusqu'ici la quantité de la
 * recette telle quelle : 200 g de farine stockée en kg sortaient 200 kg du
 * magasin (recette du 08/10/2026).
 *
 * La règle est volontairement étroite :
 *
 * - même unité (à la casse et aux accents près) : facteur 1 ;
 * - masse vers masse, volume vers volume : le rapport des deux unités ;
 * - « Unité » pour un article qui se compte (pièce, sachet, carton…) :
 *   facteur 1, c'est l'unité de l'article ;
 * - tout le reste est **incompatible** — des grammes d'un article compté en
 *   sacs, des litres d'un article pesé. On refuse plutôt que de deviner : un
 *   sac de farine ne pèse pas le même poids chez tous les fournisseurs.
 */
class ConversionUnitesService
{
    public const MASSE  = 'masse';
    public const VOLUME = 'volume';

    /**
     * Les unités convertibles, avec leur dimension et leur valeur dans l'unité
     * de base de la dimension (le kilogramme, le litre).
     */
    private const FACTEURS = [
        'kg'          => [self::MASSE, 1.0],
        'kilo'        => [self::MASSE, 1.0],
        'kilogramme'  => [self::MASSE, 1.0],
        'g'           => [self::MASSE, 0.001],
        'gr'          => [self::MASSE, 0.001],
        'gramme'      => [self::MASSE, 0.001],
        'mg'          => [self::MASSE, 0.000001],
        'tonne'       => [self::MASSE, 1000.0],
        't'           => [self::MASSE, 1000.0],
        'l'           => [self::VOLUME, 1.0],
        'litre'       => [self::VOLUME, 1.0],
        'cl'          => [self::VOLUME, 0.01],
        'centilitre'  => [self::VOLUME, 0.01],
        'ml'          => [self::VOLUME, 0.001],
        'millilitre'  => [self::VOLUME, 0.001],
    ];

    /** Les unités proposées à la saisie, par dimension, dans cet ordre. */
    private const PROPOSEES = [
        self::MASSE  => ['kg', 'g'],
        self::VOLUME => ['l', 'ml'],
    ];

    /** L'unité générique « Unité », valable pour tout article qui se compte. */
    public const UNITE_GENERIQUE = 'Unité';

    /**
     * Le facteur qui ramène une quantité exprimée en `$uniteRecette` à
     * l'unité de stock de l'article, ou `null` si les deux sont incompatibles.
     */
    public static function facteur(?string $uniteRecette, ?string $uniteStock): ?float
    {
        $recette = self::normaliser($uniteRecette);
        $stock   = self::normaliser($uniteStock);

        if ($recette === $stock) {
            return 1.0;
        }

        $dRecette = self::FACTEURS[$recette] ?? null;
        $dStock   = self::FACTEURS[$stock] ?? null;

        if ($dRecette && $dStock) {
            return $dRecette[0] === $dStock[0] ? $dRecette[1] / $dStock[1] : null;
        }

        // « Unité » pour un article qui se compte : c'est son unité.
        if (!$dStock && $recette === self::normaliser(self::UNITE_GENERIQUE)) {
            return 1.0;
        }

        return null;
    }

    /** La quantité de la recette, ramenée à l'unité de stock — `null` si incompatible. */
    public static function convertir(float $quantite, ?string $uniteRecette, ?string $uniteStock): ?float
    {
        $facteur = self::facteur($uniteRecette, $uniteStock);

        return $facteur === null ? null : $quantite * $facteur;
    }

    /**
     * Les unités qu'une recette peut employer pour un article stocké en
     * `$uniteStock` : l'unité de l'article d'abord, puis ses équivalentes.
     *
     * @return array<int, string>
     */
    public static function compatibles(?string $uniteStock): array
    {
        $propre = trim((string) $uniteStock) !== '' ? trim((string) $uniteStock) : self::UNITE_GENERIQUE;
        $dimension = self::FACTEURS[self::normaliser($propre)][0] ?? null;

        if ($dimension === null) {
            return array_values(array_unique([$propre, self::UNITE_GENERIQUE]));
        }

        $unites = [$propre];
        foreach (self::PROPOSEES[$dimension] as $u) {
            if (self::normaliser($u) !== self::normaliser($propre)) {
                $unites[] = $u;
            }
        }

        return $unites;
    }

    private static function normaliser(?string $unite): string
    {
        $u = mb_strtolower(trim((string) $unite));
        $u = strtr($u, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ï' => 'i']);
        $u = rtrim($u, '.');

        // Pluriels courants : « grammes », « litres », « kgs ».
        if (strlen($u) > 2 && str_ends_with($u, 's') && isset(self::FACTEURS[substr($u, 0, -1)])) {
            $u = substr($u, 0, -1);
        }

        return $u === '' ? 'unite' : $u;
    }
}
