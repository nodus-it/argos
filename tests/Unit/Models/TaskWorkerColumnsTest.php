<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\WorkerAgent;
use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskWorkerColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_stack_override_relation(): void
    {
        $stack = WorkerStack::factory()->create();
        $task = Task::factory()->create(['worker_stack_id_override' => $stack->id]);

        $this->assertSame($stack->id, $task->workerStackOverride->id);
    }

    public function test_worker_agent_override_relation(): void
    {
        $agent = WorkerAgent::factory()->create();
        $task = Task::factory()->create(['worker_agent_id_override' => $agent->id]);

        $this->assertSame($agent->id, $task->workerAgentOverride->id);
    }

    public function test_agent_credential_relation(): void
    {
        $credential = AgentCredential::factory()->create();
        $task = Task::factory()->create(['agent_credential_id' => $credential->id]);

        $this->assertSame($credential->id, $task->agentCredential->id);
    }

    public function test_worker_config_override_round_trips_as_array(): void
    {
        $task = Task::factory()->create([
            'worker_config_override' => ['stack' => 'php-8.4', 'extras' => ['node-22']],
        ]);

        $this->assertSame(
            ['stack' => 'php-8.4', 'extras' => ['node-22']],
            $task->fresh()->worker_config_override,
        );
    }

    public function test_agent_config_round_trips_as_array(): void
    {
        $task = Task::factory()->create([
            'agent_config' => ['model' => 'claude-haiku-4-5', 'temperature' => 0.2],
        ]);

        $reloaded = $task->fresh();

        $this->assertSame('claude-haiku-4-5', $reloaded->agent_config['model']);
        $this->assertSame(0.2, $reloaded->agent_config['temperature']);
    }

    public function test_overrides_default_to_null(): void
    {
        $task = Task::factory()->create();

        $this->assertNull($task->fresh()->worker_stack_id_override);
        $this->assertNull($task->fresh()->worker_agent_id_override);
        $this->assertNull($task->fresh()->agent_credential_id);
        $this->assertNull($task->fresh()->worker_config_override);
        $this->assertNull($task->fresh()->agent_config);
    }
}
