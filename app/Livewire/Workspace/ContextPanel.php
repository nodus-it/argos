<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\GateRunStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Models\Thread;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceGateRun;
use App\Services\Git\WorkspaceDiffService;
use App\Services\Workspace\WorkspaceException;
use App\Services\Workspace\WorkspaceGateService;
use App\Services\Workspace\WorkspaceIssueService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Context sidebar of a workspace: environment (VS Code, preview), branch,
 * quality gates and the changed files. Reads the diff through the daemon,
 * so it renders lazily and refreshes when Claude finishes a turn.
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

    /** Thread that "send to Claude" goes to (the one shown in the chat). */
    public ?string $threadId = null;

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);
        $requested = request()->query('thread');
        $this->threadId = is_string($requested) && Thread::query()->where('workspace_id', $workspaceId)->whereKey($requested)->exists()
            ? $requested
            : Thread::query()->where('workspace_id', $workspaceId)->whereNull('archived_at')->latest('updated_at')->value('id');
    }

    #[On('thread-changed')]
    public function threadChanged(?string $id): void
    {
        $this->threadId = $id;
    }

    /** Hand a failed gate to Claude with the gate's fix prompt. */
    public function sendGateFix(string $gate): void
    {
        $run = app(WorkspaceGateService::class)->latest($this->workspace());
        $thread = $this->threadId !== null ? Thread::query()->where('workspace_id', $this->workspaceId)->find($this->threadId) : null;
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

    /** "Alle prüfen", the change tray and `/gates`. */
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

    public function placeholder(): string
    {
        return '<div class="wf-context-inner"><span class="wf-spin"></span></div>';
    }

    public function render(): View
    {
        $workspace = $this->workspace();
        $diff = null;
        if ($workspace->status === WorkspaceStatus::Running) {
            try {
                $diff = app(WorkspaceDiffService::class)->summary($workspace);
            } catch (DaemonTransportException) {
                $diff = null;
            }
        }
        $gateRun = app(WorkspaceGateService::class)->latest($workspace);
        $issue = $workspace->external_issue;

        return view('livewire.workspace.context-panel', [
            'workspace' => $workspace,
            'diff' => $diff,
            'files' => self::changedFiles(array_values(array_filter($diff['files'] ?? [], fn (array $f): bool => ! $f['generated']))),
            'generated' => array_values(array_filter($diff['files'] ?? [], fn (array $f): bool => $f['generated'])),
            'gateRun' => $gateRun,
            'gates' => self::gateRows($gateRun),
            'canOperate' => $this->user()->can('operate', $workspace),
            'running' => $workspace->status === WorkspaceStatus::Running,
            'issueLabel' => $issue !== null ? app(WorkspaceIssueService::class)->label($issue['number'], $issue['title']) : null,
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
     * @return list<array{key: string, label: string, state: string, failed: list<string>, log: string}>
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
            $rows[] = ['key' => $key, 'label' => $row['label'], 'state' => self::state($found, $runningNow), 'failed' => $failed, 'log' => self::logOf($run, $failed)];
        }
        foreach ($results as $key => $result) {
            if (! in_array($key, $covered, true) && $result !== 'skip') {
                $failed = $result === 'fail' ? [$key] : [];
                $rows[] = ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'state' => $result, 'failed' => $failed, 'log' => self::logOf($run, $failed)];
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

    private function workspace(): Workspace
    {
        return Workspace::query()->with('repoProfile')->findOrFail($this->workspaceId);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
