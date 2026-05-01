<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Domain\Phase\StateReader;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Jobs\RunPhaseJob;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Symfony\Component\Process\Process;

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
            Action::make('viewConcept')
                ->label('Konzept')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(TaskResource::getUrl('concept', ['record' => $this->getRecord()])),
            $this->phaseAction('implement', 'Implement', 'heroicon-o-code-bracket'),
            Action::make('viewDiff')
                ->label('Diff')
                ->icon('heroicon-o-code-bracket-square')
                ->color('gray')
                ->url(TaskResource::getUrl('diff', ['record' => $this->getRecord()])),
            $this->phaseAction('push', 'Push', 'heroicon-o-arrow-up-tray'),
            Action::make('respond')
                ->label('Respond')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->url(TaskResource::getUrl('respond', ['record' => $this->getRecord()])),
            Action::make('logs')
                ->label('Logs')
                ->icon('heroicon-o-command-line')
                ->color('gray')
                ->url(TaskResource::getUrl('logs', ['record' => $this->getRecord()])),
            Action::make('markCompleted')
                ->label('Abschließen')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription('Task als abgeschlossen markieren? Der Workflow-Status wird auf "Abgeschlossen" gesetzt.')
                ->action(function (): void {
                    /** @var Task $task */
                    $task = $this->getRecord();
                    $task->update(['workflow_status' => WorkflowStatus::Completed]);
                    Notification::make()->title('Task abgeschlossen')->success()->send();
                    $this->redirect($this->getUrl());
                })
                ->visible(fn (): bool => $this->getRecord()->workflow_status !== WorkflowStatus::Completed),

            Action::make('deleteVolume')
                ->label('Workspace löschen')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Den Docker-Volume für diesen Task löschen? Diese Aktion ist nicht rückgängig zu machen.')
                ->action(function (): void {
                    /** @var Task $task */
                    $task = $this->getRecord();
                    Process::fromShellCommandline(
                        'docker volume rm '.escapeshellarg("task_ws_{$task->name}")
                    )->run();
                    Notification::make()->title('Workspace gelöscht')->success()->send();
                })
                ->visible(fn (): bool => $this->getRecord()->workflow_status === WorkflowStatus::Completed),

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
                ->label('Projekt'),

            TextEntry::make('current_phase')
                ->label('Aktuelle Phase')
                ->placeholder('—'),

            TextEntry::make('current_status')
                ->label('Status')
                ->badge()
                ->color(fn (?string $state): string => match ($state) {
                    'running' => 'warning',
                    'completed' => 'success',
                    'failed' => 'danger',
                    'quality_gate_failed' => 'danger',
                    'no_changes' => 'info',
                    default => 'gray',
                }),

            TextEntry::make('workflow_status')
                ->label('Workflow')
                ->badge()
                ->color(fn (?WorkflowStatus $state): string => $state?->color() ?? 'gray')
                ->formatStateUsing(fn (?WorkflowStatus $state): string => $state?->label() ?? '—'),

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
                RunPhaseJob::dispatch($task->id, $phase);
                Notification::make()->title("{$label} gestartet")->success()->send();
                $this->redirect($this->getUrl());
            });
    }
}
