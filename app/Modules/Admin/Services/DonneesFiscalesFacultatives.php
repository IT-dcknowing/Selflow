<?php

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Modeles\Entreprise;
use Illuminate\Http\Request;

/**
 * La situation fiscale est facultative à la création d'une entreprise — à
 * l'inscription comme chez le superadministrateur (décision du 15/10/2026).
 *
 * Une donnée mal formée (un NCC à moitié tapé, un régime inconnu) ne refuse
 * donc pas la création : elle est écartée, et l'écran le dit. Seule la
 * normalisation en a besoin, et c'est elle qui la réclame.
 */
class DonneesFiscalesFacultatives
{
    /**
     * Retire de la requête les données fiscales facultatives mal formées.
     *
     * @return array<int, string> ce qui a été écarté, pour le dire
     */
    public static function ecarterLesInvalides(Request $request): array
    {
        $ecartees = [];
        $formatNcc = '/^[A-Z0-9]{7}[A-Z]$/';

        // Deux blocs portent un NCC (« j'ai un compte FNE » et « je n'en ai
        // pas ») ; seul compte celui de la réponse choisie.
        if ($request->input('possede_compte_fne') === '0') {
            $request->merge(['fne_ncc' => null, 'fne_mot_de_passe' => null]);
        } elseif ($request->input('possede_compte_fne') === '1') {
            $request->merge(['ncc' => null]);
        }

        foreach (['fne_ncc', 'ncc'] as $champ) {
            $valeur = (string) $request->input($champ, '');
            if ($valeur !== '' && !preg_match($formatNcc, $valeur)) {
                $ecartees[] = sprintf('le NCC « %s » (8 caractères attendus : 7 chiffres ou lettres puis une lettre)', $valeur);
                $request->merge([$champ => null]);
                if ($champ === 'fne_ncc') {
                    $request->merge(['fne_mot_de_passe' => null]);
                }
            }
        }

        $regime = $request->input('regime_imposition');
        if (filled($regime) && !in_array($regime, Entreprise::regimesAcceptesPour(), true)) {
            $ecartees[] = 'le régime d\'imposition';
            $request->merge(['regime_imposition' => null]);
        }

        foreach (['rccm' => 100, 'centre_impots' => 100, 'adresse' => 255, 'telephone' => 30, 'compte_contribuable' => 100] as $champ => $max) {
            if (mb_strlen((string) $request->input($champ, '')) > $max) {
                $ecartees[] = 'le champ ' . str_replace('_', ' ', $champ) . ' (trop long)';
                $request->merge([$champ => null]);
            }
        }

        return $ecartees;
    }
}
