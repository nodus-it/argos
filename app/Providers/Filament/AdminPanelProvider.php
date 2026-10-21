<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Dashboard;
use App\Http\Middleware\SetUserLocale;
use App\Providers\Filament\Concerns\ConfiguresArgosPanel;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;

class AdminPanelProvider extends PanelProvider
{
    use ConfiguresArgosPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureArgosPanel($panel
            ->default()
            ->id('admin')
            ->path('admin'))
            ->maxContentWidth(Width::SevenExtraLarge)
            ->sidebarWidth('14rem')
            ->collapsibleNavigationGroups(false)
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([])
            // Labels are lazy closures: the panel is configured at provider-boot
            // (before SetUserLocale runs), so eager __() would freeze the
            // group labels to the default locale and stop matching the
            // render-time getNavigationGroup() strings in other locales — the
            // groups would then split/reorder (e.g. a stray English "Help").
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('navigation.groups.worker')),
                NavigationGroup::make()->label(fn (): string => __('navigation.groups.configuration')),
                // Kept last so the Help section sits at the bottom of the sidebar.
                NavigationGroup::make()->label(fn (): string => __('navigation.groups.help')),
            ])
            ->navigationItems([
                // Workspaces live in their own full-width panel (/w).
                NavigationItem::make('workspaces')
                    ->label(fn (): string => __('workspaces.plural'))
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->url(fn (): string => url('/w'))
                    ->visible(fn (): bool => (bool) config('argos.workspaces.enabled', true))
                    ->sort(-2),
                // Link to the auto-generated REST API docs (Scramble, served at
                // /docs/api). Lives outside the Filament panel, so it opens in a
                // new tab rather than as a panel page.
                NavigationItem::make('api-docs')
                    ->label(fn (): string => __('navigation.pages.api_docs'))
                    ->icon(Heroicon::OutlinedCodeBracket)
                    ->group(fn (): string => __('navigation.groups.help'))
                    ->url(fn (): string => url('docs/api'), shouldOpenInNewTab: true)
                    ->sort(99),
            ])
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn (): string => view('components.argos-source-footer')->render()
            );
    }
}
