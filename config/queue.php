<?php

// Closed-deployment app — driver choices are fixed, only credentials are ENV-driven.
// Exception: the queue connection is env-driven so the Docker stack runs on
// Redis/Horizon (QUEUE_CONNECTION=redis in docker-compose) while local
// `artisan serve` dev and the test suite fall back to the database queue.

return [

    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        // retry_after MUST exceed the longest job timeout (RunPhaseJob::$timeout
        // = 3600s). With the Laravel default of 90s the queue re-reserves a
        // still-running phase after 90s → Horizon re-attempts it → tries=1 →
        // "RunPhaseJob has been attempted too many times", the task is marked
        // failed while the worker container keeps running. 3900 > 3600 + headroom.
        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 3900,
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 3900,
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => 'database-uuids',
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
