<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Domain\Worker\WorkerImage;
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
use App\Services\GitServiceFactory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
        return __('tasks.navigation_group');
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
                ->label(__('tasks.fields.project'))
                ->options(RepoProfile::all()->pluck('name', 'id'))
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, ?string $state): void {
                    $set('auto_concept', RepoProfile::find($state)?->auto_concept ?? false);
                }),

            Textarea::make('description')
                ->rows(8)
                ->columnSpanFull(),

            Toggle::make('auto_concept')
                ->label(__('tasks.fields.auto_concept_label'))
                ->helperText(__('tasks.fields.auto_concept_helper'))
                ->default(fn (Get $get): bool => RepoProfile::find($get('repo_profile_id'))?->auto_concept ?? false)
                ->columnSpanFull(),

            TextInput::make('max_turns')
                ->label(__('tasks.fields.max_turns_label'))
                ->helperText(__('tasks.fields.max_turns_helper', ['default' => config('argos.implement.max_turns_default', 200)]))
                ->numeric()
                ->minValue(10)
                ->maxValue(1000)
                ->placeholder((string) config('argos.implement.max_turns_default', 200)),

            Select::make('base_branch')
                ->label(__('tasks.fields.base_branch_label'))
                ->helperText(__('tasks.fields.base_branch_helper'))
                ->options(function (Get $get): array {
                    $profileId = $get('repo_profile_id');
                    if (! is_string($profileId) || $profileId === '') {
                        return [];
                    }
                    $profile = RepoProfile::find($profileId);
                    if ($profile === null) {
                        return [];
                    }
                    try {
                        return app(GitServiceFactory::class)->fromRepoProfile($profile)->getBranchOptions($profile->getOwnerRepo());
                    } catch (\Throwable) {
                        return [];
                    }
                })
                ->placeholder(fn (Get $get): string => RepoProfile::find($get('repo_profile_id'))?->default_branch ?? 'main')
                ->searchable()
                ->native(false),

            Select::make('worker_image')
                ->label(__('tasks.fields.worker_image_label'))
                ->options(fn (Get $get): array => WorkerImage::optionsFor($get('worker_image')))
                ->placeholder(fn (Get $get): string => 'Default vom Projekt ('
                    .(RepoProfile::find($get('repo_profile_id'))?->worker_image ?: config('argos.worker_image')).')')
                ->helperText(__('tasks.fields.worker_image_helper'))
                ->searchable()
                ->native(false),
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
                    ->label(__('tasks.columns.project'))
                    ->sortable(),

                TextColumn::make('current_phase')
                    ->label(__('tasks.columns.phase'))
                    ->badge()
                    ->icon(fn (?string $state): ?string => CurrentTasksWidget::phaseIcon($state))
                    ->color(fn (?string $state): string => CurrentTasksWidget::phaseColor($state))
                    ->formatStateUsing(fn (?string $state): string => CurrentTasksWidget::phaseLabel($state)),

                TextColumn::make('current_status')
                    ->label(__('tasks.columns.status'))
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
                        'paused' => __('common.status.paused'),
                        'lock_blocked' => __('common.status.lock_blocked'),
                        default => (string) $state,
                    })
                    ->placeholder('—'),

                TextColumn::make('workflow_status')
                    ->label(__('tasks.columns.workflow'))
                    ->badge()
                    ->color(fn (?WorkflowStatus $state): string => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn (?WorkflowStatus $state): string => $state?->label() ?? '—'),

                TextColumn::make('cost_total')
                    ->label(__('tasks.columns.cost'))
                    ->sortable()
                    ->formatStateUsing(fn ($state): string => $state !== null && (float) $state > 0
                        ? '$'.number_format((float) $state, 4)
                        : '—'
                    ),

                TextColumn::make('tokens_total')
                    ->label(__('tasks.columns.tokens'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state): string => $state !== null && (int) $state > 0
                        ? number_format((int) $state)
                        : '—'
                    ),

                TextColumn::make('created_at')
                    ->label(__('tasks.columns.created'))
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
