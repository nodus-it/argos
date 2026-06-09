<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\AgentName;
use App\Enums\BackingService;
use App\Enums\WorkerSource;
use App\Models\RepoProfile;
use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_composer_registries_round_trip_encrypted(): void
    {
        $data = [['host' => 'packages.filamentphp.com', 'username' => 'u', 'token' => 'sekret']];
        $profile = RepoProfile::factory()->create(['composer_registries' => $data]);

        $this->assertSame($data, $profile->fresh()->composer_registries);

        $raw = (string) DB::table('repo_profiles')->where('id', $profile->id)->value('composer_registries');
        $this->assertStringNotContainsString('packages.filamentphp.com', $raw);
        $this->assertStringNotContainsString('sekret', $raw);
    }

    public function test_worker_env_round_trip_encrypted(): void
    {
        $data = [['name' => 'MEILI_KEY', 'value' => 'sekret']];
        $profile = RepoProfile::factory()->create(['worker_env' => $data]);

        $this->assertSame($data, $profile->fresh()->worker_env);

        $raw = (string) DB::table('repo_profiles')->where('id', $profile->id)->value('worker_env');
        $this->assertStringNotContainsString('sekret', $raw);
    }

    public function test_worker_services_round_trip_and_resolve_enums(): void
    {
        $profile = RepoProfile::factory()->withBackingServices(['mysql', 'redis', 'bogus'])->create();
        $fresh = $profile->fresh();

        $this->assertSame(['mysql', 'redis', 'bogus'], $fresh->worker_services);
        // backingServices() drops the unknown value.
        $this->assertEquals([BackingService::Mysql, BackingService::Redis], $fresh->backingServices());
    }
}
