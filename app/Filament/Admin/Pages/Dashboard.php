<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Widgets\MyWorkspacesWidget;
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
            MyWorkspacesWidget::class,
            StatsOverviewWidget::class,
        ];
    }
}
