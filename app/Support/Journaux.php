<?php

namespace App\Support;

/**
 * Les journaux rangés par dossier, un fichier par jour (24/11/2026,
 * propriétaire) :
 *
 *     storage/logs/achat/241126-portail-fne-achat.log
 *     storage/logs/vente/241126-portail-fne-vente.log
 *     storage/logs/avoir/241126-portail-fne-avoir.log
 *     storage/logs/portail-fne/241126-portail-fne-sorties.log
 *     storage/logs/comptaflow/241126-comptaflow-deversement.log
 *
 * Le nom commence par la date JJMMAA. Le chemin se calcule à chaque écriture :
 * le cache de configuration (`php artisan optimize`) ne fige jamais une date.
 */
class Journaux
{
    public const TYPES = [
        'achat'      => ['achat', 'portail-fne-achat'],
        'vente'      => ['vente', 'portail-fne-vente'],
        'avoir'      => ['avoir', 'portail-fne-avoir'],
        'sorties'    => ['portail-fne', 'portail-fne-sorties'],
        'comptaflow' => ['comptaflow', 'comptaflow-deversement'],
    ];

    public static function chemin(string $type, ?\DateTimeInterface $jour = null): string
    {
        [$dossier, $nom] = self::TYPES[$type] ?? self::TYPES['sorties'];
        $repertoire = storage_path('logs/' . $dossier);
        if (! is_dir($repertoire)) {
            @mkdir($repertoire, 0775, true);
        }

        return $repertoire . '/' . ($jour ?? now())->format('dmy') . '-' . $nom . '.log';
    }

    /** Le fichier du jour s'il existe, sinon le plus récent du dossier. */
    public static function dernier(string $type): ?string
    {
        $aujourdhui = self::chemin($type);
        if (is_file($aujourdhui)) {
            return $aujourdhui;
        }
        [$dossier, $nom] = self::TYPES[$type] ?? self::TYPES['sorties'];
        $fichiers = glob(storage_path('logs/' . $dossier . '/*-' . $nom . '.log')) ?: [];
        usort($fichiers, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $fichiers[0] ?? null;
    }
}
