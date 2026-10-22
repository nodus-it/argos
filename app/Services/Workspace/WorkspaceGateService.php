<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\GateRunStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use Illuminate\Support\Facades\Gate;

/**
 * Quality gates on demand: the daemon runs the worker's `quality.sh` gates
 * for a task and reports `gates.*` events, which land here. A failed gate
 * never blocks shipping — it is shown as a warning. The baseline (which
 * failures already exist on the base) is kept per base commit, shared by
 * the tasks built on it.
 */
class WorkspaceGateService
{
    public const BASELINE_STATES = ['missing', 'running', 'ready', 'failed'];

    /** Baseline key when the daemon does not name the base commit. */
    public const CURRENT_BASE = 'current';

    /** Size of the log tail stored with a run. */
    public const TAIL_BYTES = 8192;

    /** A run without a result after this long is considered lost. */
    private const STALE_AFTER_MINUTES = 45;

    public function __construct(
        private readonly WorkspaceDaemonClient $daemon,
        private readonly TaskStreamPublisher $publisher,
        private readonly TaskService $tasks,
    ) {}

    /** Gates that can be re-checked on their own (run-gates.sh --only). */
    public const SINGLE_GATES = ['artisan', 'pint', 'pest', 'phpunit', 'phpstan', 'migrations', 'debug_code', 'test_presence'];

    /**
     * Run the gates — all, or `$only` one of them again (the other results
     * stay from the run before).
     */
    public function run(Task $task, User $by, ?string $only = null): WorkspaceGateRun
    {
        Gate::forUser($by)->authorize('prompt', $task);
        if ($only !== null && ! in_array($only, self::SINGLE_GATES, true)) {
            throw WorkspaceException::translated('workspaces.gates.unknown_gate');
        }
        $workspace = $task->workspace;
        $active = $this->active($workspace);
        if ($active !== null) {
            throw WorkspaceException::translated('workspaces.gates.already_running');
        }

        $run = WorkspaceGateRun::query()->create([
            'workspace_id' => $workspace->id,
            'task_id' => $task->id,
            'triggered_by' => $by->id,
            'status' => GateRunStatus::Running,
            'base_commit' => $task->base_commit,
            'baseline' => $this->baselineFor($workspace, $task->base_commit),
            'only' => $only,
            'started_at' => now(),
        ]);

        try {
            $this->daemon->for($workspace)->runGates($task->slug, $run->id, $task->base_commit, $only);
        } catch (DaemonTransportException $e) {
            $run->forceFill(['status' => GateRunStatus::Error, 'message' => $e->getMessage(), 'finished_at' => now()])->save();

            throw WorkspaceException::fromDaemon($e, ['gates_running' => 'workspaces.gates.already_running']);
        }

        return $run->refresh();
    }

    public function latest(Task $task): ?WorkspaceGateRun
    {
        return WorkspaceGateRun::query()->where('task_id', $task->id)->latest('created_at')->latest('id')->first();
    }

    /** Baseline state for a base commit (null: the current checkout's base). */
    public function baselineFor(Workspace $workspace, ?string $commit): ?string
    {
        return $workspace->gate_baselines[$commit ?? self::CURRENT_BASE] ?? null;
    }

    /**
     * Apply one workspace-level `gates.*` event (idempotent per run).
     *
     * @param  array<string, mixed>  $data
     */
    public function apply(Workspace $workspace, string $type, array $data): bool
    {
        if ($type === 'gates.baseline') {
            $state = (string) ($data['state'] ?? '');
            if (! in_array($state, self::BASELINE_STATES, true)) {
                return false;
            }
            $commit = is_string($data['commit'] ?? null) && $data['commit'] !== '' ? $data['commit'] : self::CURRENT_BASE;
            $workspace->forceFill(['gate_baselines' => [...($workspace->gate_baselines ?? []), $commit => $state]])->save();

            return true;
        }

        $run = WorkspaceGateRun::query()->where('workspace_id', $workspace->id)->whereKey((string) ($data['run_id'] ?? ''))->first();
        if ($run === null || ($run->status !== GateRunStatus::Running && $type !== 'gates.started')) {
            return false;
        }

        match ($type) {
            'gates.started' => $run->forceFill(['baseline' => $this->baseline($data) ?? $run->baseline])->save(),
            'gates.completed' => $this->complete($run, $data),
            'gates.failed' => $run->forceFill([
                'status' => GateRunStatus::Error,
                'message' => mb_substr((string) ($data['message'] ?? ''), 0, 2000),
                'finished_at' => now(),
            ])->save(),
            default => null,
        };

        return true;
    }

    /** Tell open browsers to reload the gate panel. */
    public function announce(Workspace $workspace): void
    {
        $this->publisher->publish($workspace->id, TaskEventIngestor::WORKSPACE_KEY, [['type' => 'gates.updated', 'data' => []]]);
    }

    /** Ask the agent to fix a failed gate, with the worker's fix prompt. */
    public function sendFixToAgent(WorkspaceGateRun $run, Task $task, User $by, string $gate, bool $automated = false): void
    {
        Gate::forUser($by)->authorize('prompt', $task);
        if ($task->id !== $run->task_id || ! in_array($gate, $run->failedGates(), true)) {
            throw WorkspaceException::translated('workspaces.gates.no_prompt');
        }

        try {
            $prompt = $this->daemon->for($run->workspace)->gateFixPrompt($task->slug, $gate, $run->logRunFor($gate));
        } catch (DaemonTransportException $e) {
            throw WorkspaceException::fromDaemon($e);
        }
        if (trim($prompt) === '') {
            throw WorkspaceException::translated('workspaces.gates.no_prompt');
        }

        $this->tasks->send($task, $by, $prompt, automated: $automated);
    }

    /**
     * A gate's full log of a run, read from the task's worktree through the
     * daemon. Falls back to the 8 KB tail stored with the run when the
     * daemon is unreachable or the worktree is gone (`complete` = false).
     *
     * @return array{log: string, truncated: bool, complete: bool}
     */
    public function log(WorkspaceGateRun $run, Task $task, User $by, string $gate): array
    {
        Gate::forUser($by)->authorize('view', $task);
        if ($task->id !== $run->task_id || ! array_key_exists($gate, $run->gates ?? [])) {
            throw WorkspaceException::translated('workspaces.gates.no_log');
        }

        $tail = (string) ($run->logs[$gate] ?? '');
        $workspace = $run->workspace;
        if ($task->cleaned_at !== null || $workspace->status !== WorkspaceStatus::Running) {
            return ['log' => $tail, 'truncated' => strlen($tail) >= self::TAIL_BYTES, 'complete' => false];
        }

        try {
            $full = $this->daemon->for($workspace)->gateLog($task->slug, $gate, $run->logRunFor($gate));
        } catch (DaemonTransportException) {
            return ['log' => $tail, 'truncated' => strlen($tail) >= self::TAIL_BYTES, 'complete' => false];
        }
        if ($full['log'] === '' && $tail !== '') {
            // The log file is gone (e.g. the worktree was rebuilt).
            return ['log' => $tail, 'truncated' => strlen($tail) >= self::TAIL_BYTES, 'complete' => false];
        }

        return ['log' => $full['log'], 'truncated' => $full['truncated'], 'complete' => true];
    }

    /** "6/7" — for the ship dialog; null when no completed run exists. */
    public function summary(Task $task): ?string
    {
        $run = $this->latest($task);
        if ($run === null || ! in_array($run->status, [GateRunStatus::Passed, GateRunStatus::Failed], true)) {
            return null;
        }

        return $run->passedCount().'/'.count($run->ranGates());
    }

    private function active(Workspace $workspace): ?WorkspaceGateRun
    {
        $run = WorkspaceGateRun::query()->where('workspace_id', $workspace->id)->where('status', GateRunStatus::Running->value)->latest('created_at')->first();
        if ($run !== null && $run->created_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            $run->forceFill(['status' => GateRunStatus::Error, 'message' => __('workspaces.gates.lost'), 'finished_at' => now()])->save();

            return null;
        }

        return $run;
    }

    /** @param  array<string, mixed>  $data */
    private function complete(WorkspaceGateRun $run, array $data): void
    {
        $gates = [];
        foreach (is_array($data['gates'] ?? null) ? $data['gates'] : [] as $gate => $result) {
            if (is_string($gate) && in_array($result, ['pass', 'fail', 'skip'], true)) {
                $gates[$gate] = $result;
            }
        }
        $logs = [];
        foreach (is_array($data['logs'] ?? null) ? $data['logs'] : [] as $gate => $log) {
            if (is_string($gate) && isset($gates[$gate]) && is_string($log)) {
                $logs[$gate] = mb_strcut($log, 0, self::TAIL_BYTES);
            }
        }

        $logRuns = [];
        if ($run->only !== null) {
            // One gate re-checked: the others keep their result and log from the run before.
            $previous = WorkspaceGateRun::query()
                ->where('task_id', $run->task_id)
                ->whereKeyNot($run->id)
                ->whereNotNull('finished_at')
                ->latest('finished_at')
                ->first();
            $checked = $run->only === 'pest' ? ['pest', 'phpunit'] : [$run->only];
            foreach ($previous?->gates ?? [] as $gate => $result) {
                if (! in_array($gate, $checked, true)) {
                    $gates[$gate] = $result;
                    $logs[$gate] = (string) ($previous->logs[$gate] ?? '');
                    $logRuns[$gate] = $previous->logRunFor($gate);
                }
            }
        }

        $run->forceFill([
            'status' => in_array('fail', $gates, true) ? GateRunStatus::Failed : GateRunStatus::Passed,
            'gates' => $gates,
            'logs' => $logs,
            'log_runs' => $logRuns === [] ? null : $logRuns,
            'baseline' => $this->baseline($data) ?? $run->baseline,
            'finished_at' => now(),
        ])->save();
    }

    /** @param  array<string, mixed>  $data */
    private function baseline(array $data): ?string
    {
        $state = $data['baseline'] ?? null;

        return is_string($state) && in_array($state, self::BASELINE_STATES, true) ? $state : null;
    }
}
