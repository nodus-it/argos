<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Task;
use Illuminate\Support\Facades\Process;

class WorkspaceDiffService
{
    public function __construct(private readonly DiffParser $parser) {}

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
