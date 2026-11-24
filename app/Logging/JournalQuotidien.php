<?php

namespace App\Logging;

use App\Support\Journaux;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

/**
 * Canal Monolog qui écrit dans `Journaux::chemin($type)` : le fichier change
 * de lui-même à minuit (24/11/2026).
 *
 * config/logging.php :  'driver' => 'custom', 'via' => JournalQuotidien::class, 'type' => 'achat'
 */
class JournalQuotidien
{
    public function __invoke(array $config): Logger
    {
        $type = (string) ($config['type'] ?? 'sorties');

        // Un chemin imposé (tests, réglage particulier) l'emporte : un fichier fixe.
        if (! empty($config['path'])) {
            return new Logger($config['name'] ?? $type, [new StreamHandler((string) $config['path'], Level::fromName((string) ($config['level'] ?? 'debug')))]);
        }

        $handler = new class($type, Level::fromName((string) ($config['level'] ?? 'debug'))) extends StreamHandler {
            private ?string $cheminOuvert = null;

            public function __construct(private string $type, Level $niveau)
            {
                parent::__construct(Journaux::chemin($type), $niveau);
                $this->cheminOuvert = Journaux::chemin($type);
            }

            protected function write(LogRecord $record): void
            {
                $chemin = Journaux::chemin($this->type);
                if ($chemin !== $this->cheminOuvert) {
                    $this->close();
                    $this->url = $chemin;
                    $this->cheminOuvert = $chemin;
                }
                parent::write($record);
            }
        };

        return new Logger($config['name'] ?? $type, [$handler]);
    }
}
