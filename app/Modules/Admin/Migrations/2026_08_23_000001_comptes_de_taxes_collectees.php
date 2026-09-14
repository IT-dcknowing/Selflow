<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les comptes de taxes collectées manquaient au plan des entreprises.
 *
 * Le service comptable imputait déjà les taxes parafiscales au `447000` sans
 * que ce compte figure nulle part : la balance affichait un numéro sans
 * intitulé, et le grand livre une ligne qu'aucun écran ne savait nommer. La
 * ventilation de la TVA par nature de vente et le droit de timbre de
 * quittance ajoutent trois comptes dans le même cas.
 *
 * Ils sont posés dans le plan de chaque entreprise existante. Une entreprise
 * créée après cette migration les reçoit par le trousseau, qui les lit
 * désormais dans le référentiel.
 *
 * Rien n'est écrasé : un compte déjà présent — parce que l'entreprise l'a
 * créé elle-même, ou l'a renommé — est laissé tel quel.
 */
return new class extends Migration
{
    private const COMPTES = [
        '443200' => 'État, TVA facturée sur prestations de services',
        '443300' => 'État, TVA facturée sur travaux',
        '447000' => 'État, autres impôts et taxes collectés (AIRSI, GRA, DTD…)',
        '447800' => 'État, autres impôts et contributions (droit de timbre de quittance)',
    ];

    public function up(): void
    {
        $maintenant = now();

        foreach (DB::table('entreprises')->pluck('id') as $entrepriseId) {
            $existants = DB::table('plan_comptable')
                ->where('entreprise_id', $entrepriseId)
                ->whereIn('numero', array_keys(self::COMPTES))
                ->pluck('numero')
                ->all();

            $aPoser = [];
            foreach (self::COMPTES as $numero => $libelle) {
                // Les clés de COMPTES sont des chaînes dans le source, mais PHP
                // transtype toute clé numérique en entier : `array_keys()` rend
                // des `int`, là où `pluck('numero')` rend des `string`. La
                // comparaison stricte échouait donc toujours, et la garde
                // n'écartait jamais un compte déjà posé.
                if (in_array((string) $numero, $existants, true)) {
                    continue;
                }

                $aPoser[] = [
                    'entreprise_id' => $entrepriseId,
                    'numero'        => $numero,
                    'libelle'       => $libelle,
                    'created_at'    => $maintenant,
                    'updated_at'    => $maintenant,
                ];
            }

            if ($aPoser !== []) {
                DB::table('plan_comptable')->insert($aPoser);
            }
        }
    }

    /**
     * Le retour ne supprime rien.
     *
     * Ces comptes portent des écritures dès la première vente timbrée. Les
     * effacer laisserait des lignes rattachées à un compte absent du plan —
     * exactement le désordre que cette migration répare.
     */
    public function down(): void
    {
    }
};
