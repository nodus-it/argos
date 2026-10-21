<?php

declare(strict_types=1);

namespace App\Filament\Shared;

use App\Enums\AutoPlanMode;
use App\Enums\AutoShipMode;
use App\Enums\BackingService;
use App\Enums\WorkerImageEntityStatus;
use App\Enums\WorkerSource;
use App\Models\ConnectedAccount;
use App\Models\User;
use App\Models\WorkerStack;
use App\Models\Workspace;
use App\Services\Git\RepositoryFetcher;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\OAuth\ConnectedAccountService;
use App\Services\OAuth\TokenRefresher;
use App\Services\Workspace\AutoSettings;
use App\Support\DocLink;
use App\Support\DocsLinkAction;
use App\Support\RepoUrlBuilder;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * The workspace form, shared by the admin panel (create/edit) and the
 * workspaces panel (/w create page and the settings page).
 *
 * Creation and the admin edit page use {@see self::tabs()}: platform first,
 * everything else unlocks once one is chosen. The /w settings page composes
 * the single sections per tab and passes `$gated = false` — the platform is
 * fixed after creation, so nothing waits for it there.
 */
final class WorkspaceForm
{
    /** The tabbed create/edit form (platform → access → repository first). */
    public static function tabs(): Tabs
    {
        return Tabs::make()
            ->columnSpanFull()
            ->tabs([
                Tab::make(__('workspaces.settings.tabs.general'))
                    ->icon('heroicon-o-folder')
                    ->schema([
                        self::platformSection(),
                        ...self::authenticationSections(),
                        Grid::make(1)
                            ->columnSpanFull()
                            ->schema([
                                self::generalSection(),
                                self::repositorySection(),
                            ]),
                    ]),

                Tab::make(__('workspaces.settings.tabs.environment'))
                    ->icon('heroicon-o-cpu-chip')
                    ->schema([
                        self::workerSection(),
                        self::liveDemoSection(),
                        self::envSecretsSection(),
                    ]),

                Tab::make(__('workspaces.settings.tabs.resources'))
                    ->icon('heroicon-o-adjustments-vertical')
                    ->schema([self::resourcesSection()]),

                Tab::make(__('workspaces.settings.tabs.automation'))
                    ->icon('heroicon-o-bolt')
                    ->schema([self::automationSection()]),
            ]);
    }

    /** Platform select (gates everything else in the create flow). */
    public static function platformSection(): Section
    {
        return Section::make(__('projects.sections.platform'))
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
            ]);
    }

    /**
     * Authentication: one section for GitHub/GitLab with a connected account,
     * one for Bitbucket with one. Hidden without a connected account (PAT).
     *
     * @return list<Section>
     */
    public static function authenticationSections(): array
    {
        return [
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
        ];
    }

    /** Name and branch pattern. */
    public static function generalSection(bool $gated = true): Section
    {
        return Section::make(__('projects.sections.general'))
            ->description(__('workspaces.settings.general_description'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->aside()
            ->visible(self::gate($gated))
            ->columnSpan(1)
            ->schema([
                TextInput::make('name')
                    ->label(__('workspaces.fields.name'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('branch_pattern')
                    ->label(__('workspaces.settings.fields.branch_pattern'))
                    ->helperText(__('workspaces.settings.fields.branch_pattern_helper'))
                    ->placeholder('argos/{title}')
                    ->maxLength(60)
                    // TaskNamer replaces exactly these two placeholders.
                    ->rule('regex:/^[A-Za-z0-9._\/{}-]*$/')
                    ->nullable(),
            ]);
    }

    /**
     * Repository picker for creation: the OAuth repo/branch pickers with a
     * connected account, else URL + token + branch select.
     */
    public static function repositorySection(): Section
    {
        return Section::make(__('projects.sections.repository'))
            ->description(__('projects.sections.repository_description'))
            ->icon('heroicon-o-code-bracket-square')
            ->aside()
            ->visible(fn (Get $get): bool => self::platformChosen($get))
            ->columnSpan(1)
            ->schema([
                // Connected path: GitHub / GitLab / Bitbucket with an OAuth
                // account. One field pair for every provider — the provider
                // differences (account, service, URL shape) live in the
                // connected* helpers.
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
                        $set('repo_url', self::connectedRepoUrl($platform, $state, $account));

                        if (! is_string($get('name')) || $get('name') === '') {
                            $segments = explode('/', $state);
                            $set('name', end($segments));
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

                // Manual path: GitLab, GitHub without OAuth, or Bitbucket without OAuth.
                TextInput::make('repo_url')
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
                    ->helperText(fn (Get $get): HtmlString => self::tokenHelper($get))
                    ->visible(fn (Get $get): bool => ! self::isConnectedPath($get)),

                Select::make('default_branch')
                    ->label(__('projects.fields.default_branch_label'))
                    ->options(fn (Get $get): array => self::manualBranchOptions($get))
                    ->visible(fn (Get $get): bool => self::hasManualRepoAccess($get))
                    ->required(fn (Get $get): bool => self::hasManualRepoAccess($get))
                    ->searchable()
                    ->native(false),
            ]);
    }

    /**
     * The repository as the settings page shows it: address fixed (changing
     * it would orphan every task branch), default branch editable.
     */
    public static function repositorySummarySection(): Section
    {
        return Section::make(__('projects.sections.repository'))
            ->description(__('workspaces.settings.repository_description'))
            ->icon('heroicon-o-code-bracket-square')
            ->aside()
            ->schema([
                TextEntry::make('repository')
                    ->label(__('workspaces.fields.repository'))
                    ->state(fn (?Workspace $record): ?string => $record?->repo_url)
                    ->fontFamily('mono')
                    ->copyable(),
                TextEntry::make('platform_label')
                    ->label(__('projects.fields.platform'))
                    ->state(fn (?Workspace $record): ?string => $record?->platform?->label())
                    ->badge(),
                TextInput::make('default_branch')
                    ->label(__('projects.fields.default_branch_label'))
                    ->helperText(__('workspaces.settings.fields.default_branch_helper'))
                    ->required()
                    ->maxLength(255),
            ]);
    }

    /** Worker image source, stack and backing services. */
    public static function workerSection(bool $gated = true): Section
    {
        return Section::make(__('projects.sections.worker'))
            ->description(__('projects.sections.worker_description'))
            ->icon('heroicon-o-cpu-chip')
            ->aside()
            ->visible(self::gate($gated))
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

                CheckboxList::make('worker_services')
                    ->label(__('projects.fields.worker_services_label'))
                    ->helperText(__('projects.fields.worker_services_helper'))
                    ->options([
                        BackingService::Mysql->value => BackingService::Mysql->label(),
                        BackingService::Redis->value => BackingService::Redis->label(),
                    ])
                    ->live()
                    ->columns(2),

                // MySQL credentials — configurable so the worker AND the demo
                // can match a name the project hardcodes. Empty = the Argos
                // defaults.
                Grid::make(['default' => 1, 'sm' => 3])
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
            ]);
    }

    /** Live preview on/off. */
    public static function liveDemoSection(bool $gated = true): Section
    {
        return Section::make(__('projects.sections.live_demo'))
            ->visible(self::gate($gated))
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
            ]);
    }

    /** Composer registries and env secrets. */
    public static function envSecretsSection(bool $gated = true): Section
    {
        return Section::make(__('projects.sections.env_secrets'))
            ->description(__('projects.sections.env_secrets_description'))
            ->icon('heroicon-o-key')
            ->aside()
            ->visible(self::gate($gated))
            ->schema([
                Repeater::make('composer_registries')
                    ->label(__('projects.fields.composer_registries_label'))
                    ->helperText(__('projects.fields.composer_registries_helper'))
                    ->schema([
                        TextInput::make('host')
                            ->label(__('projects.fields.composer_registry_host_label'))
                            ->placeholder('packages.filamentphp.com')
                            ->required()
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        TextInput::make('username')
                            ->label(__('projects.fields.composer_registry_username_label'))
                            ->placeholder('token')
                            ->columnSpan(1),
                        TextInput::make('token')
                            ->label(__('projects.fields.composer_registry_token_label'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                    ])
                    ->columns(['default' => 1, 'sm' => 5])
                    ->addActionLabel(__('projects.fields.composer_registries_add'))
                    ->itemLabel(fn (array $state): ?string => $state['host'] ?? null)
                    ->collapsible()
                    ->defaultItems(0),

                // Discoverability for the ${service.key} placeholders — reactive
                // to which backing services are enabled, so a non-standard
                // project knows exactly what it can reference below.
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
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                        TextInput::make('value')
                            ->label(__('projects.fields.worker_env_value_label'))
                            ->password()
                            ->revealable()
                            ->columnSpan(['default' => 1, 'sm' => 3]),
                    ])
                    ->columns(['default' => 1, 'sm' => 5])
                    ->addActionLabel(__('projects.fields.worker_env_add'))
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->collapsible()
                    ->defaultItems(0),
            ]);
    }

    /** Setup state (read-only) and where the setup commands live. */
    public static function setupSection(): Section
    {
        return Section::make(__('workspaces.settings.sections.setup'))
            ->description(__('workspaces.settings.sections.setup_description'))
            ->icon('heroicon-o-wrench-screwdriver')
            ->aside()
            ->schema([
                TextEntry::make('setup_state')
                    ->label(__('workspaces.settings.fields.setup_status'))
                    ->state(fn (?Workspace $record): string => self::setupStatusLabel($record?->setup_status))
                    ->badge()
                    ->color(fn (?Workspace $record): string => in_array($record?->setup_status, [null, ''], true) ? 'gray' : 'info'),
                Callout::make(__('workspaces.settings.fields.setup_commands_heading'))
                    ->color('gray')
                    ->icon('heroicon-o-command-line')
                    ->description(__('workspaces.settings.fields.setup_commands_body')),
            ]);
    }

    /** Container and preview limits, cleanup of finished tasks. */
    public static function resourcesSection(): Section
    {
        return Section::make(__('workspaces.settings.sections.resources'))
            ->description(__('workspaces.settings.sections.resources_description'))
            ->icon('heroicon-o-adjustments-vertical')
            ->aside()
            ->schema([
                TextInput::make('max_sessions')
                    ->label(__('workspaces.settings.fields.max_sessions'))
                    ->helperText(__('workspaces.settings.fields.max_sessions_helper'))
                    ->numeric()->integer()->minValue(1)->maxValue(20)
                    ->default(3)
                    ->required(),
                TextInput::make('max_previews')
                    ->label(__('workspaces.settings.fields.max_previews'))
                    ->helperText(__('workspaces.settings.fields.max_previews_helper'))
                    ->numeric()->integer()->minValue(1)->maxValue(20)
                    ->default(3)
                    ->required(),
                TextInput::make('cpu_limit')
                    ->label(__('workspaces.settings.fields.cpu_limit'))
                    ->helperText(__('workspaces.settings.fields.cpu_limit_helper'))
                    ->placeholder(__('workspaces.settings.fields.default_value', ['value' => (string) config('argos.workspaces.cpus', '2')]))
                    ->numeric()->minValue(0.25)->maxValue(64)->step(0.25)
                    ->nullable(),
                TextInput::make('memory_limit_mb')
                    ->label(__('workspaces.settings.fields.memory_limit_mb'))
                    ->helperText(__('workspaces.settings.fields.memory_limit_mb_helper'))
                    ->placeholder(__('workspaces.settings.fields.default_value', ['value' => (string) config('argos.workspaces.memory', '4g')]))
                    ->numeric()->integer()->minValue(512)->maxValue(262144)
                    ->suffix('MB')
                    ->nullable(),
                TextInput::make('cleanup_after_days')
                    ->label(__('workspaces.settings.fields.cleanup_after_days'))
                    ->helperText(__('workspaces.settings.fields.cleanup_after_days_helper'))
                    ->numeric()->integer()->minValue(1)->maxValue(365)
                    ->default(14)
                    ->required(),
            ]);
    }

    /** Defaults for auto-tasks and the automation user. */
    public static function automationSection(): Section
    {
        return Section::make(__('projects.sections.automation'))
            ->description(__('projects.sections.automation_description'))
            ->icon('heroicon-o-bolt')
            ->aside()
            ->schema([
                Select::make('automation_user_id')
                    ->label(__('projects.fields.automation_user'))
                    ->helperText(__('projects.fields.automation_user_helper'))
                    ->options(fn (?Workspace $record): array => self::automationUserOptions($record))
                    ->searchable()
                    ->nullable(),
                Select::make('auto_settings.plan')
                    ->label(__('projects.fields.auto_plan'))
                    ->options(collect(AutoPlanMode::cases())->mapWithKeys(fn (AutoPlanMode $m): array => [$m->value => $m->label()])->all())
                    ->placeholder(fn (): string => __('projects.fields.auto_default', ['value' => AutoPlanMode::from((string) config('argos.workspaces.auto.plan'))->label()]))
                    ->native(false),
                Select::make('auto_settings.ship')
                    ->label(__('projects.fields.auto_ship'))
                    ->options(collect(AutoShipMode::cases())->mapWithKeys(fn (AutoShipMode $m): array => [$m->value => $m->label()])->all())
                    ->placeholder(fn (): string => __('projects.fields.auto_default', ['value' => AutoShipMode::from((string) config('argos.workspaces.auto.ship'))->label()]))
                    ->native(false),
                TextInput::make('auto_settings.max_fix_rounds')
                    ->label(__('projects.fields.auto_max_fix_rounds'))
                    ->helperText(__('projects.fields.auto_max_fix_rounds_helper'))
                    ->placeholder((string) config('argos.workspaces.auto.max_fix_rounds'))
                    ->numeric()->minValue(0)->maxValue(20)->nullable(),
                TextInput::make('auto_settings.max_turns')
                    ->label(__('projects.fields.auto_max_turns'))
                    ->placeholder((string) config('argos.workspaces.auto.max_turns'))
                    ->numeric()->minValue(1)->maxValue(1000)->nullable(),
                TextInput::make('auto_settings.max_cost_usd')
                    ->label(__('projects.fields.auto_max_cost_usd'))
                    ->helperText(__('projects.fields.auto_max_cost_usd_helper'))
                    ->placeholder((string) config('argos.workspaces.auto.max_cost_usd'))
                    ->numeric()->minValue(0)->nullable(),
                TextInput::make('auto_settings.max_minutes')
                    ->label(__('projects.fields.auto_max_minutes'))
                    ->placeholder((string) config('argos.workspaces.auto.max_minutes'))
                    ->numeric()->minValue(1)->maxValue(1440)->nullable(),
            ]);
    }

    /**
     * Form data for an existing workspace: derives the OAuth picker values
     * from the persisted repo_url + default_branch and adds the (hidden)
     * repository token. Used by the admin edit page.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFromRecord(Workspace $record, array $data): array
    {
        if (! isset($data['auth_method']) || $data['auth_method'] === '') {
            $data['auth_method'] = 'pat';
        }

        // The token is $hidden on the model (kept out of serialisation), so
        // the default fill misses it.
        $data['token'] = $record->token;

        $url = $data['repo_url'] ?? null;
        if (is_string($url) && $url !== '') {
            $repoPath = self::repoPathFromUrl($url);
            if ($repoPath !== null) {
                $data['oauth_repo'] = $repoPath;
            }
        }

        if (isset($data['default_branch']) && is_string($data['default_branch'])) {
            $data['oauth_branch'] = $data['default_branch'];
        }

        return $data;
    }

    /**
     * Prepare submitted form data for the service: OAuth pickers → columns,
     * auto settings cleaned.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function dehydrate(array $data): array
    {
        return self::mutateAutoSettings(self::mutateOauthFields($data));
    }

    /**
     * Keep only valid auto-task settings; an all-empty section stores null
     * (inherit the installation defaults).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateAutoSettings(array $data): array
    {
        if (array_key_exists('auto_settings', $data)) {
            $clean = AutoSettings::clean(is_array($data['auto_settings']) ? $data['auto_settings'] : []);
            $data['auto_settings'] = $clean !== [] ? $clean : null;
        }

        return $data;
    }

    /**
     * In the OAuth path the visible pickers write to `oauth_repo` /
     * `oauth_branch`; the columns are `repo_url` / `default_branch`. Map them
     * back (the URL shape depends on the provider) and drop the helper keys.
     * Only touches the access fields when the form carried them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateOauthFields(array $data): array
    {
        if (! array_key_exists('platform', $data)) {
            unset($data['oauth_repo'], $data['oauth_branch']);

            return $data;
        }

        // Ensure auth_method has a value (e.g. when the auth section is hidden).
        if (! isset($data['auth_method']) || $data['auth_method'] === '') {
            $data['auth_method'] = 'pat';
        }

        $platform = is_string($data['platform']) ? $data['platform'] : '';

        if (isset($data['oauth_repo']) && is_string($data['oauth_repo']) && $data['oauth_repo'] !== '') {
            $account = self::connectedAccountForPlatform($platform);
            $data['repo_url'] = self::connectedRepoUrl($platform, $data['oauth_repo'], $account);
        }

        if (isset($data['oauth_branch']) && is_string($data['oauth_branch']) && $data['oauth_branch'] !== '') {
            $data['default_branch'] = $data['oauth_branch'];
        }

        if ($data['auth_method'] === 'oauth') {
            $data['token'] = null;
        }

        if ($data['auth_method'] === 'pat') {
            $data['connected_account_id'] = null;
        }

        unset($data['oauth_repo'], $data['oauth_branch']);

        return $data;
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

    public static function setupStatusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return (string) __('workspaces.settings.setup_status.none');
        }
        $key = 'workspaces.settings.setup_status.'.$status;

        return __($key) !== $key ? (string) __($key) : $status;
    }

    /**
     * Who may be the automation user: on the settings page of a workspace
     * the people in it (an auto-task runs on that person's subscription);
     * Argos admins may pick anyone.
     *
     * @return array<int, string>
     */
    private static function automationUserOptions(?Workspace $record): array
    {
        /** @var User|null $user */
        $user = Auth::user();
        $query = User::query()->orderBy('name');
        if ($record !== null && ! ($user?->isAdmin() ?? false)) {
            $query->whereIn('id', $record->members()->select('user_id'));
        }

        return $query->pluck('name', 'id')->all();
    }

    /** Visibility for sections that wait for the platform in the create flow. */
    private static function gate(bool $gated): Closure
    {
        return fn (Get $get): bool => ! $gated || self::platformChosen($get);
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

    private static function tokenHelper(Get $get): HtmlString
    {
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
    }

    private static function hasManualRepoAccess(Get $get): bool
    {
        return ! self::isConnectedPath($get)
            && is_string($get('repo_url')) && $get('repo_url') !== ''
            && is_string($get('token')) && $get('token') !== '';
    }

    /**
     * Branch options for a repo entered by URL + PAT.
     *
     * @return array<string, string>
     */
    private static function manualBranchOptions(Get $get): array
    {
        $url = $get('repo_url');
        $token = $get('token');
        $platform = $get('platform');

        if (! is_string($url) || $url === '' || ! is_string($token) || $token === '' || ! is_string($platform) || $platform === '') {
            return [];
        }

        $ownerRepo = self::repoPathFromUrl($url);
        if ($ownerRepo === null) {
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
     * The cache key is platform + account id only — never the token.
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

    private static function platformChosen(Get $get): bool
    {
        $platform = $get('platform');

        return is_string($platform) && $platform !== '';
    }

    private static function hasOAuthAccount(Get $get): bool
    {
        return match ($get('platform')) {
            'github' => self::githubAccount() !== null,
            'gitlab' => self::gitlabAccount() !== null,
            default => false,
        };
    }

    /** @return array<string, string> */
    private static function authMethodOptions(Get $get): array
    {
        $oauthLabel = $get('platform') === 'gitlab'
            ? __('projects.fields.auth_method_oauth_gitlab')
            : __('projects.fields.auth_method_oauth');

        return [
            'pat' => (string) __('projects.fields.auth_method_pat'),
            'oauth' => (string) $oauthLabel,
        ];
    }

    private static function isConnectedPath(Get $get): bool
    {
        $platform = $get('platform');

        return in_array($platform, ['github', 'gitlab', 'bitbucket'], true)
            && $get('auth_method') === 'oauth'
            && self::connectedAccountForPlatform((string) $platform) !== null;
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
}
