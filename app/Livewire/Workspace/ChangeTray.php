<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\GateRunStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceGateRun;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\PullRequestService;
use App\Services\Workspace\WorkspaceGateService;
use App\Support\GateAnnotations;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The action bar above the composer of a task: what changed ("N files
 * changed +X −Y · gate state", unpushed commits), the to-dos that need
 * someone now (red gates or pipeline, conflicts, review comments, a draft
 * pull request) and the actions for them — Check gates, Review, Commit,
 * Push (& PR). Shown while there is something to do; re-renders after a turn, a
 * gate run or a pull request change.
 */
#[Lazy]
class ChangeTray extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $taskId;

    public function mount(string $workspaceId, string $taskId): void
    {
        $this->workspaceId = $workspaceId;
        $this->taskId = $taskId;
        abort_unless($this->user()->can('view', $this->task()), 403);
    }

    public function placeholder(): string
    {
        return '<div></div>';
    }

    public function render(): View
    {
        $task = $this->task();
        $diff = null;
        if ($task->workspace->status === WorkspaceStatus::Running) {
            try {
                $diff = app(WorkspaceDiffService::class)->summary($task);
            } catch (DaemonTransportException) {
                $diff = null;
            }
        }
        $files = $diff['files'] ?? [];
        $run = app(WorkspaceGateService::class)->latest($task);
        $canOperate = $this->user()->can('prompt', $task);
        $canShip = $this->user()->can('ship', $task) && $task->workspace->status === WorkspaceStatus::Running;
        $todos = $this->todos($task, $run, $canOperate, $canShip);
        $uncommitted = (int) ($diff['uncommitted'] ?? 0);
        $ahead = (int) ($diff['ahead'] ?? 0);
        // Pushed, but the pull request is still missing (e.g. its step failed).
        $push = $ahead > 0 || ($task->pr_number === null && $files !== [] && $uncommitted === 0);

        return view('livewire.workspace.change-tray', [
            'show' => $diff !== null && ($uncommitted > 0 || $push || $todos !== []),
            'hasChanges' => $files !== [],
            'ahead' => $ahead,
            'todos' => $todos,
            'canShip' => $canShip,
            'canPush' => $canShip && $push,
            'uncommitted' => $uncommitted,
            'hasPr' => $task->pr_number !== null && in_array($task->pr_state, ['open', 'draft'], true),
            'count' => count($files),
            'additions' => array_sum(array_column($files, 'additions')),
            'deletions' => array_sum(array_column($files, 'deletions')),
            'gates' => self::gateSummary($run),
            'canOperate' => $canOperate,
        ]);
    }

    /**
     * What needs someone now, each with the one action that handles it.
     *
     * @return list<array{kind: string, label: string, action: string, event?: string, gate?: string}>
     */
    private function todos(Task $task, ?WorkspaceGateRun $run, bool $canOperate, bool $canShip): array
    {
        $todos = [];
        $failed = array_values(array_filter(ContextPanel::gateRows($run), fn (array $row): bool => $row['state'] === 'fail'));
        if ($failed !== [] && $run?->status !== GateRunStatus::Running) {
            $todos[] = ['kind' => 'fail', 'label' => __('workspaces.tray.todo.gates', ['gates' => implode(', ', array_column($failed, 'label'))]), 'action' => __('workspaces.tray.todo.gates_action'), 'gate' => $failed[0]['key']];
        }

        $pr = $task->pr_number !== null ? app(PullRequestService::class)->details($task) : null;
        $open = in_array($pr?->state ?? $task->pr_state, ['open', 'draft'], true);
        if ($pr !== null && $open) {
            if ($pr->checksFailed() && $canOperate) {
                $todos[] = ['kind' => 'fail', 'label' => __('workspaces.tray.todo.pipeline'), 'action' => __('workspaces.pr.fix_checks'), 'event' => 'pr-fix-checks'];
            }
            if (($pr->conflicts || $pr->behindBase === true) && $canOperate) {
                $todos[] = ['kind' => 'warn', 'label' => $pr->conflicts ? __('workspaces.tray.todo.conflicts', ['branch' => $task->base_branch]) : __('workspaces.tray.todo.behind', ['branch' => $task->base_branch]), 'action' => __('workspaces.pr.rebase'), 'event' => 'pr-rebase'];
            }
            $new = count(app(PullRequestService::class)->uncollectedComments($task, $this->user()));
            if ($new > 0 && $canOperate) {
                $todos[] = ['kind' => 'info', 'label' => trans_choice('workspaces.tray.todo.comments', $new, ['count' => $new]), 'action' => __('workspaces.review.collect_all'), 'event' => 'pr-collect'];
            }
            if ($pr->isDraft() && $canShip) {
                $todos[] = ['kind' => 'info', 'label' => __('workspaces.tray.todo.draft'), 'action' => __('workspaces.pr.ready'), 'event' => 'pr-mark-ready'];
            }
        }

        return $todos;
    }

    /** One phrase for the gate state: not run, running, all green, or which failed. */
    public static function gateSummary(?WorkspaceGateRun $run): string
    {
        if ($run === null || $run->status === GateRunStatus::Error) {
            return __('workspaces.tray.gates_none');
        }
        if ($run->status === GateRunStatus::Running) {
            return __('workspaces.tray.gates_running');
        }
        $failed = array_values(array_filter(ContextPanel::gateRows($run), fn (array $row): bool => $row['state'] === 'fail'));
        if ($failed === []) {
            return __('workspaces.tray.gates_green');
        }

        $phpstan = array_sum(array_map('count', GateAnnotations::fromRun($run)['phpstan']));
        $labels = array_map(fn (array $row): string => $row['key'] === 'phpstan' && $phpstan > 0
            ? trans_choice('workspaces.tray.gate_errors', $phpstan, ['gate' => $row['label'], 'count' => $phpstan])
            : $row['label'], $failed);

        return __('workspaces.tray.gates_failed', ['gates' => implode(', ', $labels)]);
    }

    private function task(): Task
    {
        return Task::query()->with('workspace')->where('workspace_id', $this->workspaceId)->findOrFail($this->taskId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
