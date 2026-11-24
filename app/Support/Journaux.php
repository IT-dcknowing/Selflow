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

    /**
     * Le dossier d'une entreprise sous le type (24/11/2026) : le NCC et le nom,
     * pour la retrouver d'un coup d'œil.
     *
     *     storage/logs/comptaflow/2196217J-SAS-T/241126-comptaflow-deversement.log
     */
    public static function cheminEntreprise(string $type, $entreprise, ?\DateTimeInterface $jour = null): string
    {
        [$dossier, $nom] = self::TYPES[$type] ?? self::TYPES['sorties'];
        $etiquette = self::etiquette($entreprise);
        $repertoire = storage_path('logs/' . $dossier . '/' . $etiquette);
        if (! is_dir($repertoire)) {
            @mkdir($repertoire, 0775, true);
        }

        return $repertoire . '/' . ($jour ?? now())->format('dmy') . '-' . $nom . '.log';
    }

    /** « 2196217J-SAS-T » : le NCC (ou le numéro) et le nom, sans caractère gênant. */
    public static function etiquette($entreprise): string
    {
        if (! $entreprise) {
            return 'sans-entreprise';
        }
        $ncc = trim((string) ($entreprise->ncc ?? '')) ?: ('E' . $entreprise->id);
        $nom = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', \Illuminate\Support\Str::ascii((string) $entreprise->nom)), '-');

        return substr(preg_replace('/[^A-Za-z0-9]/', '', $ncc) . '-' . ($nom ?: 'entreprise'), 0, 80);
    }

    /** Le journal du déversement d'une entreprise : son fichier, et le journal général. */
    public static function deversement($entreprise): \Psr\Log\LoggerInterface
    {
        return new class($entreprise) extends \Psr\Log\AbstractLogger {
            public function __construct(private $entreprise)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $context = ['entreprise' => $this->entreprise
                    ? trim(($this->entreprise->ncc ?: '') . ' · ' . $this->entreprise->nom . ' (#' . $this->entreprise->id . ')', ' ·')
                    : null] + $context;
                try {
                    \Illuminate\Support\Facades\Log::channel(config('logging.default'))->log($level, $message, $context);
                } catch (\Throwable) {
                }
                try {
                    \Illuminate\Support\Facades\Log::build([
                        'driver' => 'single',
                        'path' => Journaux::cheminEntreprise('comptaflow', $this->entreprise),
                        'level' => 'debug',
                    ])->log($level, $message, $context);
                } catch (\Throwable) {
                }
            }
        };
    }
}
