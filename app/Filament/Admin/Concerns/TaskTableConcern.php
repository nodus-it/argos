<?php

declare(strict_types=1);

namespace App\Filament\Admin\Concerns;

use App\Enums\AgentName;
use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\TaskProviderKind;
use App\Enums\WorkflowStatus;
use App\Models\Task;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;

trait TaskTableConcern
{
    /**
     * Relations the task columns read on every render: the project name,
     * the provider source badge, and the agent fallback. Eager-loading them
     * on the table query avoids an N+1 storm under the 5s polling.
     *
     * @return list<string>
     */
    public static function taskTableEagerLoads(): array
    {
        return ['repoProfile', 'externalIssueLink.binding'];
    }

    /**
     * @return list<Column>
     */
    public static function taskTableColumns(bool $withProject = true): array
    {
        $columns = [
            TextColumn::make('name')
                ->searchable()
                ->sortable()
                // Let long names wrap instead of forcing the table wider than a
                // 375px phone (which pushed the status column off-screen). On
                // desktop names rarely wrap, so the table layout is unchanged.
                ->wrap(),
        ];

        if ($withProject) {
            $columns[] = TextColumn::make('repoProfile.name')
                ->label(__('tasks.columns.project'))
                ->sortable()
                ->visibleFrom('md');
        }

        $columns[] = TextColumn::make('externalIssueLink.binding.kind')
            ->label(__('tasks.columns.source'))
            ->badge()
            ->color('gray')
            ->icon('heroicon-o-arrow-down-tray')
            ->formatStateUsing(fn (?TaskProviderKind $state): string => $state?->label() ?? '—')
            ->placeholder('—')
            ->visibleFrom('md');

        // Warm-Paper status language: render the workflow + phase as the
        // <x-argos.badge> / <x-argos.phase-chip> components (colour + icon +
        // label). Shared by the dashboard widget and the task list.
        $columns[] = ViewColumn::make('workflow_status')
            ->label(__('tasks.columns.workflow'))
            ->view('filament.tables.columns.argos-workflow-badge');

        $columns[] = ViewColumn::make('current_phase')
            ->label(__('tasks.columns.phase'))
            ->view('filament.tables.columns.argos-phase-chip')
            ->visibleFrom('md');

        $columns[] = TextColumn::make('agent')
            ->label(__('tasks.columns.agent'))
            ->badge()
            ->color('gray')
            ->state(fn (Task $record): string => (
                $record->worker_agent_name_override
                ?? $record->repoProfile?->worker_agent_name
                ?? AgentName::ClaudeCode
            )->label())
            ->toggleable()
            ->visibleFrom('md');

        $columns[] = TextColumn::make('updated_at')
            ->label(__('tasks.columns.last_activity'))
            ->since()
            ->sortable()
            ->visibleFrom('md');

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
            'wartend' => Tab::make(__('tasks.tabs.waiting'))
                ->query(fn ($query) => $query
                    ->whereIn('workflow_status', [WorkflowStatus::ConceptRunning->value, WorkflowStatus::ImplementRunning->value])
                    ->where('current_status', PhaseStatus::Pending->value)),
            'abgeschlossen' => Tab::make(__('tasks.tabs.completed'))
                ->query(fn ($query) => $query->where('workflow_status', WorkflowStatus::Completed->value)),
            'alle' => Tab::make(__('tasks.tabs.all')),
        ];
    }
}
