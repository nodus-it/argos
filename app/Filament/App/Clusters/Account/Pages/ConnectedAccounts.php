<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\Account\Pages;

use App\Models\ConnectedAccount;
use App\Models\ProviderOAuthConfig;
use App\Models\User;
use App\Services\OAuth\ConnectedAccountService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use App\Filament\App\Clusters\Account;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/** My account → Connected accounts (`/account/connections`). */
class ConnectedAccounts extends Page
{
    protected string $view = 'filament.app.account.connections';

    protected static ?string $cluster = Account::class;

    protected static ?string $slug = 'connections';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-link';
    }

    public static function getNavigationLabel(): string
    {
        return __('account.connections');
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    public function getTitle(): string
    {
        return __('accounts.title');
    }

    /** The providers this page connects, in display order. */
    public const PROVIDERS = ['github', 'gitlab', 'bitbucket', 'linear'];

    /** Whether an OAuth app is configured for the provider (else the page offers to create one). */
    public function isConfigured(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true) && filled(config("services.{$provider}.client_id"));
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

        if ($this->isConfigured('github') && ! $hasGitHub) {
            $actions[] = Action::make('connectGitHub')
                ->label(__('accounts.actions.connect_github'))
                ->icon('heroicon-o-arrow-right-circle')
                ->url(route('auth.github.redirect'));
        }

        if ($this->isConfigured('gitlab') && ! $hasGitLab) {
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

        if ($this->isConfigured('bitbucket') && ! $hasBitbucket) {
            $actions[] = Action::make('connectBitbucket')
                ->label(__('accounts.actions.connect_bitbucket'))
                ->icon('heroicon-o-arrow-right-circle')
                ->url(route('auth.bitbucket.redirect'));
        }

        if ($this->isConfigured('linear') && ! $hasLinear) {
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

    public function disconnect(string $provider): void
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return;
        }
        /** @var User $user */
        $user = Auth::user();
        app(ConnectedAccountService::class)->disconnect($user, $provider);

        Notification::make()
            ->title(__("accounts.notifications.{$provider}_disconnected"))
            ->success()
            ->send();
    }
}
