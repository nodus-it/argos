<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\BackingService;
use Tests\TestCase;

class BackingServiceTest extends TestCase
{
    public function test_mysql_default_coordinates(): void
    {
        $c = BackingService::Mysql->defaultCoordinates();

        $this->assertSame('db', $c['host']);
        $this->assertSame('3306', $c['port']);
        $this->assertSame('argos', $c['database']);
        $this->assertSame(['database', 'username', 'password'], BackingService::Mysql->configurableKeys());
    }

    public function test_mysql_connection_env_from_coordinates(): void
    {
        $env = BackingService::Mysql->connectionEnv(BackingService::Mysql->defaultCoordinates());

        $this->assertSame('db', $env['DB_HOST']);
        $this->assertSame('argos', $env['DB_DATABASE']);
    }

    public function test_mysql_container_env_reflects_custom_credentials(): void
    {
        $coords = ['host' => 'db', 'port' => '3306', 'database' => 'shop', 'username' => 'u', 'password' => 'p'];
        $env = BackingService::Mysql->containerEnv($coords);

        $this->assertSame('shop', $env['MARIADB_DATABASE']);
        $this->assertSame('u', $env['MARIADB_USER']);
        $this->assertSame('p', $env['MARIADB_PASSWORD']);
        $this->assertSame('p', $env['MARIADB_ROOT_PASSWORD']);
    }

    public function test_redis_coordinates_and_env(): void
    {
        $c = BackingService::Redis->defaultCoordinates();
        $env = BackingService::Redis->connectionEnv($c);

        $this->assertSame('redis', $env['REDIS_HOST']);
        $this->assertSame('6379', $env['REDIS_PORT']);
        $this->assertSame([], BackingService::Redis->configurableKeys());
        $this->assertSame([], BackingService::Redis->containerEnv($c));
    }

    public function test_image_and_probe_defaults(): void
    {
        $this->assertSame('mariadb:11', BackingService::Mysql->image());
        $this->assertSame('redis:7-alpine', BackingService::Redis->image());
        $this->assertSame(['healthcheck.sh', '--connect', '--innodb_initialized'], BackingService::Mysql->readinessProbe());
        $this->assertSame(['redis-cli', 'ping'], BackingService::Redis->readinessProbe());
    }
}
