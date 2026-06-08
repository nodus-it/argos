<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\AuthMethod;
use App\Models\AgentCredential;
use App\Models\ConnectedAccount;
use App\Models\ProviderCredential;
use App\Models\ProviderOAuthConfig;
use App\Models\RepoProfile;
use App\Models\User;
use App\Services\Anthropic\AnthropicTokenValidator;
use App\Services\Git\RepositoryFetcher;
use App\Services\OAuth\TokenRefresher;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Guided three-step setup wizard: authenticate an agent, connect & authorize a
 * repository, done. The active step is state-driven so it survives the external
 * OAuth round-trip (the provider callback redirects back here and we resume on
 * the repository step once an agent is configured).
 */
class Onboarding extends Page
{
    public const TOTAL_STEPS = 3;

    /** Git providers usable as a repository source, mapped to their OAuth config key. */
    private const OAUTH_PROVIDERS = [
        'github' => 'services.github.client_id',
        'gitlab' => 'services.gitlab.client_id',
        'bitbucket' => 'services.bitbucket.client_id',
    ];

    /**
     * Onboarding-managed credential row name. Used to update-or-create the
     * single Default agent credential so repeat onboarding does not litter the
     * DB with extra rows.
     */
    private const ONBOARDING_CREDENTIAL_NAME = 'Default';

    protected string $view = 'filament.admin.pages.onboarding';

    public int $currentStep = 1;

    // ── Step 1: agents ──────────────────────────────────────────────────────

    /** 'agent_credential' | 'none' — drives which Claude UI is shown. */
    public string $tokenSource = 'none';

    public bool $codexConfigured = false;

    public string $claudeToken = '';

    public string $codexAuthJson = '';

    // ── Step 2: repository ──────────────────────────────────────────────────

    /** @var array<string, array{configured: bool, connected: bool}> */
    public array $oauthState = [];

    /** Selected token source for the repo picker: '' | "oauth:{id}" | "pat:{ulid}". */
    public string $repoSource = '';

    public ?string $selectedRepo = null;

    public ?string $selectedBranch = null;

    public string $projectName = '';

    // ── Step 3: done ────────────────────────────────────────────────────────

    public ?string $createdProfileId = null;

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
        // Resume on the repository step after an agent is set up (e.g. when an
        // OAuth callback redirected back here mid-flow).
        $this->currentStep = $this->isAnyAgentConfigured() ? 2 : 1;
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
        $hasCredential = AgentCredential::query()
            ->where('agent_name', AgentName::ClaudeCode->value)
            ->where('status', AgentCredentialStatus::Active->value)
            ->exists();

        return $hasCredential ? 'agent_credential' : 'none';
    }

    // ── Step navigation ──────────────────────────────────────────────────────

    /** The highest step the user is allowed to reach given current state. */
    public function furthestUnlockedStep(): int
    {
        if (! $this->isAnyAgentConfigured()) {
            return 1;
        }

        if ($this->createdProfileId === null) {
            return 2;
        }

        return 3;
    }

    /**
     * The wizard steps with their display state, derived from the current step
     * and how far the user has unlocked. Keeps the stepper view free of the
     * per-step done/active/reachable branching.
     *
     * @return list<array{number: int, label: string, done: bool, active: bool, reachable: bool}>
     */
    public function steps(): array
    {
        $furthest = $this->furthestUnlockedStep();
        $labels = [
            1 => __('onboarding.steps.agents'),
            2 => __('onboarding.steps.repository'),
            3 => __('onboarding.steps.done'),
        ];

        $steps = [];
        foreach ($labels as $number => $label) {
            $steps[] = [
                'number' => $number,
                'label' => (string) $label,
                'done' => $number < $this->currentStep,
                'active' => $number === $this->currentStep,
                'reachable' => $number <= $furthest,
            ];
        }

        return $steps;
    }

    public function goToStep(int $step): void
    {
        $step = max(1, min(self::TOTAL_STEPS, $step));

        if ($step <= $this->furthestUnlockedStep()) {
            $this->currentStep = $step;
        }
    }

    public function nextStep(): void
    {
        if ($this->currentStep === 1 && ! $this->isAnyAgentConfigured()) {
            Notification::make()
                ->title(__('onboarding.notifications.need_agent'))
                ->warning()
                ->send();

            return;
        }

        $this->goToStep($this->currentStep + 1);
    }

    public function prevStep(): void
    {
        $this->currentStep = max(1, $this->currentStep - 1);
    }

    // ── Step 1: agent actions ────────────────────────────────────────────────

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
        $this->resetRepoSelection();
        $this->refreshState();

        Notification::make()
            ->title(__('onboarding.notifications.disconnected', ['provider' => __('onboarding.providers.'.$provider)]))
            ->success()
            ->send();
    }

    public function saveClaudeToken(): void
    {
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

    public function saveCodexAuthJson(): void
    {
        $raw = trim($this->codexAuthJson);

        if ($raw === '') {
            Notification::make()->title(__('onboarding.notifications.empty_codex'))->warning()->send();

            return;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            Notification::make()
                ->title(__('onboarding.notifications.invalid_codex_title'))
                ->body(__('onboarding.notifications.invalid_codex_body'))
                ->danger()
                ->send();

            return;
        }

        AgentCredential::updateOrCreate(
            [
                'agent_name' => AgentName::Codex->value,
                'name' => self::ONBOARDING_CREDENTIAL_NAME,
            ],
            [
                'credentials' => $decoded,
                'status' => AgentCredentialStatus::Active->value,
            ],
        );

        $this->codexAuthJson = '';
        $this->refreshState();

        Notification::make()->title(__('onboarding.notifications.codex_saved'))->success()->send();
    }

    public function isAnyAgentConfigured(): bool
    {
        return $this->tokenSource !== 'none' || $this->codexConfigured;
    }

    // ── Step 2: repository source + picker ────────────────────────────────────

    public function hasAnyOAuthConfigured(): bool
    {
        return $this->oauthTargets() !== [];
    }

    /**
     * OAuth connect options for the repository step — built from the DB-stored
     * OAuth apps (public AND self-hosted GitLab) plus any ENV-configured public
     * provider. Mirrors what the Connected Accounts page offers, so a
     * self-hosted GitLab app surfaces here too.
     *
     * @return list<array{key: string, provider: string, instance_url: string, label: string, connected: bool, connect_url: string}>
     */
    public function oauthTargets(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        $targets = [];
        $seen = [];

        $configs = ProviderOAuthConfig::query()
            ->where('enabled', true)
            ->whereIn('provider', array_keys(self::OAUTH_PROVIDERS))
            ->orderBy('provider')
            ->orderBy('instance_url')
            ->get();

        foreach ($configs as $config) {
            $provider = $config->provider->value;
            $instance = (string) $config->instance_url;

            // Only GitLab has an instance-aware OAuth login flow today.
            if ($instance !== '' && $provider !== 'gitlab') {
                continue;
            }

            $targets[] = $this->makeOauthTarget($user, $provider, $instance, $config->id);
            $seen["{$provider}:{$instance}"] = true;
        }

        // ENV-configured public providers without a DB row.
        foreach (self::OAUTH_PROVIDERS as $provider => $configKey) {
            if (isset($seen["{$provider}:"]) || ! filled(config($configKey))) {
                continue;
            }
            $targets[] = $this->makeOauthTarget($user, $provider, '', null);
        }

        return $targets;
    }

    /**
     * @return array{key: string, provider: string, instance_url: string, label: string, connected: bool, connect_url: string}
     */
    private function makeOauthTarget(?User $user, string $provider, string $instance, ?string $configId): array
    {
        $connected = $user !== null && $user->connectedAccounts()
            ->where('provider', $provider)
            ->where('instance_url', $instance)
            ->exists();

        $label = __('onboarding.providers.'.$provider);
        if ($instance !== '') {
            $label .= ' ('.(parse_url($instance, PHP_URL_HOST) ?: $instance).')';
        }

        $params = ['return' => 'onboarding'];
        if ($instance !== '' && $configId !== null) {
            $params['instance'] = $configId;
        }

        return [
            'key' => "{$provider}:{$instance}",
            'provider' => $provider,
            'instance_url' => $instance,
            'label' => $label,
            'connected' => $connected,
            'connect_url' => route("auth.{$provider}.redirect", $params),
        ];
    }

    /**
     * Stored Personal Access Tokens for git providers — shown in the authorize
     * step alongside OAuth, so the user sees configured tokens there too.
     *
     * @return list<array{provider: string, label: string, display: string}>
     */
    public function patTargets(): array
    {
        $out = [];

        $credentials = ProviderCredential::query()
            ->whereIn('provider', array_keys(self::OAUTH_PROVIDERS))
            ->orderBy('provider')
            ->orderBy('label')
            ->get();

        foreach ($credentials as $credential) {
            $instance = (string) $credential->instance_url;
            $host = $instance !== ''
                ? ' ('.(parse_url($instance, PHP_URL_HOST) ?: $instance).')'
                : '';

            $out[] = [
                'provider' => $credential->provider->value,
                'label' => $credential->label,
                'display' => $credential->provider->label().$host.' · '.$credential->label,
            ];
        }

        return $out;
    }

    /** Disconnect a specific (provider, instance) account from the repo step. */
    public function disconnectTarget(string $provider, string $instanceUrl): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $user->connectedAccounts()
            ->where('provider', $provider)
            ->where('instance_url', $instanceUrl)
            ->delete();
        $this->resetRepoSelection();

        Notification::make()
            ->title(__('onboarding.notifications.disconnected', ['provider' => __('onboarding.providers.'.$provider)]))
            ->success()
            ->send();
    }

    /**
     * Selectable repository token sources, grouped for the picker: connected
     * OAuth accounts and stored Personal Access Tokens, for git providers only.
     *
     * @return array{oauth: array<string, string>, pat: array<string, string>}
     */
    public function repoSourceOptions(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        $oauth = [];
        if ($user !== null) {
            // Every connected git account — including multiple GitLab instances
            // (public + self-hosted), which connectedAccount() would collapse to one.
            $accounts = $user->connectedAccounts()
                ->whereIn('provider', array_keys(self::OAUTH_PROVIDERS))
                ->get();
            foreach ($accounts as $account) {
                $label = $account->name ?? $account->nickname ?? "#{$account->id}";
                $host = ($account->instance_url !== null && $account->instance_url !== '')
                    ? ' ('.(parse_url($account->instance_url, PHP_URL_HOST) ?: $account->instance_url).')'
                    : '';
                $oauth["oauth:{$account->id}"] = ucfirst($account->provider).$host.' · '.$label;
            }
        }

        $pat = [];
        $credentials = ProviderCredential::query()
            ->whereIn('provider', array_keys(self::OAUTH_PROVIDERS))
            ->orderBy('label')
            ->get();
        foreach ($credentials as $credential) {
            $pat["pat:{$credential->id}"] = $credential->provider->label().' · '.$credential->label;
        }

        return ['oauth' => $oauth, 'pat' => $pat];
    }

    public function hasAnyRepoSource(): bool
    {
        $options = $this->repoSourceOptions();

        return $options['oauth'] !== [] || $options['pat'] !== [];
    }

    /**
     * Resolve the selected source into the platform + token needed to talk to
     * the provider API.
     *
     * @return array{platform: string, token: string, instance_url: string, auth_method: AuthMethod, account_id: ?int, credential_id: ?string}|null
     */
    private function resolveRepoSource(): ?array
    {
        if (str_starts_with($this->repoSource, 'oauth:')) {
            $account = ConnectedAccount::find((int) substr($this->repoSource, 6));
            if (! $account instanceof ConnectedAccount) {
                return null;
            }
            $account = app(TokenRefresher::class)->refreshIfNeeded($account);

            return [
                'platform' => $account->provider,
                'token' => $account->token,
                'instance_url' => $account->provider === 'gitlab' ? $account->getInstanceUrl() : '',
                'auth_method' => AuthMethod::OAuth,
                'account_id' => $account->id,
                'credential_id' => null,
            ];
        }

        if (str_starts_with($this->repoSource, 'pat:')) {
            $credential = ProviderCredential::find(substr($this->repoSource, 4));
            if (! $credential instanceof ProviderCredential) {
                return null;
            }

            return [
                'platform' => $credential->provider->value,
                'token' => $credential->token,
                'instance_url' => $credential->provider->value === 'gitlab' ? $credential->getInstanceUrl() : '',
                'auth_method' => AuthMethod::Pat,
                'account_id' => null,
                'credential_id' => $credential->id,
            ];
        }

        return null;
    }

    /**
     * Repository options for the selected source, cached for 60s to spare the
     * provider API on every Livewire round-trip. Failures degrade to an empty
     * list rather than breaking the wizard.
     *
     * @return array<string, string>
     */
    public function repoOptions(): array
    {
        $source = $this->resolveRepoSource();
        if ($source === null) {
            return [];
        }

        return app(RepositoryFetcher::class)->repoOptions(
            $source['platform'],
            $source['token'],
            $source['instance_url'],
            'onboarding_repos:'.md5($this->repoSource),
        );
    }

    /**
     * @return array<string, string>
     */
    public function branchOptions(): array
    {
        $source = $this->resolveRepoSource();
        if ($source === null || ! is_string($this->selectedRepo) || $this->selectedRepo === '') {
            return [];
        }

        return app(RepositoryFetcher::class)->branchOptions(
            $source['platform'],
            $source['token'],
            $source['instance_url'],
            $this->selectedRepo,
            'onboarding_branches:'.md5($this->repoSource.'|'.$this->selectedRepo),
        );
    }

    public function updatedRepoSource(): void
    {
        $this->resetRepoSelection();
    }

    public function updatedSelectedRepo(): void
    {
        $this->selectedBranch = null;

        if (is_string($this->selectedRepo) && $this->selectedRepo !== '') {
            // Default the project name to the repo's short name.
            $parts = explode('/', $this->selectedRepo);
            $this->projectName = (string) end($parts);

            $source = $this->resolveRepoSource();
            if ($source !== null) {
                $default = app(RepositoryFetcher::class)->defaultBranch(
                    $source['platform'],
                    $source['token'],
                    $source['instance_url'],
                    $this->selectedRepo,
                );
                if (is_string($default) && $default !== '') {
                    $this->selectedBranch = $default;
                }
            }
        }
    }

    private function resetRepoSelection(): void
    {
        $this->selectedRepo = null;
        $this->selectedBranch = null;
        $this->projectName = '';
    }

    public function createProject(): void
    {
        $source = $this->resolveRepoSource();

        if ($source === null || ! is_string($this->selectedRepo) || $this->selectedRepo === '') {
            Notification::make()->title(__('onboarding.notifications.repo_incomplete'))->warning()->send();

            return;
        }

        $name = trim($this->projectName);
        $branch = is_string($this->selectedBranch) ? trim($this->selectedBranch) : '';

        if ($name === '' || $branch === '') {
            Notification::make()->title(__('onboarding.notifications.repo_incomplete'))->warning()->send();

            return;
        }

        if (RepoProfile::query()->where('name', $name)->exists()) {
            Notification::make()
                ->title(__('onboarding.notifications.name_taken_title'))
                ->body(__('onboarding.notifications.name_taken_body'))
                ->danger()
                ->send();

            return;
        }

        $profile = RepoProfile::create([
            'name' => $name,
            'url' => $this->repoUrl($source['platform'], $this->selectedRepo, $source['instance_url']),
            'platform' => $source['platform'],
            'default_branch' => $branch,
            'auth_method' => $source['auth_method'],
            'connected_account_id' => $source['account_id'],
            'token' => $source['credential_id'] !== null ? $source['token'] : null,
            'worker_agent_name' => $this->preferredAgentName(),
        ]);

        $this->createdProfileId = $profile->id;
        $this->currentStep = 3;

        Notification::make()->title(__('onboarding.notifications.project_created'))->success()->send();
    }

    /** Build the canonical clone URL for an "owner/repo" path on a platform. */
    private function repoUrl(string $platform, string $repo, string $instanceUrl): string
    {
        return match ($platform) {
            'github' => "https://github.com/{$repo}",
            'gitlab' => ($instanceUrl !== '' ? $instanceUrl : 'https://gitlab.com')."/{$repo}",
            'bitbucket' => "https://bitbucket.org/{$repo}",
            default => '',
        };
    }

    /** The agent the user configured during onboarding, to pre-set on the profile. */
    private function preferredAgentName(): ?string
    {
        if ($this->tokenSource !== 'none') {
            return AgentName::ClaudeCode->value;
        }

        if ($this->codexConfigured) {
            return AgentName::Codex->value;
        }

        return null;
    }
}
