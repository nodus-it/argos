<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\CredentialStore;
use App\Models\RepoProfile;
use App\Models\User;
use App\Services\Anthropic\AnthropicTokenValidator;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

class Onboarding extends Page
{
    protected string $view = 'filament.admin.pages.onboarding';

    public string $tokenSource = 'none';

    public string $claudeToken = '';

    public string $workerImage = '';

    public bool $githubOAuthAvailable = false;

    public bool $githubConnected = false;

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
        $this->githubOAuthAvailable = (bool) config('services.github.client_id')
            && (bool) config('services.github.client_secret');

        /** @var User|null $user */
        $user = Auth::user();
        $this->githubConnected = $user !== null && $user->connectedAccount('github') !== null;
    }

    public function disconnectGitHub(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return;
        }

        $user->connectedAccounts()->where('provider', 'github')->delete();
        $this->refreshState();

        Notification::make()->title(__('onboarding.notifications.github_disconnected'))->success()->send();
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
}
