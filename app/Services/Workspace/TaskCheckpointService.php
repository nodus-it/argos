<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Models\Task;
use App\Models\TaskCheckpoint;
use App\Models\TaskEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Checkpoints per turn and "reset to here": the daemon snapshots the working
 * tree before every user message; restoring one resets the files (never
 * HEAD) after snapshotting the current state, so a restore can be undone.
 * The chat history stays; Claude is told with the next message.
 */
class TaskCheckpointService
{
    /** Mirrors the daemon's MAX_CHECKPOINTS. */
    public const MAX_PER_TASK = 200;

    public function __construct(
        private readonly WorkspaceDaemonClient $daemon,
        private readonly TaskNoticeService $notices,
    ) {}

    /**
     * Store a checkpoint the daemon reported (idempotent per task + n).
     *
     * @param  array<string, mixed>  $data
     */
    public function record(Task $task, array $data): ?TaskCheckpoint
    {
        $n = (int) ($data['n'] ?? 0);
        $ref = (string) ($data['ref'] ?? '');
        if ($n < 1 || $ref !== "refs/argos/checkpoints/{$task->id}/{$n}") {
            return null;
        }

        $clientMsgId = (string) ($data['client_msg_id'] ?? '');
        $eventId = ctype_digit($clientMsgId)
            ? TaskEvent::query()->whereKey((int) $clientMsgId)->where('task_id', $task->id)->value('id')
            : null;

        $checkpoint = TaskCheckpoint::query()->firstOrCreate(
            ['task_id' => $task->id, 'n' => $n],
            [
                'ref' => $ref,
                'sha' => mb_substr((string) ($data['sha'] ?? ''), 0, 64),
                'head_sha' => is_string($data['head'] ?? null) ? mb_substr($data['head'], 0, 64) : null,
                'label' => ($data['label'] ?? '') !== '' ? mb_substr((string) $data['label'], 0, 120) : null,
                'task_event_id' => $eventId,
            ],
        );

        // The daemon deletes refs beyond the limit; forget them here too.
        TaskCheckpoint::query()->where('task_id', $task->id)->where('n', '<=', $n - self::MAX_PER_TASK)->delete();

        return $checkpoint;
    }

    /**
     * What a restore would undo, and whether commits were made since.
     *
     * @return array{diff: string, truncated: bool, commits_since: bool}
     */
    public function preview(TaskCheckpoint $checkpoint, User $by): array
    {
        Gate::forUser($by)->authorize('update', $checkpoint->task);
        $result = $this->call(fn () => $this->daemon->for($checkpoint->task->workspace)->checkpointDiff($checkpoint->task->slug, $checkpoint->ref));

        return [
            'diff' => $result['diff'],
            'truncated' => $result['truncated'],
            'commits_since' => $checkpoint->head_sha !== null && $result['head'] !== null && $result['head'] !== $checkpoint->head_sha,
        ];
    }

    public function restore(TaskCheckpoint $checkpoint, User $by): void
    {
        $task = $checkpoint->task;
        Gate::forUser($by)->authorize('update', $task);
        if ($task->session_status->isBusy()) {
            throw WorkspaceException::translated('workspaces.checkpoints.busy');
        }

        $result = $this->call(fn () => $this->daemon->for($task->workspace)->restoreCheckpoint($task->slug, $checkpoint->ref));
        $before = $this->record($task, $result['before']);

        $message = $checkpoint->taskEvent;
        $excerpt = $message !== null ? mb_strimwidth((string) ($message->payload['text'] ?? ''), 0, 80, '…') : (string) $checkpoint->label;
        $this->notices->notice($task, 'restored', $by, ['excerpt' => $excerpt, 'checkpoint_id' => $before?->id]);

        $task->forceFill(['restore_note' => __('workspaces.checkpoints.agent_note', [
            'name' => $by->name,
            'excerpt' => $excerpt,
        ], 'en')])->save();
    }

    /** `/checkpoint [name]`: a named snapshot, shown in the chat. */
    public function manual(Task $task, User $by, string $label): TaskCheckpoint
    {
        Gate::forUser($by)->authorize('update', $task);
        $label = mb_substr(trim($label), 0, 120);
        $raw = $this->call(fn () => $this->daemon->for($task->workspace)->createCheckpoint($task->slug, $task->id, $label));
        $checkpoint = $this->record($task, $raw) ?? throw WorkspaceException::translated('workspaces.checkpoints.failed');

        $this->notices->notice($task, 'checkpoint', $by, ['label' => $label, 'checkpoint_id' => $checkpoint->id]);

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
        return WorkspaceException::wrapDaemon($call, [
            'task_running' => 'workspaces.checkpoints.busy',
            'unknown_checkpoint' => 'workspaces.checkpoints.gone',
        ]);
    }
}
