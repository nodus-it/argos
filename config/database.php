<?php

// Closed-deployment app — driver choices are fixed, only credentials are ENV-driven.

use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Argos uses SQLite by default and MariaDB when ARGOS_DB_HOST is present.
    | The AppServiceProvider handles the automatic fallback when DB_CONNECTION
    | is not explicitly set.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ($_SERVER['HOME'] ?? '/root').'/.config/argos/argos.db'),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('ARGOS_DB_URL'),
            'host' => env('ARGOS_DB_HOST', '127.0.0.1'),
            'port' => env('ARGOS_DB_PORT', '3306'),
            'database' => env('ARGOS_DB_DATABASE', 'argos'),
            'username' => env('ARGOS_DB_USERNAME', 'argos'),
            'password' => env('ARGOS_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('ARGOS_DB_SSL_CA'),
            ]) : [],
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

];
