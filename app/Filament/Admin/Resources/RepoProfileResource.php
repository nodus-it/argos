<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Worker\WorkerImage;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Models\ConnectedAccount;
use App\Models\RepoProfile;
use App\Models\User;
use App\Rules\BranchExistsOnRemote;
use App\Services\GitHub\GitHubGitService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
        return 'Konfiguration';
    }

    public static function getNavigationLabel(): string
    {
        return 'Projekte';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getModelLabel(): string
    {
        return 'Projekt';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Projekte';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // ── Block 1 ─ Plattform (gates everything below) ────────────────
            Section::make('Plattform')
                ->description('Wähle die Plattform — danach werden die weiteren Felder freigeschaltet.')
                ->schema([
                    Select::make('platform')
                        ->label('Plattform')
                        ->options([
                            'github' => 'GitHub',
                            'gitlab' => 'GitLab',
                        ])
                        ->required()
                        ->live()
                        ->native(false),
                ]),

            // ── Block 2 ─ Allgemein ─────────────────────────────────────────
            Section::make('Allgemein')
                ->visible(fn (Get $get): bool => self::platformChosen($get))
                ->schema([
                    TextInput::make('name')
                        ->label('Projektname')
                        ->required()
                        ->maxLength(255),

                    Toggle::make('auto_concept')
                        ->label('Konzept automatisch starten')
                        ->helperText('Startet die Konzept-Phase direkt nach dem Anlegen eines Tasks.'),

                    Toggle::make('auto_pr')
                        ->label('PR automatisch erstellen')
                        ->helperText('Startet die Push-Phase automatisch nach erfolgreicher Implementierung.'),

                    Select::make('worker_image')
                        ->label('Worker-Image')
                        ->options(fn (Get $get): array => WorkerImage::optionsFor($get('worker_image')))
                        ->placeholder('Globaler Default ('.config('argos.worker_image').')')
                        ->helperText('Leer lassen für globalen Standard. Andere Tags müssen in config/argos.php oder per ARGOS_WORKER_IMAGE bekannt sein.')
                        ->searchable()
                        ->native(false),
                ]),

            // ── Block 3 ─ Repository (connected vs. manual) ─────────────────
            Section::make('Repository')
                ->visible(fn (Get $get): bool => self::platformChosen($get))
                ->schema([
                    // Connected-Pfad: GitHub mit OAuth-Account
                    Select::make('github_repo')
                        ->label('Repository')
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
                        ->visible(fn (Get $get): bool => self::isConnectedPath($get))
                        ->dehydrated(false),

                    Select::make('github_branch')
                        ->label('Default Branch')
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
                        ->required(fn (Get $get): bool => self::isConnectedPath($get))
                        ->searchable()
                        ->live()
                        ->visible(fn (Get $get): bool => self::isConnectedPath($get) && is_string($get('github_repo')) && $get('github_repo') !== '')
                        ->dehydrated(fn (Get $get): bool => self::isConnectedPath($get)),

                    // Manual-Pfad: GitLab oder GitHub ohne OAuth
                    TextInput::make('url')
                        ->label('Repo-URL')
                        ->required(fn (Get $get): bool => ! self::isConnectedPath($get))
                        ->url()
                        ->maxLength(500)
                        ->visible(fn (Get $get): bool => ! self::isConnectedPath($get))
                        ->dehydrated(),

                    TextInput::make('token')
                        ->label('Token (PAT)')
                        ->password()
                        ->revealable()
                        ->maxLength(500)
                        ->required()
                        ->helperText(fn (Get $get): string => $get('platform') === 'github'
                            ? 'Kein GitHub-Account verknüpft. PAT wird als Fallback verwendet.'
                            : '')
                        ->visible(fn (Get $get): bool => ! self::isConnectedPath($get)),

                    TextInput::make('default_branch')
                        ->label('Default Branch')
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

    private static function platformChosen(Get $get): bool
    {
        $platform = $get('platform');

        return is_string($platform) && $platform !== '';
    }

    private static function isConnectedPath(Get $get): bool
    {
        return $get('platform') === 'github' && self::githubAccount() !== null;
    }

    /**
     * The OAuth Select writes to `github_branch`, but the column is `default_branch`.
     * Called from CreateRepoProfile and EditRepoProfile.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateBranchKey(array $data): array
    {
        if (isset($data['github_branch']) && is_string($data['github_branch']) && $data['github_branch'] !== '') {
            $data['default_branch'] = $data['github_branch'];
        }
        unset($data['github_branch']);

        return $data;
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
                        default => 'gray',
                    }),

                TextColumn::make('default_branch')
                    ->label('Branch'),

                TextColumn::make('url')
                    ->copyable()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tasks_count')
                    ->label('Tasks')
                    ->counts('tasks'),
            ])
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

    public static function getPages(): array
    {
        return [
            'index' => ListRepoProfiles::route('/'),
            'create' => CreateRepoProfile::route('/create'),
            'edit' => EditRepoProfile::route('/{record}/edit'),
        ];
    }
}
