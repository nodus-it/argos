<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Enums\WorkerImageEntityStatus;
use App\Filament\Admin\Concerns\TaskTableConcern;
use App\Filament\Admin\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ListTasks;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewQualityGateLog;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskConcept;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskDiff;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskLogs;
use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskRespond;
use App\Filament\Admin\Resources\TaskResource\RelationManagers\PhaseRunsRelationManager;
use App\Models\AgentCredential;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\WorkerStack;
use App\Services\GitProvider\GitServiceFactory;
use App\Support\DocsLinkAction;
use App\Workers\Agents\AgentRegistry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class TaskResource extends Resource
{
    use TaskTableConcern;

    protected static ?string $model = Task::class;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-queue-list';
    }

    // Tasks is the primary surface — it sits ungrouped at the top of the
    // sidebar, right under the dashboard (sort -2), ahead of every group.
    public static function getNavigationSort(): ?int
    {
        return -1;
    }

    public static function getNavigationLabel(): string
    {
        return __('tasks.navigation_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->tabs([
                    Tab::make(__('tasks.tabs.basics'))
                        ->icon('heroicon-o-document-text')
                        ->schema([
                            TextInput::make('name')
                                ->label(__('tasks.fields.name_label'))
                                ->helperText(__('tasks.fields.name_helper'))
                                ->required()
                                ->maxLength(255)
                                ->rules([Rule::unique('tasks', 'name')]),

                            Select::make('repo_profile_id')
                                ->label(__('tasks.fields.project'))
                                ->helperText(__('tasks.fields.project_helper'))
                                ->options(RepoProfile::all()->pluck('name', 'id'))
                                ->required()
                                ->live()
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    $set('auto_concept', self::resolveProfile($state)?->auto_concept ?? false);
                                }),

                            Textarea::make('description')
                                ->label(__('tasks.fields.description_label'))
                                ->hintAction(DocsLinkAction::make('tasks'))
                                ->rows(8)
                                ->helperText(__('tasks.fields.description_helper'))
                                ->columnSpanFull(),

                            Select::make('base_branch')
                                ->label(__('tasks.fields.base_branch_label'))
                                ->helperText(fn (Get $get): string => self::baseBranchHelperText($get))
                                ->options(function (Get $get): array {
                                    $profileId = $get('repo_profile_id');
                                    if (! is_string($profileId) || $profileId === '') {
                                        return [];
                                    }
                                    $profile = self::resolveProfile($profileId);
                                    if ($profile === null) {
                                        return [];
                                    }
                                    try {
                                        return app(GitServiceFactory::class)->fromRepoProfile($profile)->getBranchOptions($profile->getOwnerRepo());
                                    } catch (\Throwable) {
                                        return [];
                                    }
                                })
                                ->placeholder('—')
                                ->searchable()
                                ->native(false),

                            Toggle::make('auto_concept')
                                ->label(__('tasks.fields.auto_concept_label'))
                                ->helperText(__('tasks.fields.auto_concept_helper'))
                                ->default(fn (Get $get): bool => self::resolveProfile($get('repo_profile_id'))?->auto_concept ?? false)
                                ->columnSpanFull(),
                        ]),

                    Tab::make(__('tasks.tabs.worker'))
                        ->icon('heroicon-o-cpu-chip')
                        ->schema([
                            Select::make('worker_stack_id_override')
                                ->label(__('tasks.fields.worker_stack_label'))
                                ->options(fn (): array => self::stackOptions())
                                ->placeholder('—')
                                ->helperText(fn (Get $get): string => self::workerStackHelperText($get))
                                ->searchable()
                                ->native(false),

                            Select::make('worker_agent_name_override')
                                ->label(__('tasks.fields.worker_agent_label'))
                                ->options(fn (): array => self::agentOptions())
                                ->placeholder('—')
                                ->helperText(fn (Get $get): string => self::workerAgentHelperText($get))
                                ->live()
                                ->afterStateUpdated(function (Set $set): void {
                                    // Different agent → previously-chosen credential
                                    // and pinned models belong to the old agent, so
                                    // clear them. Placeholders + option lists then
                                    // recompute against the new agent's spec.
                                    $set('agent_credential_id', null);
                                    $set('model_concept', null);
                                    $set('model_implement', null);
                                })
                                ->native(false),

                            Select::make('agent_credential_id')
                                ->label(__('tasks.fields.agent_credential_label'))
                                ->options(fn (Get $get): array => self::credentialOptionsForAgent(self::effectiveAgent($get)))
                                ->placeholder(__('tasks.fields.agent_credential_placeholder'))
                                ->helperText(__('tasks.fields.agent_credential_helper'))
                                ->native(false),

                            TextInput::make('max_turns_concept')
                                ->label(__('tasks.fields.max_turns_concept_label'))
                                ->helperText(__('tasks.fields.max_turns_concept_helper', ['default' => config('argos.concept.max_turns_default', 30)]))
                                ->numeric()
                                ->minValue(10)
                                ->maxValue(1000)
                                ->placeholder((string) config('argos.concept.max_turns_default', 30)),

                            TextInput::make('max_turns_implement')
                                ->label(__('tasks.fields.max_turns_implement_label'))
                                ->helperText(__('tasks.fields.max_turns_implement_helper', ['default' => config('argos.implement.max_turns_default', 200)]))
                                ->numeric()
                                ->minValue(10)
                                ->maxValue(1000)
                                ->placeholder((string) config('argos.implement.max_turns_default', 200)),

                            // See RepoProfileResource for why default lives in helperText.
                            Select::make('model_concept')
                                ->label(__('tasks.fields.model_concept_label'))
                                ->options(fn (Get $get): array => self::effectiveAgent($get)->spec()->availableModels)
                                ->placeholder(__('tasks.fields.model_placeholder_neutral'))
                                ->helperText(fn (Get $get): string => __(
                                    'tasks.fields.model_concept_helper_with_default',
                                    ['model' => self::effectiveModelLabel($get, 'concept')],
                                ))
                                ->live()
                                ->native(false),

                            Select::make('model_implement')
                                ->label(__('tasks.fields.model_implement_label'))
                                ->options(fn (Get $get): array => self::effectiveAgent($get)->spec()->availableModels)
                                ->placeholder(__('tasks.fields.model_placeholder_neutral'))
                                ->helperText(fn (Get $get): string => __(
                                    'tasks.fields.model_implement_helper_with_default',
                                    ['model' => self::effectiveModelLabel($get, 'implement')],
                                ))
                                ->live()
                                ->native(false),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * Request-local cache for the repo profile the form keeps reaching for.
     * The form closures (options, placeholders, helper text, defaults) all
     * resolve the same profile id; under the table's 5s polling that was a
     * fresh `RepoProfile::find()` per closure per render. Memoizing collapses
     * them to one query. Null results are cached too, so a never-set or
     * stale id does not re-hit the DB on every closure.
     *
     * @var array<string, RepoProfile|null>
     */
    private static array $profileCache = [];

    /**
     * Resolve a repo profile by id, memoized for the current request.
     */
    private static function resolveProfile(mixed $id): ?RepoProfile
    {
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $key = (string) $id;

        if (! array_key_exists($key, self::$profileCache)) {
            self::$profileCache[$key] = RepoProfile::find($id);
        }

        return self::$profileCache[$key];
    }

    /**
     * Active worker stacks as a [id => label] map for the override select.
     *
     * @return array<string, string>
     */
    private static function stackOptions(): array
    {
        return WorkerStack::query()
            ->where('status', '!=', WorkerImageEntityStatus::Disabled)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (WorkerStack $stack): array => [
                $stack->id => $stack->label !== '' ? "{$stack->label} ({$stack->name})" : $stack->name,
            ])
            ->all();
    }

    /**
     * Registered agents as a [name => label] map for the override select.
     *
     * @return array<string, string>
     */
    private static function agentOptions(): array
    {
        return collect(app(AgentRegistry::class)->specs())
            ->mapWithKeys(fn ($spec): array => [$spec->name->value => $spec->label])
            ->all();
    }

    /**
     * Active credentials for the given agent as a [id => name] map.
     *
     * @return array<string, string>
     */
    private static function credentialOptionsForAgent(AgentName $agent): array
    {
        return AgentCredential::query()
            ->where('agent_name', $agent->value)
            ->where('status', AgentCredentialStatus::Active->value)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (AgentCredential $cred): array => [$cred->id => $cred->name])
            ->all();
    }

    /**
     * The agent that will run for this task: explicit override on the form
     * if set, otherwise the project default, otherwise Claude Code.
     */
    private static function effectiveAgent(Get $get): AgentName
    {
        $override = $get('worker_agent_name_override');
        if ($override instanceof AgentName) {
            return $override;
        }
        if (is_string($override) && $override !== '') {
            $agent = AgentName::tryFrom($override);
            if ($agent !== null) {
                return $agent;
            }
        }

        return self::projectAgent($get);
    }

    /**
     * The agent configured at the project level — the placeholder value
     * that hint-text and "what runs if you leave the override blank?"
     * messages reach for.
     */
    private static function projectAgent(Get $get): AgentName
    {
        $profile = self::resolveProfile($get('repo_profile_id'));

        return $profile?->worker_agent_name ?? AgentName::ClaudeCode;
    }

    /**
     * The label of the project's default stack, or null if the project has
     * none configured (placeholder will then fall back to the global default).
     */
    private static function projectStackLabel(Get $get): ?string
    {
        $profile = self::resolveProfile($get('repo_profile_id'));
        $stack = $profile?->workerStack;

        if ($stack === null) {
            return null;
        }

        return $stack->label !== '' ? $stack->label : $stack->name;
    }

    private static function baseBranchHelperText(Get $get): string
    {
        $profile = self::resolveProfile($get('repo_profile_id'));
        if ($profile === null) {
            return __('tasks.fields.base_branch_helper_no_project');
        }

        return __('tasks.fields.base_branch_helper_with_default', ['branch' => $profile->default_branch]);
    }

    private static function workerStackHelperText(Get $get): string
    {
        $profile = self::resolveProfile($get('repo_profile_id'));
        if ($profile === null) {
            return __('tasks.fields.worker_stack_helper_no_project');
        }
        $stackLabel = self::projectStackLabel($get) ?? (string) config('argos.compose.default_stack', 'php-8.4');

        return __('tasks.fields.worker_stack_helper_with_default', ['stack' => $stackLabel]);
    }

    private static function workerAgentHelperText(Get $get): string
    {
        $profile = self::resolveProfile($get('repo_profile_id'));
        if ($profile === null) {
            return __('tasks.fields.worker_agent_helper_no_project');
        }

        return __('tasks.fields.worker_agent_helper_with_default', ['agent' => self::projectAgent($get)->value]);
    }

    /**
     * Display label for the model that will be used for a phase when the
     * task-level override is blank — task model → project model → agent
     * default. Returns the model id if no human label is registered.
     */
    private static function effectiveModelLabel(Get $get, string $phase): string
    {
        $profile = self::resolveProfile($get('repo_profile_id'));
        $profileModel = match ($phase) {
            'concept' => $profile?->model_concept,
            'implement' => $profile?->model_implement,
            default => null,
        };

        $agent = self::effectiveAgent($get);
        $spec = $agent->spec();

        $modelId = $profileModel !== null && $profileModel !== ''
            ? $profileModel
            : ($spec->defaultModel($phase) ?? '');

        return $spec->availableModels[$modelId] ?? $modelId;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->poll('5s')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(static::taskTableEagerLoads()))
            ->columns(static::taskTableColumns())
            ->filters(static::taskTableFilters())
            ->recordUrl(fn (Task $record): string => static::getUrl('view', ['record' => $record]))
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
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
            'quality-gates' => ViewQualityGateLog::route('/{record}/quality-gates'),
            'respond' => ViewTaskRespond::route('/{record}/respond'),
        ];
    }
}
