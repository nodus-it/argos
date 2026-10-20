<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources;

use App\Enums\WorkspaceStatus;
use App\Filament\Admin\Resources\WorkspaceResource\Pages\CreateWorkspace;
use App\Filament\Admin\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Filament\Admin\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Models\RepoProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceSummaryService;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
                        ->live(),

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
