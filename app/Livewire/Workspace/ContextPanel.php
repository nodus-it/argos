<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\GitProvider;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Livewire\Concerns\InteractsWithWorkspace;
use App\Models\Task;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\PullRequestFeedbackService;
use App\Services\Workspace\PullRequestService;
use App\Services\Workspace\WorkspaceGateService;
use App\Support\GateRows;
use App\Support\RequestedTask;
use App\Support\TaskRefs;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Context sidebar of the task shown in the chat: environment (VS Code,
 * preview), branch, the pull request, quality gates and the changed files. Reads the diff
 * through the daemon, so it renders lazily and refreshes when Claude
 * finishes a turn.
 */
#[Lazy]
class ContextPanel extends Component
{
    use InteractsWithWorkspace;

    #[Locked]
    public string $workspaceId;

    /** The task shown in the chat. */
    public ?string $taskId = null;

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);
        $this->taskId = RequestedTask::idOrLatest($workspaceId);
    }

    #[On('task-changed')]
    public function taskChanged(?string $id): void
    {
        $this->taskId = $id;
    }

    /** "Alle prüfen", the change tray and `/gates`. */
    #[On('workspace-run-gates')]
    public function runGates(): void
    {
        $task = $this->task();
        if ($task === null) {
            Notification::make()->title(__('workspaces.ship.no_task'))->warning()->send();

            return;
        }

        if ($this->attempt(fn () => app(WorkspaceGateService::class)->run($task, $this->user()), warn: true)) {
            Notification::make()->title(__('workspaces.gates.started'))->success()->send();
        }
    }

    public function prRefresh(): void
    {
        $this->prAction(function (PullRequestService $pr, Task $task): void {
            Gate::forUser($this->user())->authorize('view', $task);
            $pr->refresh($task);
        });
    }

    #[On('pr-mark-ready')]
    public function prMarkReady(): void
    {
        $this->prAction(fn (PullRequestService $pr, Task $task) => $pr->markReady($task, $this->user()));
    }

    public function prAutoMerge(bool $on): void
    {
        $this->prAction(fn (PullRequestService $pr, Task $task) => $pr->setAutoMerge($task, $this->user(), $on));
    }

    #[On('pr-fix-checks')]
    public function prFixChecks(): void
    {
        $this->prAction(fn (PullRequestService $pr, Task $task) => $pr->fixChecks($task, $this->user()));
    }

    /** "Collect in my review": the pull request's comments join the person's own review. */
    #[On('pr-collect')]
    public function prCollect(): void
    {
        $this->prAction(function (PullRequestService $pr, Task $task): void {
            $result = app(PullRequestFeedbackService::class)->collect($task, $this->user());
            if ($result['general'] !== []) {
                $this->dispatch('composer-append', text: PullRequestFeedbackService::generalText($result['general']));
            }
            $this->dispatch('review-comments-changed');
            Notification::make()->title(trans_choice('workspaces.review.collected_count', $result['collected'], ['count' => $result['collected']]))->success()->send();
            $this->js("window.dispatchEvent(new CustomEvent('review-open', { detail: { file: null } }))");
        });
    }

    #[On('pr-rebase')]
    public function prRebase(): void
    {
        $this->prAction(fn (PullRequestService $pr, Task $task) => $pr->rebase($task, $this->user()));
    }

    /**
     * Run a pull request action (messages to Claude reach the chat through
     * the task's broadcast).
     *
     * @param  callable(PullRequestService, Task): mixed  $action
     */
    private function prAction(callable $action): void
    {
        $task = $this->task();
        if ($task === null) {
            return;
        }

        $this->attempt(fn () => $action(app(PullRequestService::class), $task), warn: true);
    }

    public function placeholder(): string
    {
        return '<div class="ag-context-inner"><span class="ag-spin"></span></div>';
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $task = $this->task();
        $diff = null;
        if ($task !== null && $workspace->status === WorkspaceStatus::Running) {
            try {
                $diff = app(WorkspaceDiffService::class)->summary($task);
            } catch (DaemonTransportException) {
                $diff = null;
            }
        }
        $gateRun = $task !== null ? app(WorkspaceGateService::class)->latest($task) : null;
        $issue = $task?->external_ref;

        return view('livewire.workspace.context-panel', [
            'workspace' => $workspace,
            'task' => $task,
            'diff' => $diff,
            'files' => self::changedFiles(array_values(array_filter($diff['files'] ?? [], fn (array $f): bool => ! $f['generated']))),
            'generated' => array_values(array_filter($diff['files'] ?? [], fn (array $f): bool => $f['generated'])),
            'gateRun' => $gateRun,
            'gates' => GateRows::of($gateRun),
            'canOperate' => $task !== null && $this->user()->can('prompt', $task),
            'canEdit' => $this->user()->can('editor', $workspace),
            'running' => $workspace->status === WorkspaceStatus::Running,
            'pr' => $task?->pr_number !== null ? app(PullRequestService::class)->details($task) : null,
            // First load of the pull request details runs in the background: poll until it is there.
            'prLoading' => $task?->pr_number !== null && ! app(PullRequestService::class)->hasDetails($task),
            'feedback' => $task?->pr_number !== null ? app(PullRequestFeedbackService::class)->feedback($task) : ['reviews' => [], 'comments' => [], 'reviewers' => []],
            'collectedIds' => $task?->pr_number !== null ? app(PullRequestFeedbackService::class)->collectedIds($task, $this->user()) : [],
            'canShip' => $task !== null && $this->user()->can('ship', $task),
            // Bitbucket has no native auto-merge.
            'autoMergeSupported' => $workspace->platform !== GitProvider::Bitbucket,
            'issueLabel' => $task !== null && $issue !== null ? TaskRefs::ticketLabel($task).' · '.$issue['title'] : null,
        ]);
    }

    /**
     * Changed files as sidebar rows: status letter, path, line counts.
     *
     * @param  array<int, array<string, mixed>>  $files  summary rows (WorkspaceDiffService::summary)
     * @return list<array{status: string, path: string, name: string, dir: string, additions: int, deletions: int}>
     */
    public static function changedFiles(array $files): array
    {
        return array_map(fn (array $file): array => [
            'status' => (string) ($file['status'] ?? 'M'),
            'path' => (string) $file['path'],
            'name' => basename((string) $file['path']),
            'dir' => str_contains((string) $file['path'], '/') ? dirname((string) $file['path']) : '',
            'additions' => (int) ($file['additions'] ?? 0),
            'deletions' => (int) ($file['deletions'] ?? 0),
        ], $files);
    }

    private function task(): ?Task
    {
        return $this->findTask($this->taskId);
    }
}
