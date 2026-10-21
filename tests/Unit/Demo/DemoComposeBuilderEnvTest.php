<?php

declare(strict_types=1);

namespace Tests\Unit\Demo;

use App\Models\Task;
use App\Models\Workspace;
use App\Services\Demo\DemoComposeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DemoComposeBuilderEnvTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_env_is_merged_and_argos_keys_win(): void
    {
        $workspace = Workspace::factory()
            ->withComposerRegistries([['host' => 'a.test', 'token' => 'sek']])
            ->withWorkerEnv([
                ['name' => 'MEILI_KEY', 'value' => 'abc'],
                ['name' => 'APP_URL', 'value' => 'http://evil'], // reserved → dropped
            ])
            ->create();
        $task = Task::factory()->create(['workspace_id' => $workspace->id]);

        $entry = ['service' => 'app', 'port' => 80, 'workspace_mount' => '/var/www/html'];

        $yaml = app(DemoComposeBuilder::class)
            ->buildOverrideYaml($task, 'demo-x', $entry, 'http://demo-x.test:8080');

        $env = Yaml::parse($yaml)['services']['app']['environment'];

        $this->assertArrayHasKey('COMPOSER_AUTH', $env);
        $this->assertSame('abc', $env['MEILI_KEY']);
        // Argos-owned APP_URL wins over a workspace attempt to set it.
        $this->assertSame('http://demo-x.test:8080', $env['APP_URL']);
    }

    public function test_task_previews_serve_as_the_workspace_user(): void
    {
        $entry = ['service' => 'app', 'port' => 80, 'workspace_mount' => '/var/www/html'];
        $env = Yaml::parse(app(DemoComposeBuilder::class)
            ->buildOverrideYaml(Task::factory()->create(), 'demo-x', $entry, 'http://demo-x.test:8080'))['services']['app']['environment'];

        $this->assertSame('argos', $env['ARGOS_FPM_USER']);
    }
}
