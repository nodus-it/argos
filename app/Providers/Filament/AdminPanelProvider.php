<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\Profile;
use App\Filament\Admin\Widgets\CurrentTasksWidget;
use App\Filament\Admin\Widgets\StatsOverviewWidget;
use App\Http\Middleware\RedirectToOnboarding;
use App\Http\Middleware\SetUserLocale;
use DutchCodingCompany\FilamentDeveloperLogins\FilamentDeveloperLoginsPlugin;
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
                // Terracotta primary + warm-sand gray ("Warm Paper" redesign;
                // see docs/design/argos/ARGOS_REDESIGN.md §2.3).
                'primary' => [
                    50 => '251,243,239', 100 => '246,228,218', 200 => '238,199,180',
                    300 => '227,164,134', 400 => '217,128,95', 500 => '207,100,70',
                    600 => '187,80,52', 700 => '154,64,43', 800 => '124,55,39',
                    900 => '104,47,35', 950 => '58,22,16',
                ],
                'gray' => Color::hex('#7d7565'),
            ])
            ->maxContentWidth(Width::SevenExtraLarge)
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
            ->plugins([
                // One-click developer login on the login screen. Hard-gated to
                // the local environment — never renders in staging/production.
                FilamentDeveloperLoginsPlugin::make()
                    ->enabled(app()->environment('local'))
                    ->switchable(false)
                    ->users([
                        'Admin' => (string) config('argos.dev_login_email'),
                    ]),
            ])
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
