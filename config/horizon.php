<?php

use Illuminate\Support\Str;

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
        'redis:tasks' => 300,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'silenced_tags' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | supervisor-default: general background jobs (BuildWorkerImageJob, …)
    | supervisor-tasks:   long-running phase runs (RunPhaseJob)
    |
    | Worker counts are static (balance=simple) and driven by env vars so
    | operators can tune them via .env without touching this file.
    |
    */

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
            'balance' => 'simple',
            'minProcesses' => (int) env('ARGOS_QUEUE_DEFAULT_PROCESSES', 5),
            'maxProcesses' => (int) env('ARGOS_QUEUE_DEFAULT_PROCESSES', 5),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],

        'supervisor-tasks' => [
            'connection' => 'redis',
            'queue' => ['tasks'],
            'balance' => 'simple',
            'minProcesses' => (int) env('ARGOS_QUEUE_TASKS_PROCESSES', 2),
            'maxProcesses' => (int) env('ARGOS_QUEUE_TASKS_PROCESSES', 2),
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 3600,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => [
            'supervisor-default' => [
                'minProcesses' => (int) env('ARGOS_QUEUE_DEFAULT_PROCESSES', 5),
                'maxProcesses' => (int) env('ARGOS_QUEUE_DEFAULT_PROCESSES', 5),
            ],
            'supervisor-tasks' => [
                'minProcesses' => (int) env('ARGOS_QUEUE_TASKS_PROCESSES', 2),
                'maxProcesses' => (int) env('ARGOS_QUEUE_TASKS_PROCESSES', 2),
            ],
        ],

        'staging' => [
            'supervisor-default' => [
                'minProcesses' => (int) env('ARGOS_QUEUE_DEFAULT_PROCESSES', 5),
                'maxProcesses' => (int) env('ARGOS_QUEUE_DEFAULT_PROCESSES', 5),
            ],
            'supervisor-tasks' => [
                'minProcesses' => (int) env('ARGOS_QUEUE_TASKS_PROCESSES', 2),
                'maxProcesses' => (int) env('ARGOS_QUEUE_TASKS_PROCESSES', 2),
            ],
        ],

        'local' => [
            'supervisor-default' => [
                'minProcesses' => (int) env('ARGOS_QUEUE_DEFAULT_PROCESSES', 5),
                'maxProcesses' => (int) env('ARGOS_QUEUE_DEFAULT_PROCESSES', 5),
            ],
            'supervisor-tasks' => [
                'minProcesses' => (int) env('ARGOS_QUEUE_TASKS_PROCESSES', 2),
                'maxProcesses' => (int) env('ARGOS_QUEUE_TASKS_PROCESSES', 2),
            ],
        ],
    ],

];
