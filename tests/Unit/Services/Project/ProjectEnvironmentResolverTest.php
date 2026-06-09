<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Project;

use App\Models\RepoProfile;
use App\Services\Project\ProjectEnvironmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectEnvironmentResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function resolve(RepoProfile $profile): array
    {
        return app(ProjectEnvironmentResolver::class)->resolve($profile);
    }

    public function test_empty_profile_yields_no_env(): void
    {
        $this->assertSame([], $this->resolve(RepoProfile::factory()->create()));
    }

    public function test_builds_composer_auth_from_registries(): void
    {
        $profile = RepoProfile::factory()->withComposerRegistries([
            ['host' => 'packages.filamentphp.com', 'username' => 'lic', 'token' => 'secret1'],
            ['host' => 'composer.fluxui.dev', 'username' => '', 'token' => 'secret2'],
        ])->create();

        $env = $this->resolve($profile);

        $this->assertArrayHasKey('COMPOSER_AUTH', $env);
        $decoded = json_decode($env['COMPOSER_AUTH'], true);

        $this->assertSame(
            ['username' => 'lic', 'password' => 'secret1'],
            $decoded['http-basic']['packages.filamentphp.com'],
        );
        // empty username falls back to "token"
        $this->assertSame(
            ['username' => 'token', 'password' => 'secret2'],
            $decoded['http-basic']['composer.fluxui.dev'],
        );
    }

    public function test_skips_incomplete_registry_rows(): void
    {
        $profile = RepoProfile::factory()->withComposerRegistries([
            ['host' => '', 'token' => 'x'],
            ['host' => 'satis.dedoc.co', 'token' => ''],
        ])->create();

        $this->assertArrayNotHasKey('COMPOSER_AUTH', $this->resolve($profile));
    }

    public function test_maps_worker_env_rows_and_skips_nameless(): void
    {
        $profile = RepoProfile::factory()->withWorkerEnv([
            ['name' => 'MEILI_KEY', 'value' => 'abc'],
            ['name' => '', 'value' => 'ignored'],
        ])->create();

        $env = $this->resolve($profile);

        $this->assertSame('abc', $env['MEILI_KEY']);
        $this->assertArrayNotHasKey('', $env);
    }

    public function test_hand_written_composer_auth_overrides_generated(): void
    {
        $profile = RepoProfile::factory()
            ->withComposerRegistries([['host' => 'a.test', 'token' => 'gen']])
            ->withWorkerEnv([['name' => 'COMPOSER_AUTH', 'value' => '{"http-basic":{}}']])
            ->create();

        $this->assertSame('{"http-basic":{}}', $this->resolve($profile)['COMPOSER_AUTH']);
    }

    public function test_reserved_keys_are_dropped(): void
    {
        $profile = RepoProfile::factory()->withWorkerEnv([
            ['name' => 'REPO_TOKEN', 'value' => 'evil'],
            ['name' => 'APP_URL', 'value' => 'http://evil'],
            ['name' => 'SAFE', 'value' => 'ok'],
        ])->create();

        $env = $this->resolve($profile);

        $this->assertArrayNotHasKey('REPO_TOKEN', $env);
        $this->assertArrayNotHasKey('APP_URL', $env);
        $this->assertSame('ok', $env['SAFE']);
    }
}
