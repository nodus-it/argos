<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Providers\Filament\Concerns\ConfiguresArgosPanel;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * The workspace app (/w): full width, navigation in the top bar, so chat,
 * editor and preview get the whole screen. Administration (projects,
 * agents, users, tasks) stays in the admin panel.
 */
class WorkspacesPanelProvider extends PanelProvider
{
    use ConfiguresArgosPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureArgosPanel($panel
            ->id('workspaces')
            ->path('w'))
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            ->homeUrl(fn (): string => WorkspaceResource::getUrl('index', panel: 'workspaces'))
            ->resources([WorkspaceResource::class])
            ->navigationItems([
                NavigationItem::make('admin')
                    ->label(fn (): string => __('workspaces.panel.admin'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => url('/admin'))
                    ->sort(90),
            ]);
    }
}
