<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use App\Services\Git\WorkspaceDiffService;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;

class ViewTaskDiff extends Page
{
    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.admin.resources.task.view-task-diff';

    public Task $task;

    /** @var array<int, array{from_path: string, to_path: string, is_new: bool, is_deleted: bool, additions: int, deletions: int, hunks: list<array{header: string, context_hint: string, lines: list<array{type: string, old_num: int|null, new_num: int|null, text: string}>}>}> */
    public array $diffFiles = [];

    public string $stat = '';

    public bool $isEmpty = true;

    public string $updatedAt = '';

    public function mount(string $record): void
    {
        $this->task = Task::findOrFail($record);
        $this->loadDiff();
    }

    public function refresh(): void
    {
        $this->loadDiff();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Aktualisieren')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->loadDiff()),

            Action::make('back')
                ->label('← Zurück zur Task')
                ->color('gray')
                ->url(fn () => TaskResource::getUrl('view', ['record' => $this->task])),
        ];
    }

    public function getTitle(): string
    {
        return "Diff — {$this->task->name}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            TaskResource::getUrl() => 'Tasks',
            TaskResource::getUrl('view', ['record' => $this->task]) => $this->task->name,
            '#' => 'Diff',
        ];
    }

    private function loadDiff(): void
    {
        $result = app(WorkspaceDiffService::class)->forTask($this->task);

        $this->stat = $result['stat'];
        $this->diffFiles = $result['files'];
        $this->isEmpty = empty($this->diffFiles);
        $this->updatedAt = now()->format('H:i:s');
    }
}
