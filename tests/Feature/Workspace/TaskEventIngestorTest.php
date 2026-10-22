<?php

declare(strict_types=1);

namespace Tests\Feature\Workspace;

use App\Broadcasting\TaskStream;
use App\Enums\AgentCredentialStatus;
use App\Enums\ApprovalStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Jobs\Workspace\DrainSessionQueueJob;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\Workspace;
use App\Services\Workspace\TaskEventIngestor;
use App\Services\Workspace\TaskPreparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskEventIngestorTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([TaskStream::class]);
        $this->workspace = Workspace::factory()->create();
        $this->task = Task::factory()->open()->create(['workspace_id' => $this->workspace->id]);
    }

    /** @param list<array{0: string, 1: array<string, mixed>}> $events */
    private function ingest(array $events, int $from = 1, ?string $taskId = null): array
    {
        $seq = $from;
        $batch = array_map(function (array $e) use (&$seq, $taskId): array {
            return ['task_id' => $taskId ?? $this->task->id, 'seq' => $seq++, 'type' => $e[0], 'data' => $e[1]];
        }, $events);

        return app(TaskEventIngestor::class)->ingest($this->workspace, $batch);
    }

    private function types(): array
    {
        return TaskEvent::query()->where('task_id', $this->task->id)->orderBy('id')->pluck('type')->map(fn ($t) => $t->value)->all();
    }

    public function test_persists_history_and_broadcasts_deltas_only(): void
    {
        $acks = $this->ingest([
            ['session.started', ['claude_session_id' => 'sess-1', 'commands' => [['name' => 'review', 'description' => 'Review', 'argument_hint' => '']]]],
            ['status', ['state' => 'running']],
            ['assistant.delta', ['message_id' => 'm1', 'text' => 'Hel']],
            ['assistant.message', ['message_id' => 'm1', 'text' => 'Hello']],
            ['tool.started', ['tool_use_id' => 't1', 'name' => 'Read', 'input' => ['file_path' => 'a']]],
            ['tool.result', ['tool_use_id' => 't1', 'is_error' => false, 'output' => 'x']],
            ['turn.completed', ['session_cost_usd' => 0.42]],
            ['status', ['state' => 'idle']],
        ]);

        $this->assertSame([$this->task->id => 8], $acks);
        $this->assertSame(['assistant_message', 'tool_call', 'tool_result', 'turn_completed'], $this->types());
        $this->task->refresh();
        $this->assertSame('sess-1', $this->task->claude_session_id);
        $this->assertSame('review', $this->task->commands[0]['name']);
        $this->assertSame(SessionStatus::Idle, $this->task->session_status);
        $this->assertSame(8, $this->task->last_seq);
        $this->assertSame('0.4200', $this->task->cost_usd);

        Event::assertDispatched(TaskStream::class, function (TaskStream $stream): bool {
            $types = array_column($stream->events, 'type');

            return $stream->workspaceId === $this->workspace->id && in_array('assistant.delta', $types, true) && in_array('assistant.message', $types, true);
        });
    }

    public function test_redelivered_batches_are_applied_once_and_acked_again(): void
    {
        $batch = [['assistant.message', ['message_id' => 'm1', 'text' => 'Hi']]];
        $this->ingest($batch);
        $acks = $this->ingest($batch);

        $this->assertSame([$this->task->id => 1], $acks);
        $this->assertSame(['assistant_message'], $this->types());
    }

    public function test_gaps_are_accepted(): void
    {
        $this->ingest([['assistant.message', ['text' => 'a']]], 1);
        $acks = $this->ingest([['assistant.message', ['text' => 'b']]], 7);

        $this->assertSame([$this->task->id => 7], $acks);
        $this->assertCount(2, $this->types());
    }

    public function test_events_for_tasks_of_other_workspaces_are_never_acked(): void
    {
        $foreign = Task::factory()->create();

        $acks = $this->ingest([['assistant.message', ['text' => 'x']]], 1, $foreign->id);

        $this->assertSame([], $acks);
        $this->assertSame(0, TaskEvent::query()->count());
    }

    public function test_approvals_are_opened_and_cancelled(): void
    {
        $this->ingest([['approval.requested', ['approval_id' => '01JAPPROVAL000000000000000', 'kind' => 'plan', 'tool_name' => 'ExitPlanMode', 'tool_use_id' => 't9', 'input' => ['plan' => '1. do']]]]);

        $approval = TaskApproval::query()->findOrFail('01JAPPROVAL000000000000000');
        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame('ExitPlanMode', $approval->tool_name);
        $this->assertSame(SessionStatus::WaitingInput, $this->task->fresh()->session_status);

        $this->ingest([['approval.cancelled', ['approval_id' => '01JAPPROVAL000000000000000', 'reason' => 'interrupted']]], 2);
        $this->assertSame(ApprovalStatus::Cancelled, $approval->fresh()->status);
        $this->assertSame(['approval_requested', 'approval_resolved'], $this->types());

        // Cancelling an already decided approval records nothing.
        $this->ingest([['approval.cancelled', ['approval_id' => '01JAPPROVAL000000000000000']]], 3);
        $this->assertCount(2, $this->types());
    }

    public function test_closed_status_frees_the_agent_slot(): void
    {
        $this->ingest([['status', ['state' => 'closed']]]);

        $this->assertFalse($this->task->fresh()->session_open);
        $this->assertSame(SessionStatus::Closed, $this->task->fresh()->session_status);
    }

    public function test_usage_limits_mark_the_agent(): void
    {
        $this->ingest([['usage_limit', ['resets_at' => now()->addHours(2)->toIso8601String()]]]);

        /** @var AgentCredential $agent */
        $agent = $this->task->agentCredential;
        $this->assertTrue($agent->fresh()->isUsageLimited());
        $this->assertSame(['usage_limit'], $this->types());
    }

    public function test_cost_never_decreases(): void
    {
        $this->ingest([['turn.completed', ['session_cost_usd' => 1.5]], ['turn.completed', ['session_cost_usd' => 0]]]);

        $this->assertSame('1.5000', $this->task->fresh()->cost_usd);
    }

    public function test_user_accepted_marks_the_message(): void
    {
        $message = TaskEvent::query()->create(['task_id' => $this->task->id, 'type' => TaskEventType::UserMessage, 'payload' => ['text' => 'hi']]);

        $this->ingest([['user.accepted', ['client_msg_id' => (string) $message->id]]]);

        $this->assertArrayHasKey('accepted_at', $message->fresh()->payload);
    }

    public function test_unknown_event_types_are_acked_but_ignored(): void
    {
        $acks = $this->ingest([['something.new', ['x' => 1]]]);

        $this->assertSame([$this->task->id => 1], $acks);
        $this->assertSame([], $this->types());
    }

    public function test_a_refused_token_marks_the_agent_expired(): void
    {
        $this->ingest([['error', ['code' => 'auth_failed', 'message' => 'API Error: 401 authentication_error']]]);

        $this->assertSame(AgentCredentialStatus::Expired, $this->task->agentCredential->fresh()->status);
        $this->assertSame('auth_failed', TaskEvent::query()->where('task_id', $this->task->id)->latest('id')->first()->payload['code']);
    }

    public function test_a_failing_follow_up_neither_fails_the_batch_nor_stops_the_others(): void
    {
        Queue::fake([DrainSessionQueueJob::class]);
        $this->task->forceFill(['status' => TaskStatus::Preparing])->save();
        $this->partialMock(TaskPreparer::class)->shouldReceive('afterPrepared')->andThrow(new \RuntimeException('boom'));

        $acks = $this->ingest([['prepare.completed', ['path' => '/x', 'base_commit' => 'abc', 'created' => true, 'setup' => 'ok', 'log' => '']], ['status', ['state' => 'closed']]]);

        $this->assertSame(2, $acks[$this->task->id]);
        Queue::assertPushed(DrainSessionQueueJob::class);
    }
}
