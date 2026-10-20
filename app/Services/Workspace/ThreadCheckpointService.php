<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Thread;
use App\Models\ThreadCheckpoint;
use App\Models\ThreadEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Checkpoints per turn and "reset to here": the daemon snapshots the working
 * tree before every user message; restoring one resets the files (never
 * HEAD) after snapshotting the current state, so a restore can be undone.
 * The chat history stays; Claude is told with the next message.
 */
class ThreadCheckpointService
{
    /** Mirrors the daemon's MAX_CHECKPOINTS. */
    public const MAX_PER_THREAD = 200;

    public function __construct(
        private readonly WorkspaceDaemonClient $daemon,
        private readonly ThreadService $threads,
    ) {}

    /**
     * Store a checkpoint the daemon reported (idempotent per thread + n).
     *
     * @param  array<string, mixed>  $data
     */
    public function record(Thread $thread, array $data): ?ThreadCheckpoint
    {
        $n = (int) ($data['n'] ?? 0);
        $ref = (string) ($data['ref'] ?? '');
        if ($n < 1 || $ref !== "refs/argos/checkpoints/{$thread->id}/{$n}") {
            return null;
        }

        $clientMsgId = (string) ($data['client_msg_id'] ?? '');
        $eventId = ctype_digit($clientMsgId)
            ? ThreadEvent::query()->whereKey((int) $clientMsgId)->where('thread_id', $thread->id)->value('id')
            : null;

        $checkpoint = ThreadCheckpoint::query()->firstOrCreate(
            ['thread_id' => $thread->id, 'n' => $n],
            [
                'ref' => $ref,
                'sha' => mb_substr((string) ($data['sha'] ?? ''), 0, 64),
                'head_sha' => is_string($data['head'] ?? null) ? mb_substr($data['head'], 0, 64) : null,
                'label' => ($data['label'] ?? '') !== '' ? mb_substr((string) $data['label'], 0, 120) : null,
                'thread_event_id' => $eventId,
            ],
        );

        // The daemon deletes refs beyond the limit; forget them here too.
        ThreadCheckpoint::query()->where('thread_id', $thread->id)->where('n', '<=', $n - self::MAX_PER_THREAD)->delete();

        return $checkpoint;
    }

    /** The checkpoint taken right before a user message was processed. */
    public function forMessage(ThreadEvent $message): ?ThreadCheckpoint
    {
        return ThreadCheckpoint::query()->where('thread_event_id', $message->id)->orderBy('n')->first();
    }

    /**
     * What a restore would undo, and whether commits were made since.
     *
     * @return array{diff: string, truncated: bool, commits_since: bool}
     */
    public function preview(ThreadCheckpoint $checkpoint, User $by): array
    {
        Gate::forUser($by)->authorize('operate', $checkpoint->thread->workspace);
        $result = $this->call(fn () => $this->daemon->for($checkpoint->thread->workspace)->checkpointDiff($checkpoint->ref));

        return [
            'diff' => $result['diff'],
            'truncated' => $result['truncated'],
            'commits_since' => $checkpoint->head_sha !== null && $result['head'] !== null && $result['head'] !== $checkpoint->head_sha,
        ];
    }

    public function restore(ThreadCheckpoint $checkpoint, User $by): void
    {
        $thread = $checkpoint->thread;
        Gate::forUser($by)->authorize('operate', $thread->workspace);
        if ($thread->status->isBusy()) {
            throw WorkspaceException::translated('workspaces.checkpoints.busy');
        }

        $result = $this->call(fn () => $this->daemon->for($thread->workspace)->restoreCheckpoint($checkpoint->ref));
        $before = $this->record($thread, $result['before']);

        $message = $checkpoint->threadEvent;
        $excerpt = $message !== null ? mb_strimwidth((string) ($message->payload['text'] ?? ''), 0, 80, '…') : (string) $checkpoint->label;
        $this->threads->notice($thread, 'restored', $by, ['excerpt' => $excerpt, 'checkpoint_id' => $before?->id]);

        $thread->forceFill(['restore_note' => __('workspaces.checkpoints.agent_note', [
            'name' => $by->name,
            'excerpt' => $excerpt,
        ], 'en')])->save();
    }

    /** `/checkpoint [name]`: a named snapshot, shown in the chat. */
    public function manual(Thread $thread, User $by, string $label): ThreadCheckpoint
    {
        Gate::forUser($by)->authorize('operate', $thread->workspace);
        $label = mb_substr(trim($label), 0, 120);
        $raw = $this->call(fn () => $this->daemon->for($thread->workspace)->createCheckpoint($thread->id, $label));
        $checkpoint = $this->record($thread, $raw) ?? throw WorkspaceException::translated('workspaces.checkpoints.failed');

        $this->threads->notice($thread, 'checkpoint', $by, ['label' => $label, 'checkpoint_id' => $checkpoint->id]);

        return $checkpoint;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(callable $call): mixed
    {
        try {
            return $call();
        } catch (DaemonTransportException $e) {
            throw match ($e->errorCode) {
                'thread_running' => WorkspaceException::translated('workspaces.checkpoints.busy'),
                'unknown_checkpoint' => WorkspaceException::translated('workspaces.checkpoints.gone'),
                default => WorkspaceException::translated('workspaces.errors.daemon_unreachable', ['message' => $e->getMessage()]),
            };
        }
    }
}
