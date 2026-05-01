<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Domain\Phase\PhaseRunner;
use App\Domain\Phase\StateReader;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class ViewTaskConcept extends Page
{
    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.admin.resources.task.view-task-concept';

    public Task $task;

    public string $conceptMarkdown = '';

    public string $notes = '';

    public bool $hasConceptmd = false;

    public bool $editingNotes = false;

    public function mount(string $record): void
    {
        $this->task = Task::findOrFail($record);
        $this->loadContent();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('← Zurück zur Task')
                ->color('gray')
                ->url(fn () => TaskResource::getUrl('view', ['record' => $this->task])),

            Action::make('runConcept')
                ->label('Concept neu ausführen')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Startet einen neuen Concept-Lauf. Vorhandene Notes werden als Feedback übergeben.')
                ->action(function (): void {
                    if ($this->task->phaseRuns()->where('status', 'running')->exists()) {
                        Notification::make()->title('Phase läuft bereits')->warning()->send();
                        return;
                    }
                    app(PhaseRunner::class)->startBackground($this->task, 'concept');
                    Notification::make()->title('Concept gestartet')->success()->send();
                    $this->redirect(TaskResource::getUrl('logs', ['record' => $this->task]));
                }),
        ];
    }

    public function getTitle(): string
    {
        return "Konzept — {$this->task->name}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            TaskResource::getUrl() => 'Tasks',
            TaskResource::getUrl('view', ['record' => $this->task]) => $this->task->name,
            '#' => 'Konzept',
        ];
    }

    public function startEditingNotes(): void
    {
        $this->editingNotes = true;
    }

    public function saveNotes(): void
    {
        $reader = app(StateReader::class);

        if (!$reader->writeNotes($this->task->name, $this->notes)) {
            Notification::make()->title('Notes konnten nicht gespeichert werden')->danger()->send();
            return;
        }

        $this->editingNotes = false;
        Notification::make()->title('Notes gespeichert')->success()->send();
    }

    public function cancelEditingNotes(): void
    {
        $this->editingNotes = false;
        $this->loadContent();
    }

    private function loadContent(): void
    {
        $reader = app(StateReader::class);

        $concept = $reader->readConcept($this->task->name);
        $this->hasConceptmd = $concept !== null;
        $this->conceptMarkdown = $concept ?? '';

        $this->notes = $reader->readNotes($this->task->name) ?? '';
    }
}
