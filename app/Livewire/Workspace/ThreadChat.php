<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\ApprovalStatus;
use App\Enums\ClaudeModel;
use App\Enums\PermissionMode;
use App\Enums\ThreadEventType;
use App\Enums\ThreadStatus;
use App\Enums\WorkspaceRole;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\ThreadApproval;
use App\Models\ThreadEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\AgentPool;
use App\Services\Workspace\ThreadAttachmentService;
use App\Services\Workspace\ThreadReadService;
use App\Services\Workspace\ThreadService;
use App\Services\Workspace\WorkspaceDaemonClient;
use App\Services\Workspace\WorkspaceException;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * The chat of a workspace: thread switcher, history, approvals and the
 * composer. Live streaming happens client-side (resources/js/workspace);
 * this component renders persisted state and runs every action through
 * ThreadService, which enforces the policies.
 */
class ThreadChat extends Component
{
    use WithFileUploads;

    private const PAGE = 200;

    /** Commands Argos handles itself instead of sending them to Claude. */
    public const ARGOS_COMMANDS = ['plan', 'ship', 'preview', 'review'];

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

    public string $newMode = 'default';

    public bool $showArchived = false;

    public ?string $renamingId = null;

    public string $renameTitle = '';

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);

        if ($this->threadId === null || $this->activeThread() === null) {
            $this->threadId = $this->threads()->first()?->id;
        }
        $this->newAgentId = $this->usableAgents()->first()?->id;
        $this->creating = $this->threadId === null;
    }

    public function selectThread(string $id): void
    {
        if ($this->threads()->contains('id', $id)) {
            $this->threadId = $id;
            $this->limit = self::PAGE;
            $this->creating = false;
        }
    }

    public function startCreating(): void
    {
        $this->creating = true;
        $this->newAgentId ??= $this->usableAgents()->first()?->id;
    }

    public function createThread(): void
    {
        $this->validate([
            'newTitle' => ['nullable', 'string', 'max:120'],
            'newAgentId' => ['required', 'string'],
            'newModel' => ['nullable', 'string'],
            'newMode' => ['required', 'string'],
        ]);

        $agent = $this->usableAgents()->firstWhere('id', $this->newAgentId);
        if ($agent === null) {
            $this->addError('newAgentId', __('workspaces.errors.agent_unavailable'));

            return;
        }

        $this->attempt(function () use ($agent): void {
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
        });
    }

    public function send(): void
    {
        $text = trim($this->draft);
        $thread = $this->activeThread();
        if (($text === '' && $this->uploads === []) || $thread === null) {
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

    /** Handle `/plan`, `/ship`, `/preview`, `/review`; true when consumed. */
    private function runArgosCommand(string $text): bool
    {
        if (preg_match('/^\/([a-z-]+)(?:\s+(.*))?$/s', $text, $m) !== 1 || ! in_array($m[1], self::ARGOS_COMMANDS, true)) {
            return false;
        }
        $rest = trim($m[2] ?? '');

        match ($m[1]) {
            'plan' => $this->runPlan($rest),
            'ship' => $this->dispatch('workspace-ship'),
            'preview' => $this->dispatch('workspace-tab', tab: 'preview'),
            'review' => $this->dispatch('workspace-tab', tab: 'review'),
        };

        return true;
    }

    private function runPlan(string $rest): void
    {
        $this->setMode(PermissionMode::Plan->value);
        $thread = $this->activeThread();
        if ($rest !== '' && $thread !== null) {
            $this->attempt(fn () => app(ThreadService::class)->send($thread, $this->user(), $rest));
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
        if ($thread !== null && ClaudeModel::tryFrom($model) !== null) {
            $this->attempt(fn () => app(ThreadService::class)->setModel($thread, $this->user(), $model));
        }
    }

    /**
     * @param  array<string, mixed>|null  $answers  AskUserQuestion: question text → answer
     */
    public function resolveApproval(string $approvalId, string $decision, ?string $message = null, ?array $answers = null, ?string $nextMode = null): void
    {
        $approval = ThreadApproval::query()->with('thread.workspace')->find($approvalId);
        if ($approval === null || $approval->thread->workspace_id !== $this->workspaceId || ! in_array($decision, ['allow', 'deny'], true)) {
            return;
        }

        $updatedInput = null;
        if ($answers !== null && $decision === 'allow') {
            $updatedInput = [...(array) ($approval->request['input'] ?? []), 'answers' => $answers];
        }

        $this->attempt(function () use ($approval, $decision, $message, $updatedInput, $nextMode): void {
            $won = app(ThreadService::class)->resolveApproval($approval, $this->user(), $decision, $updatedInput, $message !== '' ? $message : null);
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

    public function startRename(string $id): void
    {
        $thread = $this->threads(true)->firstWhere('id', $id);
        if ($thread !== null) {
            $this->renamingId = $id;
            $this->renameTitle = $thread->title;
        }
    }

    public function rename(): void
    {
        $thread = $this->threads(true)->firstWhere('id', $this->renamingId);
        $title = trim($this->renameTitle);
        if ($thread !== null && $title !== '') {
            $this->attempt(fn () => app(ThreadService::class)->rename($thread, $this->user(), mb_substr($title, 0, 120)));
        }
        $this->renamingId = null;
    }

    public function archive(string $id): void
    {
        $thread = $this->threads()->firstWhere('id', $id);
        if ($thread === null) {
            return;
        }
        $this->attempt(fn () => app(ThreadService::class)->archive($thread, $this->user()));
        if ($this->threadId === $id) {
            $this->threadId = $this->threads()->first()?->id;
        }
    }

    public function unarchive(string $id): void
    {
        $thread = $this->threads(true)->firstWhere('id', $id);
        if ($thread !== null) {
            $this->attempt(fn () => app(ThreadService::class)->unarchive($thread, $this->user()));
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
        $sidebar = $this->threads($this->showArchived);

        return view('livewire.workspace.thread-chat', [
            'workspace' => $workspace,
            'thread' => $thread,
            'threads' => $sidebar,
            'unread' => app(ThreadReadService::class)->unreadCounts($sidebar, $this->user()),
            'events' => $events,
            'hasOlder' => $hasOlder,
            'toolResults' => $events->where('type', ThreadEventType::ToolResult)->keyBy(fn (ThreadEvent $e) => (string) ($e->payload['tool_use_id'] ?? '')),
            'approvals' => $thread !== null ? ThreadApproval::query()->with('resolver')->where('thread_id', $thread->id)->get()->keyBy('id') : collect(),
            'canWrite' => $role?->canOperate() ?? false,
            'isOwner' => $role === WorkspaceRole::Owner,
            'agents' => $this->usableAgents(),
            'models' => ClaudeModel::cases(),
            'modes' => PermissionMode::cases(),
            'busy' => $thread !== null && $thread->status->isBusy(),
            'user' => $this->user(),
            'commands' => $thread !== null ? $this->commandList() : [],
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

    /** True when the thread waits for a decision on an approval. */
    public static function isPending(?ThreadApproval $approval): bool
    {
        return $approval !== null && $approval->status === ApprovalStatus::Pending;
    }

    /** Session closed although the thread was used: offer to resume. */
    public static function canResume(Thread $thread): bool
    {
        return ! $thread->session_open && in_array($thread->status, [ThreadStatus::Closed, ThreadStatus::Error], true) && $thread->claude_session_id !== null;
    }
}
