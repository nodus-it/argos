<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\PermissionMode;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\Requests\InterruptTask;
use App\Integrations\WorkspaceDaemon\Requests\ResolveApproval;
use App\Integrations\WorkspaceDaemon\Requests\SendMessage;
use App\Integrations\WorkspaceDaemon\Requests\SetPermissionMode;
use App\Integrations\WorkspaceDaemon\Requests\StartTask;
use App\Integrations\WorkspaceDaemon\Requests\StopTask;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class TaskServiceTest extends TestCase
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
        Event::fake([TaskStream::class]);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->owner = $this->member(WorkspaceRole::Admin, 'Ada');
        $this->editor = $this->member(WorkspaceRole::Member, 'Bob');
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
            StartTask::class => MockResponse::make(['state' => 'starting']),
            SendMessage::class => MockResponse::make(['queued' => true], 202),
            InterruptTask::class => MockResponse::make([], 202),
            SetPermissionMode::class => MockResponse::make([], 202),
            ResolveApproval::class => MockResponse::make([], 202),
            StopTask::class => MockResponse::make([], 202),
        ];
        $fakes = [];
        foreach (array_merge($defaults, $overrides) as $class => $response) {
            $fakes[$class] = $record(class_basename($class), $response);
        }
        Saloon::fake($fakes);
    }

    private function service(): TaskService
    {
        return app(TaskService::class);
    }

    /** Ada's task; Bob is invited as a collaborator. */
    private function task(): Task
    {
        $task = $this->service()->create($this->workspace, $this->owner, $this->agent, 'Login');
        $task->members()->create(['user_id' => $this->editor->id, 'invited_by' => $this->owner->id]);

        return $task;
    }

    public function test_create_uses_the_agent_default_model(): void
    {
        $this->agent->forceFill(['default_model' => 'claude-opus-4-7'])->save();

        $task = $this->task();

        $this->assertSame('claude-opus-4-7', $task->model);
        $this->assertSame(SessionStatus::Closed, $task->session_status);
        $this->assertFalse($task->session_open);
    }

    public function test_create_names_the_task_and_makes_the_creator_its_owner(): void
    {
        $this->workspace->forceFill(['default_branch' => 'develop'])->save();

        $task = $this->service()->create($this->workspace, $this->owner, $this->agent, 'Login Seite');
        $again = $this->service()->create($this->workspace, $this->owner, $this->agent, 'Login Seite');

        $this->assertTrue($task->isOwnedBy($this->owner));
        $this->assertSame($this->owner->id, $task->created_by);
        $this->assertSame(TaskStatus::Active, $task->status);
        $this->assertSame(TaskOrigin::Manual, $task->origin);
        $this->assertSame('login-seite', $task->slug);
        $this->assertSame('argos/login-seite', $task->branch);
        $this->assertSame('develop', $task->base_branch);
        $this->assertSame('login-seite-2', $again->slug);
        $this->assertSame('argos/login-seite-2', $again->branch);
    }

    public function test_create_takes_base_branch_ticket_and_origin_options(): void
    {
        $ref = ['binding_id' => null, 'provider' => 'gitlab', 'key' => 'GITLAB-42', 'number' => '42', 'owner' => 'acme', 'project' => 'widget', 'title' => 'Login', 'url' => 'https://x/42', 'body' => ''];

        $task = $this->service()->create($this->workspace, $this->editor, AgentCredential::factory()->ownedBy($this->editor)->create(), 'Login Seite', options: [
            'base_branch' => 'release/1.2', 'external_ref' => $ref, 'origin' => TaskOrigin::Issue,
        ]);

        $this->assertTrue($task->isOwnedBy($this->editor));
        $this->assertSame('gitlab-42-login-seite', $task->branch);
        $this->assertSame('release/1.2', $task->base_branch);
        $this->assertSame('GITLAB-42', $task->external_ref['key']);
        $this->assertSame(TaskOrigin::Issue, $task->origin);
    }

    public function test_create_refuses_foreign_agents_and_viewers(): void
    {
        $foreign = AgentCredential::factory()->ownedBy(User::factory()->create())->create();
        try {
            $this->service()->create($this->workspace, $this->owner, $foreign, 'X');
            $this->fail('expected AuthorizationException');
        } catch (AuthorizationException) {
            $this->assertSame(0, Task::query()->count());
        }

        $viewer = $this->member(WorkspaceRole::Viewer, 'Vi');
        AgentCredential::factory()->ownedBy($viewer)->create();
        $this->expectException(AuthorizationException::class);
        $this->service()->create($this->workspace, $viewer, $this->agent, 'X');
    }

    public function test_send_opens_the_session_with_subscription_auth_and_queues_the_message(): void
    {
        $task = $this->task();

        $event = $this->service()->send($task, $this->editor, 'Please fix the login');

        $start = $this->calls['StartTask'][0]['body'];
        $this->assertSame('oat-ada', $start['oauth_token']);
        $this->assertSame($this->agent->id, $start['agent_id']);
        $this->assertSame(0, $start['seq_offset']);
        $this->assertNull($start['resume_session_id']);
        $this->assertStringContainsString('[Ada]', $start['append_system_prompt']);

        $message = $this->calls['SendMessage'][0]['body'];
        $this->assertSame((string) $event->id, $message['client_msg_id']);
        $this->assertSame(['id' => $this->editor->id, 'name' => 'Bob'], $message['author']);
        $this->assertSame('Please fix the login', $message['text']);

        $this->assertSame(TaskEventType::UserMessage, $event->type);
        $this->assertSame($this->editor->id, $event->author_user_id);
        $this->assertTrue($task->fresh()->session_open);
        Event::assertDispatched(TaskStream::class, fn (TaskStream $s): bool => $s->events[0]['type'] === 'user.message');
    }

    public function test_open_resumes_the_previous_claude_session(): void
    {
        $task = $this->task();
        $task->forceFill(['claude_session_id' => 'sess-9', 'last_seq' => 41])->save();

        $this->service()->open($task);

        $start = $this->calls['StartTask'][0]['body'];
        $this->assertSame('sess-9', $start['resume_session_id']);
        $this->assertSame(41, $start['seq_offset']);
    }

    public function test_send_reopens_when_the_daemon_lost_the_session(): void
    {
        $task = $this->task();
        $this->service()->open($task);
        $this->calls = [];
        $sends = 0;
        Saloon::fake([
            SendMessage::class => function () use (&$sends) {
                return ++$sends === 1 ? MockResponse::make(['error' => ['code' => 'unknown_task', 'message' => 'gone']], 404) : MockResponse::make(['queued' => true], 202);
            },
            StartTask::class => MockResponse::make(['state' => 'starting']),
        ]);

        $this->service()->send($task, $this->owner, 'again');

        $this->assertSame(2, $sends);
        $this->assertTrue($task->fresh()->session_open);
    }

    public function test_open_requires_a_running_workspace_and_releases_on_daemon_failure(): void
    {
        $task = $this->task();
        $this->workspace->forceFill(['status' => WorkspaceStatus::Stopped])->save();
        try {
            $this->service()->open($task->fresh());
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertFalse($task->fresh()->session_open);
        }

        $this->workspace->forceFill(['status' => WorkspaceStatus::Running])->save();
        $this->fakeDaemon([StartTask::class => MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'boom']], 500)]);
        try {
            $this->service()->open($task->fresh());
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertFalse($task->fresh()->session_open);
            $this->assertSame(SessionStatus::Error, $task->fresh()->session_status);
        }
    }

    public function test_viewers_and_members_outside_the_task_cannot_prompt_or_interrupt(): void
    {
        $task = $this->task();
        $viewer = $this->member(WorkspaceRole::Viewer, 'Vi');
        $outsider = $this->member(WorkspaceRole::Member, 'Ole');

        foreach ([
            fn () => $this->service()->send($task, $viewer, 'x'),
            fn () => $this->service()->interrupt($task, $viewer),
            fn () => $this->service()->send($task, $outsider, 'x'),
        ] as $call) {
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
        $task = $this->task();
        $this->service()->open($task);

        $this->service()->interrupt($task, $this->editor);

        $this->assertCount(1, $this->calls['InterruptTask']);
        $notice = TaskEvent::query()->where('type', TaskEventType::Notice->value)->firstOrFail();
        $this->assertSame(['kind' => 'interrupted', 'author_name' => 'Bob'], $notice->payload);
    }

    public function test_bypass_permissions_is_owner_only_and_forwarded_when_open(): void
    {
        $task = $this->task();
        $this->service()->open($task);

        try {
            $this->service()->setPermissionMode($task, $this->editor, PermissionMode::BypassPermissions);
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertSame(PermissionMode::Default, $task->fresh()->permission_mode);
        }

        $this->service()->setPermissionMode($task, $this->editor, PermissionMode::AcceptEdits);
        $this->service()->setPermissionMode($task, $this->owner, PermissionMode::BypassPermissions);

        $this->assertSame(['mode' => 'acceptEdits'], $this->calls['SetPermissionMode'][0]['body']);
        $this->assertSame(PermissionMode::BypassPermissions, $task->fresh()->permission_mode);
    }

    public function test_the_first_approval_decision_wins(): void
    {
        $task = $this->task();
        $approval = TaskApproval::factory()->create(['task_id' => $task->id]);

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
        $task = $this->task();
        $approval = TaskApproval::factory()->create(['task_id' => $task->id, 'kind' => ApprovalKind::Question, 'tool_name' => 'AskUserQuestion']);

        $this->service()->resolveApproval($approval, $this->owner, 'allow', ['answers' => ['Which?' => 'B']]);

        $this->assertSame(ApprovalStatus::Answered, $approval->fresh()->status);
        $this->assertSame(['answers' => ['Which?' => 'B']], $this->calls['ResolveApproval'][0]['body']['updated_input']);
    }

    public function test_an_approval_the_daemon_forgot_becomes_cancelled(): void
    {
        $task = $this->task();
        $approval = TaskApproval::factory()->create(['task_id' => $task->id]);
        $this->fakeDaemon([ResolveApproval::class => MockResponse::make(['error' => ['code' => 'unknown_approval', 'message' => 'x']], 404)]);

        $this->assertTrue($this->service()->resolveApproval($approval, $this->owner, 'allow'));
        $this->assertSame(ApprovalStatus::Cancelled, $approval->fresh()->status);
    }

    public function test_an_undelivered_approval_decision_stays_pending(): void
    {
        $task = $this->task();
        $approval = TaskApproval::factory()->create(['task_id' => $task->id]);
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
        $task = $this->task();
        $this->service()->open($task);
        $this->fakeDaemon([SendMessage::class => MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'boom']], 500)]);

        try {
            $this->service()->send($task, $this->owner, 'lost');
            $this->fail('expected WorkspaceException');
        } catch (WorkspaceException) {
            $this->assertSame(0, TaskEvent::query()->where('type', TaskEventType::UserMessage->value)->count());
        }
    }

    public function test_a_failed_retry_after_reopen_is_a_workspace_exception(): void
    {
        $task = $this->task();
        $this->service()->open($task);
        $sends = 0;
        Saloon::fake([
            SendMessage::class => function () use (&$sends) {
                return ++$sends === 1
                    ? MockResponse::make(['error' => ['code' => 'unknown_task', 'message' => 'gone']], 404)
                    : MockResponse::make(['error' => ['code' => 'internal_error', 'message' => 'boom']], 500);
            },
            StartTask::class => MockResponse::make(['state' => 'starting']),
        ]);

        $this->expectException(WorkspaceException::class);
        try {
            $this->service()->send($task, $this->owner, 'again');
        } finally {
            $this->assertSame(2, $sends);
            $this->assertSame(0, TaskEvent::query()->where('type', TaskEventType::UserMessage->value)->count());
        }
    }

    public function test_close_stops_the_session_and_frees_the_slot(): void
    {
        $task = $this->task();
        $this->service()->open($task);

        $this->service()->close($task, $this->owner);

        $this->assertCount(1, $this->calls['StopTask']);
        $this->assertFalse($task->fresh()->session_open);
        $this->assertSame(SessionStatus::Closed, $task->fresh()->session_status);
    }
}
