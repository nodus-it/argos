<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use App\Services\Workflow\PhaseCommandBuilder;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseCommandBuilderAgentSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(WorkerImageResolver::class, function ($mock): void {
            $mock->shouldReceive('resolveOrBuild')->andReturn('argos-worker:test');
        });
    }

    public function test_tasks_never_fall_back_to_a_personal_agent(): void
    {
        // The personal agent is older, so ordering alone would pick it.
        AgentCredential::factory()->ownedBy(User::factory()->create())->create([
            'credentials' => ['token' => 'personal-token'],
            'created_at' => now()->subDay(),
        ]);
        AgentCredential::factory()->create(['credentials' => ['token' => 'shared-token']]);
        $task = Task::factory()->create(['repo_profile_id' => RepoProfile::factory()->create()->id]);

        $joined = implode(' ', app(PhaseCommandBuilder::class)->build($task, 'implement'));

        $this->assertStringContainsString('shared-token', $joined);
        $this->assertStringNotContainsString('personal-token', $joined);
    }

    public function test_an_explicitly_chosen_personal_agent_is_used(): void
    {
        $personal = AgentCredential::factory()->ownedBy(User::factory()->create())->create(['credentials' => ['token' => 'personal-token']]);
        AgentCredential::factory()->create(['credentials' => ['token' => 'shared-token']]);
        $task = Task::factory()->create([
            'repo_profile_id' => RepoProfile::factory()->create()->id,
            'agent_credential_id' => $personal->id,
        ]);

        $joined = implode(' ', app(PhaseCommandBuilder::class)->build($task, 'implement'));

        $this->assertStringContainsString('personal-token', $joined);
    }
}
