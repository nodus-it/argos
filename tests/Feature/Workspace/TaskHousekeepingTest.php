<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\DemoStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\WorkspaceDaemonTransport;
use App\Models\Demo;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\Workspace;
use App\Services\Demo\DemoDeployer;
use App\Services\Worker\WorkerSidecarManager;
use App\Services\Workspace\PullRequestService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
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
        $this->workspace = Workspace::factory()->create(['cleanup_after_days' => 14, 'status' => WorkspaceStatus::Running]);
        $this->mock(DemoDeployer::class)->shouldReceive('teardown');
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->dropped = [];
        $this->mock(WorkerSidecarManager::class)->shouldReceive('dropTaskDatabase')->andReturnUsing(function (Workspace $workspace, string $database): void {
            $this->dropped[] = $database;
        });
    }

    /** @var list<string> task databases dropped */
    private array $dropped = [];

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
        $old = $this->finished('old-done', 20, ['db_name' => 'task_old_done']);
        $cancelled = $this->finished('old-cancelled', 15, ['status' => TaskStatus::Cancelled]);
        $recent = $this->finished('recent-done', 3);
        $active = $this->task('active');
        $alreadyCleaned = $this->finished('cleaned', 30, ['cleaned_at' => now()->subDays(10)]);
        $busy = $this->finished('session-open', 30, ['session_open' => true]);
        $preview = Demo::factory()->live()->create(['task_id' => $old->id]);

        // Another workspace keeps finished tasks for longer.
        $patient = Task::factory()->create([
            'workspace_id' => Workspace::factory()->create(['cleanup_after_days' => 60, 'status' => WorkspaceStatus::Running])->id,
            'status' => TaskStatus::Done,
            'done_at' => now()->subDays(20),
        ]);
        // A stopped workspace has no container to remove worktrees in: a later pass does it.
        $stopped = Task::factory()->create([
            'workspace_id' => Workspace::factory()->create(['cleanup_after_days' => 14, 'status' => WorkspaceStatus::Stopped])->id,
            'status' => TaskStatus::Done,
            'done_at' => now()->subDays(20),
            'db_name' => 'task_stopped',
        ]);

        $this->artisan('argos:tasks:cleanup')->expectsOutputToContain('2 finished task(s) cleaned up.')->assertSuccessful();

        $this->assertNotNull($old->fresh()->cleaned_at);
        $this->assertNotNull($cancelled->fresh()->cleaned_at);
        $this->assertSame(DemoStatus::Stopped, $preview->fresh()->status);
        // Worktree and database go with it.
        $this->assertEqualsCanonicalizing([$old->slug, $cancelled->slug], FakeWorkspaceDaemon::$removed);
        $this->assertSame(['task_old_done'], $this->dropped);
        foreach ([$recent, $active, $busy, $patient, $stopped] as $spared) {
            $this->assertNull($spared->fresh()->cleaned_at, $spared->title);
        }
        $this->assertTrue($alreadyCleaned->fresh()->cleaned_at->lt(now()->subDays(9)));
        // The conversation stays, in the archive.
        $this->assertNotNull($old->fresh()->archived_at);
        $this->assertNull($recent->fresh()->archived_at);
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

    public function test_cleanup_skips_tasks_of_deleted_workspaces(): void
    {
        $old = $this->finished('old-done', 20);
        $gone = Workspace::factory()->create(['cleanup_after_days' => 14]);
        Task::factory()->create(['workspace_id' => $gone->id, 'status' => TaskStatus::Done, 'done_at' => now()->subDays(30)]);
        $gone->delete();

        $this->artisan('argos:tasks:cleanup')->assertSuccessful();

        $this->assertNotNull($old->fresh()->cleaned_at);
    }

    public function test_sync_prs_refreshes_open_and_draft_pull_requests_only(): void
    {
        $open = $this->task('open', ['pr_number' => 1, 'pr_state' => 'open', 'status' => TaskStatus::PrOpen]);
        $draft = $this->task('draft', ['pr_number' => 2, 'pr_state' => 'draft', 'status' => TaskStatus::PrOpen]);
        $this->task('merged', ['pr_number' => 3, 'pr_state' => 'merged', 'status' => TaskStatus::Done]);
        $this->task('no-pr');

        $synced = [];
        $this->mock(PullRequestService::class, function (MockInterface $mock) use (&$synced): void {
            $mock->shouldReceive('refresh')->andReturnUsing(function (Task $task) use (&$synced): ?\App\Services\GitProvider\PullRequestDetails {
                $synced[] = $task->id;

                return null;
            });
        });

        $this->artisan('argos:tasks:sync-prs')->assertSuccessful();

        sort($synced);
        $expected = [$open->id, $draft->id];
        sort($expected);
        $this->assertSame($expected, $synced);
    }

    public function test_sync_prs_skips_deleted_workspaces_and_survives_a_failing_task(): void
    {
        $gone = Workspace::factory()->create();
        Task::factory()->create(['workspace_id' => $gone->id, 'pr_number' => 1, 'pr_state' => 'open', 'status' => TaskStatus::PrOpen]);
        $gone->delete();
        $failing = $this->task('failing', ['pr_number' => 2, 'pr_state' => 'open', 'status' => TaskStatus::PrOpen]);
        $next = $this->task('next', ['pr_number' => 3, 'pr_state' => 'open', 'status' => TaskStatus::PrOpen]);

        $synced = [];
        $this->mock(PullRequestService::class, function (MockInterface $mock) use (&$synced, $failing): void {
            $mock->shouldReceive('refresh')->andReturnUsing(function (Task $task) use (&$synced, $failing): null {
                $synced[] = $task->id;
                if ($task->is($failing)) {
                    throw new \RuntimeException('provider down');
                }

                return null;
            });
        });

        $this->artisan('argos:tasks:sync-prs')->assertSuccessful();

        sort($synced);
        $expected = [$failing->id, $next->id];
        sort($expected);
        $this->assertSame($expected, $synced);
    }

    public function test_cleanup_says_where_unpushed_work_was_kept(): void
    {
        $task = $this->finished('unpushed', 20);
        $this->mock(WorkspaceDaemonClient::class, function (MockInterface $mock): void {
            $api = \Mockery::mock(WorkspaceDaemonTransport::class);
            $api->shouldReceive('removeWorktree')->once()->andReturn('refs/argos/backup/unpushed');
            $mock->shouldReceive('for')->andReturn($api);
        });

        $this->artisan('argos:tasks:cleanup')->assertSuccessful();

        $notice = TaskEvent::query()->where('task_id', $task->id)->latest('id')->firstOrFail();
        $this->assertSame(['kind' => 'work_backed_up', 'ref' => 'refs/argos/backup/unpushed'], $notice->payload);
        $this->assertNotNull($task->fresh()->cleaned_at);
    }
}
