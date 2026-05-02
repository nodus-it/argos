<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class CurrentTasksWidget extends BaseWidget
{
    protected static ?string $heading = 'Aktuelle Tasks';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '5s';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Task::query()
                    ->with('repoProfile')
                    ->orderByRaw($this->priorityOrderClause())
                    ->orderByDesc('updated_at')
                    ->limit(15)
            )
            ->recordUrl(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Task')
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('repoProfile.name')
                    ->label('Projekt')
                    ->color('gray'),

                TextColumn::make('current_phase')
                    ->label('Phase')
                    ->badge()
                    ->icon(fn (?string $state): ?string => self::phaseIcon($state))
                    ->color(fn (?string $state): string => self::phaseColor($state))
                    ->formatStateUsing(fn (?string $state): string => self::phaseLabel($state)),

                TextColumn::make('workflow_status')
                    ->label('Workflow')
                    ->badge()
                    ->color(fn (?WorkflowStatus $state): string => $state?->color() ?? 'gray')
                    ->formatStateUsing(fn (?WorkflowStatus $state): string => $state?->label() ?? '—'),

                TextColumn::make('updated_at')
                    ->label('Zuletzt')
                    ->since()
                    ->color('gray'),
            ])
            ->emptyStateHeading('Noch keine Tasks')
            ->emptyStateDescription('Lege unter Aufgaben → Tasks deinen ersten Task an.')
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

    public static function phaseColor(?string $state): string
    {
        return match ($state) {
            'concept' => 'info',
            'implement' => 'warning',
            'diff' => 'gray',
            'push' => 'primary',
            'respond' => 'success',
            default => 'gray',
        };
    }

    public static function phaseIcon(?string $state): ?string
    {
        return match ($state) {
            'concept' => 'heroicon-m-light-bulb',
            'implement' => 'heroicon-m-code-bracket',
            'diff' => 'heroicon-m-document-text',
            'push' => 'heroicon-m-arrow-up-tray',
            'respond' => 'heroicon-m-chat-bubble-left-right',
            default => null,
        };
    }

    public static function phaseLabel(?string $state): string
    {
        return match ($state) {
            'concept' => 'Concept',
            'implement' => 'Implement',
            'diff' => 'Diff',
            'push' => 'Push',
            'respond' => 'Respond',
            null, '' => '—',
            default => ucfirst($state),
        };
    }
}
