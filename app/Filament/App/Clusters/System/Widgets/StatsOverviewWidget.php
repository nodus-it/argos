<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\System\Widgets;

use Illuminate\Support\Facades\DB;
use App\Enums\AutoTaskState;
use App\Enums\WorkspaceStatus;
use App\Models\Task;
use App\Models\WorkerImageBuild;
use App\Models\Workspace;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    // Warm-Paper redesign: render the stats as .stat control-room cards instead
    // of Filament's default stat view. See docs/design/argos/ARGOS_REDESIGN.md §5.11/§6.2.
    protected string $view = 'filament.widgets.argos-stats-overview';

    protected ?string $pollingInterval = '5s';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $runningWorkspaces = Workspace::query()->where('status', WorkspaceStatus::Running)->count();

        $running = array_values(array_filter(AutoTaskState::cases(), fn (AutoTaskState $state): bool => $state->isRunning()));
        $inProgress = Task::query()->whereIn('auto_state', $running)->count();

        $waitingForInput = Task::query()
            ->whereIn('auto_state', [AutoTaskState::AwaitingPlanApproval, AutoTaskState::Paused])
            ->count();

        // Same definition as WorkerImageBuild::scopeOutdated(), so the counter
        // and the list/bulk action agree. Unique (stack × agent) tuples: a
        // stack with five historical hashes for one agent needs one rebuild.
        $pendingUpdates = DB::query()
            ->fromSub(WorkerImageBuild::query()->outdated()->select(['worker_stack_id', 'agent_name'])->distinct(), 'pending')
            ->count();

        return [
            Stat::make(__('widgets.stats.running_workspaces'), $runningWorkspaces)
                ->description($runningWorkspaces > 0
                    ? __('widgets.stats.workspaces_active')
                    : __('widgets.stats.workspaces_idle'))
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color($runningWorkspaces > 0 ? 'warning' : 'gray'),

            Stat::make(__('widgets.stats.in_progress'), $inProgress)
                ->description($inProgress === 1
                    ? __('widgets.stats.runs_running_one')
                    : __('widgets.stats.runs_running_many', ['count' => $inProgress]))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($inProgress > 0 ? 'info' : 'gray'),

            Stat::make(__('widgets.stats.waiting'), $waitingForInput)
                ->description($waitingForInput > 0
                    ? __('widgets.stats.review_open')
                    : __('widgets.stats.nothing_todo'))
                ->descriptionIcon('heroicon-m-hand-raised')
                ->color($waitingForInput > 0 ? 'primary' : 'success'),

            Stat::make(__('widgets.stats.worker_updates'), $pendingUpdates)
                ->description($pendingUpdates > 0
                    ? __('widgets.stats.worker_updates_pending')
                    : __('widgets.stats.worker_updates_clean'))
                ->descriptionIcon($pendingUpdates > 0 ? 'heroicon-m-arrow-up-circle' : 'heroicon-m-check-circle')
                // Outdated images rebuild on the next workspace start.
                ->color($pendingUpdates > 0 ? 'warning' : 'success'),
        ];
    }
}
