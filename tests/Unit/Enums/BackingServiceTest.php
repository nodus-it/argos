<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\BackingService;
use Tests\TestCase;

class BackingServiceTest extends TestCase
{
    public function test_mysql_exposes_laravel_connection_env(): void
    {
        $env = BackingService::Mysql->workerEnv();

        $this->assertSame('db', $env['DB_HOST']);
        $this->assertSame('3306', $env['DB_PORT']);
        $this->assertSame('argos', $env['DB_DATABASE']);
        $this->assertSame('db', BackingService::Mysql->host());
    }

    public function test_redis_exposes_connection_env(): void
    {
        $env = BackingService::Redis->workerEnv();

        $this->assertSame('redis', $env['REDIS_HOST']);
        $this->assertSame('6379', $env['REDIS_PORT']);
        $this->assertSame('redis', BackingService::Redis->host());
    }

    public function test_image_falls_back_to_default(): void
    {
        $this->assertSame('mariadb:11', BackingService::Mysql->image());
        $this->assertSame('redis:7-alpine', BackingService::Redis->image());
    }

    public function test_readiness_probes_are_defined(): void
    {
        $this->assertSame(['healthcheck.sh', '--connect', '--innodb_initialized'], BackingService::Mysql->readinessProbe());
        $this->assertSame(['redis-cli', 'ping'], BackingService::Redis->readinessProbe());
    }
}
