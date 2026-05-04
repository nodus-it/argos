<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Domain\Phase\PhaseRunner;
use App\Filament\Admin\Resources\TaskResource;
use App\Jobs\RunPhaseJob;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class ViewTaskRespond extends Page
{
    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.admin.resources.task.view-task-respond';

    public Task $task;

    public string $feedback = '';

    public function mount(string $record): void
    {
        $this->task = Task::findOrFail($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('tasks.view.actions.back'))
                ->color('gray')
                ->url(fn () => TaskResource::getUrl('view', ['record' => $this->task])),
        ];
    }

    public function getTitle(): string
    {
        return __('tasks.view.respond.title').' — '.$this->task->name;
    }

    public function getBreadcrumbs(): array
    {
        return [
            TaskResource::getUrl() => 'Tasks',
            TaskResource::getUrl('view', ['record' => $this->task]) => $this->task->name,
            '#' => __('tasks.view.respond.breadcrumb'),
        ];
    }

    public function submitFeedback(): void
    {
        $feedback = trim($this->feedback);

        if ($feedback === '') {
            Notification::make()->title(__('tasks.view.actions.feedback_empty'))->warning()->send();

            return;
        }

        if ($this->task->phaseRuns()->where('status', 'running')->exists()) {
            Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

            return;
        }

        try {
            app(PhaseRunner::class)->writeFeedbackToVolume($this->task->name, $feedback);
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('tasks.view.actions.feedback_write_error'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        RunPhaseJob::dispatch($this->task->id, 'respond');

        Notification::make()->title(__('tasks.view.actions.respond_started'))->success()->send();

        $this->redirect(TaskResource::getUrl('logs', ['record' => $this->task]));
    }
}
