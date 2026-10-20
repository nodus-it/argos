<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\ThreadStream;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\PermissionMode;
use App\Enums\ThreadEventType;
use App\Enums\ThreadStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\Requests\InterruptThread;
use App\Integrations\WorkspaceDaemon\Requests\ResolveApproval;
use App\Integrations\WorkspaceDaemon\Requests\SendMessage;
use App\Integrations\WorkspaceDaemon\Requests\SetPermissionMode;
use App\Integrations\WorkspaceDaemon\Requests\StartThread;
use App\Integrations\WorkspaceDaemon\Requests\StopThread;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\ThreadService;
use App\Services\Workspace\WorkspaceException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class ThreadServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $editor;

    private AgentCredential $agent;

    /** @var array<string, list<array<string, mixed>>> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ThreadStream::class]);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->owner = $this->member(WorkspaceRole::Owner, 'Ada');
        $this->editor = $this->member(WorkspaceRole::Editor, 'Bob');
        $this->agent = AgentCredential::factory()->ownedBy($this->owner)->create(['credentials' => ['token' => 'oat-ada'], 'max_concurrent_sessions' => 2]);
        $this->fakeDaemon();
    }

    private function member(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param array<class-string, MockResponse> $overrides */
    private function fakeDaemon(array $overrides = []): void
    {
        $record = function (string $name, MockResponse $response) {
            return function (PendingRequest $request) use ($name, $response) {
                $this->calls[$name][] = ['url' => $request->getUrl(), 'body' => method_exists($request->body() ?? new \stdClass, 'all') ? $request->body()->all() : []];

                return $response;
            };
        };
        $defaults = [
            StartThread::class => MockResponse::make(['state' => 'starting']),
            SendMessage::class => MockResponse::make(['queued' => true], 202),
            InterruptThread::class => MockResponse::make([], 202),
            SetPermissionMode::class => MockResponse::make([], 202),
            ResolveApproval::class => MockResponse::make([], 202),
            StopThread::class => MockResponse::make([], 202),
        ];
        $fakes = [];
        foreach (array_merge($defaults, $overrides) as $class => $response) {
            $fakes[$class] = $record(class_basename($class), $response);
        }
        Saloon::fake($fakes);
    }

    private function service(): ThreadService
    {
        return app(ThreadService::class);
    }

    private function thread(): Thread
    {
        return $this->service()->create($this->workspace, $this->owner, $this->agent, 'Login');
    }

    public function test_create_uses_the_agent_default_model(): void
    {
        $this->agent->forceFill(['default_model' => 'claude-opus-4-7'])->save();

        $thread = $this->thread();

        $this->assertSame('claude-opus-4-7', $thread->model);
        $this->assertSame(ThreadStatus::Closed, $thread->status);
        $this->assertFalse($thread->session_open);
    }

    public function test_create_refuses_foreign_agents_and_viewers(): void
    {
        $foreign = AgentCredential::factory()->ownedBy(User::factory()->create())->create();
        try {
            $this->service()->create($this->workspace, $this->owner, $foreign, 'X');
            $this->fail('expected AuthorizationException');
        } catch (AuthorizationException) {
            $this->assertSame(0, Thread::query()->count());
        }

        $viewer = $this->member(WorkspaceRole::Viewer, 'Vi');
        AgentCredential::factory()->ownedBy($viewer)->create();
        $this->expectException(AuthorizationException::class);
        $this->service()->create($this->workspace, $viewer, $this->agent, 'X');
    }

    public function test_send_opens_the_session_with_subscription_auth_and_queues_the_message(): void
    {
        $thread = $this->thread();

        $event = $this->service()->send($thread, $this->editor, 'Please fix the login');

        $start = $this->calls['StartThread'][0]['body'];
        $this->assertSame('oat-ada', $start['oauth_token']);
        $this->assertSame($this->agent->id, $start['agent_id']);
        $this->assertSame(0, $start['seq_offset']);
        $this->assertNull($start['resume_session_id']);
        $this->assertStringContainsString('[Ada]', $start['append_system_prompt']);

        $message = $this->calls['SendMessage'][0]['body'];
        $this->assertSame((string) $event->id, $message['client_msg_id']);
        $this->assertSame(['id' => $this->editor->id, 'name' => 'Bob'], $message['author']);
        $this->assertSame('Please fix the login', $message['text']);

        $this->assertSame(ThreadEventType::UserMessage, $event->type);
        $this->assertSame($this->editor->id, $event->author_user_id);
        $this->assertTrue($thread->fresh()->session_open);
        Event::assertDispatched(ThreadStream::class, fn (ThreadStream $s): bool => $s->events[0]['type'] === 'user.message');
    }

    public function test_open_resumes_the_previous_claude_session(): void
    {
        $thread = $this->thread();
        $thread->forceFill(['claude_session_id' => 'sess-9', 'last_seq' => 41])->save();

        $this->service()->open($thread);

        $start = $this->calls['StartThread'][0]['body'];
        $this->assertSame('sess-9', $start['resume_session_id']);
        $this->assertSame(41, $start['seq_offset']);
    }

    public function test_send_reopens_when_the_daemon_lost_the_session(): void
    {
        $thread = $this->thread();
        $this->service()->open($thread);
        $this->calls = [];
        $sends = 0;
        Saloon::fake([
            SendMessage::class => function () use (&$sends) {
                return ++$sends === 1 ? MockResponse::make(['error' => ['code' => 'unknown_thread', 'message' => 'gone']], 404) : MockResponse::make(['queued' => true], 202);
            },
            StartThread::class => MockResponse::make(['state' => 'starting']),
        ]);

        $this->service()->send($thread, $this->owner, 'again');

        $this->assertSame(2, $sends);
        $this->assertTrue($thread->fresh()->session_open);
    }

    public function test_open_requires_a_running_workspace_and_releases_on_daemon_failure(): void
    {
        $thread = $this->thread();
        $this->workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();
        try {
            $this->service()->open($thread->fresh());
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertFalse($thread->fresh()->session_open);
        }

        $this->workspace->forceFill(['status' => WorkspaceStatus::Running])->save();
        $this->fakeDaemon([StartThread::class => MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'boom']], 500)]);
        try {
            $this->service()->open($thread->fresh());
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertFalse($thread->fresh()->session_open);
            $this->assertSame(ThreadStatus::Error, $thread->fresh()->status);
        }
    }

    public function test_viewers_cannot_prompt_or_interrupt(): void
    {
        $thread = $this->thread();
        $viewer = $this->member(WorkspaceRole::Viewer, 'Vi');

        foreach ([fn () => $this->service()->send($thread, $viewer, 'x'), fn () => $this->service()->interrupt($thread, $viewer)] as $call) {
            try {
                $call();
                $this->fail('expected AuthorizationException');
            } catch (AuthorizationException) {
                $this->assertArrayNotHasKey('SendMessage', $this->calls);
            }
        }
    }

    public function test_interrupt_records_who_stopped_the_agent(): void
    {
        $thread = $this->thread();
        $this->service()->open($thread);

        $this->service()->interrupt($thread, $this->editor);

        $this->assertCount(1, $this->calls['InterruptThread']);
        $notice = ThreadEvent::query()->where('type', ThreadEventType::Notice->value)->firstOrFail();
        $this->assertSame(['kind' => 'interrupted', 'author_name' => 'Bob'], $notice->payload);
    }

    public function test_bypass_permissions_is_owner_only_and_forwarded_when_open(): void
    {
        $thread = $this->thread();
        $this->service()->open($thread);

        try {
            $this->service()->setPermissionMode($thread, $this->editor, PermissionMode::BypassPermissions);
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertSame(PermissionMode::Default, $thread->fresh()->permission_mode);
        }

        $this->service()->setPermissionMode($thread, $this->editor, PermissionMode::AcceptEdits);
        $this->service()->setPermissionMode($thread, $this->owner, PermissionMode::BypassPermissions);

        $this->assertSame(['mode' => 'acceptEdits'], $this->calls['SetPermissionMode'][0]['body']);
        $this->assertSame(PermissionMode::BypassPermissions, $thread->fresh()->permission_mode);
    }

    public function test_the_first_approval_decision_wins(): void
    {
        $thread = $this->thread();
        $approval = ThreadApproval::factory()->create(['thread_id' => $thread->id]);

        $this->assertTrue($this->service()->resolveApproval($approval, $this->editor, 'allow'));
        $this->assertFalse($this->service()->resolveApproval($approval->fresh(), $this->owner, 'deny', null, 'no'));

        $approval->refresh();
        $this->assertSame(ApprovalStatus::Allowed, $approval->status);
        $this->assertSame($this->editor->id, $approval->resolved_by);
        $this->assertCount(1, $this->calls['ResolveApproval']);
        $this->assertSame(['decision' => 'allow'], $this->calls['ResolveApproval'][0]['body']);
        $this->assertStringContainsString("/approvals/{$approval->id}", $this->calls['ResolveApproval'][0]['url']);
    }

    public function test_question_answers_pass_the_updated_input(): void
    {
        $thread = $this->thread();
        $approval = ThreadApproval::factory()->create(['thread_id' => $thread->id, 'kind' => ApprovalKind::Question, 'tool_name' => 'AskUserQuestion']);

        $this->service()->resolveApproval($approval, $this->owner, 'allow', ['answers' => ['Which?' => 'B']]);

        $this->assertSame(ApprovalStatus::Answered, $approval->fresh()->status);
        $this->assertSame(['answers' => ['Which?' => 'B']], $this->calls['ResolveApproval'][0]['body']['updated_input']);
    }

    public function test_an_approval_the_daemon_forgot_becomes_cancelled(): void
    {
        $thread = $this->thread();
        $approval = ThreadApproval::factory()->create(['thread_id' => $thread->id]);
        $this->fakeDaemon([ResolveApproval::class => MockResponse::make(['error' => ['code' => 'unknown_approval', 'message' => 'x']], 404)]);

        $this->assertTrue($this->service()->resolveApproval($approval, $this->owner, 'allow'));
        $this->assertSame(ApprovalStatus::Cancelled, $approval->fresh()->status);
    }

    public function test_an_undelivered_approval_decision_stays_pending(): void
    {
        $thread = $this->thread();
        $approval = ThreadApproval::factory()->create(['thread_id' => $thread->id]);
        $this->fakeDaemon([ResolveApproval::class => MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'boom']], 500)]);

        try {
            $this->service()->resolveApproval($approval, $this->owner, 'allow');
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $approval->refresh();
            $this->assertSame(ApprovalStatus::Pending, $approval->status);
            $this->assertNull($approval->resolved_by);
        }

        $this->fakeDaemon();
        $this->assertTrue($this->service()->resolveApproval($approval->fresh(), $this->owner, 'allow'));
    }

    public function test_an_undelivered_message_is_removed_from_the_history(): void
    {
        $thread = $this->thread();
        $this->service()->open($thread);
        $this->fakeDaemon([SendMessage::class => MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'boom']], 500)]);

        try {
            $this->service()->send($thread, $this->owner, 'lost');
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertSame(0, ThreadEvent::query()->where('type', ThreadEventType::UserMessage->value)->count());
        }
    }

    public function test_a_failed_retry_after_reopen_is_a_workspace_exception(): void
    {
        $thread = $this->thread();
        $this->service()->open($thread);
        $sends = 0;
        Saloon::fake([
            SendMessage::class => function () use (&$sends) {
                return ++$sends === 1
                    ? MockResponse::make(['error' => ['code' => 'unknown_thread', 'message' => 'gone']], 404)
                    : MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'boom']], 500);
            },
            StartThread::class => MockResponse::make(['state' => 'starting']),
        ]);

        $this->expectException(WorkspaceException::class);
        try {
            $this->service()->send($thread, $this->owner, 'again');
        } finally {
            $this->assertSame(2, $sends);
            $this->assertSame(0, ThreadEvent::query()->where('type', ThreadEventType::UserMessage->value)->count());
        }
    }

    public function test_close_stops_the_session_and_frees_the_slot(): void
    {
        $thread = $this->thread();
        $this->service()->open($thread);

        $this->service()->close($thread, $this->owner);

        $this->assertCount(1, $this->calls['StopThread']);
        $this->assertFalse($thread->fresh()->session_open);
        $this->assertSame(ThreadStatus::Closed, $thread->fresh()->status);
    }
}
