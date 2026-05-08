<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\RelationManagers;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('projects.columns.tasks');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('name')
                    ->label(__('widgets.current_tasks.columns.task'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('current_phase')
                    ->label(__('tasks.columns.phase'))
                    ->badge()
                    ->icon(fn (?Phase $state): ?string => $state?->icon())
                    ->color(fn (?Phase $state): string => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn (?Phase $state): string => $state?->label() ?? '—'),

                TextColumn::make('current_status')
                    ->label(__('tasks.columns.status'))
                    ->badge()
                    ->color(fn (?PhaseStatus $state): string => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn (?PhaseStatus $state): string => $state?->label() ?? '—')
                    ->placeholder('—'),

                TextColumn::make('workflow_status')
                    ->label(__('tasks.columns.workflow'))
                    ->badge()
                    ->color(fn (?WorkflowStatus $state): string => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn (?WorkflowStatus $state): string => $state?->label() ?? '—'),

                TextColumn::make('created_at')
                    ->label(__('tasks.columns.created'))
                    ->since()
                    ->sortable(),
            ])
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
