<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\RepoProfile;
use App\Models\User;
use App\Services\Anthropic\AnthropicTokenValidator;
use App\Services\Anthropic\CredentialStore;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class Onboarding extends Page
{
    /** Provider key → config()-path for OAuth client_id (used to detect "configured"). */
    private const OAUTH_PROVIDERS = [
        'github' => 'services.github.client_id',
        'gitlab' => 'services.gitlab.client_id',
        'bitbucket' => 'services.bitbucket.client_id',
    ];

    protected string $view = 'filament.admin.pages.onboarding';

    public string $tokenSource = 'none';

    public string $claudeToken = '';

    public string $workerImage = '';

    /** @var array<string, array{configured: bool, connected: bool}> */
    public array $oauthState = [];

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-rocket-launch';
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return __('onboarding.navigation_label');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! RepoProfile::exists();
    }

    public function getTitle(): string
    {
        return __('onboarding.title');
    }

    public function mount(): void
    {
        $this->refreshState();
    }

    private function refreshState(): void
    {
        $this->tokenSource = app(CredentialStore::class)->claudeTokenSource();
        $this->workerImage = (string) config('argos.worker_image', '');

        /** @var User|null $user */
        $user = Auth::user();

        $this->oauthState = [];
        foreach (self::OAUTH_PROVIDERS as $provider => $configKey) {
            $this->oauthState[$provider] = [
                'configured' => filled(config($configKey)),
                'connected' => $user !== null && $user->connectedAccount($provider) !== null,
            ];
        }
    }

    public function disconnectProvider(string $provider): void
    {
        if (! array_key_exists($provider, self::OAUTH_PROVIDERS)) {
            return;
        }

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $user->connectedAccounts()->where('provider', $provider)->delete();
        $this->refreshState();

        Notification::make()
            ->title(__('onboarding.notifications.disconnected', ['provider' => __('onboarding.providers.'.$provider)]))
            ->success()
            ->send();
    }

    public function saveClaudeToken(): void
    {
        if ($this->tokenSource === 'env') {
            Notification::make()
                ->title(__('onboarding.notifications.env_token'))
                ->warning()
                ->send();

            return;
        }

        $token = trim($this->claudeToken);

        if ($token === '') {
            Notification::make()->title(__('onboarding.notifications.empty_token'))->warning()->send();

            return;
        }

        $valid = app(AnthropicTokenValidator::class)->validate($token);

        if ($valid === false) {
            Notification::make()
                ->title(__('onboarding.notifications.invalid_token_title'))
                ->body(__('onboarding.notifications.invalid_token_body'))
                ->danger()
                ->send();

            return;
        }

        app(CredentialStore::class)->setClaudeToken($token);
        $this->claudeToken = '';
        $this->refreshState();

        if ($valid === null) {
            Notification::make()
                ->title(__('onboarding.notifications.saved_title'))
                ->body(__('onboarding.notifications.saved_unreachable_body'))
                ->warning()
                ->send();
        } else {
            Notification::make()->title(__('onboarding.notifications.saved_title'))->success()->send();
        }
    }

    /**
     * Did at least one OAuth provider have credentials configured?
     * Drives whether the "Connect provider" step is shown at all.
     */
    public function hasAnyOAuthConfigured(): bool
    {
        foreach ($this->oauthState as $state) {
            if ($state['configured']) {
                return true;
            }
        }

        return false;
    }

    public function isAnyProviderConnected(): bool
    {
        foreach ($this->oauthState as $state) {
            if ($state['connected']) {
                return true;
            }
        }

        return false;
    }
}
