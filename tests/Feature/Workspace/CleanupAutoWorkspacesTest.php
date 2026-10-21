<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\AutoThreadState;
use App\Enums\WorkspaceOrigin;
use App\Models\Thread;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CleanupAutoWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $deleted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(WorkspaceService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deleteWorkspace')->andReturnUsing(function (Workspace $w): void {
                $this->deleted[] = $w->name;
            });
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function workspace(string $name, array $attributes, ?AutoThreadState $state = AutoThreadState::Done): Workspace
    {
        $workspace = Workspace::factory()->create(['name' => $name, 'origin' => WorkspaceOrigin::Api, 'last_activity_at' => now()->subDays(30), ...$attributes]);
        if ($state !== null) {
            Thread::factory()->create(['workspace_id' => $workspace->id, 'auto_state' => $state]);
        }

        return $workspace;
    }

    public function test_it_removes_finished_automatic_workspaces_and_spares_the_rest(): void
    {
        $this->workspace('merged', ['pr_state' => 'merged', 'last_activity_at' => now()->subDays(2)]);
        $this->workspace('old-done', []);
        $this->workspace('old-failed', [], AutoThreadState::Failed);
        $this->workspace('manual', ['origin' => WorkspaceOrigin::Manual]);
        $this->workspace('recent-done', ['last_activity_at' => now()->subDays(2)]);
        $this->workspace('still-running', [], AutoThreadState::Fixing);
        $this->workspace('just-touched', ['pr_state' => 'closed', 'last_activity_at' => now()->subMinutes(10)]);
        $busy = $this->workspace('session-open', ['pr_state' => 'merged']);
        Thread::factory()->create(['workspace_id' => $busy->id, 'session_open' => true]);

        $this->artisan('argos:workspaces:cleanup-auto')->assertSuccessful();

        sort($this->deleted);
        $this->assertSame(['merged', 'old-done', 'old-failed'], $this->deleted);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $this->workspace('merged', ['pr_state' => 'merged']);

        $this->artisan('argos:workspaces:cleanup-auto', ['--dry-run' => true])->expectsOutputToContain('[dry-run]')->assertSuccessful();

        $this->assertSame([], $this->deleted);
    }
}
