<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AgentName;
use App\Enums\WorkerSource;
use App\Models\RepoProfile;
use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepoProfileWorkerColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_source_defaults_to_standard(): void
    {
        $profile = RepoProfile::factory()->create();

        $this->assertSame(WorkerSource::Standard, $profile->fresh()->worker_source);
    }

    public function test_worker_source_is_enum_cast(): void
    {
        $profile = RepoProfile::factory()->create(['worker_source' => 'byoi']);

        $this->assertSame(WorkerSource::Byoi, $profile->fresh()->worker_source);
    }

    public function test_worker_config_round_trips_as_array(): void
    {
        $profile = RepoProfile::factory()->create([
            'worker_config' => ['dockerfile_path' => '.argos/worker.dockerfile'],
        ]);

        $this->assertSame(
            ['dockerfile_path' => '.argos/worker.dockerfile'],
            $profile->fresh()->worker_config,
        );
    }

    public function test_worker_stack_relation_loads(): void
    {
        $stack = WorkerStack::factory()->create();
        $profile = RepoProfile::factory()->create(['worker_stack_id' => $stack->id]);

        $this->assertSame($stack->id, $profile->workerStack->id);
    }

    public function test_worker_agent_name_is_enum_cast(): void
    {
        $profile = RepoProfile::factory()->create(['worker_agent_name' => 'claude-code']);

        $this->assertSame(AgentName::ClaudeCode, $profile->fresh()->worker_agent_name);
    }

    public function test_worker_agent_name_defaults_to_null(): void
    {
        $profile = RepoProfile::factory()->create();

        $this->assertNull($profile->fresh()->worker_agent_name);
    }
}
