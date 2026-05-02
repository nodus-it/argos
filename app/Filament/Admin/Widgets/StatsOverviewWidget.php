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

        $totalCost = (float) PhaseRun::sum('cost_usd');
        $costToday = (float) PhaseRun::whereDate('finished_at', today())->sum('cost_usd');
        $tokensTotal = (int) PhaseRun::query()
            ->selectRaw('COALESCE(SUM(input_tokens), 0) + COALESCE(SUM(output_tokens), 0) as total')
            ->value('total');

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

            Stat::make('Kosten gesamt', '$'.number_format($totalCost, 4))
                ->description('heute: $'.number_format($costToday, 4))
                ->color($totalCost > 0 ? 'primary' : 'gray'),

            Stat::make('Tokens gesamt', number_format($tokensTotal))
                ->color($tokensTotal > 0 ? 'primary' : 'gray'),
        ];
    }
}
