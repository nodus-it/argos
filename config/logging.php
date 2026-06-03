<?php

// Closed-deployment app — driver choices are fixed, only credentials are ENV-driven.

use Monolog\Handler\NullHandler;

return [

    'default' => 'stack',

    'deprecations' => [
        'channel' => 'null',
        'trace' => false,
    ],

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
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

        'argos' => [
            'driver' => 'daily',
            'path' => storage_path('logs/argos.log'),
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
