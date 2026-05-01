<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Filament\Admin\Resources\TaskResource;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Symfony\Component\Process\Process;

class ViewTaskDiff extends Page
{
    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.admin.resources.task.view-task-diff';

    public Task $task;

    /** @var array<int, array{text: string, class: string}> */
    public array $lines = [];

    public string $stat   = '';
    public bool $isEmpty  = true;
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
        $branch    = $this->task->repoProfile?->default_branch ?? 'main';
        $image     = config('argos.worker_image');
        $taskName  = $this->task->name;

        $statProcess = new Process([
            'docker', 'run', '--rm',
            '-v', "task_ws_{$taskName}:/workspace:ro",
            '--entrypoint', 'sh',
            $image,
            '-c',
            "git -C /workspace diff --stat origin/{$branch}...HEAD 2>/dev/null; "
            . "echo ''; "
            . "git -C /workspace status --short 2>/dev/null",
        ]);
        $statProcess->setTimeout(15);
        $statProcess->run();
        $this->stat = trim($statProcess->getOutput());

        $diffProcess = new Process([
            'docker', 'run', '--rm',
            '-v', "task_ws_{$taskName}:/workspace:ro",
            '--entrypoint', 'sh',
            $image,
            '-c',
            "git -C /workspace diff origin/{$branch}...HEAD 2>/dev/null | head -c 131072",
        ]);
        $diffProcess->setTimeout(15);
        $diffProcess->run();

        $raw          = $diffProcess->getOutput();
        $this->isEmpty = trim($raw) === '';
        $this->lines   = $this->parseLines($raw);
        $this->updatedAt = now()->format('H:i:s');
    }

    /** @return array<int, array{text: string, class: string}> */
    private function parseLines(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $result = [];
        foreach (explode("\n", $content) as $raw) {
            $line = (string) preg_replace('/\033\[[0-9;]*[mGKHFABCDJsu]/', '', $raw);

            $class = match (true) {
                str_starts_with($line, '+++'),
                str_starts_with($line, '---')    => 'text-slate-400 font-semibold',
                str_starts_with($line, '@@')      => 'text-sky-400',
                str_starts_with($line, 'diff '),
                str_starts_with($line, 'index '),
                str_starts_with($line, 'new file'),
                str_starts_with($line, 'deleted ') => 'text-slate-500',
                str_starts_with($line, '+')        => 'text-emerald-400',
                str_starts_with($line, '-')        => 'text-red-400',
                $line === ''                        => 'text-slate-700',
                default                            => 'text-slate-300',
            };

            $result[] = ['text' => $line, 'class' => $class];
        }

        return $result;
    }
}
