<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\ThreadService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Review tab: the workspace's diff against its base branch. Line comments
 * are collected in the browser and sent to Claude as one message.
 */
class ReviewPanel extends Component
{
    #[Locked]
    public string $workspaceId;

    /** Load the diff only once the tab is opened. */
    public bool $loaded = false;

    public ?string $threadId = null;

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);
        $this->threadId = $this->threads()->first()?->id;
    }

    public function load(): void
    {
        $this->loaded = true;
    }

    /**
     * @param  list<array{path: string, line: int|string, side?: string, text: string}>  $comments
     */
    public function sendComments(array $comments): bool
    {
        $thread = $this->threadId !== null ? $this->threads()->firstWhere('id', $this->threadId) : null;
        if ($thread === null) {
            Notification::make()->title(__('workspaces.ship.no_thread'))->warning()->send();

            return false;
        }

        $lines = [];
        foreach ($comments as $comment) {
            $text = trim((string) ($comment['text'] ?? ''));
            $path = (string) ($comment['path'] ?? '');
            if ($text === '' || $path === '') {
                continue;
            }
            $lines[] = '- '.$path.':'.(int) ($comment['line'] ?? 0).' — '.$text;
        }
        if ($lines === []) {
            return false;
        }

        try {
            app(ThreadService::class)->send($thread, $this->user(), __('workspaces.review.message_heading')."\n".implode("\n", $lines));
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return false;
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.thread.viewer_hint'))->warning()->send();

            return false;
        }

        Notification::make()->title(__('workspaces.review.sent', ['count' => count($lines)]))->success()->send();

        return true;
    }

    /** Start a gate run (header button, the panel or `/gates`). */
    #[On('workspace-run-gates')]
    public function runGates(): void
    {
        try {
            app(WorkspaceGateService::class)->run($this->workspace(), $this->user());
            Notification::make()->title(__('workspaces.gates.started'))->success()->send();
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.thread.viewer_hint'))->warning()->send();
        }
    }

    /** Hand a failed gate to Claude with the worker's fix prompt. */
    public function sendGateFix(string $gate): void
    {
        $run = app(WorkspaceGateService::class)->latest($this->workspace());
        $thread = $this->threadId !== null ? $this->threads()->firstWhere('id', $this->threadId) : null;
        if ($run === null || $thread === null) {
            Notification::make()->title(__('workspaces.ship.no_thread'))->warning()->send();

            return;
        }

        try {
            app(WorkspaceGateService::class)->sendFixToAgent($run, $thread, $this->user(), $gate);
            Notification::make()->title(__('workspaces.gates.fix_sent'))->success()->send();
        } catch (WorkspaceException|DaemonTransportException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.thread.viewer_hint'))->warning()->send();
        }
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $diff = null;
        $error = null;
        if ($this->loaded && $workspace->status === WorkspaceStatus::Running) {
            try {
                $diff = app(WorkspaceDiffService::class)->forWorkspace($workspace);
            } catch (DaemonTransportException $e) {
                $error = $e->getMessage();
            }
        }

        return view('livewire.workspace.review-panel', [
            'workspace' => $workspace,
            'diff' => $diff,
            'error' => $error,
            'threads' => $this->threads(),
            'gateRun' => app(WorkspaceGateService::class)->latest($workspace),
            'canWrite' => $workspace->roleOf($this->user())?->canOperate() ?? false,
        ]);
    }

    /** @return Collection<int, Thread> */
    private function threads(): Collection
    {
        return Thread::query()->where('workspace_id', $this->workspaceId)->whereNull('archived_at')->latest('updated_at')->get();
    }

    private function workspace(): Workspace
    {
        return Workspace::query()->findOrFail($this->workspaceId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
