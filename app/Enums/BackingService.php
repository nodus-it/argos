<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Backing services Argos can boot for a project — as ephemeral sidecars for a
 * worker phase run AND (unified) for the live demo. One private network per
 * run, torn down afterwards.
 *
 * The enum is the *catalog*: image, readiness probe, the fixed network alias,
 * and the env-key shapes. The concrete coordinate VALUES (host, port,
 * credentials) are resolved per profile by BackingServiceResolver — credentials
 * are configurable, host/port are fixed.
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
     * Default coordinates. `host`/`port` are fixed (the network alias the
     * service is reached under); the credential keys are overridable per
     * profile — see configurableKeys().
     *
     * @return array<string, string>
     */
    public function defaultCoordinates(): array
    {
        return match ($this) {
            self::Mysql => [
                'host' => 'db',
                'port' => '3306',
                'database' => 'argos',
                'username' => 'argos',
                'password' => 'argos',
            ],
            self::Redis => [
                'host' => 'redis',
                'port' => '6379',
            ],
        };
    }

    /**
     * Coordinate keys a profile may override (host/port stay fixed).
     *
     * @return list<string>
     */
    public function configurableKeys(): array
    {
        return match ($this) {
            self::Mysql => ['database', 'username', 'password'],
            self::Redis => [],
        };
    }

    /**
     * Connection env handed to the worker / demo app so it reaches the service.
     * Standard Laravel keys — read via env().
     *
     * @param  array<string, string>  $c  resolved coordinates
     * @return array<string, string>
     */
    public function connectionEnv(array $c): array
    {
        return match ($this) {
            self::Mysql => [
                'DB_HOST' => $c['host'],
                'DB_PORT' => $c['port'],
                'DB_DATABASE' => $c['database'],
                'DB_USERNAME' => $c['username'],
                'DB_PASSWORD' => $c['password'],
            ],
            self::Redis => [
                'REDIS_HOST' => $c['host'],
                'REDIS_PORT' => $c['port'],
            ],
        };
    }

    /**
     * Env the service container itself needs to initialise, from the resolved
     * coordinates (so a custom database/user/password actually takes effect).
     *
     * @param  array<string, string>  $c  resolved coordinates
     * @return array<string, string>
     */
    public function containerEnv(array $c): array
    {
        return match ($this) {
            self::Mysql => [
                'MARIADB_DATABASE' => $c['database'],
                'MARIADB_USER' => $c['username'],
                'MARIADB_PASSWORD' => $c['password'],
                'MARIADB_ROOT_PASSWORD' => $c['password'],
            ],
            self::Redis => [],
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
