<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RepoProfileResource\RelationManagers;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Filament\Admin\Widgets\CurrentTasksWidget;
use App\Models\Task;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('current_phase')
                    ->label('Phase')
                    ->badge()
                    ->icon(fn (?string $state): ?string => CurrentTasksWidget::phaseIcon($state))
                    ->color(fn (?string $state): string => CurrentTasksWidget::phaseColor($state))
                    ->formatStateUsing(fn (?string $state): string => CurrentTasksWidget::phaseLabel($state)),

                TextColumn::make('current_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'gray',
                        'running' => 'warning',
                        'paused' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        'quality_gate_failed' => 'danger',
                        'lock_blocked' => 'danger',
                        'no_changes' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'paused' => 'Pausiert',
                        'lock_blocked' => 'Lock blockiert',
                        default => (string) $state,
                    })
                    ->placeholder('—'),

                TextColumn::make('workflow_status')
                    ->label('Workflow')
                    ->badge()
                    ->color(fn (?WorkflowStatus $state): string => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn (?WorkflowStatus $state): string => $state?->label() ?? '—'),

                TextColumn::make('created_at')
                    ->label('Erstellt')
                    ->since()
                    ->sortable(),
            ])
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
