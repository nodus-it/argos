<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AgentName;
use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Enums\WorkerImageEntityStatus;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ViewRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\RelationManagers\TasksRelationManager;
use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\User;
use App\Models\WorkerStack;
use App\Services\GitProvider\BitbucketGitService;
use App\Services\GitProvider\GitHubGitService;
use App\Services\GitProvider\GitLabGitService;
use App\Services\GitProvider\GitServiceFactory;
use App\Workers\Agents\AgentRegistry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class RepoProfileResource extends Resource
{
    protected static ?string $model = RepoProfile::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-folder-open';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('projects.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('projects.navigation_label');
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getModelLabel(): string
    {
        return __('projects.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('projects.model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->tabs([
                    Tab::make(__('projects.tabs.basics'))
                        ->icon('heroicon-o-folder')
                        ->schema([
                            // ── Block 1 ─ Plattform (gates everything below) ────────────────
                            Section::make(__('projects.sections.platform'))
                                ->description(__('projects.sections.platform_description'))
                                ->schema([
                                    Select::make('platform')
                                        ->label(__('projects.fields.platform'))
                                        ->options([
                                            'github' => __('projects.fields.platform_github'),
                                            'gitlab' => __('projects.fields.platform_gitlab'),
                                            'bitbucket' => __('projects.fields.platform_bitbucket'),
                                        ])
                                        ->required()
                                        ->live()
                                        ->native(false),

                                    Callout::make(fn (Get $get): string => __('projects.platform_hints.'.($get('platform') ?: 'github').'.heading'))
                                        ->visible(fn (Get $get): bool => self::platformChosen($get))
                                        ->color('info')
                                        ->icon('heroicon-o-information-circle')
                                        ->description(fn (Get $get): HtmlString => new HtmlString(
                                            (string) __('projects.platform_hints.'.($get('platform') ?: 'github').'.body')
                                            .' <a href="'.e((string) config('argos.docs.setup_'.($get('platform') ?: 'github')))
                                            .'" target="_blank" rel="noopener" class="underline">'
                                            .e((string) __('projects.platform_hints.docs_link')).'</a>'
                                        )),
                                ]),

                            // ── Block 3a ─ Authentifizierung (GitHub/GitLab mit OAuth-Account) ─
                            Section::make(__('projects.sections.authentication'))
                                ->visible(fn (Get $get): bool => self::hasOAuthAccount($get))
                                ->schema([
                                    Select::make('auth_method')
                                        ->label(__('projects.fields.auth_method_label'))
                                        ->options(fn (Get $get): array => self::authMethodOptions($get))
                                        ->default('pat')
                                        ->required()
                                        ->live()
                                        ->native(false)
                                        ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                            if ($state === 'oauth') {
                                                $set('token', null);
                                                $account = self::connectedAccountFor($get);
                                                if ($account !== null) {
                                                    $set('connected_account_id', $account->id);
                                                }
                                            } else {
                                                $set('connected_account_id', null);
                                            }
                                        })
                                        ->dehydrated(),

                                    Select::make('connected_account_id')
                                        ->label(fn (Get $get): string => $get('platform') === 'gitlab'
                                            ? __('projects.fields.gitlab_account_label')
                                            : __('projects.fields.github_account_label'))
                                        ->options(function (Get $get): array {
                                            /** @var User|null $user */
                                            $user = Auth::user();
                                            if ($user === null) {
                                                return [];
                                            }
                                            $provider = is_string($get('platform')) ? $get('platform') : 'github';

                                            return $user->connectedAccounts()
                                                ->where('provider', $provider)
                                                ->get()
                                                ->mapWithKeys(fn (ConnectedAccount $account): array => [
                                                    $account->id => $account->name ?? $account->nickname ?? ucfirst($provider)." #{$account->id}",
                                                ])
                                                ->all();
                                        })
                                        ->visible(fn (Get $get): bool => $get('auth_method') === 'oauth')
                                        ->required(fn (Get $get): bool => $get('auth_method') === 'oauth')
                                        ->native(false)
                                        ->dehydrated(),
                                ]),

                            // ── Block 3b ─ Authentifizierung (Bitbucket mit OAuth-Account) ─
                            Section::make(__('projects.sections.authentication'))
                                ->visible(fn (Get $get): bool => $get('platform') === 'bitbucket' && self::bitbucketAccount() !== null)
                                ->schema([
                                    Select::make('auth_method')
                                        ->label(__('projects.fields.auth_method_label'))
                                        ->options([
                                            'pat' => __('projects.fields.auth_method_pat'),
                                            'oauth' => __('projects.fields.auth_method_oauth_bitbucket'),
                                        ])
                                        ->default('pat')
                                        ->required()
                                        ->live()
                                        ->native(false)
                                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                                            if ($state === 'oauth') {
                                                $set('token', null);
                                                $account = self::bitbucketAccount();
                                                if ($account !== null) {
                                                    $set('connected_account_id', $account->id);
                                                }
                                            } else {
                                                $set('connected_account_id', null);
                                            }
                                        })
                                        ->dehydrated(),

                                    Select::make('connected_account_id')
                                        ->label(__('projects.fields.bitbucket_account_label'))
                                        ->options(function (): array {
                                            /** @var User|null $user */
                                            $user = Auth::user();
                                            if ($user === null) {
                                                return [];
                                            }

                                            return $user->connectedAccounts()
                                                ->where('provider', 'bitbucket')
                                                ->get()
                                                ->mapWithKeys(fn (ConnectedAccount $account): array => [
                                                    $account->id => $account->name ?? $account->nickname ?? "Bitbucket #{$account->id}",
                                                ])
                                                ->all();
                                        })
                                        ->visible(fn (Get $get): bool => $get('auth_method') === 'oauth')
                                        ->required(fn (Get $get): bool => $get('auth_method') === 'oauth')
                                        ->native(false)
                                        ->dehydrated(),
                                ]),

                            // ── Block 2 + Block 4 nebeneinander (Allgemein + Repository) ─────
                            Grid::make(2)
                                ->columnSpanFull()
                                ->schema([
                                    Section::make(__('projects.sections.general'))
                                        ->visible(fn (Get $get): bool => self::platformChosen($get))
                                        ->columnSpan(1)
                                        ->schema([
                                            TextInput::make('name')
                                                ->label(__('projects.fields.project_name'))
                                                ->required()
                                                ->maxLength(255),

                                            Toggle::make('auto_concept')
                                                ->label(__('projects.fields.auto_concept_label'))
                                                ->helperText(__('projects.fields.auto_concept_helper')),

                                            Toggle::make('auto_pr')
                                                ->label(__('projects.fields.auto_pr_label'))
                                                ->helperText(__('projects.fields.auto_pr_helper')),
                                        ]),

                                    Section::make(__('projects.sections.repository'))
                                        ->visible(fn (Get $get): bool => self::platformChosen($get))
                                        ->columnSpan(1)
                                        ->schema([
                                            // Connected-Pfad: GitHub mit OAuth-Account
                                            Select::make('github_repo')
                                                ->label(__('projects.infolist.repo_url'))
                                                ->options(function (): array {
                                                    $account = self::githubAccount();
                                                    if ($account === null) {
                                                        return [];
                                                    }
                                                    try {
                                                        return (new GitHubGitService($account->token))->getRepoOptions();
                                                    } catch (\Throwable $e) {
                                                        report($e);

                                                        return [];
                                                    }
                                                })
                                                ->required()
                                                ->searchable()
                                                ->live()
                                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                                    if ($state === null || $state === '') {
                                                        return;
                                                    }
                                                    $set('url', "https://github.com/{$state}");

                                                    if (! is_string($get('name')) || $get('name') === '') {
                                                        $shortName = explode('/', $state, 2)[1] ?? $state;
                                                        $set('name', $shortName);
                                                    }

                                                    $account = self::githubAccount();
                                                    if ($account === null) {
                                                        return;
                                                    }
                                                    $apiDefault = (new GitHubGitService($account->token))->getDefaultBranch($state);
                                                    if ($apiDefault !== null) {
                                                        $set('github_branch', $apiDefault);
                                                        $set('default_branch', $apiDefault);
                                                    }
                                                })
                                                ->visible(fn (Get $get): bool => self::isGithubConnectedPath($get))
                                                ->dehydrated(fn (Get $get): bool => self::isGithubConnectedPath($get)),

                                            Select::make('github_branch')
                                                ->label(__('projects.fields.default_branch_label'))
                                                ->options(function (Get $get): array {
                                                    $repo = $get('github_repo');
                                                    if (! is_string($repo) || $repo === '') {
                                                        return [];
                                                    }
                                                    $account = self::githubAccount();
                                                    if ($account === null) {
                                                        return [];
                                                    }
                                                    try {
                                                        return (new GitHubGitService($account->token))->getBranchOptions($repo);
                                                    } catch (\Throwable $e) {
                                                        report($e);

                                                        return [];
                                                    }
                                                })
                                                ->required(fn (Get $get): bool => self::isGithubConnectedPath($get))
                                                ->searchable()
                                                ->live()
                                                ->visible(fn (Get $get): bool => self::isGithubConnectedPath($get) && is_string($get('github_repo')) && $get('github_repo') !== '')
                                                ->dehydrated(fn (Get $get): bool => self::isGithubConnectedPath($get)),

                                            // Connected-Pfad: GitLab mit OAuth-Account
                                            Select::make('gitlab_repo')
                                                ->label(__('projects.infolist.repo_url'))
                                                ->options(function (): array {
                                                    $account = self::gitlabAccount();
                                                    if ($account === null) {
                                                        return [];
                                                    }
                                                    try {
                                                        return (new GitLabGitService($account->token, $account->getInstanceUrl()))->getRepoOptions();
                                                    } catch (\Throwable $e) {
                                                        report($e);

                                                        return [];
                                                    }
                                                })
                                                ->required()
                                                ->searchable()
                                                ->live()
                                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                                    if ($state === null || $state === '') {
                                                        return;
                                                    }
                                                    $account = self::gitlabAccount();
                                                    $instanceUrl = $account?->getInstanceUrl() ?? 'https://gitlab.com';
                                                    $set('url', "{$instanceUrl}/{$state}");

                                                    if (! is_string($get('name')) || $get('name') === '') {
                                                        $shortName = explode('/', $state, 2)[1] ?? $state;
                                                        $set('name', $shortName);
                                                    }

                                                    if ($account === null) {
                                                        return;
                                                    }
                                                    $apiDefault = (new GitLabGitService($account->token, $account->getInstanceUrl()))->getDefaultBranch($state);
                                                    if ($apiDefault !== null) {
                                                        $set('gitlab_branch', $apiDefault);
                                                        $set('default_branch', $apiDefault);
                                                    }
                                                })
                                                ->visible(fn (Get $get): bool => self::isGitlabConnectedPath($get))
                                                ->dehydrated(fn (Get $get): bool => self::isGitlabConnectedPath($get)),

                                            Select::make('gitlab_branch')
                                                ->label(__('projects.fields.default_branch_label'))
                                                ->options(function (Get $get): array {
                                                    $repo = $get('gitlab_repo');
                                                    if (! is_string($repo) || $repo === '') {
                                                        return [];
                                                    }
                                                    $account = self::gitlabAccount();
                                                    if ($account === null) {
                                                        return [];
                                                    }
                                                    try {
                                                        return (new GitLabGitService($account->token, $account->getInstanceUrl()))->getBranchOptions($repo);
                                                    } catch (\Throwable $e) {
                                                        report($e);

                                                        return [];
                                                    }
                                                })
                                                ->required(fn (Get $get): bool => self::isGitlabConnectedPath($get))
                                                ->searchable()
                                                ->live()
                                                ->visible(fn (Get $get): bool => self::isGitlabConnectedPath($get) && is_string($get('gitlab_repo')) && $get('gitlab_repo') !== '')
                                                ->dehydrated(fn (Get $get): bool => self::isGitlabConnectedPath($get)),

                                            // Connected-Pfad: Bitbucket mit OAuth-Account
                                            Select::make('bitbucket_repo')
                                                ->label(__('projects.infolist.repo_url'))
                                                ->options(function (): array {
                                                    $account = self::bitbucketAccount();
                                                    if ($account === null) {
                                                        return [];
                                                    }
                                                    try {
                                                        return (new BitbucketGitService($account->token))->getRepoOptions();
                                                    } catch (\Throwable $e) {
                                                        report($e);

                                                        return [];
                                                    }
                                                })
                                                ->required()
                                                ->searchable()
                                                ->live()
                                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                                    if ($state === null || $state === '') {
                                                        return;
                                                    }
                                                    $set('url', "https://bitbucket.org/{$state}");

                                                    if (! is_string($get('name')) || $get('name') === '') {
                                                        $shortName = explode('/', $state, 2)[1] ?? $state;
                                                        $set('name', $shortName);
                                                    }

                                                    $account = self::bitbucketAccount();
                                                    if ($account === null) {
                                                        return;
                                                    }
                                                    $apiDefault = (new BitbucketGitService($account->token))->getDefaultBranch($state);
                                                    if ($apiDefault !== null) {
                                                        $set('bitbucket_branch', $apiDefault);
                                                        $set('default_branch', $apiDefault);
                                                    }
                                                })
                                                ->visible(fn (Get $get): bool => self::isBitbucketConnectedPath($get))
                                                ->dehydrated(fn (Get $get): bool => self::isBitbucketConnectedPath($get)),

                                            Select::make('bitbucket_branch')
                                                ->label(__('projects.fields.default_branch_label'))
                                                ->options(function (Get $get): array {
                                                    $repo = $get('bitbucket_repo');
                                                    if (! is_string($repo) || $repo === '') {
                                                        return [];
                                                    }
                                                    $account = self::bitbucketAccount();
                                                    if ($account === null) {
                                                        return [];
                                                    }
                                                    try {
                                                        return (new BitbucketGitService($account->token))->getBranchOptions($repo);
                                                    } catch (\Throwable $e) {
                                                        report($e);

                                                        return [];
                                                    }
                                                })
                                                ->required(fn (Get $get): bool => self::isBitbucketConnectedPath($get))
                                                ->searchable()
                                                ->live()
                                                ->visible(fn (Get $get): bool => self::isBitbucketConnectedPath($get) && is_string($get('bitbucket_repo')) && $get('bitbucket_repo') !== '')
                                                ->dehydrated(fn (Get $get): bool => self::isBitbucketConnectedPath($get)),

                                            // Manual-Pfad: GitLab, GitHub ohne OAuth, oder Bitbucket ohne OAuth
                                            TextInput::make('url')
                                                ->label(__('projects.fields.repo_url_label'))
                                                ->required(fn (Get $get): bool => ! self::isConnectedPath($get))
                                                ->url()
                                                ->maxLength(500)
                                                ->live(onBlur: true)
                                                ->visible(fn (Get $get): bool => ! self::isConnectedPath($get))
                                                ->dehydrated(),

                                            TextInput::make('token')
                                                ->label(__('projects.fields.token_label'))
                                                ->password()
                                                ->revealable()
                                                ->maxLength(500)
                                                ->required(fn (Get $get): bool => ! self::isConnectedPath($get))
                                                ->live(onBlur: true)
                                                ->helperText(function (Get $get): HtmlString {
                                                    $platform = $get('platform');
                                                    $oauthHint = '';

                                                    if ($platform === 'github' && self::githubAccount() !== null) {
                                                        $oauthHint = (string) __('projects.fields.token_helper_oauth_available');
                                                    } elseif ($platform === 'gitlab' && self::gitlabAccount() !== null) {
                                                        $oauthHint = (string) __('projects.fields.token_helper_oauth_available');
                                                    } elseif ($platform === 'bitbucket' && self::bitbucketAccount() !== null) {
                                                        $oauthHint = (string) __('projects.fields.token_helper_bitbucket_oauth_available');
                                                    } elseif ($platform === 'bitbucket') {
                                                        $oauthHint = (string) __('projects.fields.token_helper_bitbucket');
                                                    }

                                                    $linkUrl = match ($platform) {
                                                        'github' => (string) config('argos.docs.github_pat'),
                                                        'gitlab' => (string) config('argos.docs.gitlab_pat'),
                                                        'bitbucket' => (string) config('argos.docs.bitbucket_pat'),
                                                        default => '',
                                                    };

                                                    $link = $linkUrl !== ''
                                                        ? ' <a href="'.e($linkUrl).'" target="_blank" rel="noopener" class="underline">'
                                                          .e((string) __('projects.fields.token_create_link')).' ↗</a>'
                                                        : '';

                                                    return new HtmlString(trim($oauthHint.$link));
                                                })
                                                ->visible(fn (Get $get): bool => ! self::isConnectedPath($get)),

                                            Select::make('default_branch')
                                                ->label(__('projects.fields.default_branch_label'))
                                                ->options(function (Get $get): array {
                                                    $url = $get('url');
                                                    $token = $get('token');
                                                    $platform = $get('platform');

                                                    if (! is_string($url) || $url === '' || ! is_string($token) || $token === '' || ! is_string($platform) || $platform === '') {
                                                        return [];
                                                    }

                                                    $path = parse_url($url, PHP_URL_PATH) ?? '';
                                                    $path = rtrim($path, '/');
                                                    if (str_ends_with($path, '.git')) {
                                                        $path = substr($path, 0, -4);
                                                    }
                                                    $ownerRepo = ltrim($path, '/');

                                                    if ($ownerRepo === '') {
                                                        return [];
                                                    }

                                                    $instanceUrl = '';
                                                    if ($platform === 'gitlab') {
                                                        $parsed = parse_url($url);
                                                        $instanceUrl = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? 'gitlab.com');
                                                    }

                                                    try {
                                                        return app(GitServiceFactory::class)->forPlatform($platform, $token, $instanceUrl)->getBranchOptions($ownerRepo);
                                                    } catch (\Throwable $e) {
                                                        report($e);

                                                        return [];
                                                    }
                                                })
                                                ->visible(fn (Get $get): bool => ! self::isConnectedPath($get)
                                                    && is_string($get('url')) && $get('url') !== ''
                                                    && is_string($get('token')) && $get('token') !== '')
                                                ->required(fn (Get $get): bool => ! self::isConnectedPath($get)
                                                    && is_string($get('url')) && $get('url') !== ''
                                                    && is_string($get('token')) && $get('token') !== '')
                                                ->searchable()
                                                ->native(false),
                                        ]),
                                ]),  // ↑ end of Grid (Allgemein | Repository)
                        ]),  // ↑ end of "Allgemein" tab

                    Tab::make(__('projects.tabs.worker'))
                        ->icon('heroicon-o-cpu-chip')
                        ->schema([
                            // ── Worker (Stack & Agent) ──────────────────────────────────────
                            Section::make(__('projects.sections.worker'))
                                ->description(__('projects.sections.worker_description'))
                                ->visible(fn (Get $get): bool => self::platformChosen($get))
                                ->schema([
                                    Select::make('worker_stack_id')
                                        ->label(__('projects.fields.worker_stack_label'))
                                        ->helperText(__('projects.fields.worker_stack_helper'))
                                        ->options(fn (): array => self::stackOptions())
                                        ->placeholder(__('projects.fields.worker_stack_placeholder', ['stack' => (string) config('argos.compose.default_stack', 'php-8.4')]))
                                        ->searchable()
                                        ->native(false),

                                    Select::make('worker_agent_name')
                                        ->label(__('projects.fields.worker_agent_label'))
                                        ->helperText(__('projects.fields.worker_agent_helper'))
                                        ->options(fn (): array => self::agentOptions())
                                        ->placeholder(__('projects.fields.worker_agent_placeholder', ['agent' => AgentName::ClaudeCode->value]))
                                        ->live()
                                        ->afterStateUpdated(function (Set $set): void {
                                            // Different agent → previously-pinned models belong to
                                            // the old agent's spec (e.g. claude-haiku is invalid
                                            // for codex). Clear so the new agent's defaults take
                                            // over and the placeholder shows the right hint.
                                            $set('model_concept', null);
                                            $set('model_implement', null);
                                        })
                                        ->native(false),
                                ]),

                            // ── Modelle ─────────────────────────────────────────────────────
                            Section::make(__('projects.sections.models'))
                                ->visible(fn (Get $get): bool => self::platformChosen($get))
                                ->schema([
                                    Select::make('model_concept')
                                        ->label(__('projects.fields.model_concept_label'))
                                        ->options(fn (Get $get): array => self::modelOptions($get))
                                        ->placeholder(fn (Get $get): string => __(
                                            'projects.fields.model_concept_placeholder',
                                            ['model' => self::defaultModelLabel($get, 'concept')],
                                        ))
                                        ->live()
                                        ->native(false)
                                        ->helperText(__('projects.fields.model_concept_helper')),

                                    Select::make('model_implement')
                                        ->label(__('projects.fields.model_implement_label'))
                                        ->options(fn (Get $get): array => self::modelOptions($get))
                                        ->placeholder(fn (Get $get): string => __(
                                            'projects.fields.model_implement_placeholder',
                                            ['model' => self::defaultModelLabel($get, 'implement')],
                                        ))
                                        ->live()
                                        ->native(false)
                                        ->helperText(__('projects.fields.model_implement_helper')),
                                ]),
                        ]),  // ↑ end of "Worker" tab
                ]),  // ↑ end of Tabs::make()
        ]);
    }

    private static function githubAccount(): ?ConnectedAccount
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->connectedAccount('github');
    }

    private static function gitlabAccount(): ?ConnectedAccount
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->connectedAccount('gitlab');
    }

    private static function bitbucketAccount(): ?ConnectedAccount
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->connectedAccount('bitbucket');
    }

    private static function connectedAccountFor(Get $get): ?ConnectedAccount
    {
        $platform = $get('platform');

        return match ($platform) {
            'github' => self::githubAccount(),
            'gitlab' => self::gitlabAccount(),
            default => null,
        };
    }

    private static function platformChosen(Get $get): bool
    {
        $platform = $get('platform');

        return is_string($platform) && $platform !== '';
    }

    private static function hasOAuthAccount(Get $get): bool
    {
        $platform = $get('platform');
        if ($platform === 'github') {
            return self::githubAccount() !== null;
        }
        if ($platform === 'gitlab') {
            return self::gitlabAccount() !== null;
        }

        return false;
    }

    private static function authMethodOptions(Get $get): array
    {
        $platform = $get('platform');
        $oauthLabel = $platform === 'gitlab'
            ? __('projects.fields.auth_method_oauth_gitlab')
            : __('projects.fields.auth_method_oauth');

        return [
            'pat' => __('projects.fields.auth_method_pat'),
            'oauth' => $oauthLabel,
        ];
    }

    private static function isConnectedPath(Get $get): bool
    {
        return self::isGithubConnectedPath($get)
            || self::isGitlabConnectedPath($get)
            || self::isBitbucketConnectedPath($get);
    }

    private static function isGithubConnectedPath(Get $get): bool
    {
        return $get('platform') === 'github'
            && $get('auth_method') === 'oauth'
            && self::githubAccount() !== null;
    }

    private static function isGitlabConnectedPath(Get $get): bool
    {
        return $get('platform') === 'gitlab'
            && $get('auth_method') === 'oauth'
            && self::gitlabAccount() !== null;
    }

    private static function isBitbucketConnectedPath(Get $get): bool
    {
        return $get('platform') === 'bitbucket'
            && $get('auth_method') === 'oauth'
            && self::bitbucketAccount() !== null;
    }

    /**
     * Active worker stacks as a [id => label] map for the stack select.
     *
     * @return array<string, string>
     */
    private static function stackOptions(): array
    {
        return WorkerStack::query()
            ->where('status', '!=', WorkerImageEntityStatus::Disabled)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (WorkerStack $stack): array => [
                $stack->id => $stack->label !== '' ? "{$stack->label} ({$stack->name})" : $stack->name,
            ])
            ->all();
    }

    /**
     * Registered agents as a [name => label] map for the agent select.
     *
     * @return array<string, string>
     */
    private static function agentOptions(): array
    {
        return collect(app(AgentRegistry::class)->specs())
            ->mapWithKeys(fn ($spec): array => [$spec->name->value => $spec->label])
            ->all();
    }

    /**
     * Models offered by the currently-selected agent (or by Claude Code as
     * fallback when nothing is chosen yet) as a [id => label] map.
     *
     * @return array<string, string>
     */
    private static function modelOptions(Get $get): array
    {
        $agentValue = $get('worker_agent_name');
        $agent = is_string($agentValue) && $agentValue !== ''
            ? AgentName::tryFrom($agentValue)
            : null;
        $agent ??= AgentName::ClaudeCode;

        return $agent->spec()->availableModels;
    }

    /**
     * Default model label for the selected agent + phase, used as Select
     * placeholder. Returns the model id when no human label is registered.
     */
    private static function defaultModelLabel(Get $get, string $phase): string
    {
        $agentValue = $get('worker_agent_name');
        $agent = is_string($agentValue) && $agentValue !== ''
            ? AgentName::tryFrom($agentValue)
            : null;
        $agent ??= AgentName::ClaudeCode;

        $spec = $agent->spec();
        $modelId = $spec->defaultModel($phase) ?? '';

        return $spec->availableModels[$modelId] ?? $modelId;
    }

    /**
     * Im OAuth-Pfad schreiben die sichtbaren Picker in `github_repo` /
     * `github_branch` (GitHub) oder `gitlab_repo` / `gitlab_branch` (GitLab);
     * die DB-Spalten heißen aber `url` und `default_branch`.
     * Diese Methode mappt die Werte zurück und entfernt die Helper-Keys aus
     * den Form-Daten — aufgerufen aus CreateRepoProfile (BeforeCreate) und
     * EditRepoProfile (BeforeSave).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateOauthFields(array $data): array
    {
        // Ensure auth_method has a value (e.g. when the auth section is hidden)
        if (! isset($data['auth_method']) || $data['auth_method'] === '') {
            $data['auth_method'] = 'pat';
        }

        // GitHub OAuth path: map github_repo/github_branch → url/default_branch
        if (isset($data['github_repo']) && is_string($data['github_repo']) && $data['github_repo'] !== '') {
            $data['url'] = "https://github.com/{$data['github_repo']}";
        }

        if (isset($data['github_branch']) && is_string($data['github_branch']) && $data['github_branch'] !== '') {
            $data['default_branch'] = $data['github_branch'];
        }

        // GitLab OAuth path: map gitlab_repo/gitlab_branch → url/default_branch
        if (isset($data['gitlab_repo']) && is_string($data['gitlab_repo']) && $data['gitlab_repo'] !== '') {
            $account = self::gitlabAccount();
            $instanceUrl = $account?->getInstanceUrl() ?? 'https://gitlab.com';
            $data['url'] = "{$instanceUrl}/{$data['gitlab_repo']}";
        }

        if (isset($data['gitlab_branch']) && is_string($data['gitlab_branch']) && $data['gitlab_branch'] !== '') {
            $data['default_branch'] = $data['gitlab_branch'];
        }

        // Bitbucket OAuth path: map bitbucket_repo/bitbucket_branch → url/default_branch
        if (isset($data['bitbucket_repo']) && is_string($data['bitbucket_repo']) && $data['bitbucket_repo'] !== '') {
            $data['url'] = "https://bitbucket.org/{$data['bitbucket_repo']}";
        }

        if (isset($data['bitbucket_branch']) && is_string($data['bitbucket_branch']) && $data['bitbucket_branch'] !== '') {
            $data['default_branch'] = $data['bitbucket_branch'];
        }

        // Clear token when using oauth
        if ($data['auth_method'] === 'oauth') {
            $data['token'] = null;
        }

        // Clear connected_account_id when using pat
        if ($data['auth_method'] === 'pat') {
            $data['connected_account_id'] = null;
        }

        unset(
            $data['github_repo'],
            $data['github_branch'],
            $data['gitlab_repo'],
            $data['gitlab_branch'],
            $data['bitbucket_repo'],
            $data['bitbucket_branch'],
        );

        return $data;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('projects.sections.general'))
                ->schema([
                    TextEntry::make('name')->label(__('projects.infolist.project_name')),

                    TextEntry::make('platform')
                        ->label(__('projects.infolist.platform'))
                        ->badge()
                        ->color(fn (GitProvider $state): string => $state->color())
                        ->formatStateUsing(fn (GitProvider $state): string => $state->label()),

                    TextEntry::make('auth_method')
                        ->label(__('projects.infolist.authentication'))
                        ->badge()
                        ->color(fn (AuthMethod $state): string => $state->color())
                        ->formatStateUsing(fn (AuthMethod $state): string => $state->label()),

                    IconEntry::make('auto_concept')
                        ->label(__('projects.infolist.auto_concept'))
                        ->boolean(),

                    IconEntry::make('auto_pr')
                        ->label(__('projects.infolist.auto_pr'))
                        ->boolean(),

                    TextEntry::make('workerStack.label')
                        ->label(__('projects.infolist.worker_stack'))
                        ->placeholder(__('projects.infolist.worker_stack_placeholder', ['stack' => (string) config('argos.compose.default_stack', 'php-8.4')])),

                    TextEntry::make('worker_agent_name')
                        ->label(__('projects.infolist.worker_agent'))
                        ->formatStateUsing(fn (?AgentName $state): string => $state?->value ?? AgentName::ClaudeCode->value)
                        ->placeholder(AgentName::ClaudeCode->value),
                ]),

            Section::make(__('projects.sections.repository'))
                ->schema([
                    TextEntry::make('url')
                        ->label(__('projects.infolist.repo_url'))
                        ->copyable(),

                    TextEntry::make('default_branch')
                        ->label(__('projects.infolist.default_branch')),

                    TextEntry::make('token')
                        ->label(__('projects.infolist.token'))
                        ->state(fn (RepoProfile $record): string => $record->getRawOriginal('token') !== null ? '••••••••' : '—'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('platform')
                    ->badge()
                    ->color(fn (GitProvider $state): string => $state->color())
                    ->formatStateUsing(fn (GitProvider $state): string => $state->label()),

                TextColumn::make('default_branch')
                    ->label(__('projects.columns.branch')),

                TextColumn::make('url')
                    ->copyable()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tasks_count')
                    ->label(__('projects.columns.tasks'))
                    ->counts('tasks'),
            ])
            ->recordUrl(fn (RepoProfile $record): string => static::getUrl('view', ['record' => $record]))
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            TasksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepoProfiles::route('/'),
            'create' => CreateRepoProfile::route('/create'),
            'view' => ViewRepoProfile::route('/{record}'),
            'edit' => EditRepoProfile::route('/{record}/edit'),
        ];
    }
}
