<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\WorkflowStatus;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverviewWidget extends BaseWidget
{
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

        return [
            Stat::make('Laufende Worker', $runningWorkers)
                ->description($runningWorkers > 0 ? 'Container arbeiten gerade' : 'Keine aktiven Worker')
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color($runningWorkers > 0 ? 'warning' : 'gray'),

            Stat::make('In Bearbeitung', $inProgress)
                ->description($inProgress === 1 ? '1 Task läuft' : "{$inProgress} Tasks laufen")
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color($inProgress > 0 ? 'info' : 'gray'),

            Stat::make('Wartet auf dich', $waitingForInput)
                ->description($waitingForInput > 0 ? 'Review oder Antwort offen' : 'Nichts zu tun')
                ->descriptionIcon('heroicon-m-hand-raised')
                ->color($waitingForInput > 0 ? 'primary' : 'success'),
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
