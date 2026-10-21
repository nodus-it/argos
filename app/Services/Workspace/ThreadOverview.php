<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\AutoThreadState;
use App\Enums\ThreadEventType;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\ThreadEvent;
use App\Models\Workspace;
use App\Support\ThreadEventFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The threads of a workspace for the header's switcher: grouped into
 * "waiting for you", "running" and "done", each with one line of status
 * text (the open approval, the last step, or what the last turn changed).
 */
class ThreadOverview
{
    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const DONE = 'done';

    /** Tools whose input names the file they change. */
    private const EDIT_TOOLS = ['Edit', 'MultiEdit', 'Write', 'NotebookEdit'];

    /**
     * @return array{waiting: list<array{thread: Thread, text: string}>, running: list<array{thread: Thread, text: string}>, done: list<array{thread: Thread, text: string}>, archived: int}
     */
    public function for(Workspace $workspace): array
    {
        $threads = Thread::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('archived_at')
            ->latest('updated_at')
            ->get();
        $pending = $this->pendingApprovals($threads);

        $groups = [self::WAITING => [], self::RUNNING => [], self::DONE => []];
        foreach ($threads as $thread) {
            $approval = $pending->get($thread->id);
            $group = $this->group($thread, $approval);
            $groups[$group][] = ['thread' => $thread, 'text' => $this->text($thread, $group, $approval)];
        }

        return [
            ...$groups,
            'archived' => Thread::query()->where('workspace_id', $workspace->id)->whereNotNull('archived_at')->count(),
        ];
    }

    /** Threads waiting for a decision — the header's hint chip. */
    public function waitingCount(Workspace $workspace): int
    {
        return count($this->for($workspace)[self::WAITING]);
    }

    public function group(Thread $thread, ?ThreadApproval $approval): string
    {
        if ($approval !== null || $thread->status === ThreadStatus::WaitingInput || $thread->auto_state === AutoThreadState::AwaitingPlanApproval) {
            return self::WAITING;
        }
        if (in_array($thread->status, [ThreadStatus::Running, ThreadStatus::Starting], true) || ($thread->auto_state?->isRunning() ?? false)) {
            return self::RUNNING;
        }

        return self::DONE;
    }

    private function text(Thread $thread, string $group, ?ThreadApproval $approval): string
    {
        if ($approval !== null) {
            return __('workspaces.switcher.text.approval', ['what' => $this->approvalSummary($approval)]);
        }
        if ($group === self::WAITING) {
            return $thread->auto_state === AutoThreadState::AwaitingPlanApproval ? __('workspaces.switcher.text.plan') : __('workspaces.switcher.text.waiting');
        }
        if ($group === self::RUNNING) {
            $step = $this->lastStep($thread);

            return $step === '' ? __('workspaces.switcher.text.working') : __('workspaces.switcher.text.working_on', ['step' => $step]);
        }
        if ($thread->status === ThreadStatus::Error) {
            return __('workspaces.switcher.text.error');
        }
        if ($thread->auto_state !== null && $thread->auto_state !== AutoThreadState::Done) {
            return $thread->auto_state->label();
        }
        $files = $this->filesChangedInLastTurn($thread);

        return $files > 0 ? trans_choice('workspaces.switcher.text.done_files', $files, ['count' => $files]) : __('workspaces.switcher.text.done');
    }

    /** "npm run build", "Plan", or the tool name. */
    private function approvalSummary(ThreadApproval $approval): string
    {
        if ($approval->kind === ApprovalKind::Plan) {
            return __('workspaces.switcher.text.plan_short');
        }
        if ($approval->kind === ApprovalKind::Question) {
            return __('workspaces.switcher.text.question_short');
        }
        $summary = ThreadEventFormatter::toolSummary($approval->tool_name, $approval->request['input'] ?? null);

        return Str::limit($summary !== '' ? $summary : $approval->tool_name, 60);
    }

    private function lastStep(Thread $thread): string
    {
        $event = ThreadEvent::query()
            ->where('thread_id', $thread->id)
            ->where('type', ThreadEventType::ToolCall->value)
            ->latest('id')
            ->first();
        if ($event === null) {
            return '';
        }

        $summary = ThreadEventFormatter::toolSummary((string) ($event->payload['name'] ?? ''), $event->payload['input'] ?? null);

        return Str::limit($summary !== '' ? basename($summary) : (string) ($event->payload['name'] ?? ''), 48);
    }

    /** Distinct files the edit tools touched since the last message of a person. */
    private function filesChangedInLastTurn(Thread $thread): int
    {
        $since = (int) ThreadEvent::query()
            ->where('thread_id', $thread->id)
            ->where('type', ThreadEventType::UserMessage->value)
            ->max('id');

        return ThreadEvent::query()
            ->where('thread_id', $thread->id)
            ->where('type', ThreadEventType::ToolCall->value)
            ->where('id', '>', $since)
            ->get()
            ->filter(fn (ThreadEvent $e): bool => in_array($e->payload['name'] ?? '', self::EDIT_TOOLS, true))
            ->map(fn (ThreadEvent $e): string => (string) ($e->payload['input']['file_path'] ?? $e->payload['input']['notebook_path'] ?? ''))
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * Oldest pending approval per thread.
     *
     * @param  Collection<int, Thread>  $threads
     * @return Collection<string, ThreadApproval>
     */
    private function pendingApprovals(Collection $threads): Collection
    {
        if ($threads->isEmpty()) {
            return collect();
        }

        return ThreadApproval::query()
            ->whereIn('thread_id', $threads->pluck('id'))
            ->where('status', ApprovalStatus::Pending->value)
            ->oldest('created_at')
            ->get()
            ->unique('thread_id')
            ->keyBy('thread_id');
    }
}
