<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\ApprovalKind;
use App\Enums\AutoTaskState;
use App\Enums\Capability;
use App\Enums\PermissionMode;
use App\Enums\ReviewCommentStatus;
use App\Enums\SessionStatus;
use App\Enums\TaskEventType;
use App\Enums\WorkspaceRole;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\TaskApproval;
use App\Models\TaskCheckpoint;
use App\Models\TaskEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\AgentPool;
use App\Services\Workspace\AutoSettings;
use App\Services\Workspace\AutoTaskDriver;
use App\Services\Workspace\ModelCatalog;
use App\Services\Workspace\ReviewCommentService;
use App\Services\Workspace\TaskAccess;
use App\Services\Workspace\TaskAttachmentService;
use App\Services\Workspace\TaskCheckpointService;
use App\Services\Workspace\TaskReadService;
use App\Services\Workspace\TaskPreparer;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceIssueService;
use App\Services\Workspace\WorkspaceSetupService;
use App\Support\TaskTimeline;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The chat of a workspace: history, approvals and the composer of the
 * task picked in the header's switcher (TaskSwitcher). Live streaming happens client-side (resources/js/workspace);
 * this component renders persisted state and runs every action through
 * TaskService, which enforces the policies.
 */
class TaskChat extends Component
{
    use WithFileUploads;

    private const PAGE = 200;

    /** Commands Argos handles itself instead of sending them to Claude. */
    public const ARGOS_COMMANDS = ['plan', 'ship', 'preview', 'review', 'gates', 'checkpoint'];

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    #[Locked]
    public string $workspaceId;

    #[Url(as: 'task')]
    public ?string $taskId = null;

    public int $limit = self::PAGE;

    public string $draft = '';

    public bool $creating = false;

    public string $newTitle = '';

    public ?string $newAgentId = null;

    public ?string $newModel = null;

    /** New task as an auto-task (AutoTaskDriver) — empty overrides use the project defaults. */
    public bool $newAuto = false;

    public string $newPrompt = '';

    public ?string $newPlan = null;

    public ?string $newShip = null;

    public ?int $newMaxFixRounds = null;

    public string $newMode = 'default';

    /** Ticket of the workspace's tracker the new task works on (its number). */
    public ?string $newIssue = null;

    public string $newIssueQuery = '';

    public string $newBaseBranch = '';

    /** The pull request merges itself once the pipeline is green. */
    public bool $newAutoMerge = false;

    /** Set when the new task continues a finished one. */
    #[Locked]
    public ?string $newParentId = null;

    /** "Reset to here" dialog: the checkpoint and its diff preview. */
    public ?string $restoreId = null;

    public string $restoreDiff = '';

    public bool $restoreTruncated = false;

    public bool $restoreCommitsSince = false;

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);

        if ($this->taskId === null || $this->activeTask() === null) {
            $this->taskId = $this->tasks()->first()?->id;
        }
        $this->newAgentId = $this->usableAgents()->first()?->id;
        $this->creating = $this->taskId === null;
    }

    #[On('task-select')]
    public function selectTask(string $id): void
    {
        if ($this->tasks(withArchived: true)->contains('id', $id)) {
            $this->taskId = $id;
            $this->limit = self::PAGE;
            $this->creating = false;
            $this->announceTask();
        }
    }

    #[On('task-new')]
    public function startCreating(): void
    {
        $this->creating = true;
        $this->newParentId = null;
        $this->newAgentId ??= $this->usableAgents()->first()?->id;
    }

    /** "Follow-up task" in the header: the form starts from the finished task. */
    #[On('task-follow-up')]
    public function startFollowUp(string $id): void
    {
        $parent = $this->tasks(withArchived: true)->firstWhere('id', $id);
        if ($parent === null) {
            return;
        }
        $this->startCreating();
        $this->newParentId = $parent->id;
        $this->newTitle = mb_substr(__('workspaces.task.follow_up_title', ['title' => $parent->title]), 0, 120);
        $this->newBaseBranch = $parent->base_branch;
        $this->newIssue = $parent->external_ref['number'] ?? null;
    }

    /** Pick a ticket for the new task; its title becomes the default title. */
    public function chooseIssue(?string $number): void
    {
        $this->newIssue = $number;
        $this->newIssueQuery = '';
        if ($number !== null && trim($this->newTitle) === '') {
            $label = $this->issueOptions($number, 200)[$number] ?? null;
            $this->newTitle = $label !== null ? mb_substr((string) preg_replace('/^#\S+\s+/', '', $label), 0, 120) : '';
        }
    }

    /**
     * Open issues of the workspace's tracker matching the search.
     *
     * @return array<string, string>
     */
    private function issueOptions(string $query, int $limit = 8): array
    {
        return app(WorkspaceIssueService::class)->search($this->workspace(), $query, $limit);
    }

    /** Keep the header's switcher on the task shown here. */
    private function announceTask(): void
    {
        $this->dispatch('task-changed', id: $this->taskId);
    }

    public function createTask(): void
    {
        $this->validate([
            'newTitle' => ['nullable', 'string', 'max:120'],
            'newIssue' => ['nullable', 'string', 'max:64'],
            'newBaseBranch' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/-]*$/'],
            'newAgentId' => ['required', 'string'],
            'newModel' => ['nullable', 'string', 'max:90'],
            'newMode' => ['required', 'string'],
            'newPrompt' => [$this->newAuto ? 'required' : 'nullable', 'string', 'max:20000'],
            'newPlan' => ['nullable', 'in:off,auto,approve'],
            'newShip' => ['nullable', 'in:pr,draft_pr,none'],
            'newMaxFixRounds' => ['nullable', 'integer', 'min:0', 'max:20'],
        ]);

        $agent = $this->usableAgents()->firstWhere('id', $this->newAgentId);
        if ($agent === null) {
            $this->addError('newAgentId', __('workspaces.errors.agent_unavailable'));

            return;
        }

        $issue = null;
        if ($this->newIssue !== null && $this->newIssue !== '') {
            try {
                $issue = app(WorkspaceIssueService::class)->load($this->workspace(), $this->newIssue);
            } catch (WorkspaceException $e) {
                $this->addError('newIssue', $e->getMessage());

                return;
            }
        }
        $options = array_filter([
            'base_branch' => trim($this->newBaseBranch) !== '' ? trim($this->newBaseBranch) : null,
            'external_ref' => $issue,
            'parent_task_id' => $this->newParentId,
            'auto_merge' => $this->newAutoMerge ?: null,
        ], fn ($value): bool => $value !== null);

        if ($this->newAuto) {
            $this->attempt(function () use ($agent, $options): void {
                $task = app(AutoTaskDriver::class)->start(
                    $this->workspace(),
                    $this->user(),
                    $agent,
                    trim($this->newPrompt),
                    trim($this->newTitle) !== '' ? trim($this->newTitle) : null,
                    $this->newModel,
                    ['plan' => $this->newPlan, 'ship' => $this->newShip, 'max_fix_rounds' => $this->newMaxFixRounds],
                    $options,
                );
                $this->taskId = $task->id;
                $this->creating = false;
                $this->reset('newTitle', 'newPrompt', 'newAuto', 'newPlan', 'newShip', 'newMaxFixRounds', 'newIssue', 'newIssueQuery', 'newBaseBranch', 'newParentId', 'newAutoMerge');
                $this->announceTask();
            });

            return;
        }

        $this->attempt(function () use ($agent, $options): void {
            $task = app(TaskService::class)->create(
                $this->workspace(),
                $this->user(),
                $agent,
                trim($this->newTitle) !== '' ? trim($this->newTitle) : __('workspaces.task.title_default').' '.($this->tasks()->count() + 1),
                $this->newModel,
                PermissionMode::from($this->newMode),
                options: $options,
            );
            $this->taskId = $task->id;
            $this->creating = false;
            $this->newTitle = '';
            $this->announceTask();
            // Started from an issue or as a follow-up: prefill, never send — the person reviews it.
            $this->draft = $task->parent_task_id !== null
                ? app(TaskService::class)->followUpBrief($task)
                : app(WorkspaceIssueService::class)->composerDraft($task);
            $this->reset('newIssue', 'newIssueQuery', 'newBaseBranch', 'newParentId', 'newAutoMerge');
        });
    }

    /** "Test setup" on the setup task. */
    public function testSetup(): void
    {
        $task = $this->activeTask();
        if ($task === null) {
            return;
        }

        $this->attempt(function () use ($task): void {
            app(WorkspaceSetupService::class)->requestTest($task, $this->user());
            Notification::make()->title(__('workspaces.setup.test_queued'))->success()->send();
        });
    }

    /** "Try again" on a failed preparation. */
    public function retryPreparation(): void
    {
        $task = $this->activeTask();
        if ($task !== null) {
            $this->attempt(fn () => app(TaskPreparer::class)->retry($task, $this->user()));
        }
    }

    public function send(): void
    {
        $text = trim($this->draft);
        $task = $this->activeTask();
        if ($task === null) {
            return;
        }

        // Collected review comments go out with the text as one message.
        if ($this->collectedCount($task) > 0) {
            $this->attempt(function () use ($task, $text): void {
                $sent = app(ReviewCommentService::class)->send($task, $this->user(), $text);
                $this->draft = '';
                $this->dispatch('review-comments-changed');
                Notification::make()->title(trans_choice('workspaces.review.sent_count', $sent, ['count' => $sent]))->success()->send();
            });

            return;
        }

        if ($text === '' && $this->uploads === []) {
            return;
        }

        if ($this->runArgosCommand($text)) {
            $this->draft = '';

            return;
        }

        $this->validate([
            'uploads' => ['array', 'max:'.TaskAttachmentService::MAX_FILES],
            'uploads.*' => ['image', 'mimes:png,jpg,jpeg,gif,webp', 'max:'.((int) config('argos.workspaces.upload_max_mb', 5) * 1024)],
        ]);

        $this->attempt(function () use ($task, $text): void {
            $images = app(TaskAttachmentService::class)->store($task, $this->uploads);
            app(TaskService::class)->send($task, $this->user(), $text, $images);
            $this->draft = '';
            $this->uploads = [];
        });
    }

    /** The × on a comment chip: drop it from the collection. */
    public function removeComment(string $id): void
    {
        $task = $this->activeTask();
        $comment = $task !== null ? app(ReviewCommentService::class)->open($task, $this->user())->firstWhere('id', $id) : null;
        if ($comment !== null) {
            app(ReviewCommentService::class)->discard($comment, $this->user());
            $this->dispatch('review-comments-changed');
        }
    }

    /** Text handed to the composer (e.g. general pull request comments when collecting the review). */
    #[On('composer-append')]
    public function appendToDraft(string $text): void
    {
        $this->draft = trim($this->draft) === '' ? $text : rtrim($this->draft)."\n\n".$text;
    }

    /** The review panel changed the collection. */
    #[On('review-comments-changed')]
    public function commentsChanged(): void {}

    private function collectedCount(Task $task): int
    {
        return app(ReviewCommentService::class)->open($task, $this->user())
            ->filter(fn ($c): bool => $c->status === ReviewCommentStatus::Draft)
            ->count();
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    /**
     * Fuzzy file search for `@` mentions in the composer.
     *
     * @return list<string>
     */
    public function searchFiles(string $query): array
    {
        $task = $this->activeTask();
        if ($task === null) {
            return [];
        }
        try {
            return app(WorkspaceDaemonClient::class)->for($this->workspace(), 5)->files($task->slug, mb_substr($query, 0, 200), 15);
        } catch (DaemonTransportException) {
            return [];
        }
    }

    /**
     * Slash commands for the composer: Argos' own, then those Claude Code
     * reported for this session (project commands, skills).
     *
     * @return list<array{name: string, description: string, argos: bool}>
     */
    public function commandList(): array
    {
        $commands = array_map(fn (string $name): array => [
            'name' => $name,
            'description' => __('workspaces.commands.'.$name),
            'argos' => true,
        ], self::ARGOS_COMMANDS);

        foreach ($this->activeTask()?->commands ?? [] as $command) {
            if (! in_array($command['name'], self::ARGOS_COMMANDS, true)) {
                $commands[] = ['name' => $command['name'], 'description' => (string) $command['description'], 'argos' => false];
            }
        }

        return $commands;
    }

    /** Handle Argos' own slash commands (ARGOS_COMMANDS); true when consumed. */
    private function runArgosCommand(string $text): bool
    {
        if (preg_match('/^\/([a-z-]+)(?:\s+(.*))?$/s', $text, $m) !== 1 || ! in_array($m[1], self::ARGOS_COMMANDS, true)) {
            return false;
        }
        $rest = trim($m[2] ?? '');

        match ($m[1]) {
            'plan' => $this->runPlan($rest),
            'ship' => $this->dispatch('workspace-ship'),
            'preview' => $this->dispatch('workspace-focus', target: 'preview'),
            'review' => $this->dispatch('workspace-focus', target: 'review'),
            'gates' => [$this->dispatch('workspace-focus', target: 'gates'), $this->dispatch('workspace-run-gates')],
            'checkpoint' => $this->runCheckpoint($rest),
        };

        return true;
    }

    private function runCheckpoint(string $label): void
    {
        $task = $this->activeTask();
        if ($task !== null) {
            $this->attempt(fn () => app(TaskCheckpointService::class)->manual($task, $this->user(), $label));
        }
    }

    /** Open the "reset to here" dialog with a preview of what it undoes. */
    public function previewRestore(string $checkpointId): void
    {
        $checkpoint = $this->checkpoint($checkpointId);
        if ($checkpoint === null) {
            return;
        }

        $this->attempt(function () use ($checkpoint): void {
            $preview = app(TaskCheckpointService::class)->preview($checkpoint, $this->user());
            $this->restoreId = $checkpoint->id;
            $this->restoreDiff = mb_strcut($preview['diff'], 0, 60_000);
            $this->restoreTruncated = $preview['truncated'] || strlen($preview['diff']) > 60_000;
            $this->restoreCommitsSince = $preview['commits_since'];
        });
    }

    public function cancelRestore(): void
    {
        $this->restoreId = null;
        $this->restoreDiff = '';
    }

    public function confirmRestore(): void
    {
        $checkpoint = $this->restoreId !== null ? $this->checkpoint($this->restoreId) : null;
        if ($checkpoint === null) {
            return;
        }

        $this->attempt(function () use ($checkpoint): void {
            app(TaskCheckpointService::class)->restore($checkpoint, $this->user());
            $this->cancelRestore();
            Notification::make()->title(__('workspaces.checkpoints.restored'))->success()->send();
        });
    }

    private function checkpoint(string $id): ?TaskCheckpoint
    {
        $task = $this->activeTask();

        return $task === null ? null : TaskCheckpoint::query()->where('task_id', $task->id)->find($id);
    }

    private function runPlan(string $rest): void
    {
        $this->setMode(PermissionMode::Plan->value);
        $task = $this->activeTask();
        if ($rest !== '' && $task !== null) {
            $this->attempt(fn () => app(TaskService::class)->send($task, $this->user(), $rest));
        }
    }

    public function pauseAuto(): void
    {
        $task = $this->activeTask();
        if ($task !== null) {
            $this->attempt(fn () => app(AutoTaskDriver::class)->pause($task, $this->user()));
        }
    }

    public function resumeAuto(): void
    {
        $task = $this->activeTask();
        if ($task !== null) {
            $this->attempt(fn () => app(AutoTaskDriver::class)->resume($task, $this->user()));
        }
    }

    public function interrupt(): void
    {
        $task = $this->activeTask();
        if ($task !== null) {
            $this->attempt(fn () => app(TaskService::class)->interrupt($task, $this->user()));
        }
    }

    public function setMode(string $mode): void
    {
        $task = $this->activeTask();
        $value = PermissionMode::tryFrom($mode);
        if ($task !== null && $value !== null) {
            $this->attempt(fn () => app(TaskService::class)->setPermissionMode($task, $this->user(), $value));
        }
    }

    public function setModel(string $model): void
    {
        $task = $this->activeTask();
        if ($task !== null && ModelCatalog::isValidId($model)) {
            $this->attempt(fn () => app(TaskService::class)->setModel($task, $this->user(), $model));
        }
    }

    /**
     * @param  array<string, mixed>|null  $answers  AskUserQuestion: question text → answer
     */
    public function resolveApproval(string $approvalId, string $decision, ?string $message = null, ?array $answers = null, ?string $nextMode = null): void
    {
        $approval = TaskApproval::query()->with('task.workspace')->find($approvalId);
        if ($approval === null || $approval->task->workspace_id !== $this->workspaceId || ! in_array($decision, ['allow', 'always', 'deny'], true)) {
            return;
        }
        $remember = $decision === 'always';
        $decision = $remember ? 'allow' : $decision;

        $updatedInput = null;
        if ($answers !== null && $decision === 'allow') {
            $updatedInput = [...(array) ($approval->request['input'] ?? []), 'answers' => $answers];
        }

        // An auto-task's plan: the driver decides and continues the run.
        if ($approval->kind === ApprovalKind::Plan && $approval->task->auto_state === AutoTaskState::AwaitingPlanApproval) {
            $this->attempt(fn () => app(AutoTaskDriver::class)->decidePlan($approval->task, $this->user(), $decision === 'allow', $message));

            return;
        }

        $this->attempt(function () use ($approval, $decision, $message, $updatedInput, $nextMode, $remember): void {
            $won = app(TaskService::class)->resolveApproval($approval, $this->user(), $decision, $updatedInput, $message !== '' ? $message : null, $remember);
            if (! $won) {
                Notification::make()->title(__('workspaces.approval.taken'))->warning()->send();

                return;
            }
            // Accepting a plan leaves plan mode for the chosen next mode.
            $mode = PermissionMode::tryFrom((string) $nextMode);
            if ($decision === 'allow' && $mode !== null && $approval->tool_name === 'ExitPlanMode') {
                app(TaskService::class)->setPermissionMode($approval->task, $this->user(), $mode);
            }
        });
    }

    public function resume(): void
    {
        $task = $this->activeTask();
        if ($task !== null) {
            $this->attempt(fn () => app(TaskService::class)->open($task));
        }
    }

    /** The switcher archived the task shown here: show the next one. */
    #[On('task-archived')]
    public function taskArchived(string $id): void
    {
        if ($this->taskId === $id) {
            $this->taskId = $this->tasks()->first()?->id;
            $this->creating = $this->taskId === null;
            $this->announceTask();
        }
    }

    public function loadOlder(): void
    {
        $this->limit += self::PAGE;
    }

    public function workspace(): Workspace
    {
        return Workspace::query()->findOrFail($this->workspaceId);
    }

    /** @return Collection<int, Task> */
    public function tasks(bool $withArchived = false): Collection
    {
        return Task::query()
            ->where('workspace_id', $this->workspaceId)
            ->when(! $withArchived, fn ($q) => $q->whereNull('archived_at'))
            ->with('agentCredential.user')
            ->latest('updated_at')
            ->get();
    }

    public function activeTask(): ?Task
    {
        if ($this->taskId === null) {
            return null;
        }

        return Task::query()->with(['agentCredential.user', 'workspace'])->where('workspace_id', $this->workspaceId)->find($this->taskId);
    }

    /** @return Collection<int, AgentCredential> */
    public function usableAgents(): Collection
    {
        return app(AgentPool::class)->usableAgentsFor($this->user());
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $task = $this->activeTask();
        $role = $workspace->roleOf($this->user());
        // Without a task: the capability alone, so people without an agent
        // see the form's "add an agent" hint (the policy also needs an agent).
        $canWrite = $task !== null
            ? $this->user()->can('prompt', $task)
            : app(TaskAccess::class)->allows($this->user(), Capability::TaskCreate, $workspace);

        [$events, $hasOlder] = $task !== null ? $this->events($task) : [collect(), false];
        if ($task !== null) {
            app(TaskReadService::class)->markRead($task, $this->user());
        }
        $toolResults = $events->where('type', TaskEventType::ToolResult)->keyBy(fn (TaskEvent $e) => (string) ($e->payload['tool_use_id'] ?? ''));
        $approvals = $task !== null ? TaskApproval::query()->with('resolver')->where('task_id', $task->id)->get()->keyBy('id') : collect();

        return view('livewire.workspace.task-chat', [
            'workspace' => $workspace,
            'task' => $task,
            'events' => $events,
            'timeline' => TaskTimeline::build($events, $toolResults, $approvals, $task !== null && $task->session_status->isBusy()),
            // Who wrote the messages (profile pictures in the chat).
            'authors' => User::query()->whereIn('id', $events->where('type', TaskEventType::UserMessage)->pluck('author_user_id')->filter()->unique())->get()->keyBy('id'),
            'hasOlder' => $hasOlder,
            'approvals' => $approvals,
            'canWrite' => $canWrite,
            'isOwner' => $this->user()->isAdmin() || $role === WorkspaceRole::Admin,
            'agents' => $this->usableAgents(),
            'models' => app(ModelCatalog::class)->options($task?->agentCredential, $task?->model),
            'newModels' => app(ModelCatalog::class)->options($this->usableAgents()->firstWhere('id', $this->newAgentId)),
            'issuesEnabled' => $this->creating && app(WorkspaceIssueService::class)->bindingFor($workspace) !== null,
            'issueResults' => $this->creating && $this->newIssueQuery !== '' ? $this->issueOptions($this->newIssueQuery) : [],
            'chosenIssue' => $this->creating && $this->newIssue !== null ? ($this->issueOptions($this->newIssue, 200)[$this->newIssue] ?? '#'.$this->newIssue) : null,
            'modes' => PermissionMode::cases(),
            'busy' => $task !== null && $task->session_status->isBusy(),
            'user' => $this->user(),
            'commands' => $task !== null ? $this->commandList() : [],
            'autoDefaults' => AutoSettings::resolve($workspace),
            'queuePosition' => $task !== null ? app(TaskService::class)->queuePosition($task) : null,
            'reviewComments' => $task !== null && $canWrite ? app(ReviewCommentService::class)->open($task, $this->user()) : collect(),
            // user message id => checkpoint taken right before it
            'checkpoints' => $task !== null
                ? TaskCheckpoint::query()->where('task_id', $task->id)->whereIn('task_event_id', $events->pluck('id'))->orderBy('n')->get()->unique('task_event_id')->pluck('id', 'task_event_id')
                : collect(),
        ]);
    }

    /**
     * @return array{0: Collection<int, TaskEvent>, 1: bool}
     */
    private function events(Task $task): array
    {
        $rows = TaskEvent::query()
            ->with('author')
            ->where('task_id', $task->id)
            ->orderByDesc('id')
            ->limit($this->limit + 1)
            ->get();

        $hasOlder = $rows->count() > $this->limit;

        return [$rows->take($this->limit)->reverse()->values(), $hasOlder];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();
        }
    }

    /** Session closed although the task was used: offer to resume. */
    public static function canResume(Task $task): bool
    {
        return ! $task->session_open && in_array($task->session_status, [SessionStatus::Closed, SessionStatus::Error], true) && $task->claude_session_id !== null;
    }
}
