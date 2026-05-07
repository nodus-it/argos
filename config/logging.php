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
            'replace_placeholders' => true,
        ],

    ],

];
