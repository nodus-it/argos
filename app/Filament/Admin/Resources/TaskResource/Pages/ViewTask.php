<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\TaskResource\Pages;

use App\Enums\DemoAccessMode;
use App\Enums\DemoStatus;
use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Filament\Admin\Resources\TaskResource;
use App\Jobs\DeployDemoJob;
use App\Jobs\StopDemoJob;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Services\Demo\DemoDeployer;
use App\Services\Task\TaskService;
use App\Services\Workflow\AgentStreamParser;
use App\Services\Workflow\StateReader;
use App\Support\ConceptMarkdown;
use App\Support\LogTail;
use App\Support\Workflow\TaskStage;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    /** @var array<int, array{from_path: string, to_path: string, is_new: bool, is_deleted: bool, additions: int, deletions: int, hunks: array<int, mixed>}> */
    public array $diffFiles = [];

    public string $diffStat = '';

    public bool $diffLoaded = false;

    /** Set when the diff command failed (e.g. timed out) so the UI can surface it instead of 500ing. */
    public ?string $diffError = null;

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

    public function saveImplementNotesAndRevise(bool $refine = false): void
    {
        /** @var Task $task */
        $task = $this->getRecord();

        try {
            app(TaskService::class)->saveImplementNotesAndRevise($task, $this->implementNotes, $refine);
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

    /**
     * Advance the workflow from the respond dock (M4). The phase-start
     * controls live in the dock at the bottom, not in the page header.
     */
    public function startPhaseFromDock(string $phase): void
    {
        /** @var Task $task */
        $task = $this->getRecord();

        try {
            app(TaskService::class)->startPhase($task, Phase::from($phase));
        } catch (\RuntimeException) {
            Notification::make()->title(__('tasks.view.actions.phase_already_running'))->warning()->send();

            return;
        }

        $title = match ($phase) {
            'concept' => __('tasks.view.actions.concept_started'),
            'implement' => __('tasks.view.actions.implement_started'),
            'push' => __('tasks.view.actions.push_started'),
            default => $phase,
        };
        Notification::make()->title($title)->success()->send();
        $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
    }

    public function startConceptFromDock(): void
    {
        // Saves the (optional) concept notes, then runs the concept phase —
        // used for the initial draft start and for retry-after-failure.
        $this->saveNotesAndRevise();
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

        // The diff shells out to `docker run` against the task volume. That can
        // be slow or hang (huge/polluted workspace, daemon pressure) and is
        // auto-triggered on mount — so a failure must degrade to an inline
        // notice, never 500 the whole page.
        try {
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
            $this->diffError = null;
        } catch (\Throwable $e) {
            $this->diffStat = '';
            $this->diffFiles = [];
            $this->diffError = __('tasks.view.diff.error');
            // report() routes through the exception handler (default log
            // stack) and never throws — unlike Log::channel('argos'), whose
            // file may be unwritable, which would re-escalate a handled
            // timeout into a 500.
            report($e);
        } finally {
            $this->diffLoaded = true;
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

        $stage = TaskStage::for($task);

        // Live log of the currently-running phase, streamed from the .bg.log
        // file (the stream_log column is only populated post-phase).
        $liveLog = ($stage->isRunning() && $task->current_phase !== null)
            ? app(AgentStreamParser::class)->parse($this->readLogFile($task->current_phase->value))
            : [];

        return [
            'stage' => $stage,
            'banner' => $this->bannerData($task, $stage),
            'thread' => $this->buildThread($task, $stage),
            'liveLog' => $liveLog,
            'phaseRuns' => $task->phaseRuns()->orderBy('iteration')->get()->groupBy('phase'),
            'demo' => $task->currentDemo(),
        ];
    }

    /**
     * Build the chronological thread: the task-created entry, then every phase
     * run (each iteration its own entry) interleaved with the feedback that
     * triggered it. Drives <x-argos.thread>. Workflow M3.
     *
     * @return list<array<string, mixed>>
     */
    private function buildThread(Task $task, TaskStage $stage): array
    {
        $items = [[
            'kind' => 'created',
            'title' => __('tasks.view.thread.created'),
            'who' => __('tasks.view.thread.you'),
            'time' => $task->created_at?->diffForHumans(),
            'body' => Str::limit($task->description ?? '', 400),
        ]];

        $runs = $task->phaseRuns()
            ->whereIn('phase', ['concept', 'implement', 'push', 'respond'])
            ->orderBy('id')
            ->get();

        /** @var array<string, int> $counts */
        $counts = $runs->groupBy(fn (PhaseRun $r): string => $r->phase->value)
            ->map->count()
            ->all();

        // The diff renders the live workspace, so it only makes sense on the
        // most recent code-producing run (implement or respond).
        $latestCodeRunId = $runs->whereIn('phase', [Phase::Implement, Phase::Respond])->last()?->id;

        foreach ($runs as $run) {
            $phase = $run->phase->value;

            $notes = match ($phase) {
                'concept' => $run->concept_notes,
                'implement', 'respond' => $run->implement_notes,
                default => null,
            };
            if ($notes !== null && trim($notes) !== '') {
                $items[] = [
                    'kind' => 'feedback',
                    'who' => __('tasks.view.thread.you'),
                    'time' => $run->started_at?->diffForHumans(),
                    'body' => $notes,
                ];
            }

            $items[] = $this->buildPhaseItem($run, $counts[$phase] ?? 1, $run->id === $latestCodeRunId);
        }

        // First run of a phase that's been dispatched but not yet picked up by
        // a worker has no phase_run row yet — show a placeholder so the thread
        // reflects "the system is about to work on this".
        if ($stage->isQueued() && ($phase = $stage->phase()?->value) !== null
            && ! $runs->contains(fn (PhaseRun $r): bool => $r->phase->value === $phase)) {
            $items[] = [
                'kind' => 'phase',
                'phase' => $phase,
                'iteration' => 1,
                'title' => $this->phaseTitle($phase, 1, 1),
                'state' => 'queued',
                'who' => __('tasks.view.thread.agent'),
                'time' => null,
                'cost' => null,
                'body' => __('tasks.view.thread.queued_body'),
                'error' => null,
                'conceptHtml' => null,
                'summaryHtml' => null,
                'techHtml' => null,
                'qualityGates' => null,
                'qualityGateLogKeys' => [],
                'iterationKey' => null,
                'hasStoredLog' => false,
                'isLive' => false,
                'showDiff' => false,
                'prUrl' => null,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPhaseItem(PhaseRun $run, int $count, bool $isLatestCode): array
    {
        $phase = $run->phase->value;
        $state = match ($run->status) {
            PhaseStatus::Running, PhaseStatus::RateLimited => 'running',
            PhaseStatus::Pending => 'queued',
            PhaseStatus::Paused => 'paused',
            PhaseStatus::Failed, PhaseStatus::QualityGateFailed, PhaseStatus::LockBlocked => 'failed',
            default => 'done',
        };
        $busy = in_array($state, ['running', 'queued'], true);

        $error = $state === 'failed' && $run->error_log !== null && trim($run->error_log) !== ''
            ? Str::limit(trim($run->error_log), 400)
            : null;

        $conceptHtml = null;
        $summaryHtml = null;
        $techHtml = null;
        $qualityGates = null;
        $qualityGateLogKeys = [];
        $prUrl = null;

        if ($phase === 'concept') {
            if ($run->concept_md !== null) {
                $conceptHtml = Str::markdown(ConceptMarkdown::stripOuterCodeFence($run->concept_md));
            }
            $body = $error ?? ($busy ? __('tasks.view.thread.concept_running_body') : __('tasks.view.thread.concept_body'));
        } elseif (in_array($phase, ['implement', 'respond'], true)) {
            if ($run->implement_summary_nontechnical !== null) {
                $summaryHtml = Str::markdown($run->implement_summary_nontechnical);
            }
            if ($run->implement_summary_technical !== null) {
                $techHtml = Str::markdown($run->implement_summary_technical);
            }
            $qualityGates = $run->result_json['quality_gates'] ?? null;
            $qualityGateLogKeys = array_keys($run->quality_gate_logs ?? []);
            $short = $run->implement_summary_nontechnical !== null
                ? Str::limit(trim(strip_tags(Str::markdown($run->implement_summary_nontechnical))), 180)
                : null;
            $body = $error ?? $short ?? ($busy ? __('tasks.view.thread.implement_running_body') : __('tasks.view.thread.implement_body'));
        } else { // push
            $prUrl = $run->result_json['pr_url'] ?? $this->task()->pr_url;
            $body = $error ?? ($busy ? __('tasks.view.thread.push_running_body') : __('tasks.view.thread.push_body'));
        }

        return [
            'kind' => 'phase',
            'phase' => $phase,
            'iteration' => $run->iteration,
            'title' => $this->phaseTitle($phase, $run->iteration, $count),
            'state' => $state,
            'who' => __('tasks.view.thread.agent'),
            'time' => ($run->finished_at ?? $run->started_at)?->diffForHumans(),
            'cost' => $run->cost_usd !== null ? '$'.number_format((float) $run->cost_usd, 2) : null,
            'body' => $body,
            'error' => $error,
            'conceptHtml' => $conceptHtml,
            'summaryHtml' => $summaryHtml,
            'techHtml' => $techHtml,
            'qualityGates' => $qualityGates,
            'qualityGateLogKeys' => $qualityGateLogKeys,
            // Lazy-load key for the stored stream log of this iteration.
            'iterationKey' => $run->stream_log !== null ? $phase.'.'.$run->iteration : null,
            'hasStoredLog' => $run->stream_log !== null,
            'isLive' => $state === 'running',
            'showDiff' => $isLatestCode && in_array($phase, ['implement', 'respond'], true),
            'prUrl' => $prUrl,
        ];
    }

    private function phaseTitle(string $phase, int $iteration, int $count): string
    {
        $base = match ($phase) {
            'concept' => __('tasks.view.thread.concept'),
            'implement' => __('tasks.view.thread.implement'),
            'respond' => __('tasks.view.thread.respond'),
            'push' => __('tasks.view.thread.push'),
            default => ucfirst($phase),
        };

        return $count > 1 ? $base.' v'.$iteration : $base;
    }

    public function getHeader(): ?View
    {
        $task = $this->task();
        $stage = TaskStage::for($task);

        return view('filament.admin.resources.task.task-detail-hero', [
            'task' => $task,
            'stage' => $stage,
            'headerActions' => $this->getCachedHeaderActions(),
            'logTail' => $stage->isRunning() ? $this->phaseLogTail() : [],
            'breadcrumbs' => filament()->hasBreadcrumbs() ? $this->getBreadcrumbs() : [],
        ]);
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    /** @return array<int, array{text: string, class: string}> Last 2 lines of the current running phase log. */
    public function phaseLogTail(): array
    {
        $task = $this->task();

        if ($task->current_phase === null) {
            return [];
        }

        $events = app(AgentStreamParser::class)->parse($this->readLogFile($task->current_phase->value));

        $lines = [];
        foreach ($events as $event) {
            $text = match ($event['kind']) {
                'tool_use' => trim(($event['tool'] ?? '').' '.($event['summary'] ?? '')),
                'result' => '',
                default => trim($event['text'] ?? ''),
            };
            if ($text !== '') {
                $lines[] = ['text' => $text, 'class' => $event['kind']];
            }
        }

        return array_slice($lines, -2);
    }

    public function getView(): string
    {
        // Chronological thread layout — the per-iteration workflow view (M3).
        return 'filament.admin.resources.task.view-task-thread';
    }

    protected function getHeaderActions(): array
    {
        // Phase advancement (start/refine/retry) lives in the respond dock at
        // the bottom now (M4). The header carries only the contextual primary
        // for states the dock doesn't own (resume a paused phase, unlock,
        // complete after PR) plus auxiliary actions in the ⋯ dropdown.
        $stage = TaskStage::for($this->task());

        $actions = [
            'continueConcept' => $this->makeContinueConceptAction()
                ->visible(fn (): bool => $this->lastConceptRun()?->status === PhaseStatus::Paused),

            'continueImplement' => $this->makeContinueImplementAction()
                ->visible(fn (): bool => $this->lastImplementRun()?->status === PhaseStatus::Paused),

            'forceUnlockImplement' => Action::make('forceUnlockImplement')
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

            'rebuildDemo' => Action::make('rebuildDemo')
                ->label(__('tasks.view.demo.rebuild'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading(__('tasks.view.demo.rebuild_heading'))
                ->modalDescription(__('tasks.view.demo.rebuild_description'))
                ->action(function (): void {
                    $task = $this->task();
                    DeployDemoJob::dispatch($task->id);
                    Notification::make()->title(__('tasks.view.demo.rebuild_queued'))->success()->send();
                    $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
                })
                ->visible(fn (): bool => (bool) config('argos.preview.enabled')
                    && (bool) $this->task()->repoProfile?->live_demo_enabled
                    && $this->task()->phaseRuns()->where('phase', 'implement')->where('status', 'completed')->exists()),

            'demoAccess' => Action::make('demoAccess')
                ->label(__('tasks.view.demo.access.label'))
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->modalHeading(__('tasks.view.demo.access.heading'))
                ->modalDescription(__('tasks.view.demo.access.description'))
                ->schema([
                    Select::make('access_mode')
                        ->label(__('tasks.view.demo.access.mode_label'))
                        ->options([
                            DemoAccessMode::Inherit->value => __('tasks.view.demo.access.mode_inherit', [
                                'default' => DemoAccessMode::Inherit->resolve()->label(),
                            ]),
                            DemoAccessMode::Session->value => DemoAccessMode::Session->label(),
                            DemoAccessMode::Basic->value => DemoAccessMode::Basic->label(),
                            DemoAccessMode::Public->value => DemoAccessMode::Public->label(),
                        ])
                        ->required()
                        ->live(),
                    TextInput::make('basic_password')
                        ->label(__('tasks.view.demo.access.password_label'))
                        ->helperText(__('tasks.view.demo.access.password_hint'))
                        ->password()
                        ->revealable()
                        ->visible(fn (Get $get): bool => $get('access_mode') === DemoAccessMode::Basic->value),
                ])
                ->fillForm(fn (): array => [
                    'access_mode' => ($this->task()->demo_access_mode ?? DemoAccessMode::Inherit)->value,
                    'basic_password' => $this->task()->demo_basic_password,
                ])
                ->action(function (array $data): void {
                    $task = $this->task();
                    $mode = DemoAccessMode::from($data['access_mode']);

                    $password = $task->demo_basic_password;
                    if ($mode->resolve() === DemoAccessMode::Basic) {
                        // Use the entered password, keep the existing one, or
                        // auto-generate so a basic demo is never passwordless.
                        $password = ($data['basic_password'] ?? null) ?: ($password ?: Str::random(16));
                    }

                    $task->update([
                        'demo_access_mode' => $mode,
                        'demo_basic_password' => $password,
                    ]);

                    try {
                        app(DemoDeployer::class)->applyAccessMode($task);
                    } catch (\RuntimeException $e) {
                        Notification::make()
                            ->title(__('tasks.view.demo.access.apply_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $note = Notification::make()->title(__('tasks.view.demo.access.saved'))->success();
                    if ($mode->resolve() === DemoAccessMode::Basic) {
                        $note->body(__('tasks.view.demo.access.basic_credentials', [
                            'user' => (string) config('argos.preview.basic_user', 'demo'),
                            'password' => (string) $password,
                        ]));
                    }
                    $note->send();

                    $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
                })
                ->visible(fn (): bool => (bool) config('argos.preview.enabled')
                    && (bool) $this->task()->repoProfile?->live_demo_enabled
                    && $this->task()->phaseRuns()->where('phase', 'implement')->where('status', 'completed')->exists()),

            'stopDemo' => Action::make('stopDemo')
                ->label(__('tasks.view.demo.stop'))
                ->icon('heroicon-o-stop-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('tasks.view.demo.stop_heading'))
                ->modalDescription(__('tasks.view.demo.stop_description'))
                ->action(function (): void {
                    $task = $this->task();
                    StopDemoJob::dispatch($task->id);
                    Notification::make()->title(__('tasks.view.demo.stop_queued'))->success()->send();
                    $this->redirect(TaskResource::getUrl('view', ['record' => $task]));
                })
                ->visible(fn (): bool => in_array(
                    $this->task()->currentDemo()?->status,
                    [DemoStatus::Building, DemoStatus::Live],
                    true,
                )),

            'logsDownload' => Action::make('logsDownload')
                ->label(__('tasks.view.actions.logs_download'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn (): string => TaskResource::getUrl('logs', ['record' => $this->task()])),

            'markCompleted' => Action::make('markCompleted')
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
        ];

        // During a running/queued phase, the header collapses to nothing but
        // the ⋯ menu (recovery + logs) — see the status banner above for the
        // live indicator. No phase controls while the worker is busy.
        $primaryKey = $stage->isBusy() ? null : $this->primaryActionKey($stage);
        $primary = ($primaryKey !== null && isset($actions[$primaryKey]))
            ? $actions[$primaryKey]->color('primary')
            : null;

        $rest = collect($actions)
            ->reject(fn ($action, string $key): bool => $key === $primaryKey)
            ->values()
            ->all();

        return array_values(array_filter([
            $primary,
            ActionGroup::make($rest)
                ->label(__('tasks.view.actions.more_label'))
                ->icon('heroicon-o-ellipsis-vertical')
                ->color('gray'),
        ]));
    }

    /**
     * The single contextual header primary, for states the respond dock does
     * not own: resume a paused phase, release a lock, or complete after PR.
     * Phase start/refine/retry live in the dock.
     */
    private function primaryActionKey(TaskStage $stage): ?string
    {
        return match ($stage) {
            TaskStage::ConceptPaused => 'continueConcept',
            TaskStage::ImplementPaused => 'continueImplement',
            TaskStage::ImplementFailed => $this->task()->current_status === PhaseStatus::LockBlocked
                ? 'forceUnlockImplement'
                : null,
            TaskStage::Review => 'markCompleted',
            default => null,
        };
    }

    private function task(): Task
    {
        /** @var Task */
        return $this->getRecord();
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

    public function lastPushRun(): ?PhaseRun
    {
        return $this->task()
            ->phaseRuns()
            ->where('phase', 'push')
            ->orderByDesc('iteration')
            ->first();
    }

    /**
     * Build the status-banner payload for the current stage — the single
     * "what is the system doing right now" header. See <x-argos.status-banner>.
     *
     * @return array{state: string, title: string, hint: string|null, startedAt: int|null, error: string|null, logsUrl: string|null, logsLabel: string|null}
     */
    private function bannerData(Task $task, TaskStage $stage): array
    {
        $hint = null;
        $error = null;
        $startedAt = null;
        $logsUrl = null;

        if ($stage->isRunning()) {
            $startedAt = $task->currentPhaseStartedAt()?->timestamp;
        } elseif ($stage->isQueued()) {
            $hint = __('tasks.view.banner.queued_hint');
        } elseif ($stage->isPaused()) {
            $hint = __('tasks.view.banner.paused_hint');
        } else {
            $hint = match ($stage) {
                TaskStage::ConceptReview => __('tasks.view.banner.concept_review_hint'),
                TaskStage::ImplementReview => __('tasks.view.banner.implement_review_hint'),
                TaskStage::Review => __('tasks.view.banner.review_hint'),
                default => null,
            };
        }

        if ($stage->isFailed()) {
            $run = match ($stage->phase()?->value) {
                'implement' => $this->lastImplementRun(),
                'push' => $this->lastPushRun(),
                default => $this->lastConceptRun(),
            };
            $raw = $run?->error_log;
            $error = ($raw !== null && trim($raw) !== '')
                ? Str::limit(trim($raw), 1200)
                : __('tasks.view.banner.failed_generic');
            $hint = $task->current_status === PhaseStatus::LockBlocked
                ? __('tasks.view.banner.lock_blocked_hint')
                : __('tasks.view.banner.failed_hint');
            $logsUrl = TaskResource::getUrl('logs', ['record' => $task]);
        }

        return [
            'state' => $stage->bannerState(),
            'title' => $stage->label(),
            'hint' => $hint,
            'startedAt' => $startedAt,
            'error' => $error,
            'logsUrl' => $logsUrl,
            'logsLabel' => null,
        ];
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

        return LogTail::read($path);
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
