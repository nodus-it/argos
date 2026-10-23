<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\SessionStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Without a free session (workspace `max_sessions`, subscription
 * `max_concurrent_sessions`) messages wait in line and go out once one frees
 * up; resting sessions make room first.
 */
class SessionQueueTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $ada;

    private AgentCredential $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running, 'max_sessions' => 1]);
        $this->ada = User::factory()->create(['name' => 'Ada']);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->ada->id, 'role' => WorkspaceRole::Member]);
        $this->agent = AgentCredential::factory()->ownedBy($this->ada)->create(['credentials' => ['token' => 'oat'], 'max_concurrent_sessions' => 5]);
    }

    private function service(): TaskService
    {
        return app(TaskService::class);
    }

    private function task(string $title): Task
    {
        return $this->service()->create($this->workspace, $this->ada, $this->agent, $title)->fresh();
    }

    /** A task whose session is busy (not resting). */
    private function busyTask(string $title): Task
    {
        $task = $this->task($title);
        $this->service()->send($task, $this->ada, 'edit something');

        return tap($task->fresh(), fn (Task $t) => $this->assertSame(SessionStatus::WaitingInput, $t->session_status));
    }

    public function test_a_message_waits_when_the_workspace_has_no_free_session(): void
    {
        $this->busyTask('First');
        $second = $this->task('Second');

        $event = $this->service()->send($second, $this->ada, 'hello');

        $second->refresh();
        $this->assertFalse($second->session_open);
        $this->assertNotNull($second->queued_at);
        $this->assertTrue($event->fresh()->payload['pending']);
        $this->assertSame(1, $this->service()->queuePosition($second));

        Livewire::actingAs($this->ada)->test(TaskChat::class, ['workspaceId' => $this->workspace->id])
            ->set('taskId', $second->id)
            ->assertSee(__('workspaces.events.queued', ['position' => 1]));
    }

    public function test_positions_follow_the_order_of_arrival(): void
    {
        $this->busyTask('First');
        $second = $this->task('Second');
        $third = $this->task('Third');
        $this->service()->send($second, $this->ada, 'a');
        $this->travel(1)->seconds();
        $this->service()->send($third, $this->ada, 'b');

        $this->assertSame(1, $this->service()->queuePosition($second->fresh()));
        $this->assertSame(2, $this->service()->queuePosition($third->fresh()));
    }

    public function test_a_freed_session_delivers_the_waiting_messages(): void
    {
        $first = $this->busyTask('First');
        $second = $this->task('Second');
        $event = $this->service()->send($second, $this->ada, 'hello');

        $this->service()->close($first, $this->ada);

        $second->refresh();
        $this->assertTrue($second->session_open);
        $this->assertNull($second->queued_at);
        $this->assertArrayNotHasKey('pending', $event->fresh()->payload);
        $this->assertTrue(TaskEvent::query()->where('task_id', $second->id)->get()->contains(fn (TaskEvent $e): bool => str_contains((string) ($e->payload['text'] ?? ''), 'Echo: [Ada]: hello')));
    }

    public function test_a_resting_session_makes_room_before_anyone_waits(): void
    {
        $resting = $this->task('Resting');
        $this->service()->send($resting, $this->ada, 'hi');
        $this->assertSame(SessionStatus::Idle, $resting->fresh()->session_status);
        $next = $this->task('Next');

        $this->service()->send($next, $this->ada, 'hello');

        $this->assertFalse($resting->fresh()->session_open);
        $this->assertTrue($next->fresh()->session_open);
        $this->assertNull($next->fresh()->queued_at);
    }

    public function test_the_subscription_limit_queues_too(): void
    {
        $this->workspace->forceFill(['max_sessions' => 5])->save();
        $this->agent->forceFill(['max_concurrent_sessions' => 1])->save();
        $this->busyTask('First');
        $second = $this->task('Second');

        $this->service()->send($second, $this->ada, 'hello');

        $this->assertNotNull($second->fresh()->queued_at);
        $this->assertSame(TaskStatus::Active, $second->fresh()->status);
    }
}
