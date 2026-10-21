<?php

declare(strict_types=1);

namespace App\Filament\Workspaces\Resources;

use App\Enums\WorkspaceStatus;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\CreateWorkspace;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\EditWorkspaceSettings;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Filament\Workspaces\Resources\WorkspaceResource\Pages\ViewWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceSummaryService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Interactive workspaces: one long-lived container per piece of work, with
 * tasks to chat with Claude, the editor and the live preview.
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
        $query = parent::getEloquentQuery()->with(['members.user']);

        if (Gate::allows('administer-argos')) {
            return $query;
        }

        return $query->whereHas('members', fn (Builder $q) => $q->where('user_id', auth()->id()));
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
                TextColumn::make('repo_url')
                    ->label(__('workspaces.fields.repository'))
                    ->state(fn (Workspace $record): string => $record->getOwnerRepo())
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
                    ->label(__('workspaces.overview.tasks_column'))
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
                        $parts[] = trans_choice('workspaces.overview.tasks', $sum['tasks'], ['count' => $sum['tasks']]);

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
                        ->whereHas('tasks', fn (Builder $q) => $q
                            ->where(fn (Builder $mine) => $mine->where('owner_user_id', auth()->id())->orWhereHas('members', fn (Builder $m) => $m->where('user_id', auth()->id())))
                            ->whereHas('approvals', fn (Builder $a) => $a->where('status', 'pending')))),
                Filter::make('mine')
                    ->label(__('workspaces.filters.mine'))
                    ->query(fn (Builder $query): Builder => $query->whereHas('members', fn (Builder $q) => $q->where('user_id', auth()->id())))
                    ->visible(fn (): bool => Gate::allows('administer-argos')),
            ])
            ->recordActions([
                Action::make('settings')
                    ->label(__('workspaces.settings.title'))
                    ->icon('heroicon-o-cog-6-tooth')
                    ->iconButton()
                    ->tooltip(__('workspaces.settings.title'))
                    ->visible(fn (Workspace $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->url(fn (Workspace $record): string => static::getUrl('settings', ['record' => $record])),
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
            // Before '/{record}' so "create" is not read as a record key.
            'create' => CreateWorkspace::route('/create'),
            'view' => ViewWorkspace::route('/{record}'),
            'settings' => EditWorkspaceSettings::route('/{record}/settings'),
        ];
    }
}
