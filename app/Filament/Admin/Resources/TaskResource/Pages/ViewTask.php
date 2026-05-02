<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Domain\Phase\StateReader;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Jobs\RunPhaseJob;
use App\Models\Task;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    /** @var array<int, array{from_path: string, to_path: string, is_new: bool, is_deleted: bool, additions: int, deletions: int, hunks: array<int, mixed>}> */
    public array $diffFiles = [];

    public string $diffStat = '';

    public bool $diffLoaded = false;

    public int $diffLoadedForIteration = 0;

    public string $notes = '';

    public bool $editingNotes = false;

    public string $implementNotes = '';

    public bool $editingImplementNotes = false;

    /** @var array<string, array<int, array{text: string, class: string}>> keyed by "phase.iteration" */
    public array $loadedLogIterations = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Task $task */
        $task = $this->getRecord();

        app(StateReader::class)->syncToDb($task);
        $task->refresh();

        $this->notes = $task->concept_notes ?? '';
        $this->implementNotes = $task->implement_notes ?? '';

        $this->maybeAutoLoadDiff($task);
    }

    public function startEditingNotes(): void
    {
        $this->editingNotes = true;
    }

    public function saveNotes(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $task->update(['concept_notes' => $this->notes ?: null]);

        $this->editingNotes = false;
        Notification::make()->title('Feedback gespeichert')->success()->send();
    }

    public function cancelEditingNotes(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $this->notes = $task->fresh()->concept_notes ?? '';
        $this->editingNotes = false;
    }

    public function startEditingImplementNotes(): void
    {
        $this->editingImplementNotes = true;
    }

    public function saveImplementNotes(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $task->update(['implement_notes' => $this->implementNotes ?: null]);

        $this->editingImplementNotes = false;
        Notification::make()->title('Feedback gespeichert')->success()->send();
    }

    public function cancelEditingImplementNotes(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $this->implementNotes = $task->fresh()->implement_notes ?? '';
        $this->editingImplementNotes = false;
    }

    public function poll(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();

        $hasRunningPhaseRun = $task->phaseRuns()->where('status', 'running')->exists();
        if ($task->current_status !== 'running' || $hasRunningPhaseRun) {
            $reader = app(StateReader::class);
            $reader->syncToDb($task);
            $task->refresh();
            $this->notes = $task->concept_notes ?? '';
            $this->implementNotes = $task->implement_notes ?? '';
            $this->maybeAutoLoadDiff($task);
        }
    }

    public function loadDiff(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $branch = $task->repoProfile?->default_branch ?? 'main';
        $image = config('argos.worker_image');
        $vol = $task->volumeName();

        $statProcess = new Process([
            'docker', 'run', '--rm',
            '-v', "{$vol}:/workspace:ro",
            '--entrypoint', 'sh', $image,
            '-c',
            "git -C /workspace diff --stat origin/{$branch} 2>/dev/null; "
            ."echo ''; "
            .'git -C /workspace status --short 2>/dev/null',
        ]);
        $statProcess->setTimeout(15);
        $statProcess->run();
        $this->diffStat = trim($statProcess->getOutput());

        $diffProcess = new Process([
            'docker', 'run', '--rm',
            '-v', "{$vol}:/workspace:ro",
            '--entrypoint', 'sh', $image,
            '-c',
            "git -C /workspace diff origin/{$branch} 2>/dev/null | head -c 131072",
        ]);
        $diffProcess->setTimeout(15);
        $diffProcess->run();
        $this->diffFiles = $this->parseDiffStructured($diffProcess->getOutput());
        $this->diffLoaded = true;
    }

    private function maybeAutoLoadDiff(Task $task): void
    {
        $latestCompleted = (int) ($task->phaseRuns()
            ->where('phase', 'implement')
            ->where('status', 'completed')
            ->max('iteration') ?? 0);

        if ($latestCompleted > 0 && $latestCompleted > $this->diffLoadedForIteration) {
            $this->loadDiff();
            $this->diffLoadedForIteration = $latestCompleted;
        }
    }

    public function loadLogIteration(string $phase, int $iteration): void
    {
        $key = "{$phase}.{$iteration}";
        if (! isset($this->loadedLogIterations[$key])) {
            /** @var Task $task */
            $task = $this->getRecord();
            $this->loadedLogIterations[$key] = app(StateReader::class)
                ->readStreamLogIteration($task, $phase, $iteration);
        }
    }

    protected function getViewData(): array
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $reader = app(StateReader::class);

        $phaseRuns = $task->phaseRuns()->orderBy('iteration')->get()->groupBy('phase');

        $currentConceptIter = ($phaseRuns['concept'] ?? collect())->last()?->iteration;
        $currentImplementIter = ($phaseRuns['implement'] ?? collect())->last()?->iteration;

        $lastImplementRun = ($phaseRuns['implement'] ?? collect())->last();

        return [
            'phaseRuns' => $phaseRuns,
            'conceptHtml' => $task->concept_md ? Str::markdown($task->concept_md) : null,
            'conceptLog' => $this->parseLogLines($this->readLogFile('concept')),
            'implementLog' => $this->parseLogLines($this->readLogFile('implement')),
            'pushLog' => $this->parseLogLines($this->readLogFile('push')),
            'notesHistory' => $reader->readNotesHistory($task),
            'conceptHistory' => $reader->readConceptHistory($task, $currentConceptIter),
            'implementSummaryNontechnicalHtml' => $task->implement_summary_nontechnical
                ? Str::markdown($task->implement_summary_nontechnical)
                : null,
            'implementSummaryTechnicalHtml' => $task->implement_summary_technical
                ? Str::markdown($task->implement_summary_technical)
                : null,
            'implementHistory' => $reader->readImplementHistory($task, $currentImplementIter),
            'implementNotesHistory' => $reader->readImplementNotesHistory($task),
            'implementQualityGates' => $lastImplementRun?->result_json['quality_gates'] ?? null,
            'conceptLogIterations' => array_values(array_filter(
                $reader->listLogIterations($task, 'concept'),
                fn (int $i) => $i !== $currentConceptIter
            )),
            'implementLogIterations' => array_values(array_filter(
                $reader->listLogIterations($task, 'implement'),
                fn (int $i) => $i !== $currentImplementIter
            )),
        ];
    }

    public function getView(): string
    {
        return 'filament.admin.resources.task.view-task';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->makePhaseAction('concept', 'Konzept erstellen', 'heroicon-o-light-bulb')
                ->label(fn (): string => $this->getRecord()->phaseRuns()->where('phase', 'concept')->where('status', 'completed')->exists()
                    ? 'Konzept aktualisieren'
                    : 'Konzept erstellen')
                ->visible(fn (): bool => $this->getRecord()->workflow_status !== WorkflowStatus::Completed),

            $this->makePhaseAction('implement', 'Implement', 'heroicon-o-code-bracket')
                ->visible(fn (): bool => $this->getRecord()->workflow_status !== WorkflowStatus::Completed
                    && $this->getRecord()->phaseRuns()->where('phase', 'concept')->where('status', 'completed')->exists()),

            $this->makePhaseAction('push', 'Push & PR', 'heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => $this->getRecord()->workflow_status !== WorkflowStatus::Completed
                    && $this->getRecord()->phaseRuns()->where('phase', 'implement')->where('status', 'completed')->exists()),

            Action::make('respond')
                ->label('Respond')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->url(fn () => TaskResource::getUrl('respond', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => $this->getRecord()->workflow_status === WorkflowStatus::InReview),

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
                    $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
                }),

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
                    $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
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
                        'docker volume rm '.escapeshellarg($task->volumeName())
                    )->run();
                    Notification::make()->title('Workspace gelöscht')->success()->send();
                })
                ->visible(fn (): bool => $this->getRecord()->workflow_status === WorkflowStatus::Completed),
        ];
    }

    public function reviseConcept(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();
        if ($task->phaseRuns()->where('status', 'running')->exists()) {
            Notification::make()->title('Phase läuft bereits')->warning()->send();

            return;
        }
        $task->update(['current_phase' => 'concept', 'current_status' => 'running']);
        RunPhaseJob::dispatch($task->id, 'concept');
        Notification::make()->title('Konzept gestartet')->success()->send();
        $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
    }

    private function makePhaseAction(string $phase, string $label, string $icon): Action
    {
        return Action::make($phase)
            ->label($label)
            ->icon($icon)
            ->disabled(fn (): bool => $this->getRecord()->current_status === 'running')
            ->action(function () use ($phase, $label): void {
                /** @var Task $task */
                $task = $this->getRecord();
                if ($task->phaseRuns()->where('status', 'running')->exists()) {
                    Notification::make()->title('Phase läuft bereits')->warning()->send();

                    return;
                }
                $task->update(['current_phase' => $phase, 'current_status' => 'running']);
                RunPhaseJob::dispatch($task->id, $phase);
                Notification::make()->title("{$label} gestartet")->success()->send();
                $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
            });
    }

    private function readLogFile(string $phase): string
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $configDir = config('argos.config_dir');
        $path = "{$configDir}/tasks/{$task->name}/{$phase}.bg.log";

        if (! file_exists($path)) {
            return '';
        }

        $content = file_get_contents($path) ?: '';
        $lines = explode("\n", $content);
        if (count($lines) > 500) {
            $lines = array_slice($lines, -500);
            array_unshift($lines, '... (abgeschnitten — letzte 500 Zeilen)');
        }

        return implode("\n", $lines);
    }

    /** @return array<int, array{text: string, class: string}> */
    private function parseLogLines(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $result = [];
        foreach (explode("\n", $content) as $raw) {
            $line = (string) preg_replace('/\033\[[0-9;]*[mGKHFABCDJsu]/', '', $raw);
            $class = match (true) {
                str_contains($line, '[ERROR]'), str_contains($line, 'FAILED') => 'text-red-400',
                str_contains($line, '[WARN]') => 'text-amber-400',
                str_contains($line, '[INFO]') => 'text-slate-300',
                str_starts_with($line, '[tool:') => 'text-sky-400',
                str_contains($line, 'completed'), str_contains($line, ': OK') => 'text-emerald-400',
                str_starts_with(ltrim($line), '+') => 'text-emerald-500',
                str_starts_with(ltrim($line), '-') => 'text-red-500',
                $line === '' => 'text-slate-700',
                default => 'text-slate-400',
            };
            $result[] = ['text' => $line, 'class' => $class];
        }

        return $result;
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
