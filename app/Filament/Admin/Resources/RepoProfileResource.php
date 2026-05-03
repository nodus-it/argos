<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Models\RepoProfile;
use App\Models\User;
use App\Services\GitHub\GitHubGitService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
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
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            Select::make('platform')
                ->options([
                    'github' => 'GitHub',
                    'gitlab' => 'GitLab',
                ])
                ->required()
                ->live(),

            Select::make('github_repo')
                ->label('GitHub-Repository')
                ->options(function (): array {
                    /** @var User $user */
                    $user = Auth::user();
                    $account = $user->connectedAccount('github');

                    if ($account === null) {
                        return [];
                    }

                    try {
                        return (new GitHubGitService($account->token))->getRepoOptions();
                    } catch (\Throwable) {
                        return [];
                    }
                })
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    if ($state === null) {
                        return;
                    }

                    /** @var User $user */
                    $user = Auth::user();
                    $account = $user->connectedAccount('github');

                    if ($account === null) {
                        return;
                    }

                    $set('url', "https://github.com/{$state}");

                    // Pre-fill the token from OAuth
                    $set('token', null);
                })
                ->visible(function (Get $get): bool {
                    if ($get('platform') !== 'github') {
                        return false;
                    }

                    /** @var User $user */
                    $user = Auth::user();

                    return $user->connectedAccount('github') !== null;
                })
                ->dehydrated(false),

            Select::make('github_branch')
                ->label('Default Branch')
                ->options(function (Get $get): array {
                    $repo = $get('github_repo');

                    if (! is_string($repo) || $repo === '') {
                        return [];
                    }

                    /** @var User $user */
                    $user = Auth::user();
                    $account = $user->connectedAccount('github');

                    if ($account === null) {
                        return [];
                    }

                    try {
                        return (new GitHubGitService($account->token))->getBranchOptions($repo);
                    } catch (\Throwable) {
                        return [];
                    }
                })
                ->live()
                ->afterStateUpdated(fn (Set $set, ?string $state) => $set('default_branch', $state ?? 'main'))
                ->visible(function (Get $get): bool {
                    if ($get('platform') !== 'github') {
                        return false;
                    }

                    /** @var User $user */
                    $user = Auth::user();

                    return $user->connectedAccount('github') !== null && is_string($get('github_repo')) && $get('github_repo') !== '';
                })
                ->dehydrated(false),

            TextInput::make('url')
                ->label('Repo-URL')
                ->required()
                ->url()
                ->maxLength(500),

            TextInput::make('token')
                ->label('Token (PAT)')
                ->password()
                ->revealable()
                ->maxLength(500)
                ->visible(function (Get $get): bool {
                    if ($get('platform') !== 'github') {
                        return true;
                    }

                    /** @var User $user */
                    $user = Auth::user();

                    return $user->connectedAccount('github') === null;
                })
                ->helperText(function (Get $get): string {
                    if ($get('platform') !== 'github') {
                        return '';
                    }

                    /** @var User $user */
                    $user = Auth::user();

                    if ($user->connectedAccount('github') !== null) {
                        return '';
                    }

                    return 'Kein GitHub-Account verknüpft. PAT wird als Fallback verwendet.';
                }),

            TextInput::make('default_branch')
                ->label('Default Branch')
                ->required()
                ->default('main')
                ->maxLength(255)
                ->visible(function (Get $get): bool {
                    if ($get('platform') !== 'github') {
                        return true;
                    }

                    /** @var User $user */
                    $user = Auth::user();

                    return $user->connectedAccount('github') === null || ! (is_string($get('github_repo')) && $get('github_repo') !== '');
                }),

            TextInput::make('worker_image')
                ->label('Worker-Image')
                ->placeholder('ghcr.io/nodus-it/argos-worker:php8.4')
                ->helperText('Leer lassen für globalen Standard aus ARGOS_WORKER_IMAGE.')
                ->maxLength(255),

            Toggle::make('auto_concept')
                ->label('Konzept automatisch starten')
                ->helperText('Startet die Konzept-Phase direkt nach dem Anlegen eines Tasks.'),

            Toggle::make('auto_pr')
                ->label('PR automatisch erstellen')
                ->helperText('Startet die Push-Phase automatisch nach erfolgreicher Implementierung.'),
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
