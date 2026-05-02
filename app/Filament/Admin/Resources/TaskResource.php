<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskConcept;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskDiff;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskLogs;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskRespond;
use App\Filament\Admin\Resources\TaskResource\RelationManagers\PhaseRunsRelationManager;
use App\Filament\Admin\Widgets\CurrentTasksWidget;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-queue-list';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Aufgaben';
    }

    public static function getNavigationLabel(): string
    {
        return 'Tasks';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            Select::make('repo_profile_id')
                ->label('Projekt')
                ->options(RepoProfile::all()->pluck('name', 'id'))
                ->required()
                ->live(),

            Textarea::make('description')
                ->rows(8)
                ->columnSpanFull(),

            Toggle::make('auto_concept')
                ->label('Konzept direkt starten')
                ->helperText('Startet die Konzept-Phase sofort nach dem Anlegen.')
                ->default(fn (Get $get): bool => RepoProfile::find($get('repo_profile_id'))?->auto_concept ?? false)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('repoProfile.name')
                    ->label('Projekt')
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
                        'completed' => 'success',
                        'failed' => 'danger',
                        'quality_gate_failed' => 'danger',
                        'no_changes' => 'info',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                TextColumn::make('workflow_status')
                    ->label('Workflow')
                    ->badge()
                    ->color(fn (?WorkflowStatus $state): string => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn (?WorkflowStatus $state): string => $state?->label() ?? '—'),

                TextColumn::make('cost_total')
                    ->label('Kosten')
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => $state !== null && (float) $state > 0
                        ? '$'.number_format((float) $state, 4)
                        : '—'
                    ),

                TextColumn::make('tokens_total')
                    ->label('Tokens')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state): string => $state !== null && (int) $state > 0
                        ? number_format((int) $state)
                        : '—'
                    ),

                TextColumn::make('created_at')
                    ->label('Erstellt')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (Task $record): string => static::getUrl('view', ['record' => $record]))
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelationManagers(): array
    {
        return [
            PhaseRunsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withSum('phaseRuns as cost_total', 'cost_usd')
            ->addSelect([
                'tokens_total' => PhaseRun::query()
                    ->selectRaw('COALESCE(SUM(input_tokens), 0) + COALESCE(SUM(output_tokens), 0)')
                    ->whereColumn('phase_runs.task_id', 'tasks.id'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'view' => ViewTask::route('/{record}'),
            'concept' => ViewTaskConcept::route('/{record}/concept'),
            'diff' => ViewTaskDiff::route('/{record}/diff'),
            'logs' => ViewTaskLogs::route('/{record}/logs'),
            'respond' => ViewTaskRespond::route('/{record}/respond'),
        ];
    }
}
