<?php

declare(strict_types=1);

namespace App\Filament\App\Clusters\System\Resources;

use App\Filament\Support\Actions\ServiceDeleteAction;
use App\Filament\Support\Actions\ServiceDeleteBulkAction;
use App\Services\Api\ApiClientService;
use App\Filament\Concerns\AdminOnly;
use App\Filament\App\Clusters\System;
use App\Filament\App\Resources\WorkspaceResource\RelationManagers\ApiTokensRelationManager;
use App\Filament\App\Clusters\System\Resources\ApiClientResource\Pages\CreateApiClient;
use App\Filament\App\Clusters\System\Resources\ApiClientResource\Pages\EditApiClient;
use App\Filament\App\Clusters\System\Resources\ApiClientResource\Pages\ListApiClients;
use App\Models\ApiClient;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApiClientResource extends Resource
{
    use AdminOnly;

    protected static ?string $cluster = System::class;

    public static function getNavigationLabel(): string
    {
        return __('system.api_clients');
    }

    public static function getNavigationSort(): ?int
    {
        return 4;
    }

    protected static ?string $model = ApiClient::class;

    protected static ?string $slug = 'api-clients';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-cpu-chip';
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
                ->schema([
                    TextInput::make('name')
                        ->label(__('api_tokens.client.name'))
                        ->helperText(__('api_tokens.client.name_help'))
                        ->required()
                        ->maxLength(255),
                    Select::make('run_as_user_id')
                        ->label(__('api_tokens.client.run_as'))
                        ->helperText(__('api_tokens.client.run_as_help'))
                        ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->nullable(),
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
                    ->counts('tokens')
                    ->visibleFrom('md'),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                ServiceDeleteAction::make(ApiClientService::class),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ServiceDeleteBulkAction::make(ApiClientService::class),
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
