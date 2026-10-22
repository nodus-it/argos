<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\Requests\RestoreCheckpoint;
use App\Livewire\Workspace\TaskChat;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskCheckpoint;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Workspace\TaskCheckpointService;
use App\Services\Workspace\TaskEventIngestor;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use App\Testing\FakeWorkspaceDaemon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class TaskCheckpointTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        // New tasks are prepared inline by the fake daemon (worktree, setup).
        config(['queue.default' => 'sync']);
        Event::fake([TaskStream::class]);
        $this->app->singleton(WorkspaceDaemonClient::class, FakeWorkspaceDaemon::class);
        $this->workspace = Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
        $this->owner = $this->member(WorkspaceRole::Admin, 'Ada');
        AgentCredential::factory()->ownedBy($this->owner)->create();

        $this->chat()->set('newTitle', 'Login')->call('createTask')->assertHasNoErrors();
        $this->task = Task::query()->where('title', 'Login')->firstOrFail();
    }

    private function member(WorkspaceRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        WorkspaceMember::query()->create(['workspace_id' => $this->workspace->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function chat(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->owner)->test(TaskChat::class, ['workspaceId' => $this->workspace->id]);
    }

    private function sendMessage(string $text): TaskEvent
    {
        $this->chat()->set('taskId', $this->task->id)->set('draft', $text)->call('send');

        return TaskEvent::query()->where('task_id', $this->task->id)->where('type', TaskEventType::UserMessage->value)->latest('id')->firstOrFail();
    }

    private function service(): TaskCheckpointService
    {
        return app(TaskCheckpointService::class);
    }

    public function test_every_turn_records_a_checkpoint_for_its_message(): void
    {
        $first = $this->sendMessage('one');
        $second = $this->sendMessage('two');

        $this->assertSame(1, $this->checkpointOf($first)?->n);
        $this->assertSame(2, $this->checkpointOf($second)?->n);
        $this->assertSame('refs/argos/checkpoints/'.$this->task->id.'/2', $this->checkpointOf($second)?->ref);
    }

    public function test_checkpoint_events_are_idempotent_and_validated(): void
    {
        $ingest = fn (int $seq, array $data) => app(TaskEventIngestor::class)->ingest($this->workspace, [
            ['task_id' => $this->task->id, 'seq' => $seq, 'type' => 'checkpoint.created', 'data' => $data],
        ]);
        $ref = 'refs/argos/checkpoints/'.$this->task->id.'/7';

        $ingest(100, ['ref' => $ref, 'n' => 7, 'sha' => 'abc', 'head' => 'def']);
        $ingest(101, ['ref' => $ref, 'n' => 7, 'sha' => 'other']);
        $ingest(102, ['ref' => 'refs/argos/checkpoints/someone-else/8', 'n' => 8, 'sha' => 'x']);

        $this->assertSame(['abc'], TaskCheckpoint::query()->pluck('sha')->all());
    }

    public function test_only_the_newest_checkpoints_are_kept(): void
    {
        $base = ['sha' => 'a', 'head' => null];
        $this->service()->record($this->task, [...$base, 'n' => 1, 'ref' => "refs/argos/checkpoints/{$this->task->id}/1"]);
        $this->service()->record($this->task, [...$base, 'n' => 201, 'ref' => "refs/argos/checkpoints/{$this->task->id}/201"]);

        $this->assertSame([201], TaskCheckpoint::query()->pluck('n')->all());
    }

    public function test_reset_to_here_previews_restores_and_tells_claude(): void
    {
        $message = $this->sendMessage('change the readme');
        $checkpoint = $this->checkpointOf($message);

        $chat = $this->chat()->set('taskId', $this->task->id)
            ->assertSee(__('workspaces.checkpoints.reset_here'))
            ->call('previewRestore', $checkpoint->id)
            ->assertSet('restoreId', $checkpoint->id)
            ->assertSee(__('workspaces.checkpoints.confirm_title'))
            ->assertSee('+Hello Argos');

        $chat->call('confirmRestore')
            ->assertSet('restoreId', null)
            ->assertNotified(__('workspaces.checkpoints.restored'));

        $notice = TaskEvent::query()->where('task_id', $this->task->id)->where('type', TaskEventType::Notice->value)->latest('id')->firstOrFail();
        $this->assertSame('restored', $notice->payload['kind']);
        $this->assertSame('change the readme', $notice->payload['excerpt']);
        $this->assertNotNull(TaskCheckpoint::query()->find($notice->payload['checkpoint_id']), 'the pre-restore checkpoint is recorded');
        $this->assertStringContainsString('change the readme', (string) $this->task->fresh()->restore_note);

        // The next message carries the note to Claude — once.
        $this->chat()->set('taskId', $this->task->id)->set('draft', 'go on')->call('send');
        $this->chat()->set('taskId', $this->task->id)->assertSee('Echo: [Ada]: [Note from Argos', false);
        $this->assertNull($this->task->fresh()->restore_note);
        $this->assertSame('go on', TaskEvent::query()->where('type', TaskEventType::UserMessage->value)->latest('id')->first()->payload['text']);
    }

    public function test_restoring_is_refused_while_claude_works(): void
    {
        $checkpoint = $this->checkpointOf($this->sendMessage('one'));
        $this->task->forceFill(['session_status' => SessionStatus::Running])->save();

        $this->expectException(WorkspaceException::class);
        $this->service()->restore($checkpoint->fresh(), $this->owner);
    }

    public function test_a_daemon_side_409_is_reported_as_busy(): void
    {
        $checkpoint = $this->checkpointOf($this->sendMessage('one'));
        $this->app->forgetInstance(WorkspaceDaemonClient::class);
        $this->app->singleton(WorkspaceDaemonClient::class, WorkspaceDaemonClient::class);
        Saloon::fake([RestoreCheckpoint::class => MockResponse::make(['error' => ['code' => 'task_running', 'message' => 'busy']], 409)]);

        try {
            $this->service()->restore($checkpoint, $this->owner);
            $this->fail('expected an exception');
        } catch (WorkspaceException $e) {
            $this->assertSame(__('workspaces.checkpoints.busy'), $e->getMessage());
        }
    }

    public function test_commits_since_the_checkpoint_are_flagged(): void
    {
        $checkpoint = $this->checkpointOf($this->sendMessage('one'));
        $checkpoint->forceFill(['head_sha' => str_repeat('1', 40)])->save();

        $this->assertTrue($this->service()->preview($checkpoint, $this->owner)['commits_since']);
    }

    public function test_viewers_cannot_reset(): void
    {
        $checkpoint = $this->checkpointOf($this->sendMessage('one'));
        $viewer = $this->member(WorkspaceRole::Viewer, 'Vi');

        $this->chat($viewer)->set('taskId', $this->task->id)->assertDontSee(__('workspaces.checkpoints.reset_here'));

        $this->expectException(AuthorizationException::class);
        $this->service()->restore($checkpoint, $viewer);
    }

    public function test_slash_checkpoint_sets_a_named_checkpoint(): void
    {
        $this->chat()->set('taskId', $this->task->id)->set('draft', '/checkpoint before refactor')->call('send')->assertSet('draft', '');

        $checkpoint = TaskCheckpoint::query()->sole();
        $this->assertSame('before refactor', $checkpoint->label);
        $this->assertNull($checkpoint->task_event_id);
        $this->assertSame(0, TaskEvent::query()->where('type', TaskEventType::UserMessage->value)->count());
        $this->chat()->set('taskId', $this->task->id)->assertSee('before refactor');
    }

    /** The checkpoint taken before a message (the first, when Claude made several). */
    private function checkpointOf(TaskEvent $message): ?\App\Models\TaskCheckpoint
    {
        return \App\Models\TaskCheckpoint::query()->where('task_event_id', $message->id)->orderBy('n')->first();
    }
}
