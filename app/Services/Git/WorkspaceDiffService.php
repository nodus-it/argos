<?php

declare(strict_types=1);

namespace App\Services\Git;

use App\Integrations\WorkspaceDaemon\DaemonTransportException;
use App\Integrations\WorkspaceDaemon\WorkspaceDaemonTransport;
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
            $this->fetchThrottled($workspace, $api);
            $status = $api->gitStatus($task->slug);

            try {
                $files = $api->gitChanges($task->slug, 'origin/'.$task->base_branch);
            } catch (DaemonTransportException $e) {
                // A daemon from before /git/changes (container not restarted yet).
                if ($e->status !== 404) {
                    throw $e;
                }
                $files = $this->changesFromFullDiff($api->gitDiff($task->slug, 'origin/'.$task->base_branch)['diff']);
            }

            return [
                'files' => $files,
                'ahead' => (int) $status['ahead'],
                'uncommitted' => count($status['files']),
            ];
        });
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

    private function fetchThrottled(Workspace $workspace, WorkspaceDaemonTransport $api): void
    {
        Cache::remember('ws-fetch:'.$workspace->id, self::FETCH_THROTTLE, function () use ($api): bool {
            try {
                $api->gitFetch();
            } catch (\Throwable) {
                // Offline remote: diff against what we have.
            }

            return true;
        });
    }

    /**
     * @return list<array{path: string, from_path: string|null, status: string, additions: int, deletions: int, binary: bool, generated: bool, hash: string}>
     */
    private function changesFromFullDiff(string $diff): array
    {
        return array_map(function (array $f): array {
            $path = $f['to_path'] !== '' ? $f['to_path'] : $f['from_path'];

            return [
                'path' => $path,
                'from_path' => $f['from_path'] !== $path ? $f['from_path'] : null,
                'status' => $f['is_new'] ? 'A' : ($f['is_deleted'] ? 'D' : 'M'),
                'additions' => $f['additions'],
                'deletions' => $f['deletions'],
                'binary' => false,
                'generated' => (bool) preg_match('#(^|/)(composer\.lock|package-lock\.json|yarn\.lock|pnpm-lock\.yaml)$|\.min\.(js|css)$|\.map$#', $path),
                'hash' => sha1(serialize($f['hunks'])),
            ];
        }, $this->parser->parse($diff));
    }
}
