<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutoTaskState;
use App\Enums\SessionStatus;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceStatus;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Console\Command;

/**
 * Stops running workspaces nobody used for ARGOS_WORKSPACE_IDLE_MINUTES —
 * the container only; volumes, sidecar data and tasks stay. A workspace
 * with a task that is working or waiting for a decision is never stopped.
 */
class StopIdleWorkspacesCommand extends Command
{
    protected $signature = 'argos:workspaces:idle-stop';

    protected $description = 'Stop workspace containers that have been idle (volumes are kept).';

    public function handle(WorkspaceService $workspaces): int
    {
        $minutes = (int) config('argos.workspaces.idle_minutes', 240);
        if ($minutes <= 0) {
            $this->info('Idle stop disabled.');

            return self::SUCCESS;
        }

        $idle = Workspace::query()
            ->where('status', WorkspaceStatus::Running->value)
            ->where(fn ($q) => $q->where('last_activity_at', '<', now()->subMinutes($minutes))
                ->orWhere(fn ($q) => $q->whereNull('last_activity_at')->where('updated_at', '<', now()->subMinutes($minutes))))
            // Working, preparing or waiting tasks keep their container.
            ->whereDoesntHave('tasks', fn ($q) => $q->whereIn('session_status', [SessionStatus::Running->value, SessionStatus::WaitingInput->value]))
            ->whereDoesntHave('tasks', fn ($q) => $q->where('status', TaskStatus::Preparing->value)->orWhereNotNull('queued_at'))
            ->whereDoesntHave('tasks', fn ($q) => $q->whereNotNull('auto_state')->whereNotIn('auto_state', [AutoTaskState::Paused->value, AutoTaskState::Done->value, AutoTaskState::Failed->value, AutoTaskState::BudgetExceeded->value]))
            ->get();

        foreach ($idle as $workspace) {
            $workspaces->stop($workspace);
            $this->info("Stopping idle workspace {$workspace->slug}.");
        }

        return self::SUCCESS;
    }
}
