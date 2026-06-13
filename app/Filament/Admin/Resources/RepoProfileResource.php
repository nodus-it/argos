<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AgentName;
use App\Enums\AuthMethod;
use App\Enums\BackingService;
use App\Enums\GitProvider;
use App\Enums\WorkerImageEntityStatus;
use App\Enums\WorkerSource;
use App\Filament\Admin\RelationManagers\ApiTokensRelationManager;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Filament\Admin\Resources\RepoProfileResource\RelationManagers\TaskProviderBindingsRelationManager;
use App\Filament\Admin\Resources\RepoProfileResource\RelationManagers\TasksRelationManager;
use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\User;
use App\Models\WorkerStack;
use App\Services\Git\RepositoryFetcher;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\OAuth\ConnectedAccountService;
use App\Services\OAuth\TokenRefresher;
use App\Support\DocLink;
use App\Support\DocsLinkAction;
use App\Support\RepoUrlBuilder;
use App\Workers\Agents\AgentRegistry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
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
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return __('projects.navigation_label');
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
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
                                ->icon('heroicon-o-globe-alt')
                                ->aside()
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
                                            .' <a href="'.e(DocLink::url($get('platform') ?: 'github'))
                                            .'" target="_blank" rel="noopener" class="underline">'
                                            .e((string) __('projects.platform_hints.docs_link')).'</a>'
                                        )),
                                ]),

                            // ── Block 3a ─ Authentifizierung (GitHub/GitLab mit OAuth-Account) ─
                            Section::make(__('projects.sections.authentication'))
                                ->description(__('projects.sections.authentication_description'))
                                ->icon('heroicon-o-key')
                                ->aside()
                                ->visible(fn (Get $get): bool => self::hasOAuthAccount($get))
                                ->schema([
                                    Select::make('auth_method')
                                        ->label(__('projects.fields.auth_method_label'))
                                        ->hintAction(DocsLinkAction::make('credentials'))
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

                                            return app(ConnectedAccountService::class)
                                                ->selectableFor($user, [$provider])
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
                                ->description(__('projects.sections.authentication_description'))
                                ->icon('heroicon-o-key')
                                ->aside()
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

                                            return app(ConnectedAccountService::class)
                                                ->selectableFor($user, ['bitbucket'])
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

                            // ── Block 2 + Block 4 gestapelt (Allgemein + Repository) ─────────
                            Grid::make(1)
                                ->columnSpanFull()
                                ->schema([
                                    Section::make(__('projects.sections.general'))
                                        ->description(__('projects.sections.general_description'))
                                        ->icon('heroicon-o-adjustments-horizontal')
                                        ->aside()
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
                                        ->description(__('projects.sections.repository_description'))
                                        ->icon('heroicon-o-code-bracket-square')
                                        ->aside()
                                        ->visible(fn (Get $get): bool => self::platformChosen($get))
                                        ->columnSpan(1)
                                        ->schema([
                                            // Connected-Pfad: GitHub / GitLab / Bitbucket mit OAuth-Account.
                                            // Ein gemeinsames Feld-Paar für alle Provider — die
                                            // Provider-Unterschiede (Account, Service, URL-Aufbau)
                                            // kapseln die self::connected*-Helper.
                                            Select::make('oauth_repo')
                                                ->label(__('projects.infolist.repo_url'))
                                                ->options(fn (Get $get): array => self::connectedRepoOptions($get))
                                                ->required(fn (Get $get): bool => self::isConnectedPath($get))
                                                ->searchable()
                                                ->live()
                                                ->afterStateUpdated(function (Set $set, Get $get, ?string $state): void {
                                                    if ($state === null || $state === '') {
                                                        return;
                                                    }
                                                    $platform = is_string($get('platform')) ? $get('platform') : '';
                                                    $account = self::connectedAccountForPlatform($platform);
                                                    $set('url', self::connectedRepoUrl($platform, $state, $account));

                                                    if (! is_string($get('name')) || $get('name') === '') {
                                                        $shortName = explode('/', $state)[count(explode('/', $state)) - 1];
                                                        $set('name', $shortName);
                                                    }

                                                    $apiDefault = self::connectedDefaultBranch($platform, $state, $account);
                                                    if ($apiDefault !== null) {
                                                        $set('oauth_branch', $apiDefault);
                                                        $set('default_branch', $apiDefault);
                                                    }
                                                })
                                                ->visible(fn (Get $get): bool => self::isConnectedPath($get))
                                                ->dehydrated(fn (Get $get): bool => self::isConnectedPath($get)),

                                            Select::make('oauth_branch')
                                                ->label(__('projects.fields.default_branch_label'))
                                                ->options(fn (Get $get): array => self::connectedBranchOptions($get))
                                                ->required(fn (Get $get): bool => self::isConnectedPath($get))
                                                ->searchable()
                                                ->live()
                                                ->visible(fn (Get $get): bool => self::isConnectedPath($get) && is_string($get('oauth_repo')) && $get('oauth_repo') !== '')
                                                ->dehydrated(fn (Get $get): bool => self::isConnectedPath($get)),

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
                                ->icon('heroicon-o-cpu-chip')
                                ->aside()
                                ->visible(fn (Get $get): bool => self::platformChosen($get))
                                ->schema([
                                    Select::make('worker_source')
                                        ->label(__('projects.fields.worker_source_label'))
                                        ->helperText(__('projects.fields.worker_source_helper'))
                                        ->hintAction(DocsLinkAction::make('byoi'))
                                        ->options([
                                            WorkerSource::Standard->value => __('projects.fields.worker_source_standard'),
                                            WorkerSource::Byoi->value => __('projects.fields.worker_source_byoi'),
                                        ])
                                        ->default(WorkerSource::Standard->value)
                                        ->required()
                                        ->live()
                                        ->native(false),

                                    Callout::make(__('projects.fields.worker_byoi_hint_heading'))
                                        ->visible(fn (Get $get): bool => $get('worker_source') === WorkerSource::Byoi->value)
                                        ->color('info')
                                        ->icon('heroicon-o-information-circle')
                                        ->description(__('projects.fields.worker_byoi_hint_body')),

                                    Select::make('worker_stack_id')
                                        ->label(__('projects.fields.worker_stack_label'))
                                        ->helperText(__('projects.fields.worker_stack_helper'))
                                        ->options(fn (): array => self::stackOptions())
                                        ->placeholder(__('projects.fields.worker_stack_placeholder', ['stack' => (string) config('argos.compose.default_stack', 'php-8.4')]))
                                        ->searchable()
                                        ->native(false)
                                        // Hidden for BYOI — the repo defines its own image.
                                        ->visible(fn (Get $get): bool => $get('worker_source') !== WorkerSource::Byoi->value),

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

                                    CheckboxList::make('worker_services')
                                        ->label(__('projects.fields.worker_services_label'))
                                        ->helperText(__('projects.fields.worker_services_helper'))
                                        ->options([
                                            BackingService::Mysql->value => BackingService::Mysql->label(),
                                            BackingService::Redis->value => BackingService::Redis->label(),
                                        ])
                                        ->live()
                                        ->columns(2),

                                    // MySQL credentials — configurable so the
                                    // worker AND demo can match a name the
                                    // project hardcodes. Empty = the argos
                                    // defaults.
                                    Grid::make(3)
                                        ->visible(fn (Get $get): bool => in_array('mysql', (array) $get('worker_services'), true))
                                        ->schema([
                                            TextInput::make('worker_service_config.mysql.database')
                                                ->label(__('projects.fields.mysql_database_label'))
                                                ->placeholder('argos'),
                                            TextInput::make('worker_service_config.mysql.username')
                                                ->label(__('projects.fields.mysql_username_label'))
                                                ->placeholder('argos'),
                                            TextInput::make('worker_service_config.mysql.password')
                                                ->label(__('projects.fields.mysql_password_label'))
                                                ->placeholder('argos')
                                                ->password()
                                                ->revealable(),
                                        ]),
                                ]),

                            // ── Live-Demo ───────────────────────────────────────────────────
                            Section::make(__('projects.sections.live_demo'))
                                ->visible(fn (Get $get): bool => self::platformChosen($get))
                                ->schema([
                                    Toggle::make('live_demo_enabled')
                                        ->label(__('projects.fields.live_demo_label'))
                                        ->helperText(__('projects.fields.live_demo_helper'))
                                        ->default(false)
                                        ->live(),

                                    Callout::make(__('projects.fields.live_demo_hint_heading'))
                                        ->visible(fn (Get $get): bool => (bool) $get('live_demo_enabled'))
                                        ->color('info')
                                        ->icon('heroicon-o-information-circle')
                                        ->description(__('projects.fields.live_demo_hint_body')),
                                ]),

                            // ── Environment & Secrets ──────────────────────────────────────
                            Section::make(__('projects.sections.env_secrets'))
                                ->description(__('projects.sections.env_secrets_description'))
                                ->icon('heroicon-o-key')
                                ->aside()
                                ->visible(fn (Get $get): bool => self::platformChosen($get))
                                ->schema([
                                    Repeater::make('composer_registries')
                                        ->label(__('projects.fields.composer_registries_label'))
                                        ->helperText(__('projects.fields.composer_registries_helper'))
                                        ->schema([
                                            TextInput::make('host')
                                                ->label(__('projects.fields.composer_registry_host_label'))
                                                ->placeholder('packages.filamentphp.com')
                                                ->required()
                                                ->columnSpan(2),
                                            TextInput::make('username')
                                                ->label(__('projects.fields.composer_registry_username_label'))
                                                ->placeholder('token')
                                                ->columnSpan(1),
                                            TextInput::make('token')
                                                ->label(__('projects.fields.composer_registry_token_label'))
                                                ->password()
                                                ->revealable()
                                                ->required()
                                                ->columnSpan(2),
                                        ])
                                        ->columns(5)
                                        ->addActionLabel(__('projects.fields.composer_registries_add'))
                                        ->itemLabel(fn (array $state): ?string => $state['host'] ?? null)
                                        ->collapsible()
                                        ->defaultItems(0),

                                    // Discoverability for the ${service.key}
                                    // placeholders — reactive to which backing
                                    // services are enabled, so a non-standard
                                    // project knows exactly what it can reference
                                    // in the values below.
                                    Callout::make(__('projects.fields.env_placeholders_heading'))
                                        ->visible(fn (Get $get): bool => self::availablePlaceholders($get) !== [])
                                        ->color('gray')
                                        ->icon('heroicon-o-code-bracket')
                                        ->description(fn (Get $get): string => __('projects.fields.env_placeholders_body').' '.implode('   ', self::availablePlaceholders($get))),

                                    Repeater::make('worker_env')
                                        ->label(__('projects.fields.worker_env_label'))
                                        ->helperText(__('projects.fields.worker_env_helper'))
                                        ->schema([
                                            TextInput::make('name')
                                                ->label(__('projects.fields.worker_env_name_label'))
                                                ->placeholder('MEILISEARCH_KEY')
                                                ->required()
                                                ->columnSpan(2),
                                            TextInput::make('value')
                                                ->label(__('projects.fields.worker_env_value_label'))
                                                ->password()
                                                ->revealable()
                                                ->columnSpan(3),
                                        ])
                                        ->columns(5)
                                        ->addActionLabel(__('projects.fields.worker_env_add'))
                                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                                        ->collapsible()
                                        ->defaultItems(0),
                                ]),

                            // ── Modelle ─────────────────────────────────────────────────────
                            Section::make(__('projects.sections.models'))
                                ->description(__('projects.sections.models_description'))
                                ->icon('heroicon-o-sparkles')
                                ->aside()
                                ->visible(fn (Get $get): bool => self::platformChosen($get))
                                ->schema([
                                    // The default-model hint lives in helperText (text inside a
                                    // span) instead of placeholder (an HTML attribute). Livewire
                                    // diffs reliably update text nodes; placeholder attributes
                                    // get frozen on first render and won't change when the agent
                                    // switches even though the server re-evaluates the closure.
                                    Select::make('model_concept')
                                        ->label(__('projects.fields.model_concept_label'))
                                        ->options(fn (Get $get): array => self::modelOptions($get))
                                        ->placeholder(__('projects.fields.model_placeholder_neutral'))
                                        ->helperText(fn (Get $get): string => __(
                                            'projects.fields.model_concept_helper_with_default',
                                            ['model' => self::defaultModelLabel($get, 'concept')],
                                        ))
                                        ->live()
                                        ->native(false),

                                    Select::make('model_implement')
                                        ->label(__('projects.fields.model_implement_label'))
                                        ->options(fn (Get $get): array => self::modelOptions($get))
                                        ->placeholder(__('projects.fields.model_placeholder_neutral'))
                                        ->helperText(fn (Get $get): string => __(
                                            'projects.fields.model_implement_helper_with_default',
                                            ['model' => self::defaultModelLabel($get, 'implement')],
                                        ))
                                        ->live()
                                        ->native(false),

                                    TextInput::make('max_turns_concept')
                                        ->label(__('projects.fields.max_turns_concept_label'))
                                        ->helperText(__('projects.fields.max_turns_concept_helper', [
                                            'default' => (int) config('argos.concept.max_turns_default', 50),
                                        ]))
                                        ->numeric()
                                        ->minValue(10)
                                        ->maxValue(1000)
                                        ->nullable(),

                                    TextInput::make('max_turns_implement')
                                        ->label(__('projects.fields.max_turns_implement_label'))
                                        ->helperText(__('projects.fields.max_turns_implement_helper', [
                                            'default' => (int) config('argos.implement.max_turns_default', 200),
                                        ]))
                                        ->numeric()
                                        ->minValue(10)
                                        ->maxValue(1000)
                                        ->nullable(),
                                ]),
                        ]),  // ↑ end of "Worker" tab
                ]),  // ↑ end of Tabs::make()
        ]);
    }

    /**
     * The `${service.key}` placeholders available given the currently-enabled
     * backing services — drives the reactive hint in the secrets section.
     *
     * @return list<string>
     */
    private static function availablePlaceholders(Get $get): array
    {
        $tokens = [];
        foreach ((array) $get('worker_services') as $value) {
            $service = BackingService::tryFrom((string) $value);
            if ($service === null) {
                continue;
            }
            foreach (array_keys($service->defaultCoordinates()) as $coordKey) {
                $tokens[] = '${'.$service->value.'.'.$coordKey.'}';
            }
        }

        return $tokens;
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

        return self::connectedAccountForPlatform(is_string($platform) ? $platform : '');
    }

    /**
     * The OAuth-connected account for a given platform string, or null when
     * the user has none connected (or the platform is unknown).
     */
    private static function connectedAccountForPlatform(string $platform): ?ConnectedAccount
    {
        return match ($platform) {
            'github' => self::githubAccount(),
            'gitlab' => self::gitlabAccount(),
            'bitbucket' => self::bitbucketAccount(),
            default => null,
        };
    }

    /**
     * Build the provider git service for an OAuth-connected account. GitLab
     * needs the account's self-hosted instance URL; the others ignore it.
     */
    /**
     * Resolve the connected OAuth account into a git source for RepositoryFetcher.
     * Refreshes an expiring token so the repo/branch dropdowns keep loading
     * instead of failing once the token lapses.
     *
     * @return array{token: string, instance_url: string}
     */
    private static function sourceForConnected(string $platform, ConnectedAccount $account): array
    {
        $account = app(TokenRefresher::class)->refreshIfNeeded($account);

        return [
            'token' => $account->token,
            'instance_url' => $platform === 'gitlab' ? $account->getInstanceUrl() : '',
        ];
    }

    /**
     * Repository options for the connected OAuth account, cached for 60s.
     *
     * The cache key is platform + account id only — never the token, and the
     * options are identical for every form render of the same account, so
     * this collapses the repeated `/user/repos` API hit on each Livewire
     * round-trip to one call per minute.
     *
     * @return array<string, string>
     */
    private static function connectedRepoOptions(Get $get): array
    {
        $platform = is_string($get('platform')) ? $get('platform') : '';
        $account = self::connectedAccountForPlatform($platform);
        if ($platform === '' || $account === null) {
            return [];
        }

        $source = self::sourceForConnected($platform, $account);

        return app(RepositoryFetcher::class)->repoOptions(
            $platform,
            $source['token'],
            $source['instance_url'],
            "git_repo_options:{$platform}:{$account->id}",
        );
    }

    /**
     * Branch options for the repo picked under the connected OAuth account,
     * cached for 60s. Key is platform + account id + repo path — never token.
     *
     * @return array<string, string>
     */
    private static function connectedBranchOptions(Get $get): array
    {
        $repo = $get('oauth_repo');
        if (! is_string($repo) || $repo === '') {
            return [];
        }
        $platform = is_string($get('platform')) ? $get('platform') : '';
        $account = self::connectedAccountForPlatform($platform);
        if ($platform === '' || $account === null) {
            return [];
        }

        $source = self::sourceForConnected($platform, $account);

        return app(RepositoryFetcher::class)->branchOptions(
            $platform,
            $source['token'],
            $source['instance_url'],
            $repo,
            "git_branch_options:{$platform}:{$account->id}:{$repo}",
        );
    }

    /**
     * The API-reported default branch for a repo under the connected account,
     * or null on failure. Not cached — it fires once on repo selection.
     */
    private static function connectedDefaultBranch(string $platform, string $repo, ?ConnectedAccount $account): ?string
    {
        if ($platform === '' || $account === null) {
            return null;
        }

        $source = self::sourceForConnected($platform, $account);

        return app(RepositoryFetcher::class)->defaultBranch($platform, $source['token'], $source['instance_url'], $repo);
    }

    /**
     * Build the canonical clone URL for an OAuth-picked "owner/repo" path.
     * GitLab honours the account's self-hosted instance host; GitHub and
     * Bitbucket use their public hosts.
     */
    private static function connectedRepoUrl(string $platform, string $repo, ?ConnectedAccount $account): string
    {
        return RepoUrlBuilder::build($platform, $repo, $account?->getInstanceUrl());
    }

    /**
     * Extract the "owner/repo" (or GitLab nested "group/sub/repo") path from a
     * persisted clone URL, so the edit form can pre-select the OAuth picker.
     * Returns null when the URL has no usable path.
     */
    public static function repoPathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }

        $path = rtrim($path, '/');
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }
        $path = ltrim($path, '/');

        return $path !== '' ? $path : null;
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
        return self::agentFromState($get)->spec()->availableModels;
    }

    /**
     * Default model label for the selected agent + phase, used as Select
     * placeholder. Returns the model id when no human label is registered.
     */
    private static function defaultModelLabel(Get $get, string $phase): string
    {
        $spec = self::agentFromState($get)->spec();
        $modelId = $spec->defaultModel($phase) ?? '';

        return $spec->availableModels[$modelId] ?? $modelId;
    }

    /**
     * Resolve the agent currently selected in the form, falling back to
     * Claude Code when nothing is set yet. Accepts both string state
     * (Filament Select default) and AgentName enum instances (in case the
     * model cast leaks through into the form state).
     */
    private static function agentFromState(Get $get): AgentName
    {
        $value = $get('worker_agent_name');

        if ($value instanceof AgentName) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return AgentName::tryFrom($value) ?? AgentName::ClaudeCode;
        }

        return AgentName::ClaudeCode;
    }

    /**
     * Im OAuth-Pfad schreiben die sichtbaren Picker in das gemeinsame Feld
     * `oauth_repo` / `oauth_branch`; die DB-Spalten heißen aber `url` und
     * `default_branch`. Diese Methode mappt die Werte zurück (der URL-Aufbau
     * hängt am gewählten Provider) und entfernt die Helper-Keys aus den
     * Form-Daten — aufgerufen aus CreateRepoProfile (BeforeCreate) und
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

        $platform = is_string($data['platform'] ?? null) ? $data['platform'] : '';

        // OAuth path: map oauth_repo/oauth_branch → url/default_branch
        if (isset($data['oauth_repo']) && is_string($data['oauth_repo']) && $data['oauth_repo'] !== '') {
            $account = self::connectedAccountForPlatform($platform);
            $data['url'] = self::connectedRepoUrl($platform, $data['oauth_repo'], $account);
        }

        if (isset($data['oauth_branch']) && is_string($data['oauth_branch']) && $data['oauth_branch'] !== '') {
            $data['default_branch'] = $data['oauth_branch'];
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
            $data['oauth_repo'],
            $data['oauth_branch'],
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
            ->recordUrl(fn (RepoProfile $record): string => static::getUrl('edit', ['record' => $record]))
            ->actions([
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
            TaskProviderBindingsRelationManager::class,
            ApiTokensRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRepoProfiles::route('/'),
            'create' => CreateRepoProfile::route('/create'),
            // Detail = edit (no separate read-only view); relation managers
            // render on the edit page.
            'edit' => EditRepoProfile::route('/{record}'),
        ];
    }
}
