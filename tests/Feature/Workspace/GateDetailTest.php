<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\GateRunStatus;
use App\Enums\TaskEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Livewire\Workspace\ContextPanel;
use App\Livewire\Workspace\GateDetail;
use App\Livewire\Workspace\ReviewPanel;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/** Gate detail slide-over and WorkspaceGateService::log (full log with tail fallback). */
class GateDetailTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('app');
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->owner = $this->member(WorkspaceRole::Member, 'Ada');
        $this->task = Task::factory()->ownedBy($this->owner)->create(['workspace_id' => $this->workspace->id, 'base_commit' => str_repeat('c', 40)]);
    }

    private function member(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function gateRun(array $attributes = []): WorkspaceGateRun
    {
        return WorkspaceGateRun::factory()->create([
            'task_id' => $this->task->id,
            'triggered_by' => $this->owner->id,
            'started_at' => now()->subSeconds(83),
            'finished_at' => now(),
            ...$attributes,
        ]);
    }

    private function failedRun(): WorkspaceGateRun
    {
        return $this->gateRun([
            'status' => GateRunStatus::Failed,
            'gates' => ['artisan' => 'pass', 'pint' => 'fail', 'pest' => 'fail', 'phpstan' => 'fail'],
            'logs' => ['pest' => "FAILED LoginTest (tail)\n", 'pint' => "tail\n", 'phpstan' => "app/Old.php:3: tail error\n"],
        ]);
    }

    private function detail(?User $user = null): mixed
    {
        return Livewire::actingAs($user ?? $this->owner)->test(GateDetail::class, ['workspaceId' => $this->workspace->id]);
    }

    public function test_context_panel_rows_open_the_detail_for_every_gate(): void
    {
        $this->gateRun();

        Livewire::withoutLazyLoading()->actingAs($this->owner)->test(ContextPanel::class, ['workspaceId' => $this->workspace->id])
            ->assertSeeLivewire(GateDetail::class)
            ->assertSeeHtml("gate-detail-open', { taskId: '{$this->task->id}', gate: 'pint' }")
            ->assertSeeHtml("gate: 'phpstan'")
            ->assertDontSeeHtml('ag-gate" disabled');
    }

    public function test_review_chips_open_the_detail(): void
    {
        $this->gateRun();

        Livewire::withoutLazyLoading()->withQueryParams(['task' => $this->task->id])->actingAs($this->owner)
            ->test(ReviewPanel::class, ['workspaceId' => $this->workspace->id])
            ->set('active', true)
            ->assertSeeHtml("gate-detail-open', { taskId: '{$this->task->id}', gate: 'pest' }");
    }

    public function test_a_passed_gate_shows_result_duration_baseline_and_full_log(): void
    {
        $this->gateRun(['logs' => ['pest' => "tail only\n"]]);

        $this->detail()
            ->assertDontSee(__('workspaces.gate_detail.result'))
            ->call('show', $this->task->id, 'pest')
            ->assertSee('Pest / PHPUnit')
            ->assertSee(__('workspaces.gate_detail.result'))
            ->assertSee(__('workspaces.focus.gate_state.pass'))
            ->assertSee(__('workspaces.gate_detail.duration'))
            ->assertSee('1m 23s')
            ->assertSee('Ada')
            ->assertSee(__('workspaces.gate_detail.baseline_ready'))
            ->assertSee('[full log] pest')
            ->assertSee('Tests:    1 failed, 1 passed', false)
            ->assertDontSee('tail only')
            ->assertDontSee(__('workspaces.gate_detail.log_tail'))
            ->assertDontSee(__('workspaces.gates.send_fix'));
    }

    public function test_the_baseline_hint_says_all_errors_count_without_a_baseline(): void
    {
        $this->gateRun(['baseline' => 'missing']);

        // A skipped gate has no baseline hint.
        $this->detail()->call('show', $this->task->id, 'phpstan')
            ->assertDontSee(__('workspaces.gate_detail.baseline_missing'));

        $this->detail()->call('show', $this->task->id, 'pest')
            ->assertSee(__('workspaces.gate_detail.baseline_missing'));

        // Pint ignores the baseline: no hint.
        $this->detail()->call('show', $this->task->id, 'pint')
            ->assertDontSee(__('workspaces.gate_detail.baseline'));
    }

    public function test_a_gate_without_a_run_opens_too(): void
    {
        $this->detail()->call('show', $this->task->id, 'pint')
            ->assertSee('Pint')
            ->assertSee(__('workspaces.gate_detail.not_run'))
            ->assertDontSee(__('workspaces.gate_detail.log'))
            ->assertDontSee(__('workspaces.gates.send_fix'));
    }

    public function test_a_failed_gate_lists_affected_files_and_offers_the_fix(): void
    {
        $this->failedRun();

        $this->detail()->call('show', $this->task->id, 'phpstan')
            ->assertSee(__('workspaces.gate_detail.files'))
            ->assertSee('LoginController.php')
            ->assertSee('User.php')
            ->assertSeeHtml("openFile('app\\/Http\\/Controllers\\/LoginController.php')")
            ->assertSee(trans_choice('workspaces.gate_detail.findings', 1, ['count' => 1]))
            ->assertDontSee('Old.php')
            ->assertSee(__('workspaces.gates.send_fix'));

        $this->detail()->call('show', $this->task->id, 'pint')
            ->assertSeeHtml("openFile('app\\/Http\\/Controllers\\/LoginController.php')");

        $panel = $this->detail()->call('show', $this->task->id, 'pest')
            ->assertSee('Failed asserting that 500 is 302.')
            ->assertSee(__('workspaces.gates.send_fix'))
            ->call('sendGateFix', 'pest')
            ->assertNotified(__('workspaces.gates.fix_sent'))
            ->assertDispatched('gate-detail-closed')
            ->assertSet('gate', null);
        $message = TaskEvent::query()->where('task_id', $this->task->id)->where('type', TaskEventType::UserMessage->value)->sole();
        $this->assertStringContainsString('# Quality-Gate-Fix: pest', $message->payload['text']);

        // Only failed gates can be sent.
        $panel->call('show', $this->task->id, 'artisan')
            ->assertDontSee(__('workspaces.gates.send_fix'))
            ->call('sendGateFix', 'artisan')
            ->assertNotified(__('workspaces.gates.no_prompt'));
    }

    public function test_the_fix_is_only_offered_to_people_who_may_write(): void
    {
        $this->failedRun();
        $colleague = $this->member(WorkspaceRole::Member, 'Bo');

        $this->detail($colleague)->call('show', $this->task->id, 'pest')
            ->assertSee('[full log] pest')
            ->assertDontSee(__('workspaces.gates.send_fix'))
            ->call('sendGateFix', 'pest')
            ->assertNotified(__('workspaces.task.viewer_hint'));

        $this->detail($this->member(WorkspaceRole::Viewer, 'Vi'))->call('show', $this->task->id, 'pest')
            ->assertSee('Pest / PHPUnit')
            ->assertDontSee(__('workspaces.gates.send_fix'));
    }

    public function test_the_detail_falls_back_to_the_stored_tail(): void
    {
        $this->failedRun();
        $daemon = $this->mock(WorkspaceDaemonClient::class);
        $daemon->shouldReceive('for->gateLog')->andThrow(new DaemonTransportException('unreachable', 0, 'unreachable'));

        $this->detail()->call('show', $this->task->id, 'pest')
            ->assertSee('FAILED LoginTest (tail)')
            ->assertSee(__('workspaces.gate_detail.log_tail'));
    }

    public function test_tasks_of_other_workspaces_cannot_be_opened(): void
    {
        $foreign = Task::factory()->create();

        $this->detail()->call('show', $foreign->id, 'pest')->assertForbidden();
    }

    public function test_the_service_reads_the_full_log_through_the_daemon(): void
    {
        $run = $this->failedRun();

        $log = app(WorkspaceGateService::class)->log($run, $this->task, $this->owner, 'pest');

        $this->assertStringStartsWith("[full log] pest {$run->id}\n", $log['log']);
        $this->assertFalse($log['truncated']);
        $this->assertTrue($log['complete']);
    }

    public function test_the_service_falls_back_to_the_tail_when_the_worktree_is_gone_or_the_daemon_fails(): void
    {
        $run = $this->failedRun();
        $service = app(WorkspaceGateService::class);

        $this->task->forceFill(['cleaned_at' => now()])->save();
        $this->assertSame(['log' => "FAILED LoginTest (tail)\n", 'truncated' => false, 'complete' => false], $service->log($run, $this->task->refresh(), $this->owner, 'pest'));

        $this->task->forceFill(['cleaned_at' => null])->save();
        $daemon = $this->mock(WorkspaceDaemonClient::class);
        $daemon->shouldReceive('for->gateLog')->andThrow(new DaemonTransportException('Unknown worktree', 404, 'unknown_worktree'));
        $this->assertSame(['log' => "FAILED LoginTest (tail)\n", 'truncated' => false, 'complete' => false], app(WorkspaceGateService::class)->log($run, $this->task->refresh(), $this->owner, 'pest'));
    }

    public function test_the_service_falls_back_when_the_daemon_has_no_log_file(): void
    {
        $run = $this->failedRun();
        $daemon = $this->mock(WorkspaceDaemonClient::class);
        $daemon->shouldReceive('for->gateLog')->andReturn(['log' => '', 'truncated' => false]);

        $this->assertSame("FAILED LoginTest (tail)\n", app(WorkspaceGateService::class)->log($run, $this->task, $this->owner, 'pest')['log']);
    }

    public function test_the_service_checks_access_and_the_gate(): void
    {
        $run = $this->failedRun();
        $service = app(WorkspaceGateService::class);

        try {
            $service->log($run, $this->task, User::factory()->create(), 'pest');
            $this->fail('Outsiders must not read gate logs.');
        } catch (AuthorizationException) {
        }

        $this->expectException(WorkspaceException::class);
        $service->log($run, $this->task, $this->owner, 'rector');
    }
}
