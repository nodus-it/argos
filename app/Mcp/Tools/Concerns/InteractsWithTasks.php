<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Models\PhaseRun;
use App\Models\Task;
use App\Services\Task\TaskService;

/**
 * Shared helpers for the task-oriented MCP tools: resolving the `task`
 * argument (ULID or name) and reading the latest phase run of a kind. These
 * mirror the lookups Filament's ViewTask page performs so the tools enforce
 * the same preconditions without duplicating workflow logic.
 */
trait InteractsWithTasks
{
    /**
     * Resolve a task by its ULID or name. Returns null when nothing matches.
     */
    protected function findTask(?string $reference): ?Task
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        return app(TaskService::class)->find($reference);
    }

    /**
     * The most recent phase run of the given kind for a task, or null.
     */
    protected function latestPhaseRun(Task $task, string $phase): ?PhaseRun
    {
        return $task->phaseRuns()
            ->where('phase', $phase)
            ->orderByDesc('iteration')
            ->first();
    }

    /**
     * Whether at least one run of the given phase has completed.
     */
    protected function hasCompletedPhase(Task $task, string $phase): bool
    {
        return $task->phaseRuns()
            ->where('phase', $phase)
            ->where('status', 'completed')
            ->exists();
    }
}
