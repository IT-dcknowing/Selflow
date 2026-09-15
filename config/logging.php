<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
        ],

        /*
        | Le cycle du portail FNE — relevé, dépôt, ramassage, appels d'API.
        |
        | Les journaux PHP de la chaîne partaient jusqu'ici dans `laravel.log`,
        | au milieu de tout le reste, pendant que la sortie des scripts Node
        | allait dans `portail-fne.log`. Chercher pourquoi une facture n'était
        | pas arrivée demandait d'ouvrir deux fichiers et de recouper à la main
        | des horodatages qui n'existaient que d'un côté. Ce canal règle la
        | première moitié : tout ce que PHP dit du portail est ici, et daté.
        |
        | **Deux fichiers, et non un seul — l'essai a été fait le 08/09/2026.**
        | Les verser ensemble paraissait mieux, et ne marche pas sous Windows :
        | `appendOutputTo` redirige la sortie d'une tâche par `>>`, ce qui
        | verrouille le fichier ; Monolog ne peut plus l'ouvrir et lève
        | « Resource temporarily unavailable » — au moment précis où la commande
        | journalisait. Un journal qui casse ce qu'il observe est pire que deux
        | journaux. `portail-fne-sorties.log` reçoit donc ce qu'impriment les
        | tâches planifiées ; les deux portent le même format de ligne, un
        | `sort` les remet dans l'ordre.
        |
        | `single` et non `daily` : `appendOutputTo` ne sait écrire que dans un
        | chemin fixe, et deux conventions de nommage pour deux moitiés du même
        | cycle rendraient le recoupement encore plus pénible. Le fichier
        | grossit de l'ordre de la centaine de kilo-octets par jour ; il se vide
        | à la main, ou par la tâche de ménage du jour où il gênera.
        */
        'portail_fne' => [
            'driver' => 'single',
            'path' => env('PORTAIL_FNE_JOURNAL', storage_path('logs/portail-fne.log')),
            'level' => env('PORTAIL_FNE_JOURNAL_NIVEAU', 'debug'),
            'replace_placeholders' => true,
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', env('APP_NAME', 'Laravel')),
            'emoji' => env('LOG_SLACK_EMOJI', ':boom:'),
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
