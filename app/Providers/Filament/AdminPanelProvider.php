<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Profile;
use App\Filament\Admin\Widgets\CurrentTasksWidget;
use App\Filament\Admin\Widgets\StatsOverviewWidget;
use App\Http\Middleware\RedirectToOnboarding;
use App\Http\Middleware\SetUserLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->brandLogo(fn () => view('components.argos-logo'))
            ->brandLogoHeight('1.75rem')
            ->favicon(asset('favicon.svg'))
            ->colors([
                'primary' => Color::Slate,
            ])
            ->maxContentWidth(Width::Full)
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                StatsOverviewWidget::class,
                CurrentTasksWidget::class,
            ])
            ->navigationGroups([
                __('navigation.groups.tasks'),
                __('navigation.groups.worker'),
                __('navigation.groups.configuration'),
            ])
            ->login()
            ->profile(Profile::class)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class, SetUserLocale::class, RedirectToOnboarding::class])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                function (): HtmlString {
                    try {
                        return new HtmlString(app(Vite::class)(['resources/css/app.css']));
                    } catch (ViteManifestNotFoundException) {
                        return new HtmlString('');
                    }
                }
            )
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => Blade::render('@livewire(\'usage-limit-banner\')')
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn (): string => Blade::render('@livewire(\'anthropic-usage-sidebar\')')
            )
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn (): string => view('components.argos-source-footer')->render()
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => view('components.argos-feedback-button')->render()
            );
    }
}
