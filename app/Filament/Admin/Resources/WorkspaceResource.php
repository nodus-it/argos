<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Filament\Admin\RelationManagers\ApiTokensRelationManager;
use App\Filament\Admin\Resources\WorkspaceResource\Pages\CreateWorkspace;
use App\Filament\Admin\Resources\WorkspaceResource\Pages\EditWorkspace;
use App\Filament\Admin\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Filament\Admin\Resources\WorkspaceResource\RelationManagers\TaskProviderBindingsRelationManager;
use App\Filament\Shared\WorkspaceForm;
use App\Models\Workspace;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WorkspaceResource extends Resource
{
    protected static ?string $model = Workspace::class;

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

    /** The shared workspace form (see WorkspaceForm; the /w panel uses the same). */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([WorkspaceForm::tabs()]);
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

                    TextEntry::make('workerStack.label')
                        ->label(__('projects.infolist.worker_stack'))
                        ->placeholder(__('projects.infolist.worker_stack_placeholder', ['stack' => (string) config('argos.compose.default_stack', 'php-8.4')])),

                ]),

            Section::make(__('projects.sections.repository'))
                ->schema([
                    TextEntry::make('repo_url')
                        ->label(__('projects.infolist.repo_url'))
                        ->copyable(),

                    TextEntry::make('default_branch')
                        ->label(__('projects.infolist.default_branch')),

                    TextEntry::make('token')
                        ->label(__('projects.infolist.token'))
                        ->state(fn (Workspace $record): string => $record->getRawOriginal('token') !== null ? '••••••••' : '—'),
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
                    ->label(__('projects.columns.branch'))
                    ->visibleFrom('md'),

                TextColumn::make('repo_url')
                    ->copyable()
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tasks_count')
                    ->label(__('projects.columns.tasks'))
                    ->counts('tasks')
                    ->visibleFrom('md'),
            ])
            ->recordUrl(fn (Workspace $record): string => static::getUrl('edit', ['record' => $record]))
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
            TaskProviderBindingsRelationManager::class,
            ApiTokensRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaces::route('/'),
            'create' => CreateWorkspace::route('/create'),
            // Detail = edit (no separate read-only view); relation managers
            // render on the edit page.
            'edit' => EditWorkspace::route('/{record}'),
        ];
    }
}
