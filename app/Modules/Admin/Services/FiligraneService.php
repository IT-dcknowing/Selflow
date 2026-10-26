<?php

namespace App\Modules\Admin\Services;

/**
 * Le filigrane des documents exportés (27/10/2026).
 *
 * Une image PNG transparente : SELFLOW en diagonale, en bleu très pâle —
 * la marque de l'application, sur décision du propriétaire du 26/10/2026. La même image sert au PDF
 * (fond de chaque page) et au classeur Excel (fond de la feuille à l'écran,
 * image d'en-tête à l'impression) : les deux exports portent la même marque.
 *
 * Elle est dessinée par GD avec une police du dépôt (DejaVu, livrée avec
 * dompdf) : rien n'est cherché sur le réseau.
 */
class FiligraneService
{
    /** Le texte du filigrane : la marque, et non le nom de l'entreprise. */
    public const MARQUE = 'SELFLOW';

    private const POLICE = 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf';

    /**
     * @param  string  $titre       la ligne principale (SELFLOW par défaut)
     * @param  string  $sousTitre   une seconde ligne, facultative
     * @return string|null  le contenu PNG, ou null si GD ou la police manquent
     */
    public static function png(string $titre = self::MARQUE, string $sousTitre = '', int $largeur = 1600, int $hauteur = 1100, int $opacite = 14): ?string
    {
        $police = base_path(self::POLICE);
        if (!function_exists('imagettftext') || !is_file($police)) {
            return null;
        }

        $image = imagecreatetruecolor($largeur, $hauteur);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
        imagealphablending($image, true);

        // GD compte l'opacité à l'envers : 0 opaque, 127 transparent.
        $alpha = (int) round(127 - 127 * max(1, min(100, $opacite)) / 100);
        $encre = imagecolorallocatealpha($image, 0, 43, 92, $alpha);

        $angle = 28;
        $titre = mb_strtoupper(trim($titre)) ?: 'SELFLOW';
        $taille = self::tailleQuiTient($police, $titre, $angle, $largeur * 0.88, 240);
        self::centrer($image, $police, $titre, $taille, $angle, $encre, $largeur / 2, $hauteur / 2 - $taille * 0.35);

        if ($sousTitre !== '') {
            $tailleSous = max(18, (int) round($taille * 0.42));
            self::centrer($image, $police, mb_strtoupper($sousTitre), $tailleSous, $angle, $encre,
                $largeur / 2 + $taille * 0.55, $hauteur / 2 + $taille * 0.95);
        }

        ob_start();
        imagepng($image);
        $contenu = (string) ob_get_clean();
        imagedestroy($image);

        return $contenu;
    }

    /** Le même filigrane, prêt à poser dans une page HTML (dompdf). */
    public static function dataUri(string $titre = self::MARQUE, string $sousTitre = ''): ?string
    {
        $png = self::png($titre, $sousTitre);

        return $png === null ? null : 'data:image/png;base64,' . base64_encode($png);
    }

    /** La plus grande taille de police pour laquelle le texte tient en largeur. */
    private static function tailleQuiTient(string $police, string $texte, int $angle, float $largeurMax, int $tailleMax): int
    {
        for ($taille = $tailleMax; $taille > 16; $taille -= 2) {
            $boite = imagettfbbox($taille, $angle, $police, $texte);
            $largeur = max($boite[0], $boite[2], $boite[4], $boite[6]) - min($boite[0], $boite[2], $boite[4], $boite[6]);
            if ($largeur <= $largeurMax) {
                return $taille;
            }
        }

        return 16;
    }

    private static function centrer($image, string $police, string $texte, int $taille, int $angle, int $encre, float $cx, float $cy): void
    {
        $boite = imagettfbbox($taille, $angle, $police, $texte);
        $xs = [$boite[0], $boite[2], $boite[4], $boite[6]];
        $ys = [$boite[1], $boite[3], $boite[5], $boite[7]];
        $x = (int) round($cx - (min($xs) + max($xs)) / 2);
        $y = (int) round($cy - (min($ys) + max($ys)) / 2);
        imagettftext($image, $taille, $angle, $x, $y, $encre, $police, $texte);
    }
}
