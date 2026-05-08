<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\WorkerImageEntityStatus;
use App\Models\AgentCredential;
use App\Models\WorkerAgent;
use App\Models\WorkerImageBuild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_persists_with_defaults(): void
    {
        $agent = WorkerAgent::factory()->create();

        $this->assertNotNull($agent->id);
        $this->assertSame(WorkerImageEntityStatus::Active, $agent->status);
        $this->assertFalse($agent->is_builtin);
    }

    public function test_array_casts_round_trip(): void
    {
        $agent = WorkerAgent::factory()->create([
            'requires_stack_capabilities' => ['node', 'npm'],
            'config_schema' => ['fields' => ['model' => ['type' => 'string']]],
        ]);

        $reloaded = WorkerAgent::query()->find($agent->id);

        $this->assertSame(['node', 'npm'], $reloaded->requires_stack_capabilities);
        $this->assertSame(['fields' => ['model' => ['type' => 'string']]], $reloaded->config_schema);
    }

    public function test_claude_code_state_yields_expected_fields(): void
    {
        $agent = WorkerAgent::factory()->claudeCode()->create();

        $this->assertSame('claude-code', $agent->name);
        $this->assertSame('ClaudeCodeRunner', $agent->runner_class);
        $this->assertSame('@anthropic-ai/claude-code', $agent->npm_pkg);
        $this->assertTrue($agent->is_builtin);
    }

    public function test_credentials_relation(): void
    {
        $agent = WorkerAgent::factory()->create();
        AgentCredential::factory()->count(2)->create(['worker_agent_id' => $agent->id]);

        $this->assertCount(2, $agent->credentials);
    }

    public function test_image_builds_relation(): void
    {
        $agent = WorkerAgent::factory()->create();
        WorkerImageBuild::factory()->count(3)->create(['worker_agent_id' => $agent->id]);

        $this->assertCount(3, $agent->imageBuilds);
    }
}
