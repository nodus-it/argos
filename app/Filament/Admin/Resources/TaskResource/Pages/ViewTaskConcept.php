<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Jobs\RunPhaseJob;
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
                ->label(__('tasks.view.actions.back'))
                ->color('gray')
                ->url(fn () => TaskResource::getUrl('view', ['record' => $this->task])),

            Action::make('runConcept')
                ->label(__('tasks.view.actions.run_concept'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription(__('tasks.view.actions.run_concept_description'))
                ->action(function (): void {
                    if ($this->task->phaseRuns()->where('status', 'running')->exists()) {
                        Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

                        return;
                    }
                    RunPhaseJob::dispatch($this->task->id, 'concept');
                    Notification::make()->title(__('tasks.view.actions.concept_started'))->success()->send();
                    $this->redirect(TaskResource::getUrl('logs', ['record' => $this->task]));
                }),
        ];
    }

    public function getTitle(): string
    {
        return __('tasks.view.concept.title').' — '.$this->task->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            TaskResource::getUrl() => 'Tasks',
            TaskResource::getUrl('view', ['record' => $this->task]) => $this->task->name,
            '#' => __('tasks.view.concept.breadcrumb'),
        ];
    }

    public function startEditingNotes(): void
    {
        $this->editingNotes = true;
    }

    public function saveNotes(): void
    {
        $this->task->update(['concept_notes' => $this->notes ?: null]);

        $this->editingNotes = false;
        Notification::make()->title(__('tasks.view.actions.notes_saved'))->success()->send();
    }

    public function cancelEditingNotes(): void
    {
        $this->editingNotes = false;
        $this->loadContent();
    }

    public function startImplement(): void
    {
        if ($this->task->phaseRuns()->where('status', 'running')->exists()) {
            Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

            return;
        }
        $this->task->update(['workflow_status' => WorkflowStatus::ImplementRunning]);
        RunPhaseJob::dispatch($this->task->id, 'implement');
        Notification::make()->title(__('tasks.view.actions.implement_started'))->success()->send();
        $this->redirect(TaskResource::getUrl('logs', ['record' => $this->task]));
    }

    private function loadContent(): void
    {
        $this->task->refresh();
        $this->hasConceptmd = $this->task->concept_md !== null;
        $this->conceptMarkdown = $this->task->concept_md ?? '';
        $this->notes = $this->task->concept_notes ?? '';
    }
}
