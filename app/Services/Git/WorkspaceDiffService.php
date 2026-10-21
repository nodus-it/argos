<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Models\Workspace;
use App\Services\Workspace\WorkspaceDaemonClient;
use Illuminate\Support\Facades\Cache;

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
}
