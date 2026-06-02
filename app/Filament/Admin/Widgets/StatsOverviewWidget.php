<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\WorkflowStatus;
use App\Models\Task;
use App\Models\WorkerImageBuild;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverviewWidget extends BaseWidget
{
    // Warm-Paper redesign: render the stats as .stat control-room cards instead
    // of Filament's default stat view. The getStats() data/logic below is
    // unchanged. See docs/design/argos/ARGOS_REDESIGN.md §5.11/§6.2.
    protected string $view = 'filament.widgets.argos-stats-overview';

    protected ?string $pollingInterval = '5s';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $runningWorkers = $this->countRunningWorkers();

        $inProgress = Task::query()
            ->whereIn('workflow_status', [
                WorkflowStatus::ConceptRunning,
                WorkflowStatus::ImplementRunning,
            ])
            ->count();

        $waitingForInput = Task::query()
            ->whereIn('workflow_status', [
                WorkflowStatus::ConceptReview,
                WorkflowStatus::ImplementPaused,
                WorkflowStatus::InReview,
                WorkflowStatus::Failed,
            ])
            ->count();

        // Identische Definition wie WorkerImageBuild::scopeOutdated() —
        // Dashboard-Counter und Liste/Bulk-Action benutzen damit dieselbe
        // Wahrheit (Stack-Hash-Drift oder agent-update + build pre-dates
        // last npm check). Eindeutige (stack × agent) Tupel, weil ein Stack
        // mit fünf historischen Hashes für denselben Agent fünf Rows hat,
        // aber nur einen Rebuild-Job braucht.
        $pendingUpdates = WorkerImageBuild::query()
            ->outdated()
            ->get(['worker_stack_id', 'agent_name'])
            ->unique(fn ($b) => $b->worker_stack_id.'|'.$b->agent_name->value)
            ->count();

        return [
            Stat::make(__('widgets.stats.running_workers'), $runningWorkers)
                ->description($runningWorkers > 0
                    ? __('widgets.stats.workers_active')
                    : __('widgets.stats.workers_idle'))
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color($runningWorkers > 0 ? 'warning' : 'gray'),

            Stat::make(__('widgets.stats.in_progress'), $inProgress)
                ->description($inProgress === 1
                    ? __('widgets.stats.tasks_running_one')
                    : __('widgets.stats.tasks_running_many', ['count' => $inProgress]))
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
                ->color($pendingUpdates > 0 ? 'warning' : 'success')
                // Klick führt auf die Image-Builds-Liste — dort gibt es den
                // Per-Row-„Rebuild"-Knopf, der einen BuildWorkerImageJob für
                // genau diese (stack × agent)-Kombi neu dispatched.
                ->url($pendingUpdates > 0 ? route('filament.admin.resources.worker-image-builds.index') : null),
        ];
    }

    /**
     * Count workers that are actually busy right now. Uses the queue's `jobs`
     * table because phase_runs.status='running' goes stale on worker crashes
     * and would over-report. A reserved RunPhaseJob means supervisor's queue
     * worker is currently inside it.
     */
    private function countRunningWorkers(): int
    {
        if (config('queue.default') !== 'database') {
            return 0;
        }

        return DB::table(config('queue.connections.database.table', 'jobs'))
            ->whereNotNull('reserved_at')
            ->where('payload', 'like', '%RunPhaseJob%')
            ->count();
    }
}
