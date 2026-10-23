<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\System\Pages;

use App\Filament\App\Clusters\System;
use App\Filament\App\Clusters\System\Widgets\StatsOverviewWidget;
use App\Filament\Concerns\AdminOnly;
use Filament\Pages\Page;

/** Administration → Overview (`/system/overview`): state of the installation and the log download. */
class Settings extends Page
{
    use AdminOnly;

    protected string $view = 'filament.app.system.settings';

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
