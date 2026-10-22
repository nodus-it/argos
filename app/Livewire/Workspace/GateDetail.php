<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\GateRunStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceGateRun;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Support\GateAnnotations;
use Carbon\CarbonInterval;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Detail of one gate row of the latest run (slide-over): result, duration,
 * baseline hint, the full log (searched client-side), affected files that
 * open the review, and "Claude fixen lassen" for failed gates. Opened by
 * the window event `gate-detail-open` ({taskId, gate}) from the context
 * panel's gate rows and the review panel's gate chips.
 */
class GateDetail extends Component
{
    /** Gates whose failures are compared against the base commit's baseline. */
    private const BASELINE_GATES = ['pest', 'phpunit', 'phpstan'];

    #[Locked]
    public string $workspaceId;

    public ?string $taskId = null;

    /** Row key (ContextPanel::GATE_ROWS key or a gate name); null = closed. */
    public ?string $gate = null;

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    public function show(string $taskId, string $gate): void
    {
        $task = Task::query()->where('workspace_id', $this->workspaceId)->find($taskId);
        abort_unless($task !== null && $this->user()->can('view', $task), 403);
        $this->taskId = $task->id;
        $this->gate = $gate;
    }

    public function hide(): void
    {
        $this->gate = null;
    }

    /** Hand a failed gate of the latest run to Claude with the gate's fix prompt. */
    public function sendGateFix(string $gate): void
    {
        $task = $this->task();
        $run = $task !== null ? app(WorkspaceGateService::class)->latest($task) : null;
        if ($run === null || $task === null) {
            Notification::make()->title(__('workspaces.ship.no_task'))->warning()->send();

            return;
        }

        try {
            app(WorkspaceGateService::class)->sendFixToAgent($run, $task, $this->user(), $gate);
            Notification::make()->title(__('workspaces.gates.fix_sent'))->success()->send();
            $this->gate = null;
            $this->dispatch('gate-detail-closed');
        } catch (WorkspaceException|DaemonTransportException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();
        }
    }

    /** "Check again": re-run just this gate (the others keep their result). */
    public function rerunGate(string $gate): void
    {
        $task = $this->task();
        if ($task === null) {
            return;
        }

        try {
            app(WorkspaceGateService::class)->run($task, $this->user(), $gate);
            Notification::make()->title(__('workspaces.gates.rerun_one_started', ['gate' => $gate]))->success()->send();
            $this->dispatch('argos-gates-updated');
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();
        }
    }

    public function render(): View
    {
        return view('livewire.workspace.gate-detail', ['detail' => $this->detail()]);
    }

    /**
     * Everything the slide-over shows; null while closed.
     *
     * @return array<string, mixed>|null
     */
    private function detail(): ?array
    {
        $task = $this->gate !== null ? $this->task() : null;
        if ($task === null || ! $this->user()->can('view', $task)) {
            return null;
        }

        $service = app(WorkspaceGateService::class);
        $run = $service->latest($task);
        $row = collect(ContextPanel::gateRows($run))->firstWhere('key', $this->gate)
            ?? ['key' => $this->gate, 'label' => ucfirst(str_replace('_', ' ', (string) $this->gate)), 'keys' => [(string) $this->gate], 'state' => 'open', 'failed' => [], 'log' => ''];
        $ran = $run !== null && $row['state'] !== 'running'
            ? array_values(array_filter($row['keys'], fn (string $k): bool => in_array($run->gates[$k] ?? null, ['pass', 'fail'], true)))
            : [];

        $logs = [];
        foreach ($ran as $key) {
            try {
                $logs[$key] = $service->log($run, $task, $this->user(), $key);
            } catch (WorkspaceException) {
                // The gate has no result in this run.
            }
        }
        $log = count($logs) > 1
            ? implode("\n", array_map(fn (string $k, array $l): string => "── {$k} ──\n".$l['log'], array_keys($logs), $logs))
            : (string) (array_values($logs)[0]['log'] ?? '');

        return [
            'task' => $task,
            'run' => $run,
            'row' => $row,
            'results' => array_intersect_key($run->gates ?? [], array_flip($row['keys'])),
            'duration' => $this->duration($run),
            'ranAt' => $run?->finished_at ?? $run?->started_at,
            'baseline' => $ran !== [] && array_intersect($ran, self::BASELINE_GATES) !== [] ? $this->baselineHint($run) : null,
            'log' => $log,
            'truncated' => collect($logs)->contains(fn (array $l): bool => $l['truncated']),
            'complete' => collect($logs)->every(fn (array $l): bool => $l['complete']),
            'files' => $row['state'] === 'fail' ? $this->affectedFiles($task, $logs) : [],
            'canFix' => $row['failed'] !== [] && $this->user()->can('prompt', $task),
            'canRerun' => in_array($row['key'], WorkspaceGateService::SINGLE_GATES, true) && $this->user()->can('prompt', $task)
                && $task->workspace->status === WorkspaceStatus::Running && $run?->status !== GateRunStatus::Running,
        ];
    }

    private function duration(?WorkspaceGateRun $run): ?string
    {
        if ($run?->started_at === null || $run->finished_at === null || $run->status === GateRunStatus::Running) {
            return null;
        }
        $seconds = max(0, (int) $run->started_at->diffInSeconds($run->finished_at));

        return CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true]);
    }

    private function baselineHint(?WorkspaceGateRun $run): string
    {
        return match ($run?->baseline) {
            'ready' => __('workspaces.gate_detail.baseline_ready'),
            'running' => __('workspaces.gate_detail.baseline_running'),
            default => __('workspaces.gate_detail.baseline_missing'),
        };
    }

    /**
     * Files the gate points at, with their finding counts (PHPStan errors,
     * Pint-flagged files). Paths are relative to the task's checkout.
     *
     * @param  array<string, array{log: string, truncated: bool, complete: bool}>  $logs
     * @return list<array{path: string, count: int}>
     */
    private function affectedFiles(Task $task, array $logs): array
    {
        $counts = [];
        if (isset($logs['phpstan'])) {
            $counts = array_map('count', GateAnnotations::phpstanFindings($logs['phpstan']['log']));
        }
        if (isset($logs['pint'])) {
            foreach (GateAnnotations::pintFiles($logs['pint']['log']) as $path) {
                $counts[$path] = ($counts[$path] ?? 0) + 1;
            }
        }

        $prefix = $task->worktree_path !== null ? rtrim($task->worktree_path, '/').'/' : null;
        $files = [];
        foreach ($counts as $path => $count) {
            $path = (string) $path;
            if ($prefix !== null && str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix));
            }
            $files[$path] = ($files[$path] ?? 0) + $count;
        }
        arsort($files);

        return array_map(fn (string $path, int $count): array => ['path' => $path, 'count' => $count], array_keys($files), $files);
    }

    private function task(): ?Task
    {
        return $this->taskId !== null ? Task::query()->with('workspace')->where('workspace_id', $this->workspaceId)->find($this->taskId) : null;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
