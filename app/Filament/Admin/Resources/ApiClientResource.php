<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\RelationManagers\ApiTokensRelationManager;
use App\Filament\Admin\Resources\ApiClientResource\Pages\CreateApiClient;
use App\Filament\Admin\Resources\ApiClientResource\Pages\EditApiClient;
use App\Filament\Admin\Resources\ApiClientResource\Pages\ListApiClients;
use App\Models\ApiClient;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApiClientResource extends Resource
{
    protected static ?string $model = ApiClient::class;

    protected static ?string $slug = 'api-clients';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-cpu-chip';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.configuration');
    }

    public static function getNavigationSort(): ?int
    {
        return 6;
    }

    public static function getModelLabel(): string
    {
        return __('api_tokens.client.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('api_tokens.client.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('api_tokens.client.section'))
                ->description(__('api_tokens.client.section_description'))
                ->icon('heroicon-o-identification')
                ->aside()
                ->schema([
                    TextInput::make('name')
                        ->label(__('api_tokens.client.name'))
                        ->helperText(__('api_tokens.client.name_help'))
                        ->required()
                        ->maxLength(255),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('api_tokens.client.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tokens_count')
                    ->label(__('api_tokens.client.tokens'))
                    ->counts('tokens'),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            ApiTokensRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiClients::route('/'),
            'create' => CreateApiClient::route('/create'),
            'edit' => EditApiClient::route('/{record}/edit'),
        ];
    }
}
