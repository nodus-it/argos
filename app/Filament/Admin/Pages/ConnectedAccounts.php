<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\ConnectedAccount;
use App\Models\ProviderOAuthConfig;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class ConnectedAccounts extends Page
{
    protected string $view = 'filament.admin.pages.connected-accounts';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-link';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('accounts.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('accounts.navigation_label');
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public function getTitle(): string
    {
        return __('accounts.title');
    }

    public function isGitHubConfigured(): bool
    {
        return filled(config('services.github.client_id'));
    }

    public function isGitLabConfigured(): bool
    {
        return filled(config('services.gitlab.client_id'));
    }

    public function isBitbucketConfigured(): bool
    {
        return filled(config('services.bitbucket.client_id'));
    }

    public function isLinearConfigured(): bool
    {
        return filled(config('services.linear.client_id'));
    }

    /**
     * @return array<string, ConnectedAccount|null>
     */
    public function getConnectedAccounts(): array
    {
        /** @var User $user */
        $user = Auth::user();

        return [
            'github' => $user->connectedAccount('github'),
            'gitlab' => $user->connectedAccount('gitlab'),
            'bitbucket' => $user->connectedAccount('bitbucket'),
            'linear' => $user->connectedAccount('linear'),
        ];
    }

    protected function getHeaderActions(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $hasGitHub = $user->connectedAccount('github') !== null;
        // Public gitlab.com only — self-hosted instances are handled per-config below.
        $hasGitLab = $user->connectedAccounts()
            ->where('provider', 'gitlab')
            ->where('instance_url', '')
            ->exists();
        $hasBitbucket = $user->connectedAccount('bitbucket') !== null;
        $hasLinear = $user->connectedAccount('linear') !== null;

        $actions = [];

        if ($this->isGitHubConfigured() && ! $hasGitHub) {
            $actions[] = Action::make('connectGitHub')
                ->label(__('accounts.actions.connect_github'))
                ->icon('heroicon-o-arrow-right-circle')
                ->url(route('auth.github.redirect'));
        }

        if ($this->isGitLabConfigured() && ! $hasGitLab) {
            $actions[] = Action::make('connectGitLab')
                ->label(__('accounts.actions.connect_gitlab'))
                ->icon('heroicon-o-arrow-right-circle')
                ->url(route('auth.gitlab.redirect'));
        }

        // Self-hosted GitLab instances each have their own OAuth app; offer one
        // connect button per configured instance that isn't connected yet.
        foreach ($this->selfHostedGitlabConfigs() as $config) {
            $connected = $user->connectedAccounts()
                ->where('provider', 'gitlab')
                ->where('instance_url', $config->instance_url)
                ->exists();

            if ($connected) {
                continue;
            }

            $actions[] = Action::make('connectGitLab_'.$config->id)
                ->label(__('accounts.actions.connect_gitlab_instance', ['instance' => $config->instance_url]))
                ->icon('heroicon-o-arrow-right-circle')
                ->url(route('auth.gitlab.redirect', ['instance' => $config->id]));
        }

        if ($this->isBitbucketConfigured() && ! $hasBitbucket) {
            $actions[] = Action::make('connectBitbucket')
                ->label(__('accounts.actions.connect_bitbucket'))
                ->icon('heroicon-o-arrow-right-circle')
                ->url(route('auth.bitbucket.redirect'));
        }

        if ($this->isLinearConfigured() && ! $hasLinear) {
            $actions[] = Action::make('connectLinear')
                ->label(__('accounts.actions.connect_linear'))
                ->icon('heroicon-o-arrow-right-circle')
                ->url(route('auth.linear.redirect'));
        }

        return $actions;
    }

    /**
     * Enabled self-hosted GitLab OAuth configs (instance_url <> '').
     *
     * @return Collection<int, ProviderOAuthConfig>
     */
    protected function selfHostedGitlabConfigs(): Collection
    {
        return ProviderOAuthConfig::query()
            ->where('provider', 'gitlab')
            ->where('enabled', true)
            ->where('instance_url', '<>', '')
            ->orderBy('instance_url')
            ->get();
    }

    public function disconnectGitHub(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $user->connectedAccounts()->where('provider', 'github')->delete();

        Notification::make()
            ->title(__('accounts.notifications.github_disconnected'))
            ->success()
            ->send();
    }

    public function disconnectGitLab(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $user->connectedAccounts()->where('provider', 'gitlab')->delete();

        Notification::make()
            ->title(__('accounts.notifications.gitlab_disconnected'))
            ->success()
            ->send();
    }

    public function disconnectBitbucket(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $user->connectedAccounts()->where('provider', 'bitbucket')->delete();

        Notification::make()
            ->title(__('accounts.notifications.bitbucket_disconnected'))
            ->success()
            ->send();
    }

    public function disconnectLinear(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $user->connectedAccounts()->where('provider', 'linear')->delete();

        Notification::make()
            ->title(__('accounts.notifications.linear_disconnected'))
            ->success()
            ->send();
    }
}
