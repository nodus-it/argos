<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Jobs\Workspace\DrainSessionQueueJob;
use App\Jobs\Workspace\ReanchorReviewCommentsJob;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies event batches reported by a workspace daemon.
 *
 * Idempotent per task: events at or below `tasks.last_seq` were already
 * applied and are only acknowledged again (delivery is at-least-once). Gaps
 * are accepted — the daemon may drop old streaming deltas under pressure.
 * Streaming deltas are broadcast only; everything that belongs to the
 * history is persisted as a TaskEvent and broadcast with its id.
 */
class TaskEventIngestor
{
    /** Key the daemon uses for workspace-level events (task_id = null). */
    public const WORKSPACE_KEY = '_workspace';

    /** Turns shorter than this finish while people still watch — no notification. */
    private const NOTIFY_TURN_AFTER_MS = 30_000;

    public function __construct(
        private readonly AgentPool $pool,
        private readonly TaskStreamPublisher $publisher,
        private readonly WorkspaceService $workspaces,
        private readonly WorkspaceNotifier $notifier,
        private readonly WorkspaceGateService $gates,
        private readonly TaskCheckpointService $checkpoints,
        private readonly ModelCatalog $models,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, int> acks: highest applied seq per task key
     */
    public function ingest(Workspace $workspace, array $events): array
    {
        $byTask = [];
        foreach ($events as $event) {
            $key = is_string($event['task_id'] ?? null) ? $event['task_id'] : self::WORKSPACE_KEY;
            $byTask[$key][] = $event;
        }

        $acks = [];
        foreach ($byTask as $key => $taskEvents) {
            usort($taskEvents, fn (array $a, array $b): int => (int) $a['seq'] <=> (int) $b['seq']);

            if ($key === self::WORKSPACE_KEY) {
                $this->ingestWorkspace($workspace, $taskEvents);
                $acks[$key] = (int) end($taskEvents)['seq'];

                continue;
            }

            $ack = $this->ingestTask($workspace, (string) $key, $taskEvents);
            if ($ack !== null) {
                $acks[$key] = $ack;
            }
        }

        $this->workspaces->touch($workspace);

        return $acks;
    }

    /**
     * Workspace-level events (task_id = null): quality gates. Their seq is
     * not persisted — the daemon restarts it — so they are applied
     * idempotently by run id instead.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function ingestWorkspace(Workspace $workspace, array $events): void
    {
        $changed = false;
        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '');
            if (str_starts_with($type, 'gates.')) {
                $changed = $this->gates->apply($workspace, $type, is_array($event['data'] ?? null) ? $event['data'] : []) || $changed;
            }
        }
        if ($changed) {
            $this->gates->announce($workspace);
        }
        if (array_intersect(array_column($events, 'type'), ['gates.completed', 'gates.failed']) !== []) {
            app(AutoTaskDriver::class)->onGatesFinished($workspace);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function ingestTask(Workspace $workspace, string $taskId, array $events): ?int
    {
        $outgoing = [];

        $ack = DB::transaction(function () use ($workspace, $taskId, $events, &$outgoing): ?int {
            /** @var Task|null $task */
            $task = Task::query()
                ->whereKey($taskId)
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();
            if ($task === null) {
                // Not this workspace's task — never acknowledge it.
                return null;
            }

            $lastSeq = $task->last_seq;
            foreach ($events as $event) {
                $seq = (int) $event['seq'];
                if ($seq <= $lastSeq) {
                    continue;
                }
                $lastSeq = $seq;
                $data = is_array($event['data'] ?? null) ? $event['data'] : [];
                $outgoing[] = $this->apply($task, (string) $event['type'], $data, $seq);
            }

            $task->last_seq = $lastSeq;
            $task->save();

            return $lastSeq;
        });

        $outgoing = array_values(array_filter($outgoing));
        if ($outgoing !== []) {
            $this->publisher->publish($workspace->id, $taskId, $outgoing);
            // Re-read once after the transaction; notify and the follow-ups share it.
            $task = Task::query()->find($taskId);
            if ($task !== null) {
                $this->notify($workspace, $task, $outgoing);
            }
            // A session ended: a waiting task can have it.
            if (collect($events)->contains(fn (array $e): bool => ($e['type'] ?? '') === 'status' && (($e['data']['state'] ?? '') === 'closed'))) {
                DrainSessionQueueJob::dispatch($workspace->id);
            }
            if ($task !== null && in_array('prepare.completed', array_column($events, 'type'), true)) {
                app(TaskPreparer::class)->afterPrepared($task);
            }
            if ($task?->auto_state !== null) {
                app(AutoTaskDriver::class)->onTaskEvents($task, $outgoing);
            }
            // Claude may have changed lines that carry collected review comments.
            if ($task !== null && in_array('turn.completed', array_column($outgoing, 'type'), true)
                && app(ReviewCommentService::class)->anchoredPaths($task) !== []) {
                ReanchorReviewCommentsJob::dispatch($task->id);
            }
        }

        return $ack;
    }

    /**
     * Tell members about what needs them; refresh overviews on status changes.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function notify(Workspace $workspace, Task $task, array $events): void
    {
        $statusChanged = false;
        foreach ($events as $event) {
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            match ($event['type']) {
                'approval.requested' => $this->notifier->notify($workspace, $task, 'approval',
                    __('workspaces.notifications.approval', ['tool' => (string) ($data['tool_name'] ?? '')]), $task->title),
                'turn.completed' => (int) ($data['duration_ms'] ?? 0) >= self::NOTIFY_TURN_AFTER_MS
                    ? $this->notifier->notify($workspace, $task, 'turn', __('workspaces.notifications.turn'), $task->title)
                    : null,
                'usage_limit' => $this->notifier->notify($workspace, $task, 'limit',
                    __('workspaces.notifications.limit', ['agent' => $task->agentCredential?->name ?? '']), $task->title),
                'error' => ($data['code'] ?? '') === 'session_ended'
                    ? null
                    : $this->notifier->notify($workspace, $task, 'error', __('workspaces.notifications.error'), (string) ($data['message'] ?? '')),
                default => null,
            };
            $statusChanged = $statusChanged || $event['type'] === 'status';
        }

        if ($statusChanged) {
            $this->notifier->summaryChanged($workspace, $task);
        }
    }

    /**
     * Apply one event to the task; returns what to broadcast (or null).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function apply(Task $task, string $type, array $data, int $seq): ?array
    {
        $broadcast = ['type' => $type, 'seq' => $seq, 'data' => $data];

        switch ($type) {
            case 'assistant.delta':
            case 'thinking.delta':
                return $broadcast;

            case 'session.started':
                $task->claude_session_id = is_string($data['claude_session_id'] ?? null) ? $data['claude_session_id'] : $task->claude_session_id;
                if (is_array($data['commands'] ?? null)) {
                    $task->commands = $this->commands($data['commands']);
                }
                if (is_array($data['models'] ?? null) && $task->agentCredential instanceof AgentCredential) {
                    $this->models->record($task->agentCredential, $data['models']);
                }

                return $broadcast;

            case 'commands.updated':
                $task->commands = $this->commands(is_array($data['commands'] ?? null) ? $data['commands'] : []);

                return $broadcast;

            case 'checkpoint.created':
                $checkpoint = $this->checkpoints->record($task, $data);

                return $checkpoint === null ? null : $broadcast + ['checkpoint_id' => $checkpoint->id];

            case 'status':
                $this->applyStatus($task, (string) ($data['state'] ?? ''));

                return $broadcast;

            case 'user.accepted':
                $this->markAccepted($task, (string) ($data['client_msg_id'] ?? ''));

                return $broadcast;

            case 'assistant.message':
                return $this->persist($task, TaskEventType::AssistantMessage, $data, $seq, $broadcast);

            case 'thinking':
                return $this->persist($task, TaskEventType::Thinking, $data, $seq, $broadcast);

            case 'tool.started':
                return $this->persist($task, TaskEventType::ToolCall, $data, $seq, $broadcast);

            case 'tool.result':
                return $this->persist($task, TaskEventType::ToolResult, $data, $seq, $broadcast);

            case 'approval.requested':
                $this->openApproval($task, $data);
                $task->session_status = SessionStatus::WaitingInput;

                return $this->persist($task, TaskEventType::ApprovalRequested, $data, $seq, $broadcast);

            case 'approval.cancelled':
                $approvalId = (string) ($data['approval_id'] ?? '');
                $cancelled = TaskApproval::query()
                    ->whereKey($approvalId)
                    ->where('task_id', $task->id)
                    ->where('status', ApprovalStatus::Pending->value)
                    ->update(['status' => ApprovalStatus::Cancelled->value, 'resolved_at' => now()]);
                if ($cancelled === 0) {
                    return null;
                }

                return $this->persist($task, TaskEventType::ApprovalResolved, ['approval_id' => $approvalId, 'status' => ApprovalStatus::Cancelled->value, 'reason' => $data['reason'] ?? null], $seq, $broadcast);

            case 'turn.completed':
                $cost = (float) ($data['session_cost_usd'] ?? 0);
                // Cumulative per SDK session; keep the highest value seen so a
                // zeroed crash result never lowers it.
                if ($cost > (float) $task->cost_usd) {
                    $task->cost_usd = (string) round($cost, 4);
                }

                return $this->persist($task, TaskEventType::TurnCompleted, $data, $seq, $broadcast);

            case 'usage_limit':
                $agent = $task->agentCredential;
                if ($agent instanceof AgentCredential) {
                    $this->pool->markUsageLimited($agent, $this->parseTime($data['resets_at'] ?? null));
                }

                return $this->persist($task, TaskEventType::UsageLimit, $data, $seq, $broadcast);

            case 'error':
                return $this->persist($task, TaskEventType::Error, $data, $seq, $broadcast);

            case 'prepare.step':
            case 'prepare.completed':
            case 'prepare.failed':
                $notice = app(TaskPreparer::class)->onEvent($task, $type, $data);

                return $notice === null ? null : $this->persist($task, TaskEventType::Notice, $notice, $seq, ['type' => 'notice', 'seq' => $seq, 'data' => $notice]);

            default:
                return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $broadcast
     * @return array<string, mixed>
     */
    private function persist(Task $task, TaskEventType $type, array $data, int $seq, array $broadcast): array
    {
        $event = TaskEvent::query()->create([
            'task_id' => $task->id,
            'type' => $type,
            'daemon_seq' => $seq,
            'payload' => $data,
        ]);

        return $broadcast + ['event_id' => $event->id];
    }

    private function applyStatus(Task $task, string $state): void
    {
        $status = match ($state) {
            'starting' => SessionStatus::Starting,
            'idle' => SessionStatus::Idle,
            'running' => SessionStatus::Running,
            'waiting_input' => SessionStatus::WaitingInput,
            'closed' => SessionStatus::Closed,
            default => null,
        };
        if ($status === null) {
            return;
        }

        $task->session_status = $status;
        if ($status === SessionStatus::Closed) {
            $task->session_open = false;
        }
    }

    private function markAccepted(Task $task, string $clientMsgId): void
    {
        if ($clientMsgId === '' || ! ctype_digit($clientMsgId)) {
            return;
        }

        $event = TaskEvent::query()
            ->whereKey((int) $clientMsgId)
            ->where('task_id', $task->id)
            ->where('type', TaskEventType::UserMessage->value)
            ->first();
        if ($event === null) {
            return;
        }

        $event->payload = [...$event->payload, 'accepted_at' => now()->toIso8601String()];
        $event->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function openApproval(Task $task, array $data): void
    {
        $approvalId = (string) ($data['approval_id'] ?? '');
        if ($approvalId === '' || TaskApproval::query()->whereKey($approvalId)->exists()) {
            return;
        }

        TaskApproval::query()->create([
            'id' => $approvalId,
            'task_id' => $task->id,
            'tool_use_id' => is_string($data['tool_use_id'] ?? null) ? $data['tool_use_id'] : null,
            'kind' => ApprovalKind::tryFrom((string) ($data['kind'] ?? '')) ?? ApprovalKind::Tool,
            'tool_name' => (string) ($data['tool_name'] ?? 'unknown'),
            'request' => $data,
            'status' => ApprovalStatus::Pending,
        ]);
    }

    /**
     * @param  array<int, mixed>  $commands
     * @return list<array{name: string, description: string, argument_hint: string}>
     */
    private function commands(array $commands): array
    {
        $out = [];
        foreach ($commands as $command) {
            if (is_array($command) && is_string($command['name'] ?? null)) {
                $out[] = [
                    'name' => $command['name'],
                    'description' => (string) ($command['description'] ?? ''),
                    'argument_hint' => (string) ($command['argument_hint'] ?? ''),
                ];
            }
        }

        return $out;
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
