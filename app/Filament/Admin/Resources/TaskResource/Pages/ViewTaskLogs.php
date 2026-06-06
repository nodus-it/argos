<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Enums\PhaseStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use App\Services\Workflow\AgentStreamParser;
use App\Services\Workflow\StateReader;
use App\Support\LogTail;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;

class ViewTaskLogs extends Page
{
    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.admin.resources.task.view-task-logs';

    public Task $task;

    public string $phase = 'concept';

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    public bool $isRunning = false;

    public string $updatedAt = '';

    public int $lineCount = 0;

    public function mount(string $record): void
    {
        $this->task = Task::findOrFail($record);
        $this->phase = request()->query('phase', $this->task->current_phase?->value ?? 'concept');
        $this->doRefresh();
    }

    public function setPhase(string $phase): void
    {
        $this->phase = $phase;
        $this->doRefresh();
    }

    public function poll(): void
    {
        $this->doRefresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadLog')
                ->label('Log herunterladen')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn () => route('tasks.logs.download', ['task' => $this->task->id, 'phase' => $this->phase]))
                ->openUrlInNewTab()
                ->visible(fn () => $this->currentLogExists()),

            Action::make('downloadBundle')
                ->label('Log-Bundle (ZIP)')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('gray')
                ->url(fn () => route('tasks.logs.bundle', ['task' => $this->task->id]))
                ->openUrlInNewTab(),

            Action::make('back')
                ->label('← Zurück zur Task')
                ->color('gray')
                ->url(fn () => TaskResource::getUrl('view', ['record' => $this->task])),
        ];
    }

    public function getTitle(): string
    {
        return "Logs — {$this->task->name}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            TaskResource::getUrl() => 'Tasks',
            TaskResource::getUrl('view', ['record' => $this->task]) => $this->task->name,
            '#' => 'Logs',
        ];
    }

    private function doRefresh(): void
    {
        $this->task->refresh();

        if ($this->task->current_status === PhaseStatus::Running) {
            app(StateReader::class)->syncToDb($this->task);
            $this->task->refresh();
        }

        $this->isRunning = $this->task->current_status === PhaseStatus::Running
            && $this->task->current_phase?->value === $this->phase;

        $raw = $this->readLogFile();
        $this->lines = app(AgentStreamParser::class)->parse($raw);
        $this->lineCount = count($this->lines);
        $this->updatedAt = now()->format('H:i:s');
    }

    private function currentLogExists(): bool
    {
        $configDir = config('argos.config_dir');

        return file_exists("{$configDir}/tasks/{$this->task->name}/{$this->phase}.bg.log");
    }

    private function readLogFile(): string
    {
        $configDir = config('argos.config_dir');
        $logPath = "{$configDir}/tasks/{$this->task->name}/{$this->phase}.bg.log";

        return LogTail::read($logPath);
    }
}
