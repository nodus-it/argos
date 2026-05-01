<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\RepoProfileResource\Pages\CreateRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\ListRepoProfiles;
use App\Models\RepoProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RepoProfileResource extends Resource
{
    protected static ?string $model = RepoProfile::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-server';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Konfiguration';
    }

    public static function getNavigationLabel(): string
    {
        return 'Repo-Profile';
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
                        default  => 'gray',
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
            'index'  => ListRepoProfiles::route('/'),
            'create' => CreateRepoProfile::route('/create'),
            'edit'   => EditRepoProfile::route('/{record}/edit'),
        ];
    }
}
