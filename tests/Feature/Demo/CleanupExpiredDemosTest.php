<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Enums\DemoStatus;
use App\Jobs\Workspace\StopWorkspaceDemoJob;
use App\Models\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CleanupExpiredDemosTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_teardown_only_for_expired_live_demos(): void
    {
        Bus::fake();

        $expired = Demo::factory()->live()->create(['ttl_until' => now()->subHour()]);
        $fresh = Demo::factory()->live()->create(['ttl_until' => now()->addHour()]);
        $expiredButStopped = Demo::factory()->create([
            'status' => DemoStatus::Stopped,
            'ttl_until' => now()->subHour(),
        ]);

        $this->artisan('argos:cleanup-demos')->assertSuccessful();

        Bus::assertDispatched(StopWorkspaceDemoJob::class, fn (StopWorkspaceDemoJob $j): bool => $j->workspaceId === $expired->workspace_id);
        Bus::assertNotDispatched(StopWorkspaceDemoJob::class, fn (StopWorkspaceDemoJob $j): bool => $j->workspaceId === $fresh->workspace_id);
        Bus::assertNotDispatched(StopWorkspaceDemoJob::class, fn (StopWorkspaceDemoJob $j): bool => $j->workspaceId === $expiredButStopped->workspace_id);
    }

    public function test_succeeds_with_no_expired_demos(): void
    {
        Bus::fake();
        Demo::factory()->live()->create(['ttl_until' => now()->addDay()]);

        $this->artisan('argos:cleanup-demos')->assertSuccessful();

        Bus::assertNotDispatched(StopWorkspaceDemoJob::class);
    }
}
