<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\ApprovalKind;
use App\Enums\ApprovalStatus;
use App\Enums\AutoPlanMode;
use App\Enums\AutoShipMode;
use App\Enums\AutoThreadState;
use App\Enums\GateRunStatus;
use App\Enums\PermissionMode;
use App\Enums\WorkspaceOrigin;
use App\Enums\WorkspaceStatus;
use App\Jobs\Workspace\AdvanceAutoThreadJob;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs auto-threads: threads that plan (optionally), implement, check the
 * quality gates, fix failures and ship without a human.
 *
 *   queued → planning → (awaiting_plan_approval) → implementing
 *          → gating ⇄ fixing → shipping → done
 *   any time: paused (a human took over), failed, budget_exceeded
 *
 * Event hooks (called by ThreadEventIngestor) only decide and record the
 * next state; the work itself — opening sessions, running gates, shipping —
 * happens in AdvanceAutoThreadJob so the daemon's event endpoint never
 * waits. Every step re-reads the thread under a lock and checks the state
 * it expects, so a re-delivered event never runs a step twice.
 */
class AutoThreadDriver
{
    /** A queued thread gives up when its workspace is not up by then. */
    public const QUEUE_TIMEOUT_MINUTES = 30;

    /** Seconds before a waiting step (workspace starting, agent busy) retries. */
    public const RETRY_SECONDS = 30;

    public function __construct(
        private readonly ThreadService $threads,
        private readonly WorkspaceGateService $gates,
        private readonly WorkspaceShipService $ship,
        private readonly WorkspaceService $workspaces,
        private readonly WorkspaceNotifier $notifier,
    ) {}

    /**
     * Create an auto-thread in `$workspace` and start it.
     *
     * @param  array<string, mixed>  $overrides  per-run settings (AutoSettings keys)
     */
    public function start(Workspace $workspace, User $by, AgentCredential $agent, string $prompt, ?string $title = null, ?string $model = null, array $overrides = []): Thread
    {
        $config = AutoSettings::resolve($workspace->repoProfile, $overrides);
        $mode = $config['plan'] === AutoPlanMode::Off->value ? PermissionMode::BypassPermissions : PermissionMode::Plan;

        $thread = $this->threads->create($workspace, $by, $agent, $title ?? mb_strimwidth(trim($prompt), 0, 60, '…'), $model, $mode, automated: true);
        $thread->forceFill([
            'auto_state' => AutoThreadState::Queued,
            'auto_config' => [...$config, 'prompt' => $prompt],
            'auto_counters' => ['fix_rounds' => 0, 'turns' => 0, 'started_at' => now()->toIso8601String()],
        ])->save();

        AdvanceAutoThreadJob::dispatch($thread->id, 'begin');

        return $thread;
    }

    // ── Event hooks (cheap, synchronous) ─────────────────────────────────

    /**
     * Thread events the ingestor just applied.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function onThreadEvents(Thread $thread, array $events): void
    {
        if ($thread->auto_state === null) {
            return;
        }

        foreach ($events as $event) {
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            match ($event['type'] ?? null) {
                'approval.requested' => $this->onApproval($thread, $data),
                'turn.completed' => $this->onTurnCompleted($thread),
                'usage_limit' => $this->onUsageLimit($thread, $data),
                'error' => ($data['code'] ?? '') === 'session_ended' ? null : $this->fail($thread, (string) ($data['message'] ?? 'error')),
                default => null,
            };
        }
    }

    /** A gate run of the workspace finished: advance threads waiting for it. */
    public function onGatesFinished(Workspace $workspace): void
    {
        $waiting = Thread::query()
            ->where('workspace_id', $workspace->id)
            ->where('auto_state', AutoThreadState::Gating->value)
            ->pluck('id');
        foreach ($waiting as $threadId) {
            AdvanceAutoThreadJob::dispatch($threadId, 'gates_done');
        }
    }

    // ── Human controls ───────────────────────────────────────────────────

    public function pause(Thread $thread, User $by): void
    {
        Gate::forUser($by)->authorize('update', $thread);
        if ($thread->auto_state?->isRunning() === true) {
            $this->transition($thread, AutoThreadState::Paused, __('workspaces.auto.paused_by', ['name' => $by->name]));
        }
    }

    /** Hand the thread back to automation. */
    public function resume(Thread $thread, User $by): void
    {
        Gate::forUser($by)->authorize('update', $thread);
        if ($thread->auto_state === null || $thread->auto_state->isRunning()) {
            return;
        }

        if ($thread->status->isBusy()) {
            // Claude works on something a human asked: gate after that turn.
            $this->transition($thread, AutoThreadState::Implementing);

            return;
        }
        $this->transition($thread, AutoThreadState::Gating);
        AdvanceAutoThreadJob::dispatch($thread->id, 'gate');
    }

    /**
     * Decide a plan that waits for approval (`plan = approve`).
     */
    public function decidePlan(Thread $thread, User $by, bool $approve, ?string $feedback = null): void
    {
        if ($thread->auto_state !== AutoThreadState::AwaitingPlanApproval) {
            throw WorkspaceException::translated('workspaces.auto.no_plan_waiting');
        }
        $approval = $this->pendingPlan($thread) ?? throw WorkspaceException::translated('workspaces.auto.no_plan_waiting');

        if ($approve) {
            // Mode first: the implementation starts as soon as the plan is accepted.
            $this->transition($thread, AutoThreadState::Implementing);
            $this->threads->applyAutomatedMode($thread, PermissionMode::BypassPermissions);
            $this->threads->resolveApproval($approval, $by, 'allow');

            return;
        }

        // Rejected: Claude plans again with the feedback, still in plan mode.
        $this->transition($thread, AutoThreadState::Planning);
        $this->threads->resolveApproval($approval, $by, 'deny', null, $feedback !== null && trim($feedback) !== '' ? $feedback : __('workspaces.auto.plan_rejected_default'));
    }

    // ── Steps (AdvanceAutoThreadJob) ─────────────────────────────────────

    /**
     * Run one step. Throws AutoThreadRetry when it has to wait.
     *
     * @param  array<string, mixed>  $data
     */
    public function step(string $threadId, string $step, array $data = []): void
    {
        $thread = Thread::query()->with(['workspace.repoProfile', 'creator'])->find($threadId);
        if ($thread === null || $thread->auto_state === null || ! $thread->auto_state->isRunning()) {
            return;
        }

        try {
            match ($step) {
                'begin' => $this->begin($thread),
                'approve_plan' => $this->approvePlanAutomatically($thread, (string) ($data['approval_id'] ?? '')),
                'answer_question' => $this->answerQuestion($thread, (string) ($data['approval_id'] ?? '')),
                'gate' => $this->runGates($thread),
                'gates_done' => $this->afterGates($thread),
                'ship' => $this->shipIt($thread),
                'continue' => $this->sendAutomated($thread, __('workspaces.auto.prompts.continue')),
                default => null,
            };
        } catch (AutoThreadRetry $retry) {
            throw $retry;
        } catch (Throwable $e) {
            Log::channel('argos')->warning('Auto-thread step failed', ['thread' => $thread->id, 'step' => $step, 'error' => $e->getMessage()]);
            $this->fail($thread, $e->getMessage());
        }
    }

    private function begin(Thread $thread): void
    {
        if ($thread->auto_state !== AutoThreadState::Queued) {
            return;
        }

        $workspace = $thread->workspace;
        if ($workspace->status !== WorkspaceStatus::Running) {
            if (in_array($workspace->status, [WorkspaceStatus::Failed, WorkspaceStatus::Stopping], true)) {
                $this->fail($thread, __('workspaces.auto.workspace_unavailable'));

                return;
            }
            if ($workspace->status === WorkspaceStatus::Stopped) {
                $this->workspaces->start($workspace);
            }
            $this->retryUnlessExpired($thread);
        }

        $planning = ($thread->auto_config['plan'] ?? AutoPlanMode::Auto->value) !== AutoPlanMode::Off->value;
        $this->transition($thread, $planning ? AutoThreadState::Planning : AutoThreadState::Implementing);

        try {
            $this->sendAutomated($thread, $this->firstMessage($thread, $planning));
        } catch (WorkspaceException $e) {
            // Agent busy or usage-limited: wait in the queue instead of failing.
            $this->transition($thread, AutoThreadState::Queued, $e->getMessage());
            $this->retryUnlessExpired($thread);
        }
    }

    private function approvePlanAutomatically(Thread $thread, string $approvalId): void
    {
        $approval = ThreadApproval::query()->where('thread_id', $thread->id)->whereKey($approvalId)->first();
        if ($approval === null || $approval->status !== ApprovalStatus::Pending || $thread->auto_state !== AutoThreadState::Planning) {
            return;
        }

        // Mode first: the implementation starts as soon as the plan is accepted.
        $this->transition($thread, AutoThreadState::Implementing);
        $this->threads->applyAutomatedMode($thread, PermissionMode::BypassPermissions);
        $this->threads->resolveApproval($approval, $this->actor($thread), 'allow');
    }

    private function answerQuestion(Thread $thread, string $approvalId): void
    {
        $approval = ThreadApproval::query()->where('thread_id', $thread->id)->whereKey($approvalId)->first();
        if ($approval === null || $approval->status !== ApprovalStatus::Pending) {
            return;
        }

        // Nobody is there to answer: Claude decides and says what it assumed.
        $this->threads->resolveApproval($approval, $this->actor($thread), 'deny', null, __('workspaces.auto.prompts.decide_yourself'));
    }

    private function runGates(Thread $thread): void
    {
        if ($thread->auto_state !== AutoThreadState::Gating) {
            return;
        }

        try {
            $this->gates->run($thread->workspace, $this->actor($thread));
        } catch (WorkspaceException) {
            // Another gate run is in progress — ours follows.
            throw new AutoThreadRetry(self::RETRY_SECONDS);
        }
    }

    private function afterGates(Thread $thread): void
    {
        if ($thread->auto_state !== AutoThreadState::Gating) {
            return;
        }

        $run = $this->gates->latest($thread->workspace);
        $failed = $run !== null && $run->status === GateRunStatus::Failed ? $run->failedGates() : [];
        $rounds = (int) ($thread->auto_counters['fix_rounds'] ?? 0);

        if ($failed !== [] && $run instanceof WorkspaceGateRun && $rounds < (int) ($thread->auto_config['max_fix_rounds'] ?? 0)) {
            $this->counters($thread, ['fix_rounds' => $rounds + 1]);
            $this->transition($thread, AutoThreadState::Fixing);
            $this->gates->sendFixToAgent($run, $thread, $this->actor($thread), $failed[0], automated: true);

            return;
        }

        $this->transition($thread, AutoThreadState::Shipping);
        AdvanceAutoThreadJob::dispatch($thread->id, 'ship');
    }

    private function shipIt(Thread $thread): void
    {
        if ($thread->auto_state !== AutoThreadState::Shipping) {
            return;
        }

        $workspace = $thread->workspace;
        $actor = $this->actor($thread);
        $mode = AutoShipMode::tryFrom((string) ($thread->auto_config['ship'] ?? '')) ?? AutoShipMode::PullRequest;
        $draft = $this->ship->draft($workspace, $actor);
        $subject = $draft['subject'] !== '' ? $draft['subject'] : $thread->title;
        $body = trim($draft['body']."\n\n".$this->gateNote($workspace));

        try {
            $url = $this->ship->ship($workspace, $actor, $subject, $body, $mode === AutoShipMode::DraftPullRequest, $mode !== AutoShipMode::None);
        } catch (WorkspaceException $e) {
            if ($e->getMessage() !== __('workspaces.ship.nothing')) {
                throw $e;
            }
            $url = '';
        }

        $this->finish($thread, AutoThreadState::Done, $url !== '' ? $url : null);
    }

    // ── Event handlers ───────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    private function onApproval(Thread $thread, array $data): void
    {
        $approvalId = (string) ($data['approval_id'] ?? '');
        $kind = ApprovalKind::tryFrom((string) ($data['kind'] ?? '')) ?? ApprovalKind::Tool;

        if ($kind === ApprovalKind::Plan && $thread->auto_state === AutoThreadState::Planning) {
            if (($thread->auto_config['plan'] ?? '') === AutoPlanMode::Approve->value) {
                $this->transition($thread, AutoThreadState::AwaitingPlanApproval);
                $this->notifier->notify($thread->workspace, $thread, 'auto', __('workspaces.auto.notifications.plan'), $thread->title);

                return;
            }
            AdvanceAutoThreadJob::dispatch($thread->id, 'approve_plan', ['approval_id' => $approvalId]);

            return;
        }

        if ($kind === ApprovalKind::Question && $thread->auto_state?->isRunning() === true) {
            AdvanceAutoThreadJob::dispatch($thread->id, 'answer_question', ['approval_id' => $approvalId]);
        }
    }

    private function onTurnCompleted(Thread $thread): void
    {
        $thread = $thread->fresh() ?? $thread;
        $counters = $thread->auto_counters ?? [];
        $turns = (int) ($counters['turns'] ?? 0) + 1;
        $this->counters($thread, ['turns' => $turns]);

        if ($thread->auto_state === null || ! $thread->auto_state->isRunning()) {
            return;
        }

        if ($reason = $this->budgetExceeded($thread, $turns)) {
            $this->finish($thread, AutoThreadState::BudgetExceeded, null, $reason);

            return;
        }

        if (in_array($thread->auto_state, [AutoThreadState::Implementing, AutoThreadState::Fixing], true)) {
            $this->transition($thread, AutoThreadState::Gating);
            AdvanceAutoThreadJob::dispatch($thread->id, 'gate');
        }
        // Planning: a turn can end without a plan (Claude asked something
        // or explored first) — the plan approval request moves it on.
    }

    /** @param  array<string, mixed>  $data */
    private function onUsageLimit(Thread $thread, array $data): void
    {
        if ($thread->auto_state?->isRunning() !== true) {
            return;
        }

        $resetsAt = is_string($data['resets_at'] ?? null) ? Carbon::parse($data['resets_at']) : null;
        $message = __('workspaces.auto.usage_limit', ['time' => $resetsAt?->diffForHumans() ?? '—']);
        $thread->forceFill(['auto_message' => $message])->save();
        $this->notifier->notify($thread->workspace, $thread, 'auto', __('workspaces.auto.notifications.limit'), $message);

        // Continue where it stopped once the limit is lifted.
        if ($resetsAt !== null && $resetsAt->isFuture()) {
            AdvanceAutoThreadJob::dispatch($thread->id, 'continue')->delay($resetsAt->addMinute());
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function firstMessage(Thread $thread, bool $planning): string
    {
        $prompt = (string) ($thread->auto_config['prompt'] ?? '');

        return __($planning ? 'workspaces.auto.prompts.plan_first' : 'workspaces.auto.prompts.implement', ['prompt' => $prompt]);
    }

    private function sendAutomated(Thread $thread, string $text): void
    {
        $this->threads->send($thread, $this->actor($thread), $text, automated: true);
    }

    /** The user the automation acts for: the thread's creator. */
    private function actor(Thread $thread): User
    {
        return $thread->creator ?? throw WorkspaceException::translated('workspaces.auto.no_actor');
    }

    private function pendingPlan(Thread $thread): ?ThreadApproval
    {
        return ThreadApproval::query()
            ->where('thread_id', $thread->id)
            ->where('kind', ApprovalKind::Plan->value)
            ->where('status', ApprovalStatus::Pending->value)
            ->latest('created_at')
            ->first();
    }

    private function budgetExceeded(Thread $thread, int $turns): ?string
    {
        $config = $thread->auto_config ?? [];
        $started = isset($thread->auto_counters['started_at']) ? Carbon::parse((string) $thread->auto_counters['started_at']) : null;

        return match (true) {
            $turns >= (int) ($config['max_turns'] ?? PHP_INT_MAX) => __('workspaces.auto.budget.turns', ['max' => $config['max_turns']]),
            (float) ($config['max_cost_usd'] ?? 0) > 0 && (float) $thread->cost_usd >= (float) $config['max_cost_usd'] => __('workspaces.auto.budget.cost', ['max' => $config['max_cost_usd']]),
            $started !== null && $started->diffInMinutes(now()) >= (int) ($config['max_minutes'] ?? PHP_INT_MAX) => __('workspaces.auto.budget.time', ['max' => $config['max_minutes']]),
            default => null,
        };
    }

    private function gateNote(Workspace $workspace): string
    {
        $summary = $this->gates->summary($workspace);
        $failed = $this->gates->latest($workspace)?->failedGates() ?? [];

        return match (true) {
            $summary === null => '',
            $failed === [] => __('workspaces.auto.pr_gates_ok', ['summary' => $summary]),
            default => __('workspaces.auto.pr_gates_failed', ['summary' => $summary, 'gates' => implode(', ', $failed)]),
        };
    }

    private function retryUnlessExpired(Thread $thread): never
    {
        $started = Carbon::parse((string) ($thread->auto_counters['started_at'] ?? now()->toIso8601String()));
        if ($started->diffInMinutes(now()) >= self::QUEUE_TIMEOUT_MINUTES) {
            $this->fail($thread, __('workspaces.auto.queue_timeout'));

            throw new AutoThreadRetry(0, giveUp: true);
        }

        throw new AutoThreadRetry(self::RETRY_SECONDS);
    }

    private function fail(Thread $thread, string $message): void
    {
        $this->finish($thread, AutoThreadState::Failed, null, $message);
    }

    /** End the run: free the agent, stop an automatic workspace, tell people. */
    private function finish(Thread $thread, AutoThreadState $state, ?string $url = null, ?string $message = null): void
    {
        $this->transition($thread, $state, $message);
        if ($thread->session_open) {
            if ($state === AutoThreadState::BudgetExceeded && $thread->status->isBusy()) {
                rescue(fn () => $this->threads->interrupt($thread, $this->actor($thread)), report: false);
            }
            rescue(fn () => $this->threads->close($thread), report: false);
        }

        $workspace = $thread->workspace->fresh() ?? $thread->workspace;
        $othersBusy = Thread::query()->where('workspace_id', $workspace->id)->whereKeyNot($thread->id)->where('session_open', true)->exists();
        if ($workspace->origin !== WorkspaceOrigin::Manual && ! $othersBusy && $workspace->status === WorkspaceStatus::Running) {
            rescue(fn () => $this->workspaces->stop($workspace), report: false);
        }

        $this->notifier->notify($workspace, $thread, 'auto', __('workspaces.auto.notifications.'.$state->value), $url ?? $message ?? $thread->title);
    }

    private function transition(Thread $thread, AutoThreadState $state, ?string $message = null): void
    {
        DB::transaction(function () use ($thread, $state, $message): void {
            Thread::query()->whereKey($thread->id)->lockForUpdate()->first();
            $thread->forceFill(['auto_state' => $state, 'auto_message' => $message])->save();
        });
        $this->notifier->summaryChanged($thread->workspace, $thread);
    }

    /** @param  array<string, mixed>  $changes */
    private function counters(Thread $thread, array $changes): void
    {
        $thread->forceFill(['auto_counters' => [...($thread->auto_counters ?? []), ...$changes]])->save();
    }
}
