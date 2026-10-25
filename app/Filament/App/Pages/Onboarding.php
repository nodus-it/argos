<?php

declare(strict_types=1);

namespace App\Filament\App\Pages;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\AuthMethod;
use App\Filament\App\Clusters\System\Resources\ProviderCredentialResource;
use App\Filament\App\Clusters\System\Resources\ProviderOAuthConfigResource;
use App\Filament\App\Resources\WorkspaceResource;
use App\Filament\App\Resources\WorkspaceResource\Pages\CreateWorkspaceExpert;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Concerns\VerifiesCredentialOnSave;
use App\Models\ProviderCredential;
use App\Models\ProviderOAuthConfig;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Account\AccountPasswordService;
use App\Services\Anthropic\AnthropicTokenValidator;
use App\Services\Credentials\AgentCredentialService;
use App\Services\Credentials\CredentialRefResolver;
use App\Services\Credentials\CredentialVerifier;
use App\Services\Credentials\ProviderCredentialService;
use App\Services\Git\RepositoryFetcher;
use App\Services\OAuth\ConnectedAccountService;
use App\Services\OAuth\ProviderOAuthConfigService;
use App\Services\OAuth\TokenRefresher;
use App\Services\User\UserService;
use App\Services\Workspace\AgentPool;
use App\Support\DocsLinkAction;
use App\Support\RepoUrlBuilder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Livewire\WithFileUploads;

/**
 * Guided four-step setup wizard: secure the account, add your own Claude
 * subscription (a personal agent), create the first workspace, done. The active step is state-driven so it
 * survives the external OAuth round-trip (the provider callback redirects back
 * here and we resume on the repository step once an agent is configured).
 *
 * The first step (security) is optional and skippable — it exists so the first
 * user, who logs in with the seeded default password, can change it right where
 * the onboarding redirect strands them.
 */
class Onboarding extends Page
{
    use AdminOnly;

    /** `/setup`: the first-run wizard. */
    protected static ?string $slug = 'setup';

    use VerifiesCredentialOnSave;
    use WithFileUploads;

    public const TOTAL_STEPS = 4;

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

    protected string $view = 'filament.app.onboarding';

    public int $currentStep = 1;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        // Contextual help: the setup docs are reachable here even pre-onboarding
        // (Documentation page is on the RedirectToOnboarding whitelist).
        return [
            // Language first: switching reloads the wizard in it.
            ActionGroup::make([
                Action::make('localeDe')->label('Deutsch')->action(fn () => $this->setLocale('de')),
                Action::make('localeEn')->label('English')->action(fn () => $this->setLocale('en')),
            ])
                ->label(app()->getLocale() === 'de' ? 'Deutsch' : 'English')
                ->icon('heroicon-o-language')
                ->color('gray')
                ->button()
                ->tooltip(__('onboarding.language')),
            DocsLinkAction::make('setup'),
        ];
    }

    /**
     * "Add a token" in the workspace step: the access-token form in a modal,
     * so the wizard never leaves for the administration. The token is
     * verified against the provider before it is stored.
     */
    public function addAccessTokenAction(): Action
    {
        return Action::make('addAccessToken')
            ->label(__('onboarding.repo.pat_link'))
            ->icon('heroicon-o-plus')
            ->link()
            ->modalHeading(__('onboarding.repo.pat_card_title'))
            ->modalWidth('2xl')
            ->schema(fn (Schema $schema): Schema => ProviderCredentialResource::form($schema))
            ->action(function (array $data): void {
                $verification = app(CredentialVerifier::class)->verifyProvider(
                    (string) ($data['provider'] ?? ''),
                    (string) ($data['token'] ?? ''),
                    (string) ($data['instance_url'] ?? ''),
                );
                app(ProviderCredentialService::class)->create($this->applyVerification($verification, $data));
                $this->refreshState();
            });
    }

    /** "Set up an OAuth app": the OAuth-app form in a modal, see addAccessTokenAction(). */
    public function addOAuthAppAction(): Action
    {
        return Action::make('addOAuthApp')
            ->label(fn (): string => $this->oauthTargets() === [] ? __('onboarding.repo.oauth_app_link') : __('onboarding.repo.oauth_add_more'))
            ->icon('heroicon-o-plus')
            ->link()
            ->modalHeading(__('onboarding.repo.oauth_card_title'))
            ->modalWidth('2xl')
            ->schema(fn (Schema $schema): Schema => ProviderOAuthConfigResource::form($schema))
            ->action(function (array $data): void {
                app(ProviderOAuthConfigService::class)->create($data);
                $this->refreshState();
            });
    }

    // ── Step 1: security (password) ─────────────────────────────────────────

    /** Profile picture chosen in the account step (saved right away). */
    public mixed $avatarUpload = null;

    public function updatedAvatarUpload(): void
    {
        $this->validate(['avatarUpload' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096']]);
        /** @var User $user */
        $user = Auth::user();
        app(UserService::class)->setAvatar($user, $this->avatarUpload);
        $this->avatarUpload = null;
        Notification::make()->title(__('onboarding.notifications.avatar_saved'))->success()->send();
    }

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    /** Set once the password was changed here, to drop the default-password warning. */
    public bool $passwordJustChanged = false;

    // ── Step 2: agents ──────────────────────────────────────────────────────

    /** 'agent_credential' | 'none' — drives which Claude UI is shown. */
    public string $tokenSource = 'none';

    public string $claudeToken = '';

    // ── Step 3: repository ──────────────────────────────────────────────────

    /** Selected token source for the repo picker: '' | "oauth:{id}" | "pat:{ulid}". */
    public string $repoSource = '';

    public ?string $selectedRepo = null;

    public ?string $selectedBranch = null;

    public string $workspaceName = '';

    // ── Step 4: done ────────────────────────────────────────────────────────

    public ?string $createdWorkspaceId = null;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-rocket-launch';
    }

    public static function getNavigationLabel(): string
    {
        return __('onboarding.navigation_label');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return ! Workspace::exists();
    }

    public function getTitle(): string
    {
        return __('onboarding.title');
    }

    /** @return array<string> */
    public function getPageClasses(): array
    {
        // Hides the panel navigation while the wizard runs (resources/css/app.css).
        return ['argos-onboarding'];
    }

    /** The language picker at the top: saved on the account, the wizard reloads in it. */
    public function setLocale(string $locale): void
    {
        /** @var User $user */
        $user = Auth::user();
        app(UserService::class)->setLocale($user, $locale);
        $this->redirect(self::getUrl());
    }

    public function mount(): void
    {
        $this->refreshState();
        // Resume on the repository step after an agent is set up (e.g. when an
        // OAuth callback redirected back here mid-flow); otherwise start on the
        // security step so the first user can change the default password.
        $this->currentStep = $this->isAnyAgentConfigured() ? 3 : 1;
    }

    private function refreshState(): void
    {
        $this->tokenSource = $this->detectTokenSource();
    }

    /**
     * Set up when the admin has a Claude agent they may use — the one the
     * setup task of their first workspace runs on (AgentPool, like
     * WorkspaceSetupService::start). Other people's personal agents do not count.
     */
    private function detectTokenSource(): string
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user !== null && app(AgentPool::class)->usableAgentsFor($user)->isNotEmpty() ? 'agent_credential' : 'none';
    }

    // ── Step navigation ──────────────────────────────────────────────────────

    /** The highest step the user is allowed to reach given current state. */
    public function furthestUnlockedStep(): int
    {
        // The security step is optional, so agents (step 2) is always reachable.
        if (! $this->isAnyAgentConfigured()) {
            return 2;
        }

        if ($this->createdWorkspaceId === null) {
            return 3;
        }

        return 4;
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
            1 => __('onboarding.steps.security'),
            2 => __('onboarding.steps.agents'),
            3 => __('onboarding.steps.repository'),
            4 => __('onboarding.steps.done'),
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
        if ($this->currentStep === 2 && ! $this->isAnyAgentConfigured()) {
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

    // ── Step 1: security actions ─────────────────────────────────────────────

    /**
     * True while the logged-in user still carries the seeded default password.
     * Drives the warning banner that nudges the first user to change it — no
     * DB flag needed, we simply compare against the configured admin password.
     */
    public function usingDefaultPassword(): bool
    {
        if ($this->passwordJustChanged) {
            return false;
        }

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        $default = config('argos.admin_password');

        return is_string($default) && $default !== '' && Hash::check($default, (string) $user->password);
    }

    public function savePassword(): void
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        $validator = Validator::make(
            [
                'password' => $this->newPassword,
                'password_confirmation' => $this->newPasswordConfirmation,
            ],
            [
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );

        if ($validator->fails()) {
            Notification::make()
                ->title(__('onboarding.notifications.password_invalid_title'))
                ->body($validator->errors()->first('password'))
                ->danger()
                ->send();

            return;
        }

        app(AccountPasswordService::class)->change($user, $this->newPassword);

        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
        $this->passwordJustChanged = true;

        Notification::make()
            ->title(__('onboarding.notifications.password_saved'))
            ->success()
            ->send();
    }

    // ── Step 2: agent actions ────────────────────────────────────────────────

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

        // The admin's own subscription (a personal agent, as in the profile):
        // their tasks and the first workspace's setup task run on it.
        app(AgentCredentialService::class)->upsert(
            [
                'agent_name' => AgentName::ClaudeCode->value,
                'name' => self::ONBOARDING_CREDENTIAL_NAME,
                'user_id' => Auth::id(),
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

    public function isAnyAgentConfigured(): bool
    {
        return $this->tokenSource !== 'none';
    }

    // ── Step 3: repository source + picker ────────────────────────────────────

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

        app(ConnectedAccountService::class)->disconnect($user, $provider, $instanceUrl);
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
        $providers = array_keys(self::OAUTH_PROVIDERS);

        // Every connected git account — including multiple GitLab instances
        // (public + self-hosted), which connectedAccount() would collapse to one.
        return $user !== null
            ? app(CredentialRefResolver::class)->options($user, $providers, $providers, withProvider: true)
            : ['oauth' => [], 'pat' => []];
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
        /** @var User $user */
        $user = Auth::user();
        $refs = app(CredentialRefResolver::class);
        if (str_starts_with($this->repoSource, 'oauth:')) {
            // Only the person's own accounts — the ref arrives from the browser.
            $account = $refs->account($user, $this->repoSource);
            if ($account === null) {
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
            $credential = $refs->credential($this->repoSource);
            if ($credential === null) {
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
            $this->workspaceName = (string) end($parts);

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
        $this->workspaceName = '';
    }

    public function createWorkspace(): void
    {
        $source = $this->resolveRepoSource();

        if ($source === null || ! is_string($this->selectedRepo) || $this->selectedRepo === '') {
            Notification::make()->title(__('onboarding.notifications.repo_incomplete'))->warning()->send();

            return;
        }

        $name = trim($this->workspaceName);
        $branch = is_string($this->selectedBranch) ? trim($this->selectedBranch) : '';

        if ($name === '' || $branch === '') {
            Notification::make()->title(__('onboarding.notifications.repo_incomplete'))->warning()->send();

            return;
        }

        if (Workspace::query()->where('name', $name)->exists()) {
            Notification::make()
                ->title(__('onboarding.notifications.name_taken_title'))
                ->body(__('onboarding.notifications.name_taken_body'))
                ->danger()
                ->send();

            return;
        }

        // The guided creation reads the repository, explains what it found and starts the setup.
        session()->put(CreateWorkspaceExpert::DRAFT_KEY, ['name' => $name, 'platform' => $source['platform'], ...($source['auth_method'] === AuthMethod::OAuth
            ? ['auth_method' => 'oauth', 'connected_account_id' => $source['account_id'], 'oauth_repo' => $this->selectedRepo, 'oauth_branch' => $branch]
            : ['auth_method' => 'pat', 'repo_url' => RepoUrlBuilder::build($source['platform'], $this->selectedRepo, $source['instance_url']), 'token' => $source['token'], 'default_branch' => $branch])]);
        $this->redirect(WorkspaceResource::getUrl('create'));
    }
}
