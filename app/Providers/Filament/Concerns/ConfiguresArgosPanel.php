<?php

declare(strict_types=1);

namespace App\Providers\Filament\Concerns;

use App\Filament\Admin\Pages\Login;
use App\Filament\Admin\Pages\Profile;
use App\Http\Middleware\RedirectToOnboarding;
use App\Http\Middleware\SetUserLocale;
use DutchCodingCompany\FilamentDeveloperLogins\FilamentDeveloperLoginsPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * What every Argos panel shares: brand, colours, auth, the bell, the
 * realtime runtime config and the topbar extras. Both panels use the same
 * session guard, so one login covers both.
 */
trait ConfiguresArgosPanel
{
    protected function configureArgosPanel(Panel $panel): Panel
    {
        return $panel
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
            // The bell: workspace approvals, limits, errors and shipments.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->login(Login::class)
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
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => $this->headAssets())
            ->renderHook(
                // Slot the page breadcrumbs teleport into (see the overridden
                // filament-panels::components.header view) — moves them from the
                // content header into the topbar, matching the design.
                PanelsRenderHook::TOPBAR_START,
                fn (): string => '<div id="argos-topbar-breadcrumbs" class="fi-argos-topbar-breadcrumbs"></div>',
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => view('components.argos-feedback-button')->render()
            );
    }

    /** App CSS/JS plus the runtime realtime config read by resources/js/app.js. */
    private function headAssets(): HtmlString
    {
        $realtime = config('broadcasting.default') === 'reverb';

        try {
            $assets = app(Vite::class)(['resources/css/app.css', 'resources/js/app.js'])->toHtml();
        } catch (ViteManifestNotFoundException) {
            $assets = '';
        }

        if (! $realtime) {
            return new HtmlString($assets);
        }

        // Images are prebuilt, so nothing here can be a Vite build-time env.
        $config = json_encode([
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'authEndpoint' => url('broadcasting/auth'),
            'csrfToken' => csrf_token(),
            'userId' => auth()->id(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return new HtmlString('<script>window.ArgosRealtime = '.$config.';</script>'.$assets);
    }
}
