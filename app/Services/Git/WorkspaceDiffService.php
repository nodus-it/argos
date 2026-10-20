<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Task;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceDaemonClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

class WorkspaceDiffService
{
    /** A workspace fetches its remote at most this often (seconds). */
    private const FETCH_THROTTLE = 60;

    public function __construct(private readonly DiffParser $parser) {}

    /**
     * Diff of an interactive workspace against its base branch, read through
     * the workspace daemon (the container is running — no throwaway docker
     * run). Includes uncommitted and untracked files.
     *
     * @return array{files: array<int, array{from_path: string, to_path: string, is_new: bool, is_deleted: bool, additions: int, deletions: int, hunks: list<array{header: string, context_hint: string, lines: list<array{type: string, old_num: int|null, new_num: int|null, text: string}>}>}>, truncated: bool, ahead: int, uncommitted: int}
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $api = app(WorkspaceDaemonClient::class)->for($workspace, 60);

        Cache::remember('ws-fetch:'.$workspace->id, self::FETCH_THROTTLE, function () use ($api): bool {
            try {
                $api->gitFetch();
            } catch (\Throwable) {
                // Offline remote: diff against what we have.
            }

            return true;
        });

        $status = $api->gitStatus();
        $diff = $api->gitDiff('origin/'.$workspace->base_branch);

        return [
            'files' => $this->parser->parse($diff['diff']),
            'truncated' => $diff['truncated'],
            'ahead' => (int) $status['ahead'],
            'uncommitted' => count($status['files']),
        ];
    }

    /**
     * Generate the working-tree diff for a task against its base branch.
     *
     * It shells out to `docker run` against the task volume. alpine/git is the
     * smallest image with `git` + `sh`, which keeps the diff agent-/stack-agnostic
     * so it works the same regardless of which worker image the task ran on. The
     * container runs as root while the volume is owned by the worker uid (1000) —
     * without `-c safe.directory='*'` git refuses every read with "dubious
     * ownership". Process timeouts/failures bubble up to the caller, which is
     * responsible for degrading the UI (the diff auto-triggers on mount).
     *
     * @return array{stat: string, files: array<int, array{from_path: string, to_path: string, is_new: bool, is_deleted: bool, additions: int, deletions: int, hunks: list<array{header: string, context_hint: string, lines: list<array{type: string, old_num: int|null, new_num: int|null, text: string}>}>}>}
     */
    public function forTask(Task $task): array
    {
        $branch = $task->repoProfile?->default_branch ?? 'main';
        $image = 'alpine/git';
        $vol = $task->volumeName();
        $g = "git -c safe.directory='*' -C /workspace";

        $statResult = Process::timeout(15)->run([
            'docker', 'run', '--rm',
            '-v', "{$vol}:/workspace:ro",
            '--entrypoint', 'sh', $image,
            '-c',
            "{$g} diff --stat origin/{$branch} 2>/dev/null; "
            .$g.' ls-files --others --exclude-standard 2>/dev/null | while IFS= read -r f; do echo " (neu) $f"; done; '
            ."echo ''; "
            .$g.' status --short 2>/dev/null',
        ]);

        $diffResult = Process::timeout(15)->run([
            'docker', 'run', '--rm',
            '-v', "{$vol}:/workspace:ro",
            '--entrypoint', 'sh', $image,
            '-c',
            "{ {$g} diff origin/{$branch} 2>/dev/null; "
            .$g.' ls-files --others --exclude-standard 2>/dev/null | while IFS= read -r f; do '
            .$g.' diff --no-index -- /dev/null "$f" 2>/dev/null || true; '
            .'done; } | head -c 131072',
        ]);

        return [
            'stat' => trim($statResult->output()),
            'files' => $this->parser->parse($diffResult->output()),
        ];
    }
}
