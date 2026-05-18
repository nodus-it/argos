<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use App\Enums\AgentName;
use App\Enums\Phase;
use App\Enums\TaskProviderKind;
use App\Enums\WorkflowStatus;
use App\Models\Task;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;

trait TaskTableConcern
{
    /**
     * @return list<TextColumn>
     */
    public static function taskTableColumns(bool $withProject = true): array
    {
        $columns = [
            TextColumn::make('name')
                ->searchable()
                ->sortable(),
        ];

        if ($withProject) {
            $columns[] = TextColumn::make('repoProfile.name')
                ->label(__('tasks.columns.project'))
                ->sortable();
        }

        $columns[] = TextColumn::make('externalIssueLink.binding.kind')
            ->label(__('tasks.columns.source'))
            ->badge()
            ->color('gray')
            ->icon('heroicon-o-arrow-down-tray')
            ->formatStateUsing(fn (?TaskProviderKind $state): string => $state?->label() ?? '—')
            ->placeholder('—');

        $columns[] = TextColumn::make('workflow_status')
            ->label(__('tasks.columns.workflow'))
            ->badge()
            ->color(fn (?WorkflowStatus $state): string => $state?->color() ?? 'gray')
            ->formatStateUsing(fn (?WorkflowStatus $state): string => $state?->label() ?? '—');

        $columns[] = TextColumn::make('current_phase')
            ->label(__('tasks.columns.phase'))
            ->badge()
            ->icon(fn (?Phase $state): ?string => $state?->icon())
            ->color(fn (?Phase $state): string => $state?->color() ?? 'gray')
            ->formatStateUsing(fn (?Phase $state): string => $state?->label() ?? '—');

        $columns[] = TextColumn::make('agent')
            ->label(__('tasks.columns.agent'))
            ->badge()
            ->color('gray')
            ->state(fn (Task $record): string => (
                $record->worker_agent_name_override
                ?? $record->repoProfile?->worker_agent_name
                ?? AgentName::ClaudeCode
            )->label())
            ->toggleable();

        $columns[] = TextColumn::make('updated_at')
            ->label(__('tasks.columns.last_activity'))
            ->since()
            ->sortable();

        return $columns;
    }

    /**
     * @return list<SelectFilter>
     */
    public static function taskTableFilters(bool $withProject = true): array
    {
        $filters = [];

        if ($withProject) {
            $filters[] = SelectFilter::make('repo_profile_id')
                ->label(__('tasks.columns.project'))
                ->relationship('repoProfile', 'name');
        }

        $filters[] = SelectFilter::make('current_phase')
            ->label(__('tasks.columns.phase'))
            ->options(fn (): array => collect(Phase::cases())
                ->mapWithKeys(fn (Phase $phase): array => [$phase->value => $phase->label()])
                ->all());

        $filters[] = SelectFilter::make('workflow_status')
            ->label(__('tasks.columns.workflow'))
            ->options(fn (): array => collect(WorkflowStatus::cases())
                ->mapWithKeys(fn (WorkflowStatus $status): array => [$status->value => $status->label()])
                ->all());

        return $filters;
    }

    /**
     * @return array<string, Tab>
     */
    public static function taskTableTabs(): array
    {
        return [
            'aktuell' => Tab::make(__('tasks.tabs.current'))
                ->query(fn ($query) => $query->where('workflow_status', '!=', WorkflowStatus::Completed->value)),
            'abgeschlossen' => Tab::make(__('tasks.tabs.completed'))
                ->query(fn ($query) => $query->where('workflow_status', WorkflowStatus::Completed->value)),
            'alle' => Tab::make(__('tasks.tabs.all')),
        ];
    }
}
