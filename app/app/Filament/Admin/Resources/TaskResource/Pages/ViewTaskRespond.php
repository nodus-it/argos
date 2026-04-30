<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Domain\Phase\PhaseRunner;
use App\Filament\Admin\Resources\TaskResource;
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
                ->label('← Zurück zur Task')
                ->color('gray')
                ->url(TaskResource::getUrl('view', ['record' => $this->task])),
        ];
    }

    public function getTitle(): string
    {
        return "Review-Feedback — {$this->task->name}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            TaskResource::getUrl() => 'Tasks',
            TaskResource::getUrl('view', ['record' => $this->task]) => $this->task->name,
            '#' => 'Respond',
        ];
    }

    public function submitFeedback(): void
    {
        $feedback = trim($this->feedback);

        if ($feedback === '') {
            Notification::make()->title('Feedback darf nicht leer sein')->warning()->send();
            return;
        }

        if ($this->task->phaseRuns()->where('status', 'running')->exists()) {
            Notification::make()->title('Phase läuft bereits')->warning()->send();
            return;
        }

        $runner = app(PhaseRunner::class);

        try {
            $runner->writeFeedbackToVolume($this->task->name, $feedback);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Fehler beim Schreiben des Feedbacks')
                ->body($e->getMessage())
                ->danger()
                ->send();
            return;
        }

        $runner->startBackground($this->task, 'respond');

        Notification::make()->title('Respond gestartet')->success()->send();

        $this->redirect(TaskResource::getUrl('logs', ['record' => $this->task]));
    }
}
