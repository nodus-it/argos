<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\AutoTaskState;
use App\Enums\GitProvider;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\AdvanceAutoTaskJob;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AutoTaskUiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TaskStream::class]);
        Notification::fake();
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['repo_url' => 'https://github.com/acme/widget', 'platform' => GitProvider::GitHub, 'status' => WorkspaceStatus::Running]);
        $this->owner = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Admin]);
        AgentCredential::factory()->ownedBy($this->owner)->create(['credentials' => ['token' => 'oat']]);
        $git = Mockery::mock(GitServiceContract::class);
        $git->shouldReceive('createPullRequest')->andReturn(['number' => 3, 'html_url' => 'https://x/3', 'state' => 'open']);
        $this->mock(GitServiceFactory::class)->shouldReceive('forWorkspace')->andReturn($git);
    }

    protected function tearDown(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'fail';
        parent::tearDown();
    }

    private function chat()
    {
        return Livewire::actingAs($this->owner)->test(TaskChat::class, ['workspaceId' => $this->workspace->id]);
    }

    public function test_an_auto_task_needs_a_task(): void
    {
        $this->chat()->set('newAuto', true)->set('newPrompt', '')->call('createTask')->assertHasErrors('newPrompt');
        $this->assertSame(0, Task::query()->count());
    }

    public function test_the_form_starts_an_auto_task_that_runs_to_the_pull_request(): void
    {
        config(['queue.default' => 'sync']);

        $chat = $this->chat()
            ->assertSee(__('workspaces.auto.toggle'))
            ->set('newAuto', true)
            ->set('newPrompt', 'Add a priority to notes')
            ->set('newPlan', 'off')
            ->call('createTask')
            ->assertHasNoErrors();

        $task = Task::query()->sole();
        $this->assertSame(AutoTaskState::Done, $task->auto_state);
        $this->assertSame('off', $task->auto_config['plan']);
        $chat->assertSee(AutoTaskState::Done->label())->assertSee(__('workspaces.auto.automated'));
    }

    public function test_the_plan_card_approves_through_the_driver(): void
    {
        config(['queue.default' => 'sync']);
        $chat = $this->chat()->set('newAuto', true)->set('newPrompt', 'Refactor login')->set('newPlan', 'approve')->call('createTask');
        $task = Task::query()->sole();
        $this->assertSame(AutoTaskState::AwaitingPlanApproval, $task->auto_state);
        $chat->assertSee(AutoTaskState::AwaitingPlanApproval->label());

        $plan = TaskApproval::query()->where('task_id', $task->id)->sole();
        $chat->set('taskId', $task->id)->call('resolveApproval', $plan->id, 'allow');

        $this->assertSame(AutoTaskState::Done, $task->fresh()->auto_state);
    }

    public function test_writing_takes_over_and_resume_hands_it_back(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();
        $this->chat()->set('newAuto', true)->set('newPrompt', 'Do it')->call('createTask');
        $task = Task::query()->sole();
        $this->workspace->forceFill(['status' => WorkspaceStatus::Running])->save();
        $task->forceFill(['auto_state' => AutoTaskState::Implementing, 'status' => TaskStatus::Active])->save();

        $chat = $this->chat()->set('taskId', $task->id)->assertSee(__('workspaces.auto.pause'));
        $chat->set('draft', 'Let me help')->call('send');
        $this->assertSame(AutoTaskState::Paused, $task->fresh()->auto_state);
        $chat->assertSee(__('workspaces.auto.resume'))->assertSee(__('workspaces.auto.paused_by', ['name' => 'Ada']));

        $chat->call('resumeAuto');
        $this->assertTrue($task->fresh()->auto_state->isRunning());
    }

    public function test_a_workspace_member_starts_an_auto_task_of_their_own_with_settings(): void
    {
        Queue::fake([AdvanceAutoTaskJob::class]);
        $member = User::factory()->create(['name' => 'Bea']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $member->id, 'role' => WorkspaceRole::Member]);
        AgentCredential::factory()->ownedBy($member)->create(['credentials' => ['token' => 'oat-bea']]);

        Livewire::actingAs($member)->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->call('startCreating')
            ->set('newAuto', true)
            ->set('newTitle', 'Priorities')
            ->set('newPrompt', 'Add priorities')
            ->set('newPlan', 'approve')
            ->set('newShip', 'draft_pr')
            ->call('createTask')
            ->assertHasNoErrors()
            ->assertSet('newAuto', false)
            ->assertSet('creating', false);

        $task = Task::query()->where('workspace_id', $this->workspace->id)->sole();
        $this->assertTrue($task->isOwnedBy($member));
        $this->assertSame('Priorities', $task->title);
        $this->assertSame('argos/priorities', $task->branch);
        $this->assertSame(AutoTaskState::Queued, $task->auto_state);
        $this->assertSame('approve', $task->auto_config['plan']);
        $this->assertSame('draft_pr', $task->auto_config['ship']);
        $this->assertSame('Add priorities', $task->auto_config['prompt']);
        Queue::assertPushed(AdvanceAutoTaskJob::class, fn (AdvanceAutoTaskJob $job): bool => $job->step === 'begin');
    }
}
