<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\TaskOverview;
use App\Services\Workspace\TaskReadService;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceException;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Component;

/**
 * Task switcher in the workspace header: a button with the current task and
 * a popover (⌘J) grouped into waiting for you, running, ready, PR open and
 * done; filtered to "mine + invited" or all tasks of the workspace. Each row
 * shows its owner (when it is someone else), ticket and pull request. The
 * hint chip points at another task waiting for a decision. Selecting tells
 * TaskChat via events.
 */
class TaskSwitcher extends Component
{
    #[Locked]
    public string $workspaceId;

    public ?string $currentId = null;

    public bool $showArchived = false;

    /** Only my tasks and the ones I was invited to (remembered per session). */
    #[Session]
    public bool $mine = true;

    public ?string $renamingId = null;

    public string $renameTitle = '';

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);

        // Same default as TaskChat: the task from the URL, else the latest.
        $requested = request()->query('task');
        $tasks = Task::query()->where('workspace_id', $workspaceId)->whereNull('archived_at')->latest('updated_at')->pluck('id');
        $this->currentId = is_string($requested) && Task::query()->where('workspace_id', $workspaceId)->whereKey($requested)->exists()
            ? $requested
            : $tasks->first();
    }

    #[On('task-changed')]
    public function taskChanged(?string $id): void
    {
        $this->currentId = $id;
    }

    public function select(string $id): void
    {
        if (Task::query()->where('workspace_id', $this->workspaceId)->whereKey($id)->exists()) {
            $this->currentId = $id;
            $this->dispatch('task-select', id: $id);
        }
    }

    public function startRename(string $id): void
    {
        $task = $this->task($id);
        if ($task !== null) {
            $this->renamingId = $id;
            $this->renameTitle = $task->title;
        }
    }

    public function rename(): void
    {
        $task = $this->renamingId !== null ? $this->task($this->renamingId) : null;
        $title = trim($this->renameTitle);
        if ($task !== null && $title !== '') {
            $this->attempt(fn () => app(TaskService::class)->rename($task, $this->user(), mb_substr($title, 0, 120)));
        }
        $this->renamingId = null;
    }

    public function archive(string $id): void
    {
        $task = $this->task($id);
        if ($task !== null) {
            $this->attempt(fn () => app(TaskService::class)->archive($task, $this->user()));
            $this->dispatch('task-archived', id: $id);
        }
    }

    public function unarchive(string $id): void
    {
        $task = $this->task($id);
        if ($task !== null) {
            $this->attempt(fn () => app(TaskService::class)->unarchive($task, $this->user()));
        }
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $overview = app(TaskOverview::class)->for($workspace, $this->mine ? $this->user() : null);
        $all = collect(TaskOverview::GROUPS)->flatMap(fn (string $group): array => $overview[$group])->map(fn (array $row): Task => $row['task']);
        $current = $all->firstWhere('id', $this->currentId) ?? ($this->currentId !== null ? $this->task($this->currentId) : null);
        $waitingElsewhere = array_values(array_filter($overview['waiting'], fn (array $row): bool => $row['task']->id !== $this->currentId));

        return view('livewire.workspace.task-switcher', [
            'overview' => $overview,
            'current' => $current,
            'currentGroup' => $current !== null ? $this->groupOf($overview, $current->id) : null,
            'count' => $all->count(),
            'waitingElsewhere' => $waitingElsewhere,
            'unread' => app(TaskReadService::class)->unreadCounts($all, $this->user()),
            'archived' => $this->showArchived
                ? Task::query()->where('workspace_id', $this->workspaceId)->whereNotNull('archived_at')->latest('archived_at')->get()
                : collect(),
            'user' => $this->user(),
            'canCreate' => $this->user()->can('create', [Task::class, $workspace]),
            'others' => $this->mine ? Task::query()->where('workspace_id', $workspace->id)->whereNull('archived_at')->count() - $all->count() : 0,
        ]);
    }

    /** @param  array<string, mixed>  $overview */
    private function groupOf(array $overview, string $id): string
    {
        foreach (TaskOverview::GROUPS as $group) {
            foreach ($overview[$group] as $row) {
                if ($row['task']->id === $id) {
                    return $group;
                }
            }
        }

        return TaskOverview::DONE;
    }

    private function task(string $id): ?Task
    {
        return Task::query()->where('workspace_id', $this->workspaceId)->find($id);
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
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();
        }
    }
}
