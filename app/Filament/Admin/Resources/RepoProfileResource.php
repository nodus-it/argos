<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Worker\WorkerImage;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ViewRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\RelationManagers\TasksRelationManager;
use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\User;
use App\Rules\BranchExistsOnRemote;
use App\Services\Bitbucket\BitbucketGitService;
use App\Services\GitHub\GitHubGitService;
use App\Services\GitLab\GitLabGitService;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

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
                ]),

            // ── Block 2 ─ Allgemein ─────────────────────────────────────────
            Section::make(__('projects.sections.general'))
                ->visible(fn (Get $get): bool => self::platformChosen($get))
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

                    Select::make('worker_image')
                        ->label(__('projects.fields.worker_image_label'))
                        ->options(fn (Get $get): array => WorkerImage::optionsFor($get('worker_image')))
                        ->placeholder(__('projects.fields.worker_image_placeholder', ['image' => config('argos.worker_image')]))
                        ->helperText(__('projects.fields.worker_image_helper'))
                        ->searchable()
                        ->native(false),
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

            // ── Block 4 ─ Repository (connected vs. manual) ─────────────────
            Section::make(__('projects.sections.repository'))
                ->visible(fn (Get $get): bool => self::platformChosen($get))
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
                            } catch (\Throwable) {
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
                            } catch (\Throwable) {
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
                            } catch (\Throwable) {
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
                            } catch (\Throwable) {
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
                            } catch (\Throwable) {
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
                            } catch (\Throwable) {
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
                        ->visible(fn (Get $get): bool => ! self::isConnectedPath($get))
                        ->dehydrated(),

                    TextInput::make('token')
                        ->label(__('projects.fields.token_label'))
                        ->password()
                        ->revealable()
                        ->maxLength(500)
                        ->required(fn (Get $get): bool => ! self::isConnectedPath($get))
                        ->helperText(function (Get $get): string {
                            if ($get('platform') === 'bitbucket') {
                                return self::bitbucketAccount() !== null
                                    ? __('projects.fields.token_helper_bitbucket_oauth_available')
                                    : __('projects.fields.token_helper_bitbucket');
                            }

                            if ($get('platform') === 'github' && self::githubAccount() !== null) {
                                return __('projects.fields.token_helper_oauth_available');
                            }

                            return '';
                        })
                        ->visible(fn (Get $get): bool => ! self::isConnectedPath($get)),

                    TextInput::make('default_branch')
                        ->label(__('projects.fields.default_branch_label'))
                        ->required(fn (Get $get): bool => ! self::isConnectedPath($get))
                        ->default('main')
                        ->maxLength(255)
                        ->rules([
                            fn (Get $get) => new BranchExistsOnRemote(
                                url: is_string($get('url')) ? $get('url') : null,
                                platform: is_string($get('platform')) ? $get('platform') : null,
                                token: is_string($get('token')) ? $get('token') : null,
                            ),
                        ])
                        ->visible(fn (Get $get): bool => ! self::isConnectedPath($get)),
                ]),
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
                        ->color(fn (string $state): string => match ($state) {
                            'github' => 'gray',
                            'gitlab' => 'warning',
                            'bitbucket' => 'info',
                            default => 'gray',
                        }),

                    TextEntry::make('auth_method')
                        ->label(__('projects.infolist.authentication'))
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'oauth' => 'success',
                            default => 'gray',
                        })
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'oauth' => 'OAuth',
                            default => 'PAT',
                        }),

                    IconEntry::make('auto_concept')
                        ->label(__('projects.infolist.auto_concept'))
                        ->boolean(),

                    IconEntry::make('auto_pr')
                        ->label(__('projects.infolist.auto_pr'))
                        ->boolean(),

                    TextEntry::make('worker_image')
                        ->label(__('projects.infolist.worker_image'))
                        ->placeholder(__('projects.infolist.worker_image_placeholder')),
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
                    ->color(fn (string $state): string => match ($state) {
                        'github' => 'gray',
                        'gitlab' => 'warning',
                        'bitbucket' => 'info',
                        default => 'gray',
                    }),

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
