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
        $branch = $this->task->repoProfile?->default_branch ?? 'main';
        $image = config('argos.worker_image');
        $taskName = $this->task->name;

        $statProcess = new Process([
            'docker', 'run', '--rm',
            '-v', 'task_ws_'.Task::slugifyName($taskName).':/workspace:ro',
            '--entrypoint', 'sh',
            $image,
            '-c',
            "git -C /workspace diff --stat origin/{$branch} 2>/dev/null; "
            .'git -C /workspace ls-files --others --exclude-standard 2>/dev/null | while IFS= read -r f; do echo " (neu) $f"; done; '
            ."echo ''; "
            .'git -C /workspace status --short 2>/dev/null',
        ]);
        $statProcess->setTimeout(15);
        $statProcess->run();
        $this->stat = trim($statProcess->getOutput());

        $diffProcess = new Process([
            'docker', 'run', '--rm',
            '-v', 'task_ws_'.Task::slugifyName($taskName).':/workspace:ro',
            '--entrypoint', 'sh',
            $image,
            '-c',
            "{ git -C /workspace diff origin/{$branch} 2>/dev/null; "
            .'git -C /workspace ls-files --others --exclude-standard 2>/dev/null | while IFS= read -r f; do '
            .'git -C /workspace diff --no-index -- /dev/null "$f" 2>/dev/null || true; '
            .'done; } | head -c 131072',
        ]);
        $diffProcess->setTimeout(15);
        $diffProcess->run();

        $raw = $diffProcess->getOutput();
        $this->diffFiles = $this->parseDiffStructured($raw);
        $this->isEmpty = empty($this->diffFiles);
        $this->updatedAt = now()->format('H:i:s');
    }

    /**
     * Parse a unified diff into a structured representation for GitHub-style rendering.
     *
     * @return array<int, array{from_path: string, to_path: string, is_new: bool, is_deleted: bool, additions: int, deletions: int, hunks: list<array{header: string, context_hint: string, lines: list<array{type: string, old_num: int|null, new_num: int|null, text: string}>}>}>
     */
    private function parseDiffStructured(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $files = [];
        $currentFile = null;
        $currentHunk = null;
        $oldLine = 0;
        $newLine = 0;

        foreach (explode("\n", $content) as $raw) {
            $line = (string) preg_replace('/\033\[[0-9;]*[mGKHFABCDJsu]/', '', $raw);

            if (str_starts_with($line, 'diff --git ')) {
                if ($currentFile !== null) {
                    if ($currentHunk !== null) {
                        $currentFile['hunks'][] = $currentHunk;
                        $currentHunk = null;
                    }
                    $files[] = $currentFile;
                }
                preg_match('/^diff --git a\/(.+) b\/(.+)$/', $line, $m);
                $currentFile = [
                    'from_path' => $m[1] ?? '',
                    'to_path' => $m[2] ?? '',
                    'is_new' => false,
                    'is_deleted' => false,
                    'additions' => 0,
                    'deletions' => 0,
                    'hunks' => [],
                ];

                continue;
            }

            if ($currentFile === null) {
                continue;
            }

            if (str_starts_with($line, 'new file')) {
                $currentFile['is_new'] = true;

                continue;
            }

            if (str_starts_with($line, 'deleted file')) {
                $currentFile['is_deleted'] = true;

                continue;
            }

            if (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ') || str_starts_with($line, 'index ')) {
                continue;
            }

            if (str_starts_with($line, '@@')) {
                if ($currentHunk !== null) {
                    $currentFile['hunks'][] = $currentHunk;
                }
                preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/', $line, $m);
                $oldLine = isset($m[1]) ? (int) $m[1] : 1;
                $newLine = isset($m[2]) ? (int) $m[2] : 1;
                $currentHunk = [
                    'header' => $line,
                    'context_hint' => trim($m[3] ?? ''),
                    'lines' => [],
                ];

                continue;
            }

            if ($currentHunk === null) {
                continue;
            }

            $firstChar = $line !== '' ? $line[0] : ' ';

            if ($firstChar === '+') {
                $currentHunk['lines'][] = [
                    'type' => 'add',
                    'old_num' => null,
                    'new_num' => $newLine++,
                    'text' => substr($line, 1),
                ];
                $currentFile['additions']++;
            } elseif ($firstChar === '-') {
                $currentHunk['lines'][] = [
                    'type' => 'del',
                    'old_num' => $oldLine++,
                    'new_num' => null,
                    'text' => substr($line, 1),
                ];
                $currentFile['deletions']++;
            } else {
                $currentHunk['lines'][] = [
                    'type' => 'context',
                    'old_num' => $oldLine++,
                    'new_num' => $newLine++,
                    'text' => substr($line, 1),
                ];
            }
        }

        if ($currentHunk !== null && $currentFile !== null) {
            $currentFile['hunks'][] = $currentHunk;
        }
        if ($currentFile !== null) {
            $files[] = $currentFile;
        }

        return $files;
    }
}
