<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\BackingService;
use App\Models\Task;
use App\Services\Workspace\Environment\BackingServiceResolver;
use App\Services\Workspace\Environment\EnvAudit;

/**
 * The values that set a task apart from its siblings in the same container:
 * its own database on the workspace's MySQL sidecar and its own Redis key
 * prefix. They go into the worktree's `.env` and override the container's
 * environment for everything running in the worktree.
 */
class TaskEnvironment
{
    public function __construct(private readonly BackingServiceResolver $services, private readonly EnvAudit $audit) {}

    /** `task_<slug>` (underscores; MySQL names stay short). */
    public static function databaseName(Task $task): string
    {
        return substr('task_'.str_replace('-', '_', $task->slug), 0, 64);
    }

    /**
     * @return array<string, string>
     */
    public function values(Task $task): array
    {
        $workspace = $task->workspace;
        $coordinates = $this->services->coordinates($workspace);
        $env = [];

        if (isset($coordinates[BackingService::Mysql->value])) {
            $env = [
                'DB_CONNECTION' => $this->audit->databaseDriver($workspace),
                ...BackingService::Mysql->connectionEnv([...$coordinates[BackingService::Mysql->value], 'database' => self::databaseName($task)]),
            ];
        }
        if (isset($coordinates[BackingService::Redis->value])) {
            $prefix = str_replace('-', '_', $task->slug).'_';
            $env = [
                ...$env,
                ...BackingService::Redis->connectionEnv($coordinates[BackingService::Redis->value]),
                'REDIS_PREFIX' => $prefix,
                'CACHE_PREFIX' => $prefix,
            ];
        }

        return $env;
    }
}
