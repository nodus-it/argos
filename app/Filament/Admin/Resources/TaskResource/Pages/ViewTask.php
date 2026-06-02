<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Services\Task\TaskService;
use App\Services\Workflow\StateReader;
use App\Support\ConceptMarkdown;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

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
        app(TaskService::class)->saveConceptNotes($task, $this->notes);

        $this->editingNotes = false;
        Notification::make()->title(__('tasks.view.actions.feedback_saved'))->success()->send();
    }

    public function saveNotesAndRevise(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();

        try {
            app(TaskService::class)->saveConceptNotesAndRevise($task, $this->notes);
        } catch (\RuntimeException) {
            Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

            return;
        }

        $this->editingNotes = false;
        Notification::make()->title(__('tasks.view.actions.concept_started'))->success()->send();
        $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
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
        app(TaskService::class)->saveImplementNotes($task, $this->implementNotes);

        $this->editingImplementNotes = false;
        Notification::make()->title(__('tasks.view.actions.feedback_saved'))->success()->send();
    }

    public function saveImplementNotesAndRevise(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();

        try {
            app(TaskService::class)->saveImplementNotesAndRevise($task, $this->implementNotes);
        } catch (\RuntimeException) {
            Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

            return;
        }

        $this->editingImplementNotes = false;
        Notification::make()->title(__('tasks.view.actions.implement_started'))->success()->send();
        $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
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

        $reader = app(StateReader::class);
        $reader->syncToDb($task);
        $task->refresh();
        $this->notes = $task->concept_notes ?? '';
        $this->implementNotes = $task->implement_notes ?? '';
        $this->maybeAutoLoadDiff($task);
    }

    public function loadDiff(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();
        $branch = $task->repoProfile?->default_branch ?? 'main';
        // alpine/git is the smallest image with `git` + `sh` available; keeps
        // the diff view agent-/stack-agnostic so it works the same regardless
        // of which worker image the task ran on. The container runs as root
        // while the volume is owned by the worker uid (1000) — without
        // `-c safe.directory='*'` git refuses every read with "dubious
        // ownership", which silently fell through to a useless --no-index
        // path until 2026-05.
        $image = 'alpine/git';
        $vol = $task->volumeName();
        $g = "git -c safe.directory='*' -C /workspace";

        $statResult = Process::timeout(15)->run([
            'docker', 'run', '--rm',
            '-v', "{$vol}:/workspace:ro",
            '--entrypoint', 'sh', $image,
            '-c',
            "{$g} diff --stat origin/{$branch} 2>/dev/null; "
            .$g.' ls-files --others --exclude-standard 2>/dev/null | while IFS= read -r f; do echo " (neu) $f"; done; '
            ."echo ''; "
            .$g.' status --short 2>/dev/null',
        ]);
        $this->diffStat = trim($statResult->output());

        $diffResult = Process::timeout(15)->run([
            'docker', 'run', '--rm',
            '-v', "{$vol}:/workspace:ro",
            '--entrypoint', 'sh', $image,
            '-c',
            "{ {$g} diff origin/{$branch} 2>/dev/null; "
            .$g.' ls-files --others --exclude-standard 2>/dev/null | while IFS= read -r f; do '
            .$g.' diff --no-index -- /dev/null "$f" 2>/dev/null || true; '
            .'done; } | head -c 131072',
        ]);
        $this->diffFiles = $this->parseDiffStructured($diffResult->output());
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
        $task->loadMissing(['externalIssueLink.binding']);
        $reader = app(StateReader::class);

        $phaseRuns = $task->phaseRuns()->orderBy('iteration')->get()->groupBy('phase');

        $currentConceptIter = ($phaseRuns['concept'] ?? collect())->last()?->iteration;
        $currentImplementIter = ($phaseRuns['implement'] ?? collect())->last()?->iteration;

        $lastConceptRun = ($phaseRuns['concept'] ?? collect())->last();
        $lastImplementRun = ($phaseRuns['implement'] ?? collect())->last();

        // Defense-in-depth: rows persisted before PhaseRunner started
        // stripping the outer ```markdown wrapper still have it. Strip on
        // render so the UI heals old data without a backfill migration.
        $conceptMd = $task->concept_md !== null
            ? ConceptMarkdown::stripOuterCodeFence($task->concept_md)
            : null;

        return [
            'phaseRuns' => $phaseRuns,
            'conceptHtml' => $conceptMd !== null ? Str::markdown($conceptMd) : null,
            'conceptError' => $lastConceptRun?->status !== PhaseStatus::Completed
                ? $lastConceptRun?->error_log
                : null,
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
            'implementQualityGateLogKeys' => array_keys($lastImplementRun?->quality_gate_logs ?? []),
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

    public function getHeading(): string|Htmlable
    {
        // Task name + status badge inline in the page header (next to the
        // title, with the actions on the right). See ARGOS_REDESIGN.md §6.3.
        $task = $this->task();
        $badge = Blade::render(
            '<x-argos.badge :status="$status" :label="$label" />',
            ['status' => $task->displayBadgeStatus(), 'label' => $task->displayStatusLabel()],
        );

        return new HtmlString(
            '<span class="td-heading-name">'.e($task->name).'</span>'
            .'<span class="td-heading-badge">'.$badge.'</span>'
        );
    }

    public function getView(): string
    {
        // Warm-Paper redesign: chronological thread layout. The previous
        // section/tab view stays at ...task.view-task as a fallback to revert
        // to. See docs/design/argos/ARGOS_REDESIGN.md §6.3.
        return 'filament.admin.resources.task.view-task-thread';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->makePhaseAction('concept', __('tasks.view.actions.concept_create'), 'heroicon-o-light-bulb')
                ->label(fn (): string => $this->task()->phaseRuns()->where('phase', 'concept')->where('status', 'completed')->exists()
                    ? __('tasks.view.actions.concept_update')
                    : __('tasks.view.actions.concept_create'))
                ->visible(fn (): bool => $this->task()->workflow_status->value !== 'completed'
                    && $this->lastConceptRun()?->status !== PhaseStatus::Paused),

            $this->makeContinueConceptAction()
                ->visible(fn (): bool => $this->lastConceptRun()?->status === PhaseStatus::Paused),

            $this->makePhaseAction('implement', __('tasks.view.actions.implement'), 'heroicon-o-code-bracket')
                ->visible(fn (): bool => $this->task()->workflow_status->value !== 'completed'
                    && $this->task()->phaseRuns()->where('phase', 'concept')->where('status', 'completed')->exists()),

            $this->makeContinueImplementAction()
                ->visible(fn (): bool => $this->lastImplementRun()?->status === PhaseStatus::Paused),

            $this->makePhaseAction('push', __('tasks.view.actions.push_pr'), 'heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => $this->task()->workflow_status->value !== 'completed'
                    && $this->task()->phaseRuns()->where('phase', 'implement')->where('status', 'completed')->exists()),

            // One primary action per screen; utilities collapse into a ⋯ menu.
            ActionGroup::make([
                Action::make('forceUnlockImplement')
                    ->label(__('tasks.view.actions.force_unlock_label'))
                    ->icon('heroicon-o-lock-open')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('tasks.view.actions.force_unlock_heading'))
                    ->modalDescription(__('tasks.view.actions.force_unlock_description'))
                    ->modalSubmitActionLabel(__('tasks.view.actions.force_unlock_submit'))
                    ->action(function (): void {
                        $task = $this->task();
                        try {
                            app(TaskService::class)->forceUnlockImplement($task);
                        } catch (\RuntimeException) {
                            Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

                            return;
                        }
                        Notification::make()->title(__('tasks.view.actions.lock_released'))->success()->send();
                        $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
                    })
                    ->visible(fn (): bool => $this->task()->current_status === PhaseStatus::LockBlocked),

                Action::make('logsDownload')
                    ->label(__('tasks.view.actions.logs_download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (): string => TaskResource::getUrl('logs', ['record' => $this->task()])),

                Action::make('markCompleted')
                    ->label(__('tasks.view.actions.mark_completed'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('tasks.view.actions.mark_completed_description'))
                    ->action(function (): void {
                        $task = $this->task();
                        app(TaskService::class)->markCompleted($task);
                        Notification::make()->title(__('tasks.view.actions.task_completed'))->success()->send();
                        $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
                    })
                    ->visible(fn (): bool => $this->task()->workflow_status->value !== 'completed'),
            ])
                ->label(__('tasks.view.actions.more_label'))
                ->icon('heroicon-o-ellipsis-vertical')
                ->color('gray'),
        ];
    }

    private function task(): Task
    {
        /** @var Task */
        return $this->getRecord();
    }

    public function reviseConcept(): void
    {
        /** @var Task $task */
        $task = $this->getRecord();

        try {
            app(TaskService::class)->startPhase($task, Phase::Concept);
        } catch (\RuntimeException) {
            Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

            return;
        }

        Notification::make()->title(__('tasks.view.actions.concept_started'))->success()->send();
        $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
    }

    private function makePhaseAction(string $phase, string $label, string $icon): Action
    {
        return Action::make($phase)
            ->label($label)
            ->icon($icon)
            ->disabled(fn (): bool => $this->task()->current_status === PhaseStatus::Running)
            ->action(function () use ($phase): void {
                $task = $this->task();
                try {
                    app(TaskService::class)->startPhase($task, Phase::from($phase));
                } catch (\RuntimeException) {
                    Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

                    return;
                }
                $notificationTitle = match ($phase) {
                    'concept' => __('tasks.view.actions.concept_started'),
                    'implement' => __('tasks.view.actions.implement_started'),
                    'push' => __('tasks.view.actions.push_started'),
                    default => $phase,
                };
                Notification::make()->title($notificationTitle)->success()->send();
                $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
            });
    }

    public function lastImplementRun(): ?PhaseRun
    {
        return $this->task()
            ->phaseRuns()
            ->where('phase', 'implement')
            ->orderByDesc('iteration')
            ->first();
    }

    public function lastConceptRun(): ?PhaseRun
    {
        return $this->task()
            ->phaseRuns()
            ->where('phase', 'concept')
            ->orderByDesc('iteration')
            ->first();
    }

    private function makeContinueImplementAction(): Action
    {
        return Action::make('continueImplement')
            ->label(__('tasks.view.actions.continue'))
            ->icon('heroicon-o-play-circle')
            ->color('warning')
            ->disabled(fn (): bool => $this->task()->current_status === PhaseStatus::Running)
            ->modalHeading(__('tasks.view.actions.continue_heading'))
            ->modalDescription(fn (): string => __('tasks.view.actions.continue_description')
                .($this->task()->hasRepeatedMaxTurns('implement')
                    ? "\n\n".__('tasks.view.actions.max_turns_repeated_hint')
                    : ''))
            ->modalSubmitActionLabel(__('tasks.view.actions.continue'))
            ->schema([
                TextInput::make('max_turns')
                    ->label(__('tasks.view.actions.max_turns_label'))
                    ->helperText(__('tasks.view.actions.max_turns_helper'))
                    ->numeric()
                    ->minValue(10)
                    ->maxValue(1000)
                    ->required()
                    ->default(fn (): int => $this->task()->max_turns_implement
                        ?? $this->task()->repoProfile?->max_turns_implement
                        ?? (int) config('argos.implement.max_turns_default', 200)),
            ])
            ->action(function (array $data): void {
                $task = $this->task();
                $maxTurns = (int) $data['max_turns'];
                try {
                    app(TaskService::class)->continueImplement($task, $maxTurns);
                } catch (\RuntimeException) {
                    Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

                    return;
                }
                Notification::make()
                    ->title(__('tasks.view.actions.implement_continued'))
                    ->body(__('tasks.view.actions.implement_continued_body', ['max_turns' => $maxTurns]))
                    ->success()
                    ->send();
                $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
            });
    }

    private function makeContinueConceptAction(): Action
    {
        return Action::make('continueConcept')
            ->label(__('tasks.view.actions.continue_concept'))
            ->icon('heroicon-o-play-circle')
            ->color('warning')
            ->disabled(fn (): bool => $this->task()->current_status === PhaseStatus::Running)
            ->modalHeading(__('tasks.view.actions.continue_concept_heading'))
            ->modalDescription(fn (): string => __('tasks.view.actions.continue_concept_description')
                .($this->task()->hasRepeatedMaxTurns('concept')
                    ? "\n\n".__('tasks.view.actions.max_turns_repeated_hint')
                    : ''))
            ->modalSubmitActionLabel(__('tasks.view.actions.continue_concept'))
            ->schema([
                TextInput::make('max_turns')
                    ->label(__('tasks.view.actions.max_turns_label'))
                    ->helperText(__('tasks.view.actions.max_turns_helper'))
                    ->numeric()
                    ->minValue(10)
                    ->maxValue(1000)
                    ->required()
                    ->default(fn (): int => $this->task()->max_turns_concept
                        ?? $this->task()->repoProfile?->max_turns_concept
                        ?? (int) config('argos.concept.max_turns_default', 50)),
            ])
            ->action(function (array $data): void {
                $task = $this->task();
                $maxTurns = (int) $data['max_turns'];
                try {
                    app(TaskService::class)->continueConcept($task, $maxTurns);
                } catch (\RuntimeException) {
                    Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

                    return;
                }
                Notification::make()
                    ->title(__('tasks.view.actions.concept_continued'))
                    ->body(__('tasks.view.actions.concept_continued_body', ['max_turns' => $maxTurns]))
                    ->success()
                    ->send();
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
            array_unshift($lines, __('tasks.view.logs.truncated'));
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
