<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\GateRunStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Filament\App\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Livewire\Workspace\GateDetail;
use App\Livewire\Workspace\TaskChat;
use App\Livewire\Workspace\TaskSwitcher;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Models\WorkspaceMember;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceGateService;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/** The cleaned-up task actions: menu, switcher, commands, gates, forms. */
class TaskActionsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $ada;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('app');
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running, 'default_branch' => 'develop']);
        $this->ada = $this->member('Ada');
        $this->bob = $this->member('Bob');
        AgentCredential::factory()->ownedBy($this->ada)->create(['credentials' => ['token' => 'oat-a']]);
        AgentCredential::factory()->ownedBy($this->bob)->create(['credentials' => ['token' => 'oat-b']]);
    }

    private function member(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => WorkspaceRole::Member]);

        return $user;
    }

    /** @param  array<string, mixed>  $attributes */
    private function task(User $owner, array $attributes = []): Task
    {
        return Task::factory()->ownedBy($owner)->create([
            'workspace_id' => $this->workspace->id,
            'agent_credential_id' => $owner->agentCredentials()->first()->id,
            'status' => TaskStatus::Active,
            ...$attributes,
        ]);
    }

    private function page(User $user, Task $task)
    {
        return Livewire::withQueryParams(['task' => $task->id])->actingAs($user)
            ->test(ViewWorkspace::class, ['record' => $this->workspace->getKey()]);
    }

    public function test_the_menu_offers_rebase_only_when_needed(): void
    {
        $task = $this->task($this->ada);
        $this->page($this->ada, $task)->assertActionHidden('rebase');

        $task->forceFill(['pr_number' => 3, 'pr_url' => 'https://x/3', 'pr_conflicts' => true])->save();
        $this->page($this->ada, $task->fresh())->assertActionVisible('rebase');
    }

    public function test_owners_remove_collaborators_from_their_avatar(): void
    {
        $task = $this->task($this->ada);
        TaskMember::query()->create(['task_id' => $task->id, 'user_id' => $this->bob->id]);

        $this->page($this->ada, $task)
            ->assertSeeHtml("mountAction('uninvite', { user: {$this->bob->id} })")
            ->callAction('uninvite', arguments: ['user' => $this->bob->id]);

        $this->assertFalse($task->fresh()->collaborators()->whereKey($this->bob->id)->exists());
    }

    public function test_deleting_moves_on_only_when_it_went_through(): void
    {
        $task = $this->task($this->ada);
        $this->page($this->bob, $task)->assertActionHidden('deleteTask');

        $tasks = \Mockery::mock(app(\App\Services\Workspace\TaskService::class));
        $tasks->shouldReceive('discard')->once()->andThrow(new \App\Services\Workspace\WorkspaceException('Daemon down'));
        $this->app->instance(\App\Services\Workspace\TaskService::class, $tasks);
        $this->page($this->ada, $task)->callAction('deleteTask')->assertNotDispatched('task-archived')->assertNotified('Daemon down');

        $this->app->forgetInstance(\App\Services\Workspace\TaskService::class);
        $this->page($this->ada, $task)->callAction('deleteTask')->assertDispatched('task-archived');
        $this->assertNull(Task::query()->find($task->id));
    }

    public function test_the_switcher_offers_rename_and_archive_only_on_own_tasks(): void
    {
        $mine = $this->task($this->ada, ['title' => 'Mine']);
        $theirs = $this->task($this->bob, ['title' => 'Theirs']);

        $html = Livewire::actingAs($this->ada)->test(TaskSwitcher::class, ['workspaceId' => $this->workspace->id])->set('mine', false)->html();

        $this->assertStringContainsString("archive('{$mine->id}')", $html);
        $this->assertStringNotContainsString("archive('{$theirs->id}')", $html);
        $this->assertStringContainsString('wire:confirm', $html);
        // "+" for a new task sits right next to the switcher.
        $this->assertStringContainsString('data-testid="switcher-new-task"', $html);
    }

    public function test_commands_say_why_they_cannot_run(): void
    {
        $task = $this->task($this->ada);
        $this->workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();

        Livewire::withQueryParams(['task' => $task->id])->actingAs($this->ada)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->set('draft', '/ship')
            ->call('send')
            ->assertNotDispatched('workspace-ship')
            ->assertNotified(__('workspaces.chat.command_not_running'));
    }

    public function test_the_new_task_form_can_be_cancelled(): void
    {
        $task = $this->task($this->ada);

        Livewire::withQueryParams(['task' => $task->id])->actingAs($this->ada)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->call('startCreating')
            ->assertSet('creating', true)
            ->assertSee(__('workspaces.review.cancel'))
            ->call('cancelCreating')
            ->assertSet('creating', false)
            ->assertSet('taskId', $task->id);
    }

    public function test_a_single_gate_is_checked_again_and_the_others_keep_their_result(): void
    {
        $task = $this->task($this->ada);
        $service = app(WorkspaceGateService::class);
        FakeWorkspaceDaemon::$gateOutcome = 'fail';
        $first = $service->run($task, $this->ada);
        $this->assertSame(['artisan' => 'pass', 'pint' => 'pass', 'pest' => 'fail', 'phpstan' => 'skip'], $first->fresh()->gates);

        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        Livewire::actingAs($this->ada)->test(GateDetail::class, ['workspaceId' => $this->workspace->id])
            ->call('show', $task->id, 'pest')
            ->assertSee(__('workspaces.gates.rerun_one'))
            ->call('rerunGate', 'pest');

        $second = WorkspaceGateRun::query()->whereKeyNot($first->id)->sole();
        $this->assertSame('pest', $second->only);
        $this->assertSame(GateRunStatus::Passed, $second->status);
        $this->assertSame(['pass', 'pass', 'pass', 'skip'], [$second->gates['artisan'], $second->gates['pint'], $second->gates['pest'], $second->gates['phpstan']]);
        // The pint log still lies in the first run.
        $this->assertSame($first->id, $second->logRunFor('pint'));
        $this->assertSame($second->id, $second->logRunFor('pest'));
    }

    public function test_unknown_gates_cannot_be_checked_alone(): void
    {
        $this->expectException(\App\Services\Workspace\WorkspaceException::class);
        app(WorkspaceGateService::class)->run($this->task($this->ada), $this->ada, 'rm -rf');
    }

    public function test_the_header_is_lean(): void
    {
        $task = $this->task($this->ada, ['pr_number' => 4, 'pr_url' => 'https://github.com/acme/widget/pull/4', 'pr_state' => 'open']);

        $html = $this->page($this->ada, $task)->html();
        $header = substr($html, (int) strpos($html, 'ag-task-meta'), 3000);

        // No PR chip (the sidebar has it), no commit/push (the action bar has them), invite only as "+".
        $this->assertStringNotContainsString('is-pr', substr($header, 0, (int) strpos($header, 'ag-review-flag')));
        $this->assertStringContainsString("mountAction('invite')", $html);
        $this->page($this->ada, $task)->assertActionVisible('commit')->assertActionVisible('push');
    }

    public function test_the_action_bar_offers_commit_push_and_the_to_dos(): void
    {
        $task = $this->task($this->ada);
        FakeWorkspaceDaemon::$gateOutcome = 'fail';
        app(WorkspaceGateService::class)->run($task, $this->ada);

        // The fake has an uncommitted file and an unpushed commit: commit is the next step.
        Livewire::withoutLazyLoading()->actingAs($this->ada)
            ->test(\App\Livewire\Workspace\ChangeTray::class, ['workspaceId' => $this->workspace->id, 'taskId' => $task->id])
            ->assertSeeHtmlInOrder(['ag-btn-primary', 'data-testid="tray-commit"', 'ag-btn-surface', 'data-testid="tray-push"'])
            ->assertSee(__('workspaces.ship.push_pr'))
            ->assertSee(__('workspaces.tray.todo.gates', ['gates' => 'Pest / PHPUnit']))
            ->assertSee(__('workspaces.tray.todo.gates_action'));
    }

    public function test_what_a_person_did_carries_their_picture(): void
    {
        $task = $this->task($this->ada);
        \App\Models\TaskEvent::query()->create(['task_id' => $task->id, 'type' => \App\Enums\TaskEventType::Notice, 'author_user_id' => $this->bob->id, 'payload' => ['kind' => 'committed', 'author_name' => 'Bob', 'subject' => 'feat: x']]);
        \App\Models\TaskEvent::query()->create(['task_id' => $task->id, 'type' => \App\Enums\TaskEventType::Notice, 'payload' => ['kind' => 'pr_merged']]);

        $html = Livewire::withQueryParams(['task' => $task->id])->actingAs($this->ada)
            ->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->html();

        $this->assertSame(1, substr_count($html, 'ag-notice-avatar'));
        $this->assertStringContainsString(__('workspaces.events.committed', ['name' => 'Bob', 'subject' => 'feat: x']), html_entity_decode($html));
    }
}
