<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Worker\WorkerImage;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Models\RepoProfile;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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

            TextInput::make('url')
                ->label('Repo-URL')
                ->required()
                ->url()
                ->maxLength(500),

            TextInput::make('token')
                ->label('Token (PAT)')
                ->password()
                ->revealable()
                ->maxLength(500),

            Select::make('platform')
                ->options([
                    'github' => 'GitHub',
                    'gitlab' => 'GitLab',
                ])
                ->required(),

            TextInput::make('default_branch')
                ->label('Default Branch')
                ->required()
                ->default('main')
                ->maxLength(255),

            Select::make('worker_image')
                ->label('Worker-Image')
                ->options(fn (Get $get): array => WorkerImage::optionsFor($get('worker_image')))
                ->placeholder('Globaler Default ('.config('argos.worker_image').')')
                ->helperText('Leer lassen für globalen Standard. Andere Tags müssen in config/argos.php oder per ARGOS_WORKER_IMAGE bekannt sein.')
                ->searchable()
                ->native(false),

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
