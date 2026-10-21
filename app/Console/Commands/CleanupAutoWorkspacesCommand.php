<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutoThreadState;
use App\Enums\WorkspaceOrigin;
use App\Models\Thread;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Console\Command;

/**
 * Deletes automatic workspaces (started by the API, MCP or an issue) once
 * they are no longer needed: their pull request is merged or closed, or
 * their run ended and nobody worked in them for `cleanup_days`. Workspaces
 * people created by hand are never touched.
 */
class CleanupAutoWorkspacesCommand extends Command
{
    protected $signature = 'argos:workspaces:cleanup-auto {--dry-run : List what would be deleted}';

    protected $description = 'Delete automatic workspaces whose pull request is merged/closed or whose run ended long ago.';

    /** Grace period after the last activity, so nobody loses a workspace they just looked at. */
    private const GRACE_HOURS = 1;

    public function handle(WorkspaceService $workspaces): int
    {
        $days = (int) config('argos.workspaces.auto.cleanup_days', 14);
        $deleted = 0;

        Workspace::query()
            ->where('origin', '!=', WorkspaceOrigin::Manual->value)
            ->where(fn ($q) => $q->whereNull('last_activity_at')->orWhere('last_activity_at', '<', now()->subHours(self::GRACE_HOURS)))
            ->whereDoesntHave('threads', fn ($q) => $q->where('session_open', true))
            ->each(function (Workspace $workspace) use ($workspaces, $days, &$deleted): void {
                if (! $this->expired($workspace, $days)) {
                    return;
                }
                $this->line(($this->option('dry-run') ? '[dry-run] ' : '').'Deleting '.$workspace->slug);
                if (! $this->option('dry-run')) {
                    $workspaces->deleteWorkspace($workspace);
                }
                $deleted++;
            });

        $this->info("{$deleted} automatic workspace(s) cleaned up.");

        return self::SUCCESS;
    }

    private function expired(Workspace $workspace, int $days): bool
    {
        if (in_array($workspace->pr_state, ['merged', 'closed'], true)) {
            return true;
        }

        $running = Thread::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('auto_state')
            ->whereNotIn('auto_state', [AutoThreadState::Done->value, AutoThreadState::Failed->value, AutoThreadState::BudgetExceeded->value])
            ->exists();

        return ! $running && $days > 0 && ($workspace->last_activity_at ?? $workspace->updated_at)->lt(now()->subDays($days));
    }
}
