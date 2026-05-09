<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\User;
use App\Services\Anthropic\AnthropicTokenValidator;
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

    /**
     * Onboarding-managed credential row name. Used to update-or-create the
     * single Default Claude credential when the user pastes a token here,
     * so repeat onboarding does not litter the DB with extra rows.
     */
    private const ONBOARDING_CREDENTIAL_NAME = 'Default';

    protected string $view = 'filament.admin.pages.onboarding';

    /** 'env' | 'agent_credential' | 'none' — drives which UI step is shown. */
    public string $tokenSource = 'none';

    public bool $codexConfigured = false;

    public string $claudeToken = '';

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
        $this->tokenSource = $this->detectTokenSource();
        $this->codexConfigured = AgentCredential::query()
            ->where('agent_name', AgentName::Codex->value)
            ->where('status', AgentCredentialStatus::Active->value)
            ->exists();

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

    private function detectTokenSource(): string
    {
        $envToken = config('argos.claude_token');
        if (is_string($envToken) && $envToken !== '') {
            return 'env';
        }

        $hasCredential = AgentCredential::query()
            ->where('agent_name', AgentName::ClaudeCode->value)
            ->where('status', AgentCredentialStatus::Active->value)
            ->exists();

        return $hasCredential ? 'agent_credential' : 'none';
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

        AgentCredential::updateOrCreate(
            [
                'agent_name' => AgentName::ClaudeCode->value,
                'name' => self::ONBOARDING_CREDENTIAL_NAME,
            ],
            [
                'credentials' => ['token' => $token],
                'status' => AgentCredentialStatus::Active->value,
                'last_validated_at' => $valid === true ? now() : null,
            ],
        );

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

    /**
     * At least one agent (Claude Code or Codex) has usable credentials —
     * drives the green checkmark on the agent step. The user can ship a
     * project with either, so we don't require both.
     */
    public function isAnyAgentConfigured(): bool
    {
        return $this->tokenSource !== 'none' || $this->codexConfigured;
    }
}
