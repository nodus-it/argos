<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\PhaseRun;
use App\Models\Task;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected ?string $pollingInterval = '5s';

    protected function getStats(): array
    {
        $activePhases = PhaseRun::where('status', 'running')->count();

        return [
            Stat::make('Tasks gesamt', Task::count()),

            Stat::make('Aktive Phasen', $activePhases)
                ->color($activePhases > 0 ? 'warning' : 'gray'),

            Stat::make(
                'Abgeschlossen heute',
                PhaseRun::where('status', 'completed')
                    ->whereDate('finished_at', today())
                    ->count()
            )
                ->color('success'),
        ];
    }
}
