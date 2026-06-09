<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Workflow\PhaseCommandBuilder;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseCommandBuilderProjectEnvTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_injects_project_env_into_docker_command(): void
    {
        // Stub the image resolver so build() doesn't try to build a real image.
        $this->mock(WorkerImageResolver::class, function ($mock): void {
            $mock->shouldReceive('resolveOrBuild')->andReturn('argos-worker:test');
        });

        AgentCredential::create([
            'agent_name' => AgentName::ClaudeCode->value,
            'name' => 'test',
            'credentials' => ['token' => 'claude-token'],
            'status' => AgentCredentialStatus::Active->value,
        ]);

        $profile = RepoProfile::factory()
            ->withComposerRegistries([['host' => 'packages.filamentphp.com', 'username' => 'u', 'token' => 'sek']])
            ->withWorkerEnv([['name' => 'MEILI_KEY', 'value' => 'abc']])
            ->create();
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        $cmd = app(PhaseCommandBuilder::class)->build($task, 'implement');
        $joined = implode(' ', $cmd);

        $this->assertStringContainsString('MEILI_KEY=abc', $joined);
        $this->assertStringContainsString('COMPOSER_AUTH=', $joined);
    }
}
