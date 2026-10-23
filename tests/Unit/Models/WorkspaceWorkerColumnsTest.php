<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\BackingService;
use App\Enums\WorkerSource;
use App\Models\WorkerStack;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceWorkerColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_source_defaults_to_standard(): void
    {
        $workspace = Workspace::factory()->create();

        $this->assertSame(WorkerSource::Standard, $workspace->fresh()->worker_source);
    }

    public function test_worker_source_is_enum_cast(): void
    {
        $workspace = Workspace::factory()->create(['worker_source' => 'byoi']);

        $this->assertSame(WorkerSource::Byoi, $workspace->fresh()->worker_source);
    }

    public function test_worker_stack_relation_loads(): void
    {
        $stack = WorkerStack::factory()->create();
        $workspace = Workspace::factory()->create(['worker_stack_id' => $stack->id]);

        $this->assertSame($stack->id, $workspace->workerStack->id);
    }

    public function test_composer_registries_round_trip_encrypted(): void
    {
        $data = [['host' => 'packages.filamentphp.com', 'username' => 'u', 'token' => 'sekret']];
        $workspace = Workspace::factory()->create(['composer_registries' => $data]);

        $this->assertSame($data, $workspace->fresh()->composer_registries);

        $raw = (string) DB::table('workspaces')->where('id', $workspace->id)->value('composer_registries');
        $this->assertStringNotContainsString('packages.filamentphp.com', $raw);
        $this->assertStringNotContainsString('sekret', $raw);
    }

    public function test_worker_env_round_trip_encrypted(): void
    {
        $data = [['name' => 'MEILI_KEY', 'value' => 'sekret']];
        $workspace = Workspace::factory()->create(['worker_env' => $data]);

        $this->assertSame($data, $workspace->fresh()->worker_env);

        $raw = (string) DB::table('workspaces')->where('id', $workspace->id)->value('worker_env');
        $this->assertStringNotContainsString('sekret', $raw);
    }

    public function test_worker_services_round_trip_and_resolve_enums(): void
    {
        $workspace = Workspace::factory()->withBackingServices(['mysql', 'redis', 'bogus'])->create();
        $fresh = $workspace->fresh();

        $this->assertSame(['mysql', 'redis', 'bogus'], $fresh->worker_services);
        // backingServices() drops the unknown value.
        $this->assertEquals([BackingService::Mysql, BackingService::Redis], $fresh->backingServices());
    }

    public function test_worker_service_config_round_trips(): void
    {
        $config = ['mysql' => ['database' => 'shop', 'username' => 'sa', 'password' => 'pw']];
        $workspace = Workspace::factory()->create(['worker_service_config' => $config]);

        $this->assertSame($config, $workspace->fresh()->worker_service_config);
    }
}
