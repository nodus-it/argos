<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backing services Argos can boot as ephemeral sidecars for a worker phase run,
 * so a project's test suite can talk to a real MySQL/Redis instead of
 * sqlite/in-memory. One private network per run, torn down afterwards.
 *
 * Each case knows its image, the network alias the worker reaches it under (the
 * conventional Laravel host: db / redis), the env the service container itself
 * needs, the connection env handed to the worker, and a readiness probe run via
 * `docker exec`.
 */
enum BackingService: string
{
    case Mysql = 'mysql';
    case Redis = 'redis';

    public function label(): string
    {
        return match ($this) {
            self::Mysql => 'MySQL / MariaDB',
            self::Redis => 'Redis',
        };
    }

    public function image(): string
    {
        return match ($this) {
            self::Mysql => (string) config('argos.worker.services.mysql.image', 'mariadb:11'),
            self::Redis => (string) config('argos.worker.services.redis.image', 'redis:7-alpine'),
        };
    }

    /**
     * Network alias / hostname the worker reaches this service under.
     */
    public function host(): string
    {
        return match ($this) {
            self::Mysql => 'db',
            self::Redis => 'redis',
        };
    }

    /**
     * Env the service container itself needs to initialise.
     *
     * @return array<string, string>
     */
    public function containerEnv(): array
    {
        return match ($this) {
            self::Mysql => [
                'MARIADB_DATABASE' => 'argos',
                'MARIADB_USER' => 'argos',
                'MARIADB_PASSWORD' => 'argos',
                'MARIADB_ROOT_PASSWORD' => 'argos',
            ],
            self::Redis => [],
        };
    }

    /**
     * Connection env handed to the worker so the project's tests reach this
     * service. Standard Laravel keys — the project's phpunit.xml / .env must
     * read them via env().
     *
     * @return array<string, string>
     */
    public function workerEnv(): array
    {
        return match ($this) {
            self::Mysql => [
                'DB_HOST' => 'db',
                'DB_PORT' => '3306',
                'DB_DATABASE' => 'argos',
                'DB_USERNAME' => 'argos',
                'DB_PASSWORD' => 'argos',
            ],
            self::Redis => [
                'REDIS_HOST' => 'redis',
                'REDIS_PORT' => '6379',
            ],
        };
    }

    /**
     * Readiness probe executed via `docker exec <container> …`. The worker is
     * only launched once every enabled service answers it.
     *
     * @return list<string>
     */
    public function readinessProbe(): array
    {
        return match ($this) {
            self::Mysql => ['healthcheck.sh', '--connect', '--innodb_initialized'],
            self::Redis => ['redis-cli', 'ping'],
        };
    }
}
