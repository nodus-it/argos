<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\WorkerSource;
use App\Models\RepoProfile;
use App\Models\WorkerAgent;
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

    public function test_worker_agent_relation_loads(): void
    {
        $agent = WorkerAgent::factory()->create();
        $profile = RepoProfile::factory()->create(['worker_agent_id' => $agent->id]);

        $this->assertSame($agent->id, $profile->workerAgent->id);
    }

    public function test_legacy_worker_image_column_remains_writable(): void
    {
        $profile = RepoProfile::factory()->create([
            'worker_image' => 'argos-worker:local-php8.4',
        ]);

        $this->assertSame('argos-worker:local-php8.4', $profile->fresh()->worker_image);
    }
}
