<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\GateRunStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceGateRun;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\WorkspaceGateService;
use App\Support\GateAnnotations;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The change tray above the composer of a task: "N files changed +X −Y · gate state"
 * with "Check gates" and "Review". Only shown while there are uncommitted
 * changes; re-renders after a turn or a gate run.
 */
#[Lazy]
class ChangeTray extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $taskId;

    public function mount(string $workspaceId, string $taskId): void
    {
        $this->workspaceId = $workspaceId;
        $this->taskId = $taskId;
        abort_unless($this->user()->can('view', $this->task()), 403);
    }

    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function render(): View
    {
        $task = $this->task();
        $diff = null;
        if ($task->workspace->status === WorkspaceStatus::Running) {
            try {
                $diff = app(WorkspaceDiffService::class)->summary($task);
            } catch (DaemonTransportException) {
                $diff = null;
            }
        }
        $files = $diff['files'] ?? [];

        return view('livewire.workspace.change-tray', [
            'show' => $diff !== null && $diff['uncommitted'] > 0 && $files !== [],
            'count' => count($files),
            'additions' => array_sum(array_column($files, 'additions')),
            'deletions' => array_sum(array_column($files, 'deletions')),
            'gates' => self::gateSummary(app(WorkspaceGateService::class)->latest($task)),
            'canOperate' => $this->user()->can('prompt', $task),
        ]);
    }

    /** One phrase for the gate state: not run, running, all green, or which failed. */
    public static function gateSummary(?WorkspaceGateRun $run): string
    {
        if ($run === null || $run->status === GateRunStatus::Error) {
            return __('workspaces.tray.gates_none');
        }
        if ($run->status === GateRunStatus::Running) {
            return __('workspaces.tray.gates_running');
        }
        $failed = array_values(array_filter(ContextPanel::gateRows($run), fn (array $row): bool => $row['state'] === 'fail'));
        if ($failed === []) {
            return __('workspaces.tray.gates_green');
        }

        $phpstan = array_sum(array_map('count', GateAnnotations::fromRun($run)['phpstan']));
        $labels = array_map(fn (array $row): string => $row['key'] === 'phpstan' && $phpstan > 0
            ? trans_choice('workspaces.tray.gate_errors', $phpstan, ['gate' => $row['label'], 'count' => $phpstan])
            : $row['label'], $failed);

        return __('workspaces.tray.gates_failed', ['gates' => implode(', ', $labels)]);
    }

    private function task(): Task
    {
        return Task::query()->with('workspace')->where('workspace_id', $this->workspaceId)->findOrFail($this->taskId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
