<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Project;

use App\Models\RepoProfile;
use App\Services\Project\BackingServiceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackingServiceResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): BackingServiceResolver
    {
        return app(BackingServiceResolver::class);
    }

    public function test_coordinates_use_defaults(): void
    {
        $profile = RepoProfile::factory()->withBackingServices(['mysql', 'redis'])->create();
        $coords = $this->resolver()->coordinates($profile);

        $this->assertSame('db', $coords['mysql']['host']);
        $this->assertSame('argos', $coords['mysql']['database']);
        $this->assertSame('redis', $coords['redis']['host']);
    }

    public function test_credential_overrides_apply_but_host_and_port_stay_fixed(): void
    {
        $profile = RepoProfile::factory()
            ->withBackingServices(['mysql'])
            ->withServiceConfig(['mysql' => [
                'database' => 'shop',
                'username' => 'sa',
                'password' => 'pw',
                'host' => 'evil',   // not configurable → ignored
                'port' => '9999',   // not configurable → ignored
            ]])
            ->create();

        $coords = $this->resolver()->coordinates($profile);

        $this->assertSame('shop', $coords['mysql']['database']);
        $this->assertSame('sa', $coords['mysql']['username']);
        $this->assertSame('db', $coords['mysql']['host']);
        $this->assertSame('3306', $coords['mysql']['port']);
    }

    public function test_connection_env_and_placeholders(): void
    {
        $profile = RepoProfile::factory()
            ->withBackingServices(['mysql', 'redis'])
            ->withServiceConfig(['mysql' => ['database' => 'shop']])
            ->create();

        $env = $this->resolver()->connectionEnv($profile);
        $this->assertSame('db', $env['DB_HOST']);
        $this->assertSame('shop', $env['DB_DATABASE']);
        $this->assertSame('redis', $env['REDIS_HOST']);

        $placeholders = $this->resolver()->placeholders($profile);
        $this->assertSame('db', $placeholders['${mysql.host}']);
        $this->assertSame('shop', $placeholders['${mysql.database}']);
        $this->assertSame('6379', $placeholders['${redis.port}']);
    }
}
