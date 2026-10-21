<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\ThreadOverview;
use App\Services\Workspace\ThreadReadService;
use App\Services\Workspace\ThreadService;
use App\Services\Workspace\WorkspaceException;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Thread switcher in the workspace header (replaces the thread column):
 * a button with the current thread and a popover (⌘J) grouped into
 * "waiting for you", "running" and "done", plus the hint chip when another
 * thread waits for a decision. Selecting tells ThreadChat via events.
 */
class ThreadSwitcher extends Component
{
    #[Locked]
    public string $workspaceId;

    public ?string $currentId = null;

    public bool $showArchived = false;

    public ?string $renamingId = null;

    public string $renameTitle = '';

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);

        // Same default as ThreadChat: the thread from the URL, else the latest.
        $requested = request()->query('thread');
        $threads = Thread::query()->where('workspace_id', $workspaceId)->whereNull('archived_at')->latest('updated_at')->pluck('id');
        $this->currentId = is_string($requested) && Thread::query()->where('workspace_id', $workspaceId)->whereKey($requested)->exists()
            ? $requested
            : $threads->first();
    }

    #[On('thread-changed')]
    public function threadChanged(?string $id): void
    {
        $this->currentId = $id;
    }

    public function select(string $id): void
    {
        if (Thread::query()->where('workspace_id', $this->workspaceId)->whereKey($id)->exists()) {
            $this->currentId = $id;
            $this->dispatch('thread-select', id: $id);
        }
    }

    public function startRename(string $id): void
    {
        $thread = $this->thread($id);
        if ($thread !== null) {
            $this->renamingId = $id;
            $this->renameTitle = $thread->title;
        }
    }

    public function rename(): void
    {
        $thread = $this->renamingId !== null ? $this->thread($this->renamingId) : null;
        $title = trim($this->renameTitle);
        if ($thread !== null && $title !== '') {
            $this->attempt(fn () => app(ThreadService::class)->rename($thread, $this->user(), mb_substr($title, 0, 120)));
        }
        $this->renamingId = null;
    }

    public function archive(string $id): void
    {
        $thread = $this->thread($id);
        if ($thread !== null) {
            $this->attempt(fn () => app(ThreadService::class)->archive($thread, $this->user()));
            $this->dispatch('thread-archived', id: $id);
        }
    }

    public function unarchive(string $id): void
    {
        $thread = $this->thread($id);
        if ($thread !== null) {
            $this->attempt(fn () => app(ThreadService::class)->unarchive($thread, $this->user()));
        }
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $overview = app(ThreadOverview::class)->for($workspace);
        $all = collect([...$overview['waiting'], ...$overview['running'], ...$overview['done']])->map(fn (array $row): Thread => $row['thread']);
        $current = $all->firstWhere('id', $this->currentId) ?? ($this->currentId !== null ? $this->thread($this->currentId) : null);
        $waitingElsewhere = array_values(array_filter($overview['waiting'], fn (array $row): bool => $row['thread']->id !== $this->currentId));

        return view('livewire.workspace.thread-switcher', [
            'overview' => $overview,
            'current' => $current,
            'currentGroup' => $current !== null ? $this->groupOf($overview, $current->id) : null,
            'count' => $all->count(),
            'waitingElsewhere' => $waitingElsewhere,
            'unread' => app(ThreadReadService::class)->unreadCounts($all, $this->user()),
            'archived' => $this->showArchived
                ? Thread::query()->where('workspace_id', $this->workspaceId)->whereNotNull('archived_at')->latest('archived_at')->get()
                : collect(),
            'canWrite' => $workspace->roleOf($this->user())?->canOperate() ?? false,
        ]);
    }

    /** @param  array<string, mixed>  $overview */
    private function groupOf(array $overview, string $id): string
    {
        foreach ([ThreadOverview::WAITING, ThreadOverview::RUNNING] as $group) {
            foreach ($overview[$group] as $row) {
                if ($row['thread']->id === $id) {
                    return $group;
                }
            }
        }

        return ThreadOverview::DONE;
    }

    private function thread(string $id): ?Thread
    {
        return Thread::query()->where('workspace_id', $this->workspaceId)->find($id);
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
}
