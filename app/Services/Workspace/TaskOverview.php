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
        // The rows check abilities per task: one workspace with its members for all.
        $workspace->loadMissing('members');
        $tasks->each(fn (Task $task) => $task->setRelation('workspace', $workspace));
        $pending = $this->pendingApprovals($tasks);
        $groupOf = $tasks->mapWithKeys(fn (Task $task): array => [$task->id => $this->group($task, $pending->get($task->id))]);
        // Only running tasks show their step, only ready ones their files.
        $steps = $this->lastSteps($groupOf->filter(fn (string $g): bool => $g === self::RUNNING)->keys()->all());
        $files = $this->filesChangedInLastTurn($groupOf->filter(fn (string $g): bool => $g === self::READY)->keys()->all());

        $groups = array_fill_keys(self::GROUPS, []);
        foreach ($tasks as $task) {
            $group = $groupOf[$task->id];
            $groups[$group][] = ['task' => $task, 'text' => $this->text($task, $group, $pending->get($task->id), $steps[$task->id] ?? '', $files[$task->id] ?? 0)];
        }

        return [
            ...$groups,
            'archived' => Task::query()->where('workspace_id', $workspace->id)->whereNotNull('archived_at')->count(),
        ];
    }

    public function group(Task $task, ?TaskApproval $approval): string
    {
        if ($approval !== null) {
            return self::WAITING;
        }

        return $task->state()->group();
    }

    private function text(Task $task, string $group, ?TaskApproval $approval, string $step, int $files): string
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

    /**
     * "What Claude does": the latest tool call of each task, in one query.
     *
     * @param  list<string>  $taskIds
     * @return array<string, string> task id => step
     */
    private function lastSteps(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $latest = TaskEvent::query()->selectRaw('max(id)')->whereIn('task_id', $taskIds)->where('type', TaskEventType::ToolCall->value)->groupBy('task_id');

        return TaskEvent::query()->whereIn('id', $latest)->get()
            ->mapWithKeys(function (TaskEvent $event): array {
                $summary = TaskEventFormatter::toolSummary((string) ($event->payload['name'] ?? ''), $event->payload['input'] ?? null);

                return [$event->task_id => Str::limit($summary !== '' ? basename($summary) : (string) ($event->payload['name'] ?? ''), 48)];
            })
            ->all();
    }

    /**
     * Distinct files the edit tools touched since a person last wrote, per
     * task — two queries for all tasks.
     *
     * @param  list<string>  $taskIds
     * @return array<string, int> task id => files
     */
    private function filesChangedInLastTurn(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $since = TaskEvent::query()->whereIn('task_id', $taskIds)->where('type', TaskEventType::UserMessage->value)
            ->groupBy('task_id')->selectRaw('task_id, max(id) as since')->pluck('since', 'task_id');

        return TaskEvent::query()
            ->whereIn('task_id', $taskIds)
            ->where('type', TaskEventType::ToolCall->value)
            ->where('id', '>', (int) ($since->min() ?? 0))
            ->get(['id', 'task_id', 'payload'])
            ->filter(fn (TaskEvent $e): bool => $e->id > (int) ($since[$e->task_id] ?? 0) && in_array($e->payload['name'] ?? '', self::EDIT_TOOLS, true))
            ->groupBy('task_id')
            ->map(fn ($events): int => $events->map(fn (TaskEvent $e): string => (string) ($e->payload['input']['file_path'] ?? $e->payload['input']['notebook_path'] ?? ''))->filter()->unique()->count())
            ->all();
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
