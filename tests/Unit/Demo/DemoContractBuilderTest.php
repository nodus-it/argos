<?php

declare(strict_types=1);

namespace Tests\Unit\Demo;

use App\Models\RepoProfile;
use App\Services\Demo\DemoContractBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DemoContractBuilderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: string}
     */
    private function build(RepoProfile $profile): array
    {
        return app(DemoContractBuilder::class)->buildDefault($profile);
    }

    public function test_unconfigured_profile_keeps_stub_defaults_and_no_redis(): void
    {
        [$composeYaml, $settingsYaml] = $this->build(RepoProfile::factory()->create());
        $compose = Yaml::parse($composeYaml);

        $this->assertSame('demo', $compose['services']['db']['environment']['MARIADB_DATABASE']);
        $this->assertArrayNotHasKey('redis', $compose['services']);
        $this->assertStringContainsString('entry:', $settingsYaml);
    }

    public function test_configured_mysql_credentials_apply_to_db_and_app(): void
    {
        $profile = RepoProfile::factory()
            ->withServiceConfig(['mysql' => ['database' => 'shop', 'username' => 'sa', 'password' => 'pw']])
            ->create();

        $compose = Yaml::parse($this->build($profile)[0]);

        $this->assertSame('shop', $compose['services']['db']['environment']['MARIADB_DATABASE']);
        $this->assertSame('sa', $compose['services']['db']['environment']['MARIADB_USER']);
        $this->assertSame('pw', $compose['services']['db']['environment']['MARIADB_ROOT_PASSWORD']);
        $this->assertSame('shop', $compose['services']['app']['environment']['DB_DATABASE']);
        $this->assertSame('pw', $compose['services']['app']['environment']['DB_PASSWORD']);
    }

    public function test_redis_added_when_enabled(): void
    {
        $profile = RepoProfile::factory()->withBackingServices(['redis'])->create();

        $compose = Yaml::parse($this->build($profile)[0]);

        $this->assertArrayHasKey('redis', $compose['services']);
        $this->assertSame('redis:7-alpine', $compose['services']['redis']['image']);
        $this->assertSame('redis', $compose['services']['app']['environment']['REDIS_HOST']);
        $this->assertEquals('6379', $compose['services']['app']['environment']['REDIS_PORT']);
        $this->assertSame(['condition' => 'service_healthy'], $compose['services']['app']['depends_on']['redis']);
    }
}
