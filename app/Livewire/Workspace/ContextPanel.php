<?php

declare(strict_types=1);

namespace App\Livewire\Workspace;

use App\Enums\GateRunStatus;
use App\Enums\WorkspaceStatus;
use App\Integrations\WorkspaceDaemon\DaemonTransportException;
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

    public function mount(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
        abort_unless($this->user()->can('view', $this->workspace()), 403);
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
                $diff = app(WorkspaceDiffService::class)->forWorkspace($workspace);
            } catch (DaemonTransportException) {
                $diff = null;
            }
        }
        $gateRun = app(WorkspaceGateService::class)->latest($workspace);
        $issue = $workspace->external_issue;

        return view('livewire.workspace.context-panel', [
            'workspace' => $workspace,
            'diff' => $diff,
            'files' => self::changedFiles($diff['files'] ?? []),
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
     * @param  array<int, array<string, mixed>>  $files
     * @return list<array{status: string, path: string, name: string, additions: int, deletions: int}>
     */
    public static function changedFiles(array $files): array
    {
        $rows = [];
        foreach ($files as $file) {
            $path = (string) (($file['to_path'] ?? '') !== '' ? $file['to_path'] : ($file['from_path'] ?? ''));
            $status = match (true) {
                (bool) ($file['is_new'] ?? false) => 'A',
                (bool) ($file['is_deleted'] ?? false) => 'D',
                ($file['from_path'] ?? $path) !== $path => 'R',
                default => 'M',
            };
            $rows[] = [
                'status' => $status,
                'path' => $path,
                'name' => basename($path),
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Gate rows with a state: open, running, pass, fail or skip. Gates outside
     * the four fixed rows appear once they ran.
     *
     * @return list<array{key: string, label: string, state: string}>
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
            $rows[] = ['key' => $key, 'label' => $row['label'], 'state' => self::state($found, $runningNow)];
        }
        foreach ($results as $key => $result) {
            if (! in_array($key, $covered, true) && $result !== 'skip') {
                $rows[] = ['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'state' => $result];
            }
        }

        return $rows;
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
