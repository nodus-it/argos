<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Enums\Phase;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use App\Services\Task\TaskService;
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
                    try {
                        app(TaskService::class)->startPhase($this->task, Phase::Concept);
                    } catch (\RuntimeException) {
                        Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

                        return;
                    }
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
        app(TaskService::class)->saveConceptNotes($this->task, $this->notes);

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
        try {
            app(TaskService::class)->startPhase($this->task, Phase::Implement);
        } catch (\RuntimeException) {
            Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

            return;
        }
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
