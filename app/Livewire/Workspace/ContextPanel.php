<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\GateRunStatus;
use App\Enums\GitProvider;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\PullRequestService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Support\TaskRefs;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
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
    /** The four gates the sidebar always lists, with the run keys behind each row. */
    public const GATE_ROWS = [
        'pint' => ['label' => 'Pint', 'keys' => ['pint']],
        'pest' => ['label' => 'Pest / PHPUnit', 'keys' => ['pest', 'phpunit']],
        'phpstan' => ['label' => 'PHPStan', 'keys' => ['phpstan']],
        'artisan' => ['label' => 'Artisan', 'keys' => ['artisan']],
    ];

    #[Locked]
    public string $workspaceId;

    /** The task shown in the chat. */
    public ?string $taskId = null;

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);
        $requested = request()->query('task');
        $this->taskId = is_string($requested) && Task::query()->where('workspace_id', $workspaceId)->whereKey($requested)->exists()
            ? $requested
            : Task::query()->where('workspace_id', $workspaceId)->whereNull('archived_at')->latest('updated_at')->value('id');
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

        try {
            app(WorkspaceGateService::class)->run($task, $this->user());
            Notification::make()->title(__('workspaces.gates.started'))->success()->send();
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();
        }
    }

    public function prRefresh(): void
    {
        $this->prAction(fn (PullRequestService $pr, Task $task) => $pr->refresh($task));
    }

    public function prMarkReady(): void
    {
        $this->prAction(fn (PullRequestService $pr, Task $task) => $pr->markReady($task, $this->user()));
    }

    public function prAutoMerge(bool $on): void
    {
        $this->prAction(fn (PullRequestService $pr, Task $task) => $pr->setAutoMerge($task, $this->user(), $on));
    }

    public function prFixChecks(): void
    {
        $this->prAction(fn (PullRequestService $pr, Task $task) => $pr->fixChecks($task, $this->user()));
    }

    public function prSendReview(): void
    {
        $this->prAction(fn (PullRequestService $pr, Task $task) => $pr->sendReview($task, $this->user()));
    }

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

        try {
            $action(app(PullRequestService::class), $task);
        } catch (WorkspaceException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        } catch (AuthorizationException) {
            Notification::make()->title(__('workspaces.task.viewer_hint'))->warning()->send();
        }
    }

    public function placeholder(): string
    {
        return '<div class="wf-context-inner"><span class="wf-spin"></span></div>';
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
            'gates' => self::gateRows($gateRun),
            'canOperate' => $task !== null && $this->user()->can('prompt', $task),
            'canEdit' => $this->user()->can('editor', $workspace),
            'running' => $workspace->status === WorkspaceStatus::Running,
            'pr' => $task?->pr_number !== null ? app(PullRequestService::class)->details($task) : null,
            'feedback' => $task?->pr_number !== null ? app(PullRequestService::class)->feedback($task) : ['reviews' => [], 'comments' => []],
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

    /**
     * Gate rows with a state: open, running, pass, fail or skip. Gates outside
     * the four fixed rows appear once they ran.
     *
     * @return list<array{key: string, label: string, keys: list<string>, state: string, failed: list<string>, log: string}>
     */
    public static function gateRows(?WorkspaceGateRun $run): array
    {
        $results = $run?->gates ?? [];
        $runningNow = $run?->status === GateRunStatus::Running;
        $rows = [];
        $covered = [];

        foreach (self::GATE_ROWS as $key => $row) {
            $found = array_values(array_filter(array_map(fn (string $k): ?string => $results[$k] ?? null, $row['keys'])));
            $covered = [...$covered, ...$row['keys']];
            $failed = array_values(array_filter($row['keys'], fn (string $k): bool => ($results[$k] ?? null) === 'fail'));
            $rows[] = ['key' => $key, 'label' => $row['label'], 'keys' => $row['keys'], 'state' => self::state($found, $runningNow), 'failed' => $failed, 'log' => self::logOf($run, $failed)];
        }
        foreach ($results as $key => $result) {
            if (! in_array($key, $covered, true) && $result !== 'skip') {
                $failed = $result === 'fail' ? [$key] : [];
                $rows[] = ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'keys' => [$key], 'state' => $result, 'failed' => $failed, 'log' => self::logOf($run, $failed)];
            }
        }

        return $rows;
    }

    /** @param  list<string>  $keys */
    private static function logOf(?WorkspaceGateRun $run, array $keys): string
    {
        return implode("\n", array_filter(array_map(fn (string $k): string => (string) ($run?->logs[$k] ?? ''), $keys)));
    }

    /** @param  list<string>  $results */
    private static function state(array $results, bool $running): string
    {
        return match (true) {
            $running => 'running',
            in_array('fail', $results, true) => 'fail',
            in_array('pass', $results, true) => 'pass',
            $results !== [] => 'skip',
            default => 'open',
        };
    }

    private function task(): ?Task
    {
        return $this->taskId !== null ? Task::query()->where('workspace_id', $this->workspaceId)->find($this->taskId) : null;
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
