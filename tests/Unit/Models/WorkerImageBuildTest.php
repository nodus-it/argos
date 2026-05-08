<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\WorkerImageBuildStatus;
use App\Models\WorkerAgent;
use App\Models\WorkerImageBuild;
use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkerImageBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_queued_build_with_stack_and_agent(): void
    {
        $build = WorkerImageBuild::factory()->create();

        $this->assertSame(WorkerImageBuildStatus::Queued, $build->status);
        $this->assertNotNull($build->worker_stack_id);
        $this->assertNotNull($build->worker_agent_id);
        $this->assertNull($build->built_at);
    }

    public function test_ready_state_sets_built_at_and_size(): void
    {
        $build = WorkerImageBuild::factory()->ready()->create();

        $this->assertSame(WorkerImageBuildStatus::Ready, $build->status);
        $this->assertNotNull($build->built_at);
        $this->assertSame(1_200_000_000, $build->size_bytes);
    }

    public function test_failed_state_carries_log(): void
    {
        $build = WorkerImageBuild::factory()->failed()->create();

        $this->assertSame(WorkerImageBuildStatus::Failed, $build->status);
        $this->assertStringContainsString('docker build failed', $build->build_log);
    }

    public function test_stack_and_agent_relations_load(): void
    {
        $stack = WorkerStack::factory()->create();
        $agent = WorkerAgent::factory()->create();
        $build = WorkerImageBuild::factory()->create([
            'worker_stack_id' => $stack->id,
            'worker_agent_id' => $agent->id,
        ]);

        $this->assertSame($stack->id, $build->stack->id);
        $this->assertSame($agent->id, $build->agent->id);
    }

    public function test_status_is_terminal_helper(): void
    {
        $this->assertTrue(WorkerImageBuildStatus::Ready->isTerminal());
        $this->assertTrue(WorkerImageBuildStatus::Failed->isTerminal());
        $this->assertFalse(WorkerImageBuildStatus::Queued->isTerminal());
        $this->assertFalse(WorkerImageBuildStatus::Building->isTerminal());
    }
}
