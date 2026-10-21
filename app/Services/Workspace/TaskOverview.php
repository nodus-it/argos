<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\AutoTaskState;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskEvent;
use App\Models\Workspace;
use App\Support\TaskEventFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The tasks of a workspace for the header's switcher: grouped into
 * "waiting for you", "running" and "done", each with one line of status
 * text (the open approval, the last step, or what the last turn changed).
 */
class TaskOverview
{
    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const DONE = 'done';

    /** Tools whose input names the file they change. */
    private const EDIT_TOOLS = ['Edit', 'MultiEdit', 'Write', 'NotebookEdit'];

    /**
     * @return array{waiting: list<array{task: Task, text: string}>, running: list<array{task: Task, text: string}>, done: list<array{task: Task, text: string}>, archived: int}
     */
    public function for(Workspace $workspace): array
    {
        $tasks = Task::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('archived_at')
            ->latest('updated_at')
            ->get();
        $pending = $this->pendingApprovals($tasks);

        $groups = [self::WAITING => [], self::RUNNING => [], self::DONE => []];
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
        if ($approval !== null || $task->session_status === SessionStatus::WaitingInput || $task->auto_state === AutoTaskState::AwaitingPlanApproval) {
            return self::WAITING;
        }
        if (in_array($task->session_status, [SessionStatus::Running, SessionStatus::Starting], true) || ($task->auto_state?->isRunning() ?? false)) {
            return self::RUNNING;
        }

        return self::DONE;
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
            $step = $this->lastStep($task);

            return $step === '' ? __('workspaces.switcher.text.working') : __('workspaces.switcher.text.working_on', ['step' => $step]);
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
