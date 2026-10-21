<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\ApprovalKind;
use App\Enums\AutoThreadState;
use App\Enums\PermissionMode;
use App\Enums\ThreadEventType;
use App\Enums\ThreadStatus;
use App\Enums\WorkspaceRole;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\ThreadCheckpoint;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\AgentPool;
use App\Services\Workspace\AutoSettings;
use App\Services\Workspace\AutoThreadDriver;
use App\Services\Workspace\ModelCatalog;
use App\Services\Workspace\ReviewCommentService;
use App\Services\Workspace\ThreadAttachmentService;
use App\Services\Workspace\ThreadCheckpointService;
use App\Services\Workspace\ThreadReadService;
use App\Services\Workspace\ThreadService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceIssueService;
use App\Support\ThreadTimeline;
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
 * thread picked in the header's switcher (ThreadSwitcher). Live streaming happens client-side (resources/js/workspace);
 * this component renders persisted state and runs every action through
 * ThreadService, which enforces the policies.
 */
class ThreadChat extends Component
{
    use WithFileUploads;

    private const PAGE = 200;

    /** Commands Argos handles itself instead of sending them to Claude. */
    public const ARGOS_COMMANDS = ['plan', 'ship', 'preview', 'review', 'gates', 'checkpoint'];

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    #[Locked]
    public string $workspaceId;

    #[Url(as: 'thread')]
    public ?string $threadId = null;

    public int $limit = self::PAGE;

    public string $draft = '';

    public bool $creating = false;

    public string $newTitle = '';

    public ?string $newAgentId = null;

    public ?string $newModel = null;

    /** New thread as an auto-thread (AutoThreadDriver) — empty overrides use the project defaults. */
    public bool $newAuto = false;

    public string $newPrompt = '';

    public ?string $newPlan = null;

    public ?string $newShip = null;

    public ?int $newMaxFixRounds = null;

    public string $newMode = 'default';

    /** "Reset to here" dialog: the checkpoint and its diff preview. */
    public ?string $restoreId = null;

    public string $restoreDiff = '';

    public bool $restoreTruncated = false;

    public bool $restoreCommitsSince = false;

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);

        if ($this->threadId === null || $this->activeThread() === null) {
            $this->threadId = $this->threads()->first()?->id;
        }
        $this->newAgentId = $this->usableAgents()->first()?->id;
        $this->creating = $this->threadId === null;

        $issue = $this->workspace()->external_issue;
        if ($this->creating && $issue !== null) {
            $this->newTitle = mb_substr(app(WorkspaceIssueService::class)->label($issue['number'], $issue['title']), 0, 120);
        }
    }

    #[On('thread-select')]
    public function selectThread(string $id): void
    {
        if ($this->threads(withArchived: true)->contains('id', $id)) {
            $this->threadId = $id;
            $this->limit = self::PAGE;
            $this->creating = false;
            $this->announceThread();
        }
    }

    #[On('thread-new')]
    public function startCreating(): void
    {
        $this->creating = true;
        $this->newAgentId ??= $this->usableAgents()->first()?->id;
    }

    /** Keep the header's switcher on the thread shown here. */
    private function announceThread(): void
    {
        $this->dispatch('thread-changed', id: $this->threadId);
    }

    public function createThread(): void
    {
        $this->validate([
            'newTitle' => ['nullable', 'string', 'max:120'],
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

        if ($this->newAuto) {
            $this->attempt(function () use ($agent): void {
                $thread = app(AutoThreadDriver::class)->start(
                    $this->workspace(),
                    $this->user(),
                    $agent,
                    trim($this->newPrompt),
                    trim($this->newTitle) !== '' ? trim($this->newTitle) : null,
                    $this->newModel,
                    ['plan' => $this->newPlan, 'ship' => $this->newShip, 'max_fix_rounds' => $this->newMaxFixRounds],
                );
                $this->threadId = $thread->id;
                $this->creating = false;
                $this->reset('newTitle', 'newPrompt', 'newAuto', 'newPlan', 'newShip', 'newMaxFixRounds');
                $this->announceThread();
            });

            return;
        }

        $firstThread = $this->threads(withArchived: true)->isEmpty();

        $this->attempt(function () use ($agent, $firstThread): void {
            $thread = app(ThreadService::class)->create(
                $this->workspace(),
                $this->user(),
                $agent,
                trim($this->newTitle) !== '' ? trim($this->newTitle) : __('workspaces.thread.title_default').' '.($this->threads()->count() + 1),
                $this->newModel,
                PermissionMode::from($this->newMode),
            );
            $this->threadId = $thread->id;
            $this->creating = false;
            $this->newTitle = '';
            $this->announceThread();
            // Started from an issue: prefill, never send — the person reviews it.
            if ($firstThread) {
                $this->draft = app(WorkspaceIssueService::class)->composerDraft($this->workspace());
            }
        });
    }

    /** Stale review comments the person chose to send anyway (ids). */
    public array $includeStale = [];

    public function send(): void
    {
        $text = trim($this->draft);
        $thread = $this->activeThread();
        if ($thread === null) {
            return;
        }

        // Collected review comments go out with the text as one message.
        if ($this->collectedCount($thread) > 0) {
            $this->attempt(function () use ($thread, $text): void {
                $sent = app(ReviewCommentService::class)->send($thread, $this->user(), $text, $this->includeStale);
                $this->draft = '';
                $this->includeStale = [];
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
            'uploads' => ['array', 'max:'.ThreadAttachmentService::MAX_FILES],
            'uploads.*' => ['image', 'mimes:png,jpg,jpeg,gif,webp', 'max:'.((int) config('argos.workspaces.upload_max_mb', 5) * 1024)],
        ]);

        $this->attempt(function () use ($thread, $text): void {
            $images = app(ThreadAttachmentService::class)->store($thread, $this->uploads);
            app(ThreadService::class)->send($thread, $this->user(), $text, $images);
            $this->draft = '';
            $this->uploads = [];
        });
    }

    /** The × on a comment chip: drop it from the collection. */
    public function removeComment(string $id): void
    {
        $thread = $this->activeThread();
        $comment = $thread !== null ? app(ReviewCommentService::class)->open($thread, $this->user())->firstWhere('id', $id) : null;
        if ($comment !== null) {
            app(ReviewCommentService::class)->discard($comment, $this->user());
            $this->includeStale = array_values(array_diff($this->includeStale, [$id]));
            $this->dispatch('review-comments-changed');
        }
    }

    /** The review panel changed the collection. */
    #[On('review-comments-changed')]
    public function commentsChanged(): void {}

    private function collectedCount(Thread $thread): int
    {
        return app(ReviewCommentService::class)->open($thread, $this->user())
            ->filter(fn ($c): bool => $c->status === \App\Enums\ReviewCommentStatus::Draft || in_array($c->id, $this->includeStale, true))
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
        try {
            return app(WorkspaceDaemonClient::class)->for($this->workspace(), 5)->files(mb_substr($query, 0, 200), 15);
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

        foreach ($this->activeThread()?->commands ?? [] as $command) {
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
        $thread = $this->activeThread();
        if ($thread !== null) {
            $this->attempt(fn () => app(ThreadCheckpointService::class)->manual($thread, $this->user(), $label));
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
            $preview = app(ThreadCheckpointService::class)->preview($checkpoint, $this->user());
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
            app(ThreadCheckpointService::class)->restore($checkpoint, $this->user());
            $this->cancelRestore();
            Notification::make()->title(__('workspaces.checkpoints.restored'))->success()->send();
        });
    }

    private function checkpoint(string $id): ?ThreadCheckpoint
    {
        $thread = $this->activeThread();

        return $thread === null ? null : ThreadCheckpoint::query()->where('thread_id', $thread->id)->find($id);
    }

    private function runPlan(string $rest): void
    {
        $this->setMode(PermissionMode::Plan->value);
        $thread = $this->activeThread();
        if ($rest !== '' && $thread !== null) {
            $this->attempt(fn () => app(ThreadService::class)->send($thread, $this->user(), $rest));
        }
    }

    public function pauseAuto(): void
    {
        $thread = $this->activeThread();
        if ($thread !== null) {
            $this->attempt(fn () => app(AutoThreadDriver::class)->pause($thread, $this->user()));
        }
    }

    public function resumeAuto(): void
    {
        $thread = $this->activeThread();
        if ($thread !== null) {
            $this->attempt(fn () => app(AutoThreadDriver::class)->resume($thread, $this->user()));
        }
    }

    public function interrupt(): void
    {
        $thread = $this->activeThread();
        if ($thread !== null) {
            $this->attempt(fn () => app(ThreadService::class)->interrupt($thread, $this->user()));
        }
    }

    public function setMode(string $mode): void
    {
        $thread = $this->activeThread();
        $value = PermissionMode::tryFrom($mode);
        if ($thread !== null && $value !== null) {
            $this->attempt(fn () => app(ThreadService::class)->setPermissionMode($thread, $this->user(), $value));
        }
    }

    public function setModel(string $model): void
    {
        $thread = $this->activeThread();
        if ($thread !== null && ModelCatalog::isValidId($model)) {
            $this->attempt(fn () => app(ThreadService::class)->setModel($thread, $this->user(), $model));
        }
    }

    /**
     * @param  array<string, mixed>|null  $answers  AskUserQuestion: question text → answer
     */
    public function resolveApproval(string $approvalId, string $decision, ?string $message = null, ?array $answers = null, ?string $nextMode = null): void
    {
        $approval = ThreadApproval::query()->with('thread.workspace')->find($approvalId);
        if ($approval === null || $approval->thread->workspace_id !== $this->workspaceId || ! in_array($decision, ['allow', 'always', 'deny'], true)) {
            return;
        }
        $remember = $decision === 'always';
        $decision = $remember ? 'allow' : $decision;

        $updatedInput = null;
        if ($answers !== null && $decision === 'allow') {
            $updatedInput = [...(array) ($approval->request['input'] ?? []), 'answers' => $answers];
        }

        // An auto-thread's plan: the driver decides and continues the run.
        if ($approval->kind === ApprovalKind::Plan && $approval->thread->auto_state === AutoThreadState::AwaitingPlanApproval) {
            $this->attempt(fn () => app(AutoThreadDriver::class)->decidePlan($approval->thread, $this->user(), $decision === 'allow', $message));

            return;
        }

        $this->attempt(function () use ($approval, $decision, $message, $updatedInput, $nextMode, $remember): void {
            $won = app(ThreadService::class)->resolveApproval($approval, $this->user(), $decision, $updatedInput, $message !== '' ? $message : null, $remember);
            if (! $won) {
                Notification::make()->title(__('workspaces.approval.taken'))->warning()->send();

                return;
            }
            // Accepting a plan leaves plan mode for the chosen next mode.
            $mode = PermissionMode::tryFrom((string) $nextMode);
            if ($decision === 'allow' && $mode !== null && $approval->tool_name === 'ExitPlanMode') {
                app(ThreadService::class)->setPermissionMode($approval->thread, $this->user(), $mode);
            }
        });
    }

    public function resume(): void
    {
        $thread = $this->activeThread();
        if ($thread !== null) {
            $this->attempt(fn () => app(ThreadService::class)->open($thread));
        }
    }

    /** The switcher archived the thread shown here: show the next one. */
    #[On('thread-archived')]
    public function threadArchived(string $id): void
    {
        if ($this->threadId === $id) {
            $this->threadId = $this->threads()->first()?->id;
            $this->creating = $this->threadId === null;
            $this->announceThread();
        }
    }

    public function loadOlder(): void
    {
        $this->limit += self::PAGE;
    }

    public function workspace(): Workspace
    {
        return Workspace::query()->with('repoProfile')->findOrFail($this->workspaceId);
    }

    /** @return Collection<int, Thread> */
    public function threads(bool $withArchived = false): Collection
    {
        return Thread::query()
            ->where('workspace_id', $this->workspaceId)
            ->when(! $withArchived, fn ($q) => $q->whereNull('archived_at'))
            ->with('agentCredential.user')
            ->latest('updated_at')
            ->get();
    }

    public function activeThread(): ?Thread
    {
        if ($this->threadId === null) {
            return null;
        }

        return Thread::query()->with(['agentCredential.user', 'workspace.repoProfile'])->where('workspace_id', $this->workspaceId)->find($this->threadId);
    }

    /** @return Collection<int, AgentCredential> */
    public function usableAgents(): Collection
    {
        return app(AgentPool::class)->usableAgentsFor($this->user());
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $thread = $this->activeThread();
        $role = $workspace->roleOf($this->user());

        [$events, $hasOlder] = $thread !== null ? $this->events($thread) : [collect(), false];
        if ($thread !== null) {
            app(ThreadReadService::class)->markRead($thread, $this->user());
        }
        $toolResults = $events->where('type', ThreadEventType::ToolResult)->keyBy(fn (ThreadEvent $e) => (string) ($e->payload['tool_use_id'] ?? ''));
        $approvals = $thread !== null ? ThreadApproval::query()->with('resolver')->where('thread_id', $thread->id)->get()->keyBy('id') : collect();

        return view('livewire.workspace.thread-chat', [
            'workspace' => $workspace,
            'thread' => $thread,
            'events' => $events,
            'timeline' => ThreadTimeline::build($events, $toolResults, $approvals, $thread !== null && $thread->status->isBusy()),
            'hasOlder' => $hasOlder,
            'approvals' => $approvals,
            'canWrite' => $role?->canOperate() ?? false,
            'isOwner' => $role === WorkspaceRole::Owner,
            'agents' => $this->usableAgents(),
            'models' => app(ModelCatalog::class)->options($thread?->agentCredential, $thread?->model),
            'newModels' => app(ModelCatalog::class)->options($this->usableAgents()->firstWhere('id', $this->newAgentId)),
            'modes' => PermissionMode::cases(),
            'busy' => $thread !== null && $thread->status->isBusy(),
            'user' => $this->user(),
            'commands' => $thread !== null ? $this->commandList() : [],
            'autoDefaults' => AutoSettings::resolve($workspace->repoProfile),
            'reviewComments' => $thread !== null && ($role?->canOperate() ?? false) ? app(ReviewCommentService::class)->open($thread, $this->user()) : collect(),
            // user message id => checkpoint taken right before it
            'checkpoints' => $thread !== null
                ? ThreadCheckpoint::query()->where('thread_id', $thread->id)->whereIn('thread_event_id', $events->pluck('id'))->orderBy('n')->get()->unique('thread_event_id')->pluck('id', 'thread_event_id')
                : collect(),
        ]);
    }

    /**
     * @return array{0: Collection<int, ThreadEvent>, 1: bool}
     */
    private function events(Thread $thread): array
    {
        $rows = ThreadEvent::query()
            ->with('author')
            ->where('thread_id', $thread->id)
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
            Notification::make()->title(__('workspaces.thread.viewer_hint'))->warning()->send();
        }
    }

    /** Session closed although the thread was used: offer to resume. */
    public static function canResume(Thread $thread): bool
    {
        return ! $thread->session_open && in_array($thread->status, [ThreadStatus::Closed, ThreadStatus::Error], true) && $thread->claude_session_id !== null;
    }
}
