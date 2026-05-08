<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Models\AgentVersion;
use App\Models\WorkerStack;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Surfaces upstream-update count for stacks + agents on the dashboard.
 * Only renders the stat when at least one update is pending — the
 * dashboard stays quiet when everything is current.
 */
class WorkerUpdatesWidget extends BaseWidget
{
    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return self::pendingCount() > 0;
    }

    protected function getStats(): array
    {
        $count = self::pendingCount();

        return [
            Stat::make(__('worker.updates.widget_label'), (string) $count)
                ->description($count === 0
                    ? __('worker.updates.no_updates')
                    : __('worker.image_builds.actions.rebuild'))
                ->color($count > 0 ? 'warning' : 'success')
                ->icon($count > 0 ? 'heroicon-o-arrow-up-circle' : 'heroicon-o-check-circle'),
        ];
    }

    private static function pendingCount(): int
    {
        return WorkerStack::query()->where('has_update', true)->count()
             + AgentVersion::query()->where('has_update', true)->count();
    }
}
