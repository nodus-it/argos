<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Enums\ApprovalStatus;
use App\Enums\AutoThreadState;
use App\Enums\GitProvider;
use App\Enums\PermissionMode;
use App\Enums\ThreadEventType;
use App\Enums\WorkspaceOrigin;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\AdvanceAutoThreadJob;
use App\Jobs\Workspace\StopWorkspaceJob;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Models\WorkspaceMember;
use App\Services\GitProvider\Contracts\GitServiceContract;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\Workspace\AutoThreadDriver;
use App\Services\Workspace\ThreadEventIngestor;
use App\Services\Workspace\ThreadService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AutoThreadDriverTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private AgentCredential $agent;

    private GitServiceContract&MockInterface $git;

    protected function setUp(): void
    {
        parent::setUp();
        // Run the driver's steps inline: the whole run plays out in the test.
        config(['queue.default' => 'sync']);
        Event::fake([ThreadStream::class]);
        Notification::fake();
        FakeWorkspaceDaemon::$gateOutcome = 'fail';
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);

        $profile = RepoProfile::factory()->create(['url' => 'https://github.com/acme/widget', 'platform' => GitProvider::GitHub]);
        $this->workspace = Workspace::factory()->create(['repo_profile_id' => $profile->id, 'status' => WorkspaceStatus::Running, 'branch' => 'ws/auto', 'base_branch' => 'main']);
        $this->owner = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id, 'role' => WorkspaceRole::Owner]);
        $this->agent = AgentCredential::factory()->ownedBy($this->owner)->create(['credentials' => ['token' => 'oat']]);

        $this->git = Mockery::mock(GitServiceContract::class);
        $this->git->shouldReceive('createPullRequest')->andReturn(['number' => 7, 'html_url' => 'https://github.com/acme/widget/pull/7', 'state' => 'open'])->byDefault();
        $this->mock(GitServiceFactory::class)->shouldReceive('fromRepoProfile')->andReturn($this->git);
    }

    protected function tearDown(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'fail';
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function start(array $overrides = []): Thread
    {
        return app(AutoThreadDriver::class)->start($this->workspace, $this->owner, $this->agent, 'Add a priority field to notes', null, null, $overrides)->fresh();
    }

    private function messages(Thread $thread): array
    {
        return ThreadEvent::query()->where('thread_id', $thread->id)->where('type', ThreadEventType::UserMessage->value)->orderBy('id')->get()->all();
    }

    public function test_without_a_plan_it_implements_fixes_the_gates_and_ships(): void
    {
        $thread = $this->start(['plan' => 'off', 'max_fix_rounds' => 2]);

        $this->assertSame(AutoThreadState::Done, $thread->auto_state);
        $this->assertSame(PermissionMode::BypassPermissions, $thread->permission_mode);
        $this->assertSame(2, $thread->auto_counters['fix_rounds']);
        $this->assertFalse($thread->session_open);

        $messages = $this->messages($thread);
        $this->assertCount(3, $messages, 'task + 2 fix prompts');
        $this->assertStringContainsString('Add a priority field to notes', $messages[0]->payload['text']);
        $this->assertTrue($messages[0]->payload['auto']);
        $this->assertStringContainsString('Quality-Gate-Fix: pest', $messages[1]->payload['text']);
        $this->assertSame(3, WorkspaceGateRun::query()->count());
        $this->assertSame(7, $this->workspace->fresh()->pr_number);
    }

    public function test_green_gates_ship_right_away_and_the_pr_mentions_them(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        $this->git->shouldReceive('createPullRequest')->once()
            ->withArgs(fn ($owner, $repo, $title, $body) => str_contains($body, '3/3'))
            ->andReturn(['number' => 8, 'html_url' => 'https://x/8', 'state' => 'open']);

        $thread = $this->start(['plan' => 'off']);

        $this->assertSame(AutoThreadState::Done, $thread->auto_state);
        $this->assertSame(0, $thread->auto_counters['fix_rounds']);
    }

    public function test_an_automatic_plan_is_accepted_and_implemented_in_bypass_mode(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'pass';

        $thread = $this->start(['plan' => 'auto']);

        $this->assertSame(AutoThreadState::Done, $thread->auto_state);
        $this->assertSame(PermissionMode::BypassPermissions, $thread->permission_mode);
        $plan = ThreadApproval::query()->where('thread_id', $thread->id)->sole();
        $this->assertSame(ApprovalStatus::Allowed, $plan->status);
        $this->assertStringContainsString('ExitPlanMode', $this->messages($thread)[0]->payload['text']);
    }

    public function test_a_plan_waits_for_approval_and_can_be_rejected_or_approved(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        $thread = $this->start(['plan' => 'approve']);

        $this->assertSame(AutoThreadState::AwaitingPlanApproval, $thread->auto_state);
        $this->assertSame(PermissionMode::Plan, $thread->permission_mode);

        $driver = app(AutoThreadDriver::class);
        $driver->decidePlan($thread, $this->owner, false, 'Use an enum for the priority.');
        $thread->refresh();
        $this->assertSame(AutoThreadState::AwaitingPlanApproval, $thread->auto_state, 'the revised plan waits again');
        $this->assertSame(2, ThreadApproval::query()->where('thread_id', $thread->id)->count());

        $driver->decidePlan($thread, $this->owner, true);
        $this->assertSame(AutoThreadState::Done, $thread->fresh()->auto_state);
    }

    public function test_a_human_message_pauses_and_resume_continues_with_the_gates(): void
    {
        Queue::fake();
        $thread = $this->start(['plan' => 'off']);
        $thread->forceFill(['auto_state' => AutoThreadState::Implementing])->save();
        app(ThreadService::class)->open($thread);

        app(ThreadService::class)->send($thread, $this->owner, 'Also add a test for the default');

        $thread->refresh();
        $this->assertSame(AutoThreadState::Paused, $thread->auto_state);
        $this->assertStringContainsString('Ada', (string) $thread->auto_message);

        app(AutoThreadDriver::class)->resume($thread, $this->owner);
        $this->assertSame(AutoThreadState::Gating, $thread->fresh()->auto_state);
        Queue::assertPushed(AdvanceAutoThreadJob::class, fn (AdvanceAutoThreadJob $job): bool => $job->step === 'gate');
    }

    public function test_the_turn_budget_stops_the_run(): void
    {
        $thread = $this->start(['plan' => 'off', 'max_turns' => 1]);

        $this->assertSame(AutoThreadState::BudgetExceeded, $thread->auto_state);
        $this->assertStringContainsString('1', (string) $thread->auto_message);
        $this->assertSame(0, WorkspaceGateRun::query()->count());
    }

    public function test_questions_are_answered_with_decide_yourself(): void
    {
        // A starting workspace keeps the run queued, so nothing else happens.
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();
        $thread = $this->start(['plan' => 'off']);
        $this->workspace->forceFill(['status' => WorkspaceStatus::Running])->save();
        $thread->forceFill(['auto_state' => AutoThreadState::Implementing])->save();
        app(ThreadService::class)->open($thread);

        app(ThreadEventIngestor::class)->ingest($this->workspace, [[
            'thread_id' => $thread->id, 'seq' => 50, 'type' => 'approval.requested',
            'data' => ['approval_id' => '01JQUESTION0000000000000000', 'kind' => 'question', 'tool_name' => 'AskUserQuestion', 'input' => ['questions' => []]],
        ]]);

        $question = ThreadApproval::query()->whereKey('01JQUESTION0000000000000000')->sole();
        $this->assertSame(ApprovalStatus::Denied, $question->status);
        $this->assertStringContainsString('automatic', (string) ($question->response['message'] ?? ''));
    }

    public function test_a_repeated_turn_completed_does_not_run_the_gates_twice(): void
    {
        Queue::fake();
        $thread = $this->start(['plan' => 'off']);
        $thread->forceFill(['auto_state' => AutoThreadState::Implementing])->save();
        $driver = app(AutoThreadDriver::class);

        $driver->onThreadEvents($thread->fresh(), [['type' => 'turn.completed', 'data' => []]]);
        $driver->onThreadEvents($thread->fresh(), [['type' => 'turn.completed', 'data' => []]]);

        Queue::assertPushed(AdvanceAutoThreadJob::class, fn (AdvanceAutoThreadJob $job): bool => $job->step === 'gate');
        $this->assertSame(1, collect(Queue::pushed(AdvanceAutoThreadJob::class))->filter(fn ($job) => $job->step === 'gate')->count());
    }

    public function test_it_waits_while_the_workspace_starts(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();

        $thread = $this->start(['plan' => 'off']);

        $this->assertSame(AutoThreadState::Queued, $thread->auto_state);
        $this->assertCount(0, $this->messages($thread));
    }

    public function test_an_automatic_workspace_is_stopped_when_done_a_manual_one_is_not(): void
    {
        FakeWorkspaceDaemon::$gateOutcome = 'pass';
        // Only the stop job is faked; the auto-thread steps keep running inline.
        Queue::fake([StopWorkspaceJob::class]);
        $this->start(['plan' => 'off']);
        Queue::assertNotPushed(StopWorkspaceJob::class);

        $this->workspace->forceFill(['origin' => WorkspaceOrigin::Api])->save();
        $this->start(['plan' => 'off']);
        Queue::assertPushed(StopWorkspaceJob::class);
    }

    public function test_a_usage_limit_continues_the_run_once_lifted(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();
        $thread = $this->start(['plan' => 'off']);
        $thread->forceFill(['auto_state' => AutoThreadState::Implementing])->save();
        Queue::fake([AdvanceAutoThreadJob::class]);

        app(AutoThreadDriver::class)->onThreadEvents($thread->fresh(), [['type' => 'usage_limit', 'data' => ['resets_at' => now()->addHour()->toIso8601String()]]]);

        Queue::assertPushed(AdvanceAutoThreadJob::class, fn (AdvanceAutoThreadJob $job): bool => $job->step === 'continue' && $job->delay !== null);
        $this->assertSame(AutoThreadState::Implementing, $thread->fresh()->auto_state);
        $this->assertNotNull($thread->fresh()->auto_message);
    }

    public function test_an_error_fails_the_run_and_tells_the_members(): void
    {
        $this->workspace->forceFill(['status' => WorkspaceStatus::Starting])->save();
        $thread = $this->start(['plan' => 'off']);
        $thread->forceFill(['auto_state' => AutoThreadState::Implementing])->save();

        app(AutoThreadDriver::class)->onThreadEvents($thread->fresh(), [['type' => 'error', 'data' => ['code' => 'session_failed', 'message' => 'claude crashed']]]);

        $this->assertSame(AutoThreadState::Failed, $thread->fresh()->auto_state);
        $this->assertSame('claude crashed', $thread->fresh()->auto_message);
        Notification::assertSentTo($this->owner, DatabaseNotification::class);
    }
}
