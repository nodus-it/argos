<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\AutoTaskState;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TaskEventFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The tasks of a workspace for the header's switcher, grouped by state —
 * waiting for you, running, ready, pull request open, done (merged or
 * cancelled) — each with one line of status text (the open approval, the
 * last step, the pull request, or what the last turn changed).
 */
class TaskOverview
{
    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const PR_OPEN = 'pr_open';

    public const DONE = 'done';

    /** Display order of the groups. */
    public const GROUPS = [self::WAITING, self::RUNNING, self::READY, self::PR_OPEN, self::DONE];

    /** Tools whose input names the file they change. */
    private const EDIT_TOOLS = ['Edit', 'MultiEdit', 'Write', 'NotebookEdit'];

    /**
     * @param  User|null  $mine  only tasks this person owns or was invited to
     * @return array{waiting: list<array{task: Task, text: string}>, running: list<array{task: Task, text: string}>, ready: list<array{task: Task, text: string}>, pr_open: list<array{task: Task, text: string}>, done: list<array{task: Task, text: string}>, archived: int}
     */
    public function for(Workspace $workspace, ?User $mine = null): array
    {
        $tasks = Task::query()
            ->with(['owner', 'members'])
            ->where('workspace_id', $workspace->id)
            ->whereNull('archived_at')
            ->when($mine !== null, fn ($q) => $q->where(fn ($q) => $q->where('owner_user_id', $mine->id)->orWhereHas('members', fn ($m) => $m->where('user_id', $mine->id))))
            ->latest('updated_at')
            ->get();
        $pending = $this->pendingApprovals($tasks);

        $groups = array_fill_keys(self::GROUPS, []);
        foreach ($tasks as $task) {
            $approval = $pending->get($task->id);
            $group = $this->group($task, $approval);
            $groups[$group][] = ['task' => $task, 'text' => $this->text($task, $group, $approval)];
        }

        return [
            ...$groups,
            'archived' => Task::query()->where('workspace_id', $workspace->id)->whereNotNull('archived_at')->count(),
        ];
    }

    /** Tasks waiting for a decision — the header's hint chip. */
    public function waitingCount(Workspace $workspace): int
    {
        return count($this->for($workspace)[self::WAITING]);
    }

    public function group(Task $task, ?TaskApproval $approval): string
    {
        if ($approval !== null) {
            return self::WAITING;
        }

        return $task->state()->group();
    }

    private function text(Task $task, string $group, ?TaskApproval $approval): string
    {
        if ($approval !== null) {
            return __('workspaces.switcher.text.approval', ['what' => $this->approvalSummary($approval)]);
        }
        if ($group === self::WAITING) {
            return $task->auto_state === AutoTaskState::AwaitingPlanApproval ? __('workspaces.switcher.text.plan') : __('workspaces.switcher.text.waiting');
        }
        if ($group === self::RUNNING) {
            if ($task->status === TaskStatus::Preparing) {
                return __('workspaces.switcher.text.preparing');
            }
            $step = $this->lastStep($task);

            return $step === '' ? __('workspaces.switcher.text.working') : __('workspaces.switcher.text.working_on', ['step' => $step]);
        }
        if ($task->queued_at !== null) {
            return __('workspaces.switcher.text.queued');
        }
        if ($group === self::PR_OPEN) {
            return __('workspaces.switcher.text.pr', ['number' => (string) $task->pr_number, 'state' => __('workspaces.pr_state.'.($task->pr_state ?? 'open'))]);
        }
        if ($group === self::DONE) {
            return $task->state()->label();
        }
        if ($task->session_status === SessionStatus::Error) {
            return __('workspaces.switcher.text.error');
        }
        if ($task->auto_state !== null && $task->auto_state !== AutoTaskState::Done) {
            return $task->auto_state->label();
        }
        $files = $this->filesChangedInLastTurn($task);

        return $files > 0 ? trans_choice('workspaces.switcher.text.done_files', $files, ['count' => $files]) : __('workspaces.switcher.text.done');
    }

    /** "npm run build", "Plan", or the tool name. */
    private function approvalSummary(TaskApproval $approval): string
    {
        if ($approval->kind === ApprovalKind::Plan) {
            return __('workspaces.switcher.text.plan_short');
        }
        if ($approval->kind === ApprovalKind::Question) {
            return __('workspaces.switcher.text.question_short');
        }
        $summary = TaskEventFormatter::toolSummary($approval->tool_name, $approval->request['input'] ?? null);

        return Str::limit($summary !== '' ? $summary : $approval->tool_name, 60);
    }

    private function lastStep(Task $task): string
    {
        $event = TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('type', TaskEventType::ToolCall->value)
            ->latest('id')
            ->first();
        if ($event === null) {
            return '';
        }

        $summary = TaskEventFormatter::toolSummary((string) ($event->payload['name'] ?? ''), $event->payload['input'] ?? null);

        return Str::limit($summary !== '' ? basename($summary) : (string) ($event->payload['name'] ?? ''), 48);
    }

    /** Distinct files the edit tools touched since the last message of a person. */
    private function filesChangedInLastTurn(Task $task): int
    {
        $since = (int) TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('type', TaskEventType::UserMessage->value)
            ->max('id');

        return TaskEvent::query()
            ->where('task_id', $task->id)
            ->where('type', TaskEventType::ToolCall->value)
            ->where('id', '>', $since)
            ->get()
            ->filter(fn (TaskEvent $e): bool => in_array($e->payload['name'] ?? '', self::EDIT_TOOLS, true))
            ->map(fn (TaskEvent $e): string => (string) ($e->payload['input']['file_path'] ?? $e->payload['input']['notebook_path'] ?? ''))
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * Oldest pending approval per task.
     *
     * @param  Collection<int, Task>  $tasks
     * @return Collection<string, TaskApproval>
     */
    private function pendingApprovals(Collection $tasks): Collection
    {
        if ($tasks->isEmpty()) {
            return collect();
        }

        return TaskApproval::query()
            ->whereIn('task_id', $tasks->pluck('id'))
            ->where('status', ApprovalStatus::Pending->value)
            ->oldest('created_at')
            ->get()
            ->unique('task_id')
            ->keyBy('task_id');
    }
}
