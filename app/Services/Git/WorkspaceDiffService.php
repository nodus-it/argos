<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Jobs\Workspace\FetchWorkspaceRemoteJob;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceDaemonClient;
use Illuminate\Support\Facades\Cache;

class WorkspaceDiffService
{
    /** A workspace fetches its remote at most this often (seconds). */
    private const FETCH_THROTTLE = 60;

    public function __construct(private readonly DiffParser $parser) {}

    /**
     * Changed files with line counts, the commits ahead and the uncommitted
     * count — cheap enough for every live refresh. Shared for a moment, so
     * the sidebar and the change tray refreshing together cost one call.
     *
     * @return array{files: list<array{path: string, from_path: string|null, status: string, additions: int, deletions: int, binary: bool, generated: bool, hash: string}>, ahead: int, uncommitted: int}
     */
    public function summary(Task $task): array
    {
        return Cache::remember('ws-changes:'.$task->id, 2, function () use ($task): array {
            $workspace = $task->workspace;
            $api = app(WorkspaceDaemonClient::class)->for($workspace, 30);
            $this->fetchThrottled($workspace);
            $status = $api->gitStatus($task->slug);

            return [
                'files' => $api->gitChanges($task->slug, 'origin/'.$task->base_branch),
                'ahead' => (int) $status['ahead'],
                'uncommitted' => count($status['files']),
            ];
        });
    }

    /** After a commit or push the numbers move on — the next summary asks again. */
    public function forget(Task $task): void
    {
        Cache::forget('ws-changes:'.$task->id);
    }

    /**
     * The parsed diff of one file, cached by the file's content hash.
     *
     * @return array{from_path: string, to_path: string, is_new: bool, is_deleted: bool, additions: int, deletions: int, hunks: list<array{header: string, context_hint: string, lines: list<array{type: string, old_num: int|null, new_num: int|null, text: string}>}>}|null
     */
    public function fileDiff(Task $task, string $path, string $hash): ?array
    {
        return Cache::remember('ws-file-diff:'.$task->id.':'.sha1($path.'|'.$hash), 600, function () use ($task, $path): ?array {
            $diff = app(WorkspaceDaemonClient::class)->for($task->workspace, 30)->gitDiff($task->slug, 'origin/'.$task->base_branch, $path);

            return $this->parser->parse($diff['diff'])[0] ?? null;
        });
    }

    /**
     * Keep origin/<base> current without making anyone wait: at most once a
     * minute a background job fetches; the diff uses what is there now.
     */
    private function fetchThrottled(Workspace $workspace): void
    {
        if (Cache::add('ws-fetch:'.$workspace->id, true, self::FETCH_THROTTLE)) {
            FetchWorkspaceRemoteJob::dispatch($workspace->id);
        }
    }
}
