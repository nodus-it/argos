<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Concerns\TaskTableConcern;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Contracts\Support\Htmlable;

class CurrentTasksWidget extends BaseWidget
{
    use TaskTableConcern;

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '5s';

    protected function getTableHeading(): string|Htmlable|null
    {
        return __('widgets.current_tasks.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Task::query()
                    ->with(static::taskTableEagerLoads())
                    ->orderByRaw($this->priorityOrderClause())
                    ->orderByDesc('updated_at')
                    ->limit(15)
            )
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->columns(static::taskTableColumns())
            ->filters(static::taskTableFilters())
            ->emptyStateHeading(__('widgets.current_tasks.empty_heading'))
            ->emptyStateDescription(__('widgets.current_tasks.empty_description'))
            ->emptyStateIcon('heroicon-o-queue-list');
    }

    /**
     * Sort tasks waiting on the user first, then those that are running, then everything else.
     */
    private function priorityOrderClause(): string
    {
        $waiting = $this->quotedList([
            WorkflowStatus::ConceptReview->value,
            WorkflowStatus::InReview->value,
            WorkflowStatus::Failed->value,
            WorkflowStatus::ImplementPaused->value,
        ]);

        $running = $this->quotedList([
            WorkflowStatus::ConceptRunning->value,
            WorkflowStatus::ImplementRunning->value,
        ]);

        return "CASE
            WHEN workflow_status IN ({$waiting}) THEN 0
            WHEN workflow_status IN ({$running}) THEN 1
            ELSE 2
        END";
    }

    /**
     * @param  array<int, string>  $values
     */
    private function quotedList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value): string => "'".addslashes($value)."'")
            ->implode(', ');
    }
}
