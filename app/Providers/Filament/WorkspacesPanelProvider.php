<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Workspaces\Resources\WorkspaceResource;
use App\Livewire\Workspace\RailNotifications;
use App\Providers\Filament\Concerns\ConfiguresArgosPanel;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;

/**
 * The workspace app (/w): full width, no top bar — a 64px icon rail (home,
 * administration, the bell, the user menu) instead, so chat, review and the
 * context sidebar get the whole screen. Administration (projects, agents,
 * users) stays in the admin panel.
 */
class WorkspacesPanelProvider extends PanelProvider
{
    use ConfiguresArgosPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureArgosPanel($panel
            ->id('workspaces')
            ->path('w'))
            ->topbar(false)
            ->navigation(false)
            ->databaseNotifications(livewireComponent: RailNotifications::class)
            ->maxContentWidth(Width::Full)
            ->homeUrl(fn (): string => WorkspaceResource::getUrl('index', panel: 'workspaces'))
            ->resources([WorkspaceResource::class])
            ->renderHook(PanelsRenderHook::LAYOUT_START, fn (): string => view('filament.workspaces.rail')->render());
    }
}
