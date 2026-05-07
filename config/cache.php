<?php

// Closed-deployment app — driver choices are fixed, only credentials are ENV-driven.

return [

    'default' => 'database',

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'cache',
            'lock_connection' => null,
            'lock_table' => 'cache_locks',
        ],

    ],

    'prefix' => 'argos-cache-',

    'serializable_classes' => false,

];
