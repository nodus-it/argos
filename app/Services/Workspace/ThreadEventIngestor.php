<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\ThreadEventType;
use App\Enums\ThreadStatus;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\ThreadEvent;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies event batches reported by a workspace daemon.
 *
 * Idempotent per thread: events at or below `threads.last_seq` were already
 * applied and are only acknowledged again (delivery is at-least-once). Gaps
 * are accepted — the daemon may drop old streaming deltas under pressure.
 * Streaming deltas are broadcast only; everything that belongs to the
 * history is persisted as a ThreadEvent and broadcast with its id.
 */
class ThreadEventIngestor
{
    /** Key the daemon uses for workspace-level events (thread_id = null). */
    public const WORKSPACE_KEY = '_workspace';

    public function __construct(
        private readonly AgentPool $pool,
        private readonly ThreadStreamPublisher $publisher,
        private readonly WorkspaceService $workspaces,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, int> acks: highest applied seq per thread key
     */
    public function ingest(Workspace $workspace, array $events): array
    {
        $byThread = [];
        foreach ($events as $event) {
            $key = is_string($event['thread_id'] ?? null) ? $event['thread_id'] : self::WORKSPACE_KEY;
            $byThread[$key][] = $event;
        }

        $acks = [];
        foreach ($byThread as $key => $threadEvents) {
            usort($threadEvents, fn (array $a, array $b): int => (int) $a['seq'] <=> (int) $b['seq']);

            if ($key === self::WORKSPACE_KEY) {
                // No workspace-level event kinds yet (quality gates add them).
                $acks[$key] = (int) end($threadEvents)['seq'];

                continue;
            }

            $ack = $this->ingestThread($workspace, (string) $key, $threadEvents);
            if ($ack !== null) {
                $acks[$key] = $ack;
            }
        }

        $this->workspaces->touch($workspace);

        return $acks;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function ingestThread(Workspace $workspace, string $threadId, array $events): ?int
    {
        $outgoing = [];

        $ack = DB::transaction(function () use ($workspace, $threadId, $events, &$outgoing): ?int {
            /** @var Thread|null $thread */
            $thread = Thread::query()
                ->whereKey($threadId)
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();
            if ($thread === null) {
                // Not this workspace's thread — never acknowledge it.
                return null;
            }

            $lastSeq = $thread->last_seq;
            foreach ($events as $event) {
                $seq = (int) $event['seq'];
                if ($seq <= $lastSeq) {
                    continue;
                }
                $lastSeq = $seq;
                $data = is_array($event['data'] ?? null) ? $event['data'] : [];
                $outgoing[] = $this->apply($thread, (string) $event['type'], $data, $seq);
            }

            $thread->last_seq = $lastSeq;
            $thread->save();

            return $lastSeq;
        });

        $outgoing = array_values(array_filter($outgoing));
        if ($outgoing !== []) {
            $this->publisher->publish($workspace->id, $threadId, $outgoing);
        }

        return $ack;
    }

    /**
     * Apply one event to the thread; returns what to broadcast (or null).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function apply(Thread $thread, string $type, array $data, int $seq): ?array
    {
        $broadcast = ['type' => $type, 'seq' => $seq, 'data' => $data];

        switch ($type) {
            case 'assistant.delta':
            case 'thinking.delta':
                return $broadcast;

            case 'session.started':
                $thread->claude_session_id = is_string($data['claude_session_id'] ?? null) ? $data['claude_session_id'] : $thread->claude_session_id;
                if (is_array($data['commands'] ?? null)) {
                    $thread->commands = $this->commands($data['commands']);
                }

                return $broadcast;

            case 'commands.updated':
                $thread->commands = $this->commands(is_array($data['commands'] ?? null) ? $data['commands'] : []);

                return $broadcast;

            case 'status':
                $this->applyStatus($thread, (string) ($data['state'] ?? ''));

                return $broadcast;

            case 'user.accepted':
                $this->markAccepted($thread, (string) ($data['client_msg_id'] ?? ''));

                return $broadcast;

            case 'assistant.message':
                return $this->persist($thread, ThreadEventType::AssistantMessage, $data, $seq, $broadcast);

            case 'thinking':
                return $this->persist($thread, ThreadEventType::Thinking, $data, $seq, $broadcast);

            case 'tool.started':
                return $this->persist($thread, ThreadEventType::ToolCall, $data, $seq, $broadcast);

            case 'tool.result':
                return $this->persist($thread, ThreadEventType::ToolResult, $data, $seq, $broadcast);

            case 'approval.requested':
                $this->openApproval($thread, $data);
                $thread->status = ThreadStatus::WaitingInput;

                return $this->persist($thread, ThreadEventType::ApprovalRequested, $data, $seq, $broadcast);

            case 'approval.cancelled':
                $approvalId = (string) ($data['approval_id'] ?? '');
                $cancelled = ThreadApproval::query()
                    ->whereKey($approvalId)
                    ->where('thread_id', $thread->id)
                    ->where('status', ApprovalStatus::Pending->value)
                    ->update(['status' => ApprovalStatus::Cancelled->value, 'resolved_at' => now()]);
                if ($cancelled === 0) {
                    return null;
                }

                return $this->persist($thread, ThreadEventType::ApprovalResolved, ['approval_id' => $approvalId, 'status' => ApprovalStatus::Cancelled->value, 'reason' => $data['reason'] ?? null], $seq, $broadcast);

            case 'turn.completed':
                $cost = (float) ($data['session_cost_usd'] ?? 0);
                // Cumulative per SDK session; keep the highest value seen so a
                // zeroed crash result never lowers it.
                if ($cost > (float) $thread->cost_usd) {
                    $thread->cost_usd = (string) round($cost, 4);
                }

                return $this->persist($thread, ThreadEventType::TurnCompleted, $data, $seq, $broadcast);

            case 'usage_limit':
                $agent = $thread->agentCredential;
                if ($agent instanceof AgentCredential) {
                    $this->pool->markUsageLimited($agent, $this->parseTime($data['resets_at'] ?? null));
                }

                return $this->persist($thread, ThreadEventType::UsageLimit, $data, $seq, $broadcast);

            case 'error':
                return $this->persist($thread, ThreadEventType::Error, $data, $seq, $broadcast);

            default:
                return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $broadcast
     * @return array<string, mixed>
     */
    private function persist(Thread $thread, ThreadEventType $type, array $data, int $seq, array $broadcast): array
    {
        $event = ThreadEvent::query()->create([
            'thread_id' => $thread->id,
            'type' => $type,
            'daemon_seq' => $seq,
            'payload' => $data,
        ]);

        return $broadcast + ['event_id' => $event->id];
    }

    private function applyStatus(Thread $thread, string $state): void
    {
        $status = match ($state) {
            'starting' => ThreadStatus::Starting,
            'idle' => ThreadStatus::Idle,
            'running' => ThreadStatus::Running,
            'waiting_input' => ThreadStatus::WaitingInput,
            'closed' => ThreadStatus::Closed,
            default => null,
        };
        if ($status === null) {
            return;
        }

        $thread->status = $status;
        if ($status === ThreadStatus::Closed) {
            $thread->session_open = false;
        }
    }

    private function markAccepted(Thread $thread, string $clientMsgId): void
    {
        if ($clientMsgId === '' || ! ctype_digit($clientMsgId)) {
            return;
        }

        $event = ThreadEvent::query()
            ->whereKey((int) $clientMsgId)
            ->where('thread_id', $thread->id)
            ->where('type', ThreadEventType::UserMessage->value)
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
    private function openApproval(Thread $thread, array $data): void
    {
        $approvalId = (string) ($data['approval_id'] ?? '');
        if ($approvalId === '' || ThreadApproval::query()->whereKey($approvalId)->exists()) {
            return;
        }

        ThreadApproval::query()->create([
            'id' => $approvalId,
            'thread_id' => $thread->id,
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
