<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\AutoPlanMode;
use App\Enums\AutoShipMode;
use App\Enums\AutoTaskState;
use App\Enums\GateRunStatus;
use App\Enums\PermissionMode;
use App\Enums\TaskOrigin;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\AdvanceAutoTaskJob;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Services\IssueTracker\IssueRunReporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs auto-tasks: tasks that plan (optionally), implement, check the
 * quality gates, fix failures and ship without a human.
 *
 *   queued → planning → (awaiting_plan_approval) → implementing
 *          → gating ⇄ fixing → shipping → done
 *   any time: paused (a human took over), failed, budget_exceeded
 *
 * Event hooks (called by TaskEventIngestor) only decide and record the
 * next state; the work itself — opening sessions, running gates, shipping —
 * happens in AdvanceAutoTaskJob so the daemon's event endpoint never
 * waits. Every step re-reads the task under a lock and checks the state
 * it expects, so a re-delivered event never runs a step twice.
 */
class AutoTaskDriver
{
    /** A queued task gives up when its workspace is not up by then. */
    public const QUEUE_TIMEOUT_MINUTES = 30;

    /** Seconds before a waiting step (workspace starting, agent busy) retries. */
    public const RETRY_SECONDS = 30;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly WorkspaceGateService $gates,
        private readonly WorkspaceShipService $ship,
        private readonly WorkspaceService $workspaces,
        private readonly WorkspaceNotifier $notifier,
    ) {}

    /**
     * Create an auto-task in `$workspace`, owned by `$by`, and start it.
     *
     * @param  array<string, mixed>  $overrides  per-run settings (AutoSettings keys)
     * @param  array{base_branch?: string|null, external_ref?: array<string, mixed>|null, origin?: TaskOrigin, parent_task_id?: string|null, auto_merge?: bool}  $taskOptions  see TaskService::create()
     */
    public function start(Workspace $workspace, User $by, AgentCredential $agent, string $prompt, ?string $title = null, ?string $model = null, array $overrides = [], array $taskOptions = []): Task
    {
        $config = AutoSettings::resolve($workspace, $overrides);
        $mode = $config['plan'] === AutoPlanMode::Off->value ? PermissionMode::BypassPermissions : PermissionMode::Plan;

        $task = $this->tasks->create($workspace, $by, $agent, $title ?? mb_strimwidth(trim($prompt), 0, 60, '…'), $model, $mode, automated: true, options: $taskOptions);
        $task->forceFill([
            'auto_state' => AutoTaskState::Queued,
            'auto_config' => [...$config, 'prompt' => $prompt],
            'auto_counters' => ['fix_rounds' => 0, 'turns' => 0, 'started_at' => now()->toIso8601String()],
        ])->save();

        AdvanceAutoTaskJob::dispatch($task->id, 'begin');

        return $task;
    }

    // ── Event hooks (cheap, synchronous) ─────────────────────────────────

    /**
     * Task events the ingestor just applied.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function onTaskEvents(Task $task, array $events): void
    {
        if ($task->auto_state === null) {
            return;
        }

        foreach ($events as $event) {
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            match ($event['type'] ?? null) {
                'approval.requested' => $this->onApproval($task, $data),
                'turn.completed' => $this->onTurnCompleted($task),
                'usage_limit' => $this->onUsageLimit($task, $data),
                'error' => ($data['code'] ?? '') === 'session_ended' ? null : $this->fail($task, (string) ($data['message'] ?? 'error')),
                default => null,
            };
        }
    }

    /** A gate run of the workspace finished: advance tasks waiting for it. */
    public function onGatesFinished(Workspace $workspace): void
    {
        $waiting = Task::query()
            ->where('workspace_id', $workspace->id)
            ->where('auto_state', AutoTaskState::Gating->value)
            ->pluck('id');
        foreach ($waiting as $taskId) {
            AdvanceAutoTaskJob::dispatch($taskId, 'gates_done');
        }
    }

    // ── Human controls ───────────────────────────────────────────────────

    public function pause(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('update', $task);
        if ($task->auto_state?->isRunning() === true) {
            $this->transition($task, AutoTaskState::Paused, __('workspaces.auto.paused_by', ['name' => $by->name]));
        }
    }

    /** Hand the task back to automation. */
    public function resume(Task $task, User $by): void
    {
        Gate::forUser($by)->authorize('update', $task);
        if ($task->auto_state === null || $task->auto_state->isRunning()) {
            return;
        }

        if ($task->session_status->isBusy()) {
            // Claude works on something a human asked: gate after that turn.
            $this->transition($task, AutoTaskState::Implementing);

            return;
        }
        $this->transition($task, AutoTaskState::Gating);
        AdvanceAutoTaskJob::dispatch($task->id, 'gate');
    }

    /**
     * Decide a plan that waits for approval (`plan = approve`).
     */
    public function decidePlan(Task $task, User $by, bool $approve, ?string $feedback = null): void
    {
        if ($task->auto_state !== AutoTaskState::AwaitingPlanApproval) {
            throw WorkspaceException::translated('workspaces.auto.no_plan_waiting');
        }
        $approval = $this->pendingPlan($task) ?? throw WorkspaceException::translated('workspaces.auto.no_plan_waiting');

        if ($approve) {
            // Mode first: the implementation starts as soon as the plan is accepted.
            $this->transition($task, AutoTaskState::Implementing);
            $this->tasks->applyAutomatedMode($task, PermissionMode::BypassPermissions);
            $this->tasks->resolveApproval($approval, $by, 'allow');

            return;
        }

        // Rejected: Claude plans again with the feedback, still in plan mode.
        $this->transition($task, AutoTaskState::Planning);
        $this->tasks->resolveApproval($approval, $by, 'deny', null, $feedback !== null && trim($feedback) !== '' ? $feedback : __('workspaces.auto.plan_rejected_default'));
    }

    // ── Steps (AdvanceAutoTaskJob) ─────────────────────────────────────

    /**
     * Run one step. Throws AutoTaskRetry when it has to wait.
     *
     * @param  array<string, mixed>  $data
     */
    public function step(string $taskId, string $step, array $data = []): void
    {
        $task = Task::query()->with(['workspace', 'owner'])->find($taskId);
        if ($task === null || $task->auto_state === null || ! $task->auto_state->isRunning()) {
            return;
        }

        try {
            match ($step) {
                'begin' => $this->begin($task),
                'approve_plan' => $this->approvePlanAutomatically($task, (string) ($data['approval_id'] ?? '')),
                'answer_question' => $this->answerQuestion($task, (string) ($data['approval_id'] ?? '')),
                'gate' => $this->runGates($task),
                'gates_done' => $this->afterGates($task),
                'ship' => $this->shipIt($task),
                'continue' => $this->sendAutomated($task, __('workspaces.auto.prompts.continue')),
                default => null,
            };
        } catch (AutoTaskRetry $retry) {
            throw $retry;
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Auto-task step failed', ['task' => $task->id, 'step' => $step, 'error' => $e->getMessage()]);
            $this->fail($task, $e->getMessage());
        }
    }

    private function begin(Task $task): void
    {
        if ($task->auto_state !== AutoTaskState::Queued) {
            return;
        }

        $workspace = $task->workspace;
        if ($workspace->status !== WorkspaceStatus::Running) {
            if (in_array($workspace->status, [WorkspaceStatus::Failed, WorkspaceStatus::Stopping], true)) {
                $this->fail($task, __('workspaces.auto.workspace_unavailable'));

                return;
            }
            if ($workspace->status === WorkspaceStatus::Stopped) {
                $this->workspaces->start($workspace);
            }
            $this->retryUnlessExpired($task);
        }
        if ($task->status === TaskStatus::Preparing) {
            // TaskPreparer starts this step again once the working copy is ready.
            $this->retryUnlessExpired($task);
        }

        $planning = ($task->auto_config['plan'] ?? AutoPlanMode::Auto->value) !== AutoPlanMode::Off->value;
        $this->transition($task, $planning ? AutoTaskState::Planning : AutoTaskState::Implementing);

        try {
            $this->sendAutomated($task, $this->firstMessage($task, $planning));
        } catch (WorkspaceException $e) {
            // Agent busy or usage-limited: wait in the queue instead of failing.
            $this->transition($task, AutoTaskState::Queued, $e->getMessage());
            $this->retryUnlessExpired($task);
        }
    }

    private function approvePlanAutomatically(Task $task, string $approvalId): void
    {
        $approval = TaskApproval::query()->where('task_id', $task->id)->whereKey($approvalId)->first();
        if ($approval === null || $approval->status !== ApprovalStatus::Pending || $task->auto_state !== AutoTaskState::Planning) {
            return;
        }

        // Mode first: the implementation starts as soon as the plan is accepted.
        $this->transition($task, AutoTaskState::Implementing);
        $this->tasks->applyAutomatedMode($task, PermissionMode::BypassPermissions);
        $this->tasks->resolveApproval($approval, $this->actor($task), 'allow');
    }

    private function answerQuestion(Task $task, string $approvalId): void
    {
        $approval = TaskApproval::query()->where('task_id', $task->id)->whereKey($approvalId)->first();
        if ($approval === null || $approval->status !== ApprovalStatus::Pending) {
            return;
        }

        // Nobody is there to answer: Claude decides and says what it assumed.
        $this->tasks->resolveApproval($approval, $this->actor($task), 'deny', null, __('workspaces.auto.prompts.decide_yourself'));
    }

    private function runGates(Task $task): void
    {
        if ($task->auto_state !== AutoTaskState::Gating) {
            return;
        }

        try {
            $this->gates->run($task, $this->actor($task));
        } catch (WorkspaceException) {
            // Another gate run is in progress — ours follows.
            throw new AutoTaskRetry(self::RETRY_SECONDS);
        }
    }

    private function afterGates(Task $task): void
    {
        if ($task->auto_state !== AutoTaskState::Gating) {
            return;
        }

        $run = $this->gates->latest($task);
        if ($run === null || $run->status === GateRunStatus::Running) {
            // Another task's run finished; ours is still to come.
            return;
        }
        $failed = $run !== null && $run->status === GateRunStatus::Failed ? $run->failedGates() : [];
        $rounds = (int) ($task->auto_counters['fix_rounds'] ?? 0);

        if ($failed !== [] && $run instanceof WorkspaceGateRun && $rounds < (int) ($task->auto_config['max_fix_rounds'] ?? 0)) {
            $this->counters($task, ['fix_rounds' => $rounds + 1]);
            $this->transition($task, AutoTaskState::Fixing);
            $this->gates->sendFixToAgent($run, $task, $this->actor($task), $failed[0], automated: true);

            return;
        }

        $this->transition($task, AutoTaskState::Shipping);
        AdvanceAutoTaskJob::dispatch($task->id, 'ship');
    }

    private function shipIt(Task $task): void
    {
        if ($task->auto_state !== AutoTaskState::Shipping) {
            return;
        }

        $actor = $this->actor($task);
        $mode = AutoShipMode::tryFrom((string) ($task->auto_config['ship'] ?? '')) ?? AutoShipMode::PullRequest;
        $draft = $this->ship->draft($task, $actor);
        $subject = $draft['subject'] !== '' ? $draft['subject'] : $task->title;
        $body = trim($draft['body']."\n\n".$this->gateNote($task));

        try {
            $url = $this->ship->ship($task, $actor, $subject, $body, $mode === AutoShipMode::DraftPullRequest, $mode !== AutoShipMode::None);
        } catch (WorkspaceException $e) {
            if ($e->reason !== 'nothing') {
                throw $e;
            }
            $url = '';
        }

        $this->finish($task, AutoTaskState::Done, $url !== '' ? $url : null);
    }

    // ── Event handlers ───────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    private function onApproval(Task $task, array $data): void
    {
        $approvalId = (string) ($data['approval_id'] ?? '');
        $kind = ApprovalKind::tryFrom((string) ($data['kind'] ?? '')) ?? ApprovalKind::Tool;

        if ($kind === ApprovalKind::Plan && $task->auto_state === AutoTaskState::Planning) {
            if (($task->auto_config['plan'] ?? '') === AutoPlanMode::Approve->value) {
                $this->transition($task, AutoTaskState::AwaitingPlanApproval);
                $this->notifier->notify($task->workspace, $task, 'auto', __('workspaces.auto.notifications.plan'), $task->title);
                app(IssueRunReporter::class)->planWaiting($task, (string) ($data['input']['plan'] ?? ''));

                return;
            }
            AdvanceAutoTaskJob::dispatch($task->id, 'approve_plan', ['approval_id' => $approvalId]);

            return;
        }

        if ($kind === ApprovalKind::Question && $task->auto_state?->isRunning() === true) {
            AdvanceAutoTaskJob::dispatch($task->id, 'answer_question', ['approval_id' => $approvalId]);
        }
    }

    private function onTurnCompleted(Task $task): void
    {
        $task = $task->fresh() ?? $task;
        $counters = $task->auto_counters ?? [];
        $turns = (int) ($counters['turns'] ?? 0) + 1;
        $this->counters($task, ['turns' => $turns]);

        if ($task->auto_state === null || ! $task->auto_state->isRunning()) {
            return;
        }

        if ($reason = $this->budgetExceeded($task, $turns)) {
            $this->finish($task, AutoTaskState::BudgetExceeded, null, $reason);

            return;
        }

        if (in_array($task->auto_state, [AutoTaskState::Implementing, AutoTaskState::Fixing], true)) {
            $this->transition($task, AutoTaskState::Gating);
            AdvanceAutoTaskJob::dispatch($task->id, 'gate');
        }
        // Planning: a turn can end without a plan (Claude asked something
        // or explored first) — the plan approval request moves it on.
    }

    /** @param  array<string, mixed>  $data */
    private function onUsageLimit(Task $task, array $data): void
    {
        if ($task->auto_state?->isRunning() !== true) {
            return;
        }

        $resetsAt = is_string($data['resets_at'] ?? null) ? Carbon::parse($data['resets_at']) : null;
        $message = __('workspaces.auto.usage_limit', ['time' => $resetsAt?->diffForHumans() ?? '—']);
        $task->forceFill(['auto_message' => $message])->save();
        $this->notifier->notify($task->workspace, $task, 'auto', __('workspaces.auto.notifications.limit'), $message);

        // Continue where it stopped once the limit is lifted.
        if ($resetsAt !== null && $resetsAt->isFuture()) {
            AdvanceAutoTaskJob::dispatch($task->id, 'continue')->delay($resetsAt->addMinute());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function firstMessage(Task $task, bool $planning): string
    {
        $prompt = (string) ($task->auto_config['prompt'] ?? '');

        return __($planning ? 'workspaces.auto.prompts.plan_first' : 'workspaces.auto.prompts.implement', ['prompt' => $prompt]);
    }

    private function sendAutomated(Task $task, string $text): void
    {
        $this->tasks->send($task, $this->actor($task), $text, automated: true);
    }

    /** The user the automation acts for: the task's owner. */
    private function actor(Task $task): User
    {
        return $task->owner ?? throw WorkspaceException::translated('workspaces.auto.owner_gone');
    }

    private function pendingPlan(Task $task): ?TaskApproval
    {
        return TaskApproval::query()
            ->where('task_id', $task->id)
            ->where('kind', ApprovalKind::Plan->value)
            ->where('status', ApprovalStatus::Pending->value)
            ->latest('created_at')
            ->first();
    }

    private function budgetExceeded(Task $task, int $turns): ?string
    {
        $config = $task->auto_config ?? [];
        $started = isset($task->auto_counters['started_at']) ? Carbon::parse((string) $task->auto_counters['started_at']) : null;

        return match (true) {
            $turns >= (int) ($config['max_turns'] ?? PHP_INT_MAX) => __('workspaces.auto.budget.turns', ['max' => $config['max_turns']]),
            (float) ($config['max_cost_usd'] ?? 0) > 0 && (float) $task->cost_usd >= (float) $config['max_cost_usd'] => __('workspaces.auto.budget.cost', ['max' => $config['max_cost_usd']]),
            $started !== null && $started->diffInMinutes(now()) >= (int) ($config['max_minutes'] ?? PHP_INT_MAX) => __('workspaces.auto.budget.time', ['max' => $config['max_minutes']]),
            default => null,
        };
    }

    private function gateNote(Task $task): string
    {
        $summary = $this->gates->summary($task);
        $failed = $this->gates->latest($task)?->failedGates() ?? [];

        return match (true) {
            $summary === null => '',
            $failed === [] => __('workspaces.auto.pr_gates_ok', ['summary' => $summary]),
            default => __('workspaces.auto.pr_gates_failed', ['summary' => $summary, 'gates' => implode(', ', $failed)]),
        };
    }

    private function retryUnlessExpired(Task $task): never
    {
        $started = Carbon::parse((string) ($task->auto_counters['started_at'] ?? now()->toIso8601String()));
        if ($started->diffInMinutes(now()) >= self::QUEUE_TIMEOUT_MINUTES) {
            $this->fail($task, __('workspaces.auto.queue_timeout'));

            throw new AutoTaskRetry(0, giveUp: true);
        }

        throw new AutoTaskRetry(self::RETRY_SECONDS);
    }

    private function fail(Task $task, string $message): void
    {
        $this->finish($task, AutoTaskState::Failed, null, $message);
    }

    /** End the run: free the agent and tell people. The container keeps running for the other tasks. */
    private function finish(Task $task, AutoTaskState $state, ?string $url = null, ?string $message = null): void
    {
        $this->transition($task, $state, $message);
        if ($task->session_open) {
            if ($state === AutoTaskState::BudgetExceeded && $task->session_status->isBusy()) {
                rescue(fn () => $this->tasks->interrupt($task, $this->actor($task)), report: false);
            }
            rescue(fn () => $this->tasks->close($task), report: false);
        }

        $this->notifier->notify($task->workspace, $task, 'auto', __('workspaces.auto.notifications.'.$state->value), $url ?? $message ?? $task->title);
        app(IssueRunReporter::class)->finished($task, $state, $message);
    }

    private function transition(Task $task, AutoTaskState $state, ?string $message = null): void
    {
        DB::transaction(function () use ($task, $state, $message): void {
            Task::query()->whereKey($task->id)->lockForUpdate()->first();
            $task->forceFill(['auto_state' => $state, 'auto_message' => $message])->save();
        });
        $this->notifier->summaryChanged($task->workspace, $task);
    }

    /** @param  array<string, mixed>  $changes */
    private function counters(Task $task, array $changes): void
    {
        $task->forceFill(['auto_counters' => [...($task->auto_counters ?? []), ...$changes]])->save();
    }
}
