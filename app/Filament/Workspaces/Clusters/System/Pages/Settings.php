<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Clusters\System\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Workspaces\Clusters\System;
use App\Filament\Workspaces\Clusters\System\Widgets\StatsOverviewWidget;
use Filament\Pages\Page;

/** Administration → Overview (`/system/overview`): state of the installation and the log download. */
class Settings extends Page
{
    use AdminOnly;

    protected string $view = 'filament.workspaces.system.settings';

    protected static ?string $cluster = System::class;

    protected static ?string $slug = 'overview';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-squares-2x2';
    }

    public static function getNavigationLabel(): string
    {
        return __('system.overview');
    }

    public static function getNavigationSort(): ?int
    {
        return 0;
    }

    public function getTitle(): string
    {
        return __('system.overview');
    }

    /** Running workspaces, automatic tasks, waiting tasks, outdated worker images. */
    protected function getHeaderWidgets(): array
    {
        return [StatsOverviewWidget::class];
    }
}
