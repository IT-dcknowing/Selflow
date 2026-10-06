<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Un avoir qui rendrait plus que la facture n'a encaissé.
 *
 * Levée DANS la transaction de création, une fois l'avoir chiffré : elle
 * l'annule entière — l'avoir, ses lignes, le retour de marchandise — plutôt
 * que de laisser une pièce à moitié posée.
 */
class AvoirExcessif extends RuntimeException
{
    public function __construct(float $demande, float $reste, float $facture)
    {
        parent::__construct(sprintf(
            "Un avoir ne peut pas dépasser la facture : celui-ci porte %s F, il reste %s F à avoirer sur %s F. "
            . "Rien n'a été enregistré.",
            number_format($demande, 0, ',', ' '),
            number_format($reste, 0, ',', ' '),
            number_format($facture, 0, ',', ' ')
        ));
    }
}
