<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Enums\DemoStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\App\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Jobs\Workspace\DeployWorkspaceDemoJob;
use App\Jobs\Workspace\RerunWorkspaceDemoCommandsJob;
use App\Jobs\Workspace\StopWorkspaceDemoJob;
use App\Livewire\Workspace\ContextPanel;
use App\Livewire\Workspace\PreviewPanel;
use App\Models\Demo;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Demo\DemoDeployer;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspacePreviewService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspacePreviewTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $editor;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        // Workspace pages live in the workspaces panel (/w).
        Filament::setCurrentPanel('app');
        Queue::fake();
        config(['argos.preview.enabled' => true]);
        $this->workspace = Workspace::factory()->create(['slug' => 'ws', 'live_demo_enabled' => true, 'status' => WorkspaceStatus::Running]);
        $this->editor = $this->member(WorkspaceRole::Member);
        $this->task = Task::factory()->ownedBy($this->editor)->create(['workspace_id' => $this->workspace->id, 'slug' => 'login']);
    }

    private function member(WorkspaceRole $role): User
    {
        $user = User::factory()->create();
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function service(): WorkspacePreviewService
    {
        return app(WorkspacePreviewService::class);
    }

    public function test_reasons_when_previews_are_unavailable(): void
    {
        $this->assertNull($this->service()->unavailableReason($this->task));

        $this->workspace->forceFill(['live_demo_enabled' => false])->save();
        $this->assertSame(__('workspaces.preview.disabled_project'), $this->service()->unavailableReason($this->task->fresh()));

        config(['argos.preview.enabled' => false]);
        $this->assertSame(__('workspaces.preview.disabled_platform'), $this->service()->unavailableReason($this->task->fresh()));
    }

    public function test_the_task_owner_starts_viewers_cannot(): void
    {
        $this->service()->start($this->task, $this->editor);
        Queue::assertPushed(DeployWorkspaceDemoJob::class, fn ($job) => $job->taskId === $this->task->id);

        $this->expectException(AuthorizationException::class);
        $this->service()->start($this->task, $this->member(WorkspaceRole::Viewer));
    }

    public function test_members_cannot_start_previews_of_tasks_they_are_not_part_of(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->service()->start($this->task, $this->member(WorkspaceRole::Member));
    }

    public function test_the_preview_slug_combines_workspace_and_task(): void
    {
        $this->assertSame('demo-ws-login', $this->task->demoSlug());
    }

    public function test_start_refuses_when_unavailable(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();

        $this->expectException(WorkspaceException::class);
        $this->service()->start($this->task, $this->editor);
    }

    public function test_rerun_needs_a_live_preview(): void
    {
        try {
            $this->service()->rerunCommands($this->task, $this->editor);
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            Queue::assertNotPushed(RerunWorkspaceDemoCommandsJob::class);
        }

        Demo::factory()->create(['task_id' => $this->task->id, 'status' => DemoStatus::Live]);
        $this->service()->rerunCommands($this->task, $this->editor);
        Queue::assertPushed(RerunWorkspaceDemoCommandsJob::class, fn ($job) => $job->taskId === $this->task->id);
    }

    public function test_stop_tears_down_and_marks_stopped(): void
    {
        $demo = Demo::factory()->create(['task_id' => $this->task->id, 'status' => DemoStatus::Live, 'url' => 'http://x']);
        $other = Demo::factory()->create(['task_id' => Task::factory()->create(['workspace_id' => $this->workspace->id])->id, 'status' => DemoStatus::Live, 'url' => 'http://y']);
        $this->mock(DemoDeployer::class)->shouldReceive('teardown')->once();

        $this->service()->stop($this->task, $this->editor);

        $this->assertSame(DemoStatus::Stopped, $demo->fresh()->status);
        $this->assertNull($demo->fresh()->url);
        // Other tasks keep their previews.
        $this->assertSame(DemoStatus::Live, $other->fresh()->status);
    }

    public function test_the_preview_panel_is_wired_into_the_workspace_page(): void
    {
        Livewire::actingAs($this->editor)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()])
            ->assertSeeLivewire(ContextPanel::class);
        Livewire::withoutLazyLoading()->actingAs($this->editor)
            ->test(ContextPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertSeeLivewire(PreviewPanel::class);

        Livewire::actingAs($this->editor)
            ->test(PreviewPanel::class, ['workspaceId' => $this->workspace->id, 'taskId' => $this->task->id])
            ->assertSee(__('workspaces.preview.start_short'))
            ->call('start')
            ->assertSee(__('workspaces.preview.queued'));
        Queue::assertPushed(DeployWorkspaceDemoJob::class, fn ($job) => $job->taskId === $this->task->id);
    }

    public function test_the_panel_shows_live_previews_with_their_actions(): void
    {
        Demo::factory()->create(['task_id' => $this->task->id, 'status' => DemoStatus::Live, 'ttl_until' => now()->addDay(), 'build_log' => 'built ok']);

        Livewire::actingAs($this->editor)
            ->test(PreviewPanel::class, ['workspaceId' => $this->workspace->id, 'taskId' => $this->task->id])
            ->assertSee(__('workspaces.preview.rerun'))
            ->assertSee(__('workspaces.preview.stop'))
            ->assertSee('built ok');
    }

    public function test_viewers_see_no_preview_actions(): void
    {
        Livewire::actingAs($this->member(WorkspaceRole::Viewer))
            ->test(PreviewPanel::class, ['workspaceId' => $this->workspace->id, 'taskId' => $this->task->id])
            ->assertDontSee(__('workspaces.preview.start_short'));
    }

    public function test_expired_task_previews_are_stopped_by_the_cleanup(): void
    {
        $demo = Demo::factory()->create(['task_id' => $this->task->id, 'status' => DemoStatus::Live, 'ttl_until' => now()->subMinute()]);

        $this->artisan('argos:cleanup-demos')->assertSuccessful();

        Queue::assertPushed(StopWorkspaceDemoJob::class, fn ($job) => $job->taskId === $this->task->id);
        $this->assertSame($this->workspace->id, $demo->workspace_id);

        $this->mock(DemoDeployer::class)->shouldReceive('teardown')->once();
        (new StopWorkspaceDemoJob($this->task->id))->handle($this->service());
        $this->assertSame(DemoStatus::Stopped, $demo->fresh()->status);
    }
}
