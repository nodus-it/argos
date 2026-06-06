<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\WorkflowStatus;
use App\Models\PhaseRun;
use App\Models\Task;
use Filament\Widgets\Widget;

class DashboardHeroWidget extends Widget
{
    protected string $view = 'filament.widgets.argos-dashboard-hero';

    protected ?string $pollingInterval = '3s';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    protected function getViewData(): array
    {
        $running = Task::query()
            ->whereIn('workflow_status', [
                WorkflowStatus::ConceptRunning,
                WorkflowStatus::ImplementRunning,
            ])
            ->count();

        $waiting = Task::query()
            ->whereIn('workflow_status', [
                WorkflowStatus::ConceptReview,
                WorkflowStatus::ImplementPaused,
                WorkflowStatus::InReview,
                WorkflowStatus::Failed,
            ])
            ->count();

        $failed = Task::query()
            ->where('workflow_status', WorkflowStatus::Failed)
            ->count();

        $tickerLines = PhaseRun::query()
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get(['phase', 'status', 'updated_at', 'task_id'])
            ->map(function (PhaseRun $run): array {
                $statusLabel = $run->status->label();
                $phaseLabel = $run->phase->label();
                $timeStr = $run->updated_at?->format('H:i:s') ?? '';

                $class = match ($run->status->value) {
                    'completed' => 't-ok',
                    'failed' => 't-err',
                    'running' => 't-accent',
                    'queued' => 't-warn',
                    default => 't-info',
                };

                return [
                    'time' => $timeStr,
                    'text' => "{$phaseLabel} · {$statusLabel}",
                    'class' => $class,
                ];
            })
            ->all();

        return [
            'running' => $running,
            'waiting' => $waiting,
            'failed' => $failed,
            'tickerLines' => $tickerLines,
        ];
    }
}
