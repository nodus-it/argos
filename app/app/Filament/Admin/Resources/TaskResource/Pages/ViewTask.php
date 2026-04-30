<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Domain\Phase\PhaseRunner;
use App\Domain\Phase\StateReader;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Task $task */
        $task = $this->getRecord();
        app(StateReader::class)->syncToDb($task);
        $task->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->phaseAction('concept', 'Concept', 'heroicon-o-light-bulb'),
            $this->phaseAction('implement', 'Implement', 'heroicon-o-code-bracket'),
            $this->phaseAction('push', 'Push', 'heroicon-o-arrow-up-tray'),
            Action::make('logs')
                ->label('Logs')
                ->icon('heroicon-o-command-line')
                ->color('gray')
                ->url(TaskResource::getUrl('logs', ['record' => $this->getRecord()])),
            Action::make('refresh')
                ->label('Aktualisieren')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    /** @var Task $task */
                    $task = $this->getRecord();
                    app(StateReader::class)->syncToDb($task);
                    $task->refresh();
                    Notification::make()->title('Status aktualisiert')->success()->send();
                    $this->redirect($this->getUrl());
                }),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')
                ->label('Name'),

            TextEntry::make('repoProfile.name')
                ->label('Repo-Profil'),

            TextEntry::make('current_phase')
                ->label('Aktuelle Phase')
                ->placeholder('—'),

            TextEntry::make('current_status')
                ->label('Status')
                ->badge()
                ->color(fn (?string $state): string => match ($state) {
                    'running'             => 'warning',
                    'completed'           => 'success',
                    'failed'              => 'danger',
                    'quality_gate_failed' => 'danger',
                    'no_changes'          => 'info',
                    default               => 'gray',
                }),

            TextEntry::make('feature_branch')
                ->label('Feature Branch')
                ->placeholder('—'),

            TextEntry::make('pr_url')
                ->label('PR URL')
                ->url(fn (?string $state): ?string => $state ?: null)
                ->openUrlInNewTab()
                ->placeholder('—'),
        ]);
    }

    private function phaseAction(string $phase, string $label, string $icon): Action
    {
        return Action::make($phase)
            ->label($label)
            ->icon($icon)
            ->action(function () use ($phase, $label): void {
                /** @var Task $task */
                $task = $this->getRecord();
                if ($task->phaseRuns()->where('status', 'running')->exists()) {
                    Notification::make()->title('Phase läuft bereits')->warning()->send();
                    return;
                }
                app(PhaseRunner::class)->startBackground($task, $phase);
                Notification::make()->title("{$label} gestartet")->success()->send();
                $this->redirect($this->getUrl());
            });
    }


}
