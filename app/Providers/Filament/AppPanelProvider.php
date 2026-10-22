<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\App\Clusters\Account;
use App\Filament\App\Clusters\Account\Pages\ConnectedAccounts;
use App\Filament\App\Clusters\Account\Pages\Profile;
use App\Filament\App\Clusters\Account\Resources\AgentCredentialResource;
use App\Filament\App\Clusters\System;
use App\Filament\App\Clusters\System\Pages\Settings;
use App\Filament\App\Clusters\System\Resources\ApiClientResource;
use App\Filament\App\Clusters\System\Resources\ProviderCredentialResource;
use App\Filament\App\Clusters\System\Resources\ProviderOAuthConfigResource;
use App\Filament\App\Clusters\System\Resources\UserResource;
use App\Filament\App\Pages\Documentation;
use App\Filament\App\Pages\MyWork;
use App\Filament\App\Pages\Onboarding;
use App\Filament\App\Resources\WorkspaceResource;
use App\Livewire\Workspace\RailNotifications;
use App\Providers\Filament\Concerns\ConfiguresArgosPanel;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;

/**
 * Argos is this one panel. The workspace app at the root (`/`, workspaces at `/{slug}`): full width, no top bar — a 64px icon rail ("My
 * work", the workspaces, administration, the bell, the user menu) instead,
 * so chat, review and the context sidebar get the whole screen. `/` is
 * "My work"; `/account` is the person's own (profile, Claude subscriptions,
 * connected accounts), `/system` the administration, `/docs` the
 * documentation and `/setup` the first-run wizard.
 */
class AppPanelProvider extends PanelProvider
{
    use ConfiguresArgosPanel;

    public function panel(Panel $panel): Panel
    {
        return $this->configureArgosPanel($panel
            ->default()
            ->id('app')
            ->path(''))
            ->profile(Profile::class)
            ->topbar(false)
            ->navigation(false)
            ->databaseNotifications(livewireComponent: RailNotifications::class)
            ->maxContentWidth(Width::Full)
            ->homeUrl(fn (): string => MyWork::getUrl(panel: 'app'))
            ->pages([
                MyWork::class, Documentation::class, Onboarding::class,
                Account::class, ConnectedAccounts::class,
                System::class, Settings::class,
            ])
            ->resources([
                WorkspaceResource::class,
                AgentCredentialResource::class,
                UserResource::class, ProviderCredentialResource::class, ProviderOAuthConfigResource::class, ApiClientResource::class,
            ])
            // Filament seeds the collapsed-groups store in its sidebar, which
            // this panel does not render; the sub-navigation of /account and
            // /system needs it all the same.
            ->renderHook(PanelsRenderHook::HEAD_START, fn (): string => '<script>try{for(const k of ["collapsedGroups","filament:sidebar:collapsed_groups"]){const g=localStorage.getItem(k);if(g===null||g==="null"){localStorage.setItem(k,"[]")}}}catch(e){}</script>')
            ->renderHook(PanelsRenderHook::LAYOUT_START, fn (): string => view('filament.app.rail')->render())
            // Version, license and source (AGPL-3.0) under every page but the
            // full-screen workspace, which has them in the rail's user menu.
            ->renderHook(PanelsRenderHook::FOOTER, fn (): string => request()->routeIs('filament.app.resources.workspaces.view')
                ? ''
                : view('components.argos-source-footer')->render());
    }
}
