<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\CurrentTasksWidget;
use App\Filament\Admin\Widgets\DashboardHeroWidget;
use App\Filament\Admin\Widgets\StatsOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getWidgets(): array
    {
        return [
            DashboardHeroWidget::class,
            StatsOverviewWidget::class,
            CurrentTasksWidget::class,
        ];
    }
}
