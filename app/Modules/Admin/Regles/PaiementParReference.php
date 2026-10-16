<?php

namespace App\Modules\Admin\Regles;

use App\Modules\Admin\Modeles\ParametrePlateforme;

/**
 * Le paiement par référence, en attendant l'API de paiement — 16/10/2026.
 *
 * L'entreprise dépose le montant sur un numéro du cabinet DC-Knowing, puis
 * saisit l'opérateur et la référence de la transaction ; le superadministrateur
 * vérifie et confirme. Les numéros se saisissent dans Superadmin › Pannes &
 * assistance.
 */
final class PaiementParReference
{
    public const OPERATEURS = [
        'WAVE'   => 'Wave',
        'ORANGE' => 'Orange Money',
        'MTN'    => 'MTN Mobile Money',
        'MOOV'   => 'Moov Money',
    ];

    /** La clé du réglage : un numéro par ligne, « Wave : 07 00 00 00 00 ». */
    public const PARAMETRE_NUMEROS = 'paiement_numeros';

    /** @return array<int, string> */
    public static function numerosDuCabinet(): array
    {
        $texte = (string) ParametrePlateforme::lire(self::PARAMETRE_NUMEROS, '');

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $texte) ?: [])));
    }

    /** Les règles de validation communes aux deux formulaires. */
    public static function regles(): array
    {
        return [
            'operateur_paiement' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(self::OPERATEURS))],
            'reference_paiement' => ['required', 'string', 'min:4', 'max:100'],
        ];
    }

    public static function messages(): array
    {
        return [
            'operateur_paiement.required' => 'Choisissez l\'opérateur utilisé pour le paiement.',
            'reference_paiement.required' => 'Saisissez la référence de la transaction reçue après le paiement.',
            'reference_paiement.min'      => 'La référence de la transaction paraît incomplète.',
        ];
    }
}
