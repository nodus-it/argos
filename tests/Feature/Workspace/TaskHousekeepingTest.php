<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\DemoStatus;
use App\Enums\TaskStatus;
use App\Models\Demo;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\Demo\DemoDeployer;
use App\Services\Workspace\WorkspaceShipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/** The scheduled task commands: `argos:tasks:cleanup` and `argos:tasks:sync-prs`. */
class TaskHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create(['cleanup_after_days' => 14]);
        $this->mock(DemoDeployer::class)->shouldReceive('teardown');
    }

    /** @param  array<string, mixed>  $attributes */
    private function task(string $title, array $attributes = []): Task
    {
        return Task::factory()->create(['workspace_id' => $this->workspace->id, 'title' => $title, ...$attributes]);
    }

    private function finished(string $title, int $daysAgo, array $attributes = []): Task
    {
        return $this->task($title, ['status' => TaskStatus::Done, 'done_at' => now()->subDays($daysAgo), ...$attributes]);
    }

    public function test_cleanup_frees_tasks_finished_longer_ago_than_the_workspace_keeps_them(): void
    {
        $old = $this->finished('old-done', 20);
        $cancelled = $this->finished('old-cancelled', 15, ['status' => TaskStatus::Cancelled]);
        $recent = $this->finished('recent-done', 3);
        $active = $this->task('active');
        $alreadyCleaned = $this->finished('cleaned', 30, ['cleaned_at' => now()->subDays(10)]);
        $busy = $this->finished('session-open', 30, ['session_open' => true]);
        $preview = Demo::factory()->live()->create(['task_id' => $old->id]);

        // Another workspace keeps finished tasks for longer.
        $patient = Task::factory()->create([
            'workspace_id' => Workspace::factory()->create(['cleanup_after_days' => 60])->id,
            'status' => TaskStatus::Done,
            'done_at' => now()->subDays(20),
        ]);

        $this->artisan('argos:tasks:cleanup')->expectsOutputToContain('2 finished task(s) cleaned up.')->assertSuccessful();

        $this->assertNotNull($old->fresh()->cleaned_at);
        $this->assertNotNull($cancelled->fresh()->cleaned_at);
        $this->assertSame(DemoStatus::Stopped, $preview->fresh()->status);
        foreach ([$recent, $active, $busy, $patient] as $spared) {
            $this->assertNull($spared->fresh()->cleaned_at, $spared->title);
        }
        $this->assertTrue($alreadyCleaned->fresh()->cleaned_at->lt(now()->subDays(9)));
        // The conversation stays.
        $this->assertNull($old->fresh()->archived_at);
        $this->assertDatabaseHas('tasks', ['id' => $old->id]);
    }

    public function test_cleanup_dry_run_changes_nothing(): void
    {
        $old = $this->finished('old-done', 20);
        $preview = Demo::factory()->live()->create(['task_id' => $old->id]);

        $this->artisan('argos:tasks:cleanup', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] Cleaning up '.$this->workspace->slug.'/'.$old->slug)
            ->assertSuccessful();

        $this->assertNull($old->fresh()->cleaned_at);
        $this->assertSame(DemoStatus::Live, $preview->fresh()->status);
    }

    public function test_sync_prs_refreshes_open_and_draft_pull_requests_only(): void
    {
        $open = $this->task('open', ['pr_number' => 1, 'pr_state' => 'open', 'status' => TaskStatus::PrOpen]);
        $draft = $this->task('draft', ['pr_number' => 2, 'pr_state' => 'draft', 'status' => TaskStatus::PrOpen]);
        $this->task('merged', ['pr_number' => 3, 'pr_state' => 'merged', 'status' => TaskStatus::Done]);
        $this->task('no-pr');

        $synced = [];
        $this->mock(WorkspaceShipService::class, function (MockInterface $mock) use (&$synced): void {
            $mock->shouldReceive('syncPullRequest')->andReturnUsing(function (Task $task) use (&$synced): void {
                $synced[] = $task->id;
            });
        });

        $this->artisan('argos:tasks:sync-prs')->assertSuccessful();

        sort($synced);
        $expected = [$open->id, $draft->id];
        sort($expected);
        $this->assertSame($expected, $synced);
    }
}
