<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Resources;

use App\Enums\AutoPlanMode;
use App\Enums\AutoShipMode;
use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\CreateWorkspace;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Models\RepoProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\AutoSettings;
use App\Services\Workspace\WorkspaceIssueService;
use App\Services\Workspace\WorkspaceSummaryService;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Interactive workspaces: one long-lived container per piece of work, with
 * threads to chat with Claude, the editor and the live preview.
 */
class WorkspaceResource extends Resource
{
    protected static ?string $model = Workspace::class;

    protected static ?string $recordTitleAttribute = 'name';

    /** Keep the panel without a global search box (no other resource has one). */
    protected static bool $isGloballySearchable = false;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-command-line';
    }

    public static function getNavigationSort(): ?int
    {
        return -2;
    }

    public static function getModelLabel(): string
    {
        return __('workspaces.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workspaces.plural');
    }

    /**
     * Members see their workspaces; admins see all.
     *
     * @return Builder<Workspace>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['repoProfile', 'members.user']);

        if (Gate::allows('administer-argos')) {
            return $query;
        }

        return $query->whereHas('members', fn (Builder $q) => $q->where('user_id', auth()->id()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Select::make('repo_profile_id')
                        ->label(__('workspaces.fields.project'))
                        ->options(fn (): array => RepoProfile::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('issue', null)),

                    Select::make('issue')
                        ->label(__('workspaces.fields.issue'))
                        ->helperText(__('workspaces.fields.issue_help'))
                        ->visible(fn (Get $get): bool => app(WorkspaceIssueService::class)->bindingFor(RepoProfile::query()->find($get('repo_profile_id'))) !== null)
                        ->searchable()
                        ->getSearchResultsUsing(fn (Get $get, string $search): array => ($profile = RepoProfile::query()->find($get('repo_profile_id'))) !== null
                            ? app(WorkspaceIssueService::class)->search($profile, $search)
                            : [])
                        ->getOptionLabelUsing(fn (Get $get, ?string $value): ?string => $value !== null && ($profile = RepoProfile::query()->find($get('repo_profile_id'))) !== null
                            ? (app(WorkspaceIssueService::class)->search($profile, $value, 200)[$value] ?? '#'.$value)
                            : null)
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            $profile = RepoProfile::query()->find($get('repo_profile_id'));
                            if ($state === null || $profile === null || filled($get('name'))) {
                                return;
                            }
                            $set('name', mb_substr(app(WorkspaceIssueService::class)->search($profile, $state, 200)[$state] ?? '#'.$state, 0, 120));
                        }),

                    TextInput::make('name')
                        ->label(__('workspaces.fields.name'))
                        ->helperText(__('workspaces.fields.name_help'))
                        ->required()
                        ->maxLength(120),

                    TextInput::make('base_branch')
                        ->label(__('workspaces.fields.base_branch'))
                        ->helperText(__('workspaces.fields.base_branch_help'))
                        ->placeholder(fn ($get): ?string => RepoProfile::query()->find($get('repo_profile_id'))?->default_branch)
                        ->maxLength(255),
                ]),

            // Optional: start an auto-thread right away.
            Section::make(__('workspaces.auto.toggle'))
                ->description(__('workspaces.auto.toggle_help'))
                ->icon('heroicon-o-bolt')
                ->collapsible()
                ->schema([
                    Toggle::make('auto_start')
                        ->label(__('workspaces.auto.start_with_workspace'))
                        ->live(),
                    Textarea::make('auto_prompt')
                        ->label(__('workspaces.auto.prompt'))
                        ->placeholder(__('workspaces.auto.prompt_placeholder'))
                        ->rows(4)
                        ->maxLength(20000)
                        ->required(fn (Get $get): bool => (bool) $get('auto_start'))
                        ->visible(fn (Get $get): bool => (bool) $get('auto_start')),
                    Select::make('auto_plan')
                        ->label(__('projects.fields.auto_plan'))
                        ->options(collect(AutoPlanMode::cases())->mapWithKeys(fn (AutoPlanMode $m): array => [$m->value => $m->label()])->all())
                        ->placeholder(fn (Get $get): string => __('workspaces.auto.project_default', ['value' => AutoPlanMode::from(AutoSettings::resolve(RepoProfile::query()->find($get('repo_profile_id')))['plan'])->label()]))
                        ->visible(fn (Get $get): bool => (bool) $get('auto_start')),
                    Select::make('auto_ship')
                        ->label(__('projects.fields.auto_ship'))
                        ->options(collect(AutoShipMode::cases())->mapWithKeys(fn (AutoShipMode $m): array => [$m->value => $m->label()])->all())
                        ->placeholder(fn (Get $get): string => __('workspaces.auto.project_default', ['value' => AutoShipMode::from(AutoSettings::resolve(RepoProfile::query()->find($get('repo_profile_id')))['ship'])->label()]))
                        ->visible(fn (Get $get): bool => (bool) $get('auto_start')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('workspaces.fields.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('repoProfile.name')
                    ->label(__('workspaces.fields.project')),
                TextColumn::make('branch')
                    ->label(__('workspaces.fields.branch'))
                    ->fontFamily('mono')
                    ->visibleFrom('md'),
                TextColumn::make('status')
                    ->label(__('workspaces.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (WorkspaceStatus $state): string => $state->label())
                    ->color(fn (WorkspaceStatus $state): string => $state->color()),
                TextColumn::make('members')
                    ->label(__('workspaces.fields.members'))
                    ->state(fn (Workspace $record): string => $record->members->map(fn ($m) => $m->user->name)->implode(', '))
                    ->visibleFrom('lg'),
                TextColumn::make('activity')
                    ->label(__('workspaces.overview.threads_column'))
                    ->state(function (Workspace $record): string {
                        /** @var User $user */
                        $user = auth()->user();
                        $sum = app(WorkspaceSummaryService::class)->for($record, $user);
                        $parts = [];
                        if ($sum['waiting_for_me']) {
                            $parts[] = __('workspaces.overview.waiting_for_you');
                        }
                        if ($sum['working'] > 0) {
                            $parts[] = trans_choice('workspaces.overview.working', $sum['working'], ['count' => $sum['working']]);
                        }
                        $parts[] = trans_choice('workspaces.overview.threads', $sum['threads'], ['count' => $sum['threads']]);

                        return implode(' · ', $parts);
                    })
                    ->visibleFrom('md'),
                TextColumn::make('last_activity_at')
                    ->label(__('workspaces.fields.last_activity'))
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('workspaces.fields.status'))
                    ->options(collect(WorkspaceStatus::cases())->mapWithKeys(fn (WorkspaceStatus $s): array => [$s->value => $s->label()])->all()),
                Filter::make('waiting_for_me')
                    ->label(__('workspaces.overview.waiting_for_you'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereHas('members', fn (Builder $q) => $q->where('user_id', auth()->id())->whereIn('role', ['owner', 'editor']))
                        ->whereHas('threads.approvals', fn (Builder $q) => $q->where('status', 'pending'))),
                Filter::make('mine')
                    ->label(__('workspaces.filters.mine'))
                    ->query(fn (Builder $query): Builder => $query->whereHas('members', fn (Builder $q) => $q->where('user_id', auth()->id())))
                    ->visible(fn (): bool => Gate::allows('administer-argos')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->recordUrl(fn (Workspace $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('last_activity_at', 'desc')
            ->poll('10s');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkspaces::route('/'),
            'create' => CreateWorkspace::route('/create'),
            'view' => ViewWorkspace::route('/{record}'),
        ];
    }
}
