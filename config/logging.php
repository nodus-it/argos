<?php

// Closed-deployment app — driver choices are fixed, only credentials are ENV-driven.

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;

// Compose points ARGOS_LOG_DIR at the shared data volume: app, queue and
// scheduler write the same files, and they survive an upgrade.
$logDir = rtrim((string) env('ARGOS_LOG_DIR', storage_path('logs')), '/');

return [

    'default' => 'stack',

    'deprecations' => [
        'channel' => 'null',
        'trace' => false,
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            // Errors also go to stderr, so `docker compose logs` shows them.
            'channels' => ['single', 'stderr'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => $logDir.'/laravel.log',
            'level' => env('LOG_LEVEL', 'debug'),
            // Written by containers under different uids (see the argos channel).
            'permission' => 0666,
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => 'error',
            'handler' => StreamHandler::class,
            'with' => ['stream' => 'php://stderr'],
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
            'path' => $logDir.'/laravel.log',
        ],

        'argos' => [
            'driver' => 'daily',
            'path' => $logDir.'/argos.log',
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 30,
            // app (www-data), queue and scheduler containers write this shared
            // bind-mounted daily file under different uids (some as root). The
            // default 0644 lets whoever creates the day's file lock the others
            // out with "Permission denied", and the log call then throws — in a
            // request that can turn a handled error into a 500. 0666 keeps the
            // shared dev log writable for every container regardless of uid.
            'permission' => 0666,
            'replace_placeholders' => true,
        ],

    ],

];
