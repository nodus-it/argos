<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\ClaudeModel;
use App\Filament\Admin\Concerns\TaskTableConcern;
use App\Filament\Admin\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskConcept;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskDiff;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskLogs;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskRespond;
use App\Filament\Admin\Resources\TaskResource\RelationManagers\PhaseRunsRelationManager;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\GitProvider\GitServiceFactory;
use App\Services\WorkerImage;
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
use Filament\Tables\Table;
use Illuminate\Validation\Rule;

class TaskResource extends Resource
{
    use TaskTableConcern;

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
                ->maxLength(255)
                ->rules([Rule::unique('tasks', 'name')]),

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
                ->helperText(__('tasks.fields.description_helper'))
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

            Select::make('model_concept')
                ->label(__('tasks.fields.model_concept_label'))
                ->options(fn (): array => collect(ClaudeModel::cases())
                    ->mapWithKeys(fn (ClaudeModel $m): array => [$m->value => $m->label()])
                    ->all())
                ->placeholder(fn (Get $get): string => __('tasks.fields.model_concept_placeholder', [
                    'model' => (RepoProfile::find($get('repo_profile_id'))?->model_concept
                        ?? ClaudeModel::default('concept'))->label(),
                ]))
                ->helperText(__('tasks.fields.model_concept_helper'))
                ->native(false),

            Select::make('model_implement')
                ->label(__('tasks.fields.model_implement_label'))
                ->options(fn (): array => collect(ClaudeModel::cases())
                    ->mapWithKeys(fn (ClaudeModel $m): array => [$m->value => $m->label()])
                    ->all())
                ->placeholder(fn (Get $get): string => __('tasks.fields.model_implement_placeholder', [
                    'model' => (RepoProfile::find($get('repo_profile_id'))?->model_implement
                        ?? ClaudeModel::default('implement'))->label(),
                ]))
                ->helperText(__('tasks.fields.model_implement_helper'))
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->poll('5s')
            ->columns(static::taskTableColumns())
            ->filters(static::taskTableFilters())
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
