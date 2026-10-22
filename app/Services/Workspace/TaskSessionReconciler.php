<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskStatus;
use App\Jobs\Workspace\DrainSessionQueueJob;
use App\Jobs\Workspace\PrepareTaskJob;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the manager's view of sessions in line with the daemon's. Sessions,
 * approvals and running preparations live in the daemon's memory: when it
 * (or its container) restarts, the tasks it knew are gone without a single
 * event. This closes what the daemon no longer knows — the turn ended, the
 * approvals lapsed, the agent slot is free again — and restarts the
 * preparations a restart cut off.
 */
class TaskSessionReconciler
{
    /**
     * A session the daemon does not list yet may just be starting: leave
     * tasks alone that changed this recently.
     */
    public const GRACE_SECONDS = 90;

    public function __construct(
        private readonly TaskNoticeService $notices,
        private readonly WorkspaceNotifier $notifier,
    ) {}

    /**
     * Compare with the daemon's `/health` answer. Returns how many sessions
     * were closed.
     *
     * @param  array<string, mixed>  $health
     */
    public function reconcile(Workspace $workspace, array $health): int
    {
        $known = collect(is_array($health['tasks'] ?? null) ? $health['tasks'] : [])
            ->filter(fn (mixed $t): bool => is_array($t) && ($t['state'] ?? 'closed') !== 'closed')
            ->pluck('id')
            ->all();

        $lost = Task::query()
            ->where('workspace_id', $workspace->id)
            ->where(fn ($q) => $q->where('session_open', true)
                ->orWhereNotIn('session_status', [SessionStatus::Closed->value, SessionStatus::Error->value]))
            ->whereNotIn('id', $known)
            ->where('updated_at', '<', now()->subSeconds(self::GRACE_SECONDS))
            ->pluck('id');

        $closed = $this->closeAll($workspace, $lost->all());
        $this->restartCutPreparations($workspace, $health);

        return $closed;
    }

    /**
     * The workspace container (re)started: no session survived it. Called
     * by the boot right after the daemon answered.
     *
     * @param  array<string, mixed>  $health
     */
    public function afterBoot(Workspace $workspace, array $health): void
    {
        $open = Task::query()
            ->where('workspace_id', $workspace->id)
            ->where(fn ($q) => $q->where('session_open', true)
                ->orWhereNotIn('session_status', [SessionStatus::Closed->value, SessionStatus::Error->value]))
            ->pluck('id');
        $this->closeAll($workspace, $open->all());
        if (is_string($health['boot_id'] ?? null)) {
            Cache::forever($this->bootKey($workspace), $health['boot_id']);
        }
    }

    /**
     * The daemon answered `unknown_task` for a task the manager holds open:
     * close it right away.
     */
    public function forgetSession(Task $task): void
    {
        if ($this->closeLost($task->id)) {
            DrainSessionQueueJob::dispatch($task->workspace_id);
        }
    }

    /**
     * @param  list<mixed>  $taskIds
     */
    private function closeAll(Workspace $workspace, array $taskIds): int
    {
        $closed = 0;
        foreach ($taskIds as $id) {
            $closed += $this->closeLost((string) $id) ? 1 : 0;
        }
        if ($closed > 0) {
            DrainSessionQueueJob::dispatch($workspace->id);
        }

        return $closed;
    }

    private function closeLost(string $taskId): bool
    {
        $wasWorking = false;
        $task = DB::transaction(function () use ($taskId, &$wasWorking): ?Task {
            /** @var Task|null $task */
            $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();
            if ($task === null || (! $task->session_open && in_array($task->session_status, [SessionStatus::Closed, SessionStatus::Error], true))) {
                return null;
            }
            $wasWorking = in_array($task->session_status, [SessionStatus::Starting, SessionStatus::Running, SessionStatus::WaitingInput], true);
            $task->forceFill(['session_open' => false, 'session_status' => SessionStatus::Closed])->save();
            TaskApproval::query()->where('task_id', $task->id)->where('status', ApprovalStatus::Pending->value)
                ->update(['status' => ApprovalStatus::Cancelled->value, 'resolved_at' => now()]);

            return $task;
        });
        if ($task === null) {
            return false;
        }

        if ($wasWorking) {
            $this->notices->systemNotice($task, 'session_lost');
            if ($task->auto_state !== null) {
                app(AutoTaskDriver::class)->onTaskEvents($task, [['type' => 'error', 'data' => ['message' => __('workspaces.events.session_lost')]]]);
            }
        }
        $this->notifier->summaryChanged($task->workspace, $task);

        return true;
    }

    /**
     * A new daemon instance forgot the preparations in flight: prepare those
     * tasks again (preparing is idempotent — an existing worktree is kept).
     *
     * @param  array<string, mixed>  $health
     */
    private function restartCutPreparations(Workspace $workspace, array $health): void
    {
        $boot = $health['boot_id'] ?? null;
        if (! is_string($boot)) {
            return;
        }
        $previous = Cache::get($this->bootKey($workspace));
        Cache::forever($this->bootKey($workspace), $boot);
        if ($previous === null || $previous === $boot) {
            return;
        }

        Task::query()->where('workspace_id', $workspace->id)->where('status', TaskStatus::Preparing->value)->pluck('id')
            ->each(fn (string $id) => PrepareTaskJob::dispatch($id));
    }

    private function bootKey(Workspace $workspace): string
    {
        return 'argos:daemon-boot:'.$workspace->id;
    }
}
