<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\WorkspaceOrigin;
use App\Models\RepoProfile;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Start an automatic run in a new workspace — the one entry point for the
 * REST API, MCP and issue triggers.
 */
class AutoRunService
{
    public function __construct(
        private readonly WorkspaceService $workspaces,
        private readonly AgentPool $pool,
        private readonly AutoThreadDriver $driver,
    ) {}

    /**
     * @param  array{title?: string|null, base_branch?: string|null, agent_id?: string|null, model?: string|null, issue?: array<string, mixed>|null}  $options
     * @param  array<string, mixed>  $settings  per-run overrides (AutoSettings keys)
     */
    public function launch(User $actor, RepoProfile $project, string $prompt, array $options = [], array $settings = [], WorkspaceOrigin $origin = WorkspaceOrigin::Api): Thread
    {
        $agents = $this->pool->usableAgentsFor($actor);
        $agent = isset($options['agent_id']) && $options['agent_id'] !== null
            ? $agents->firstWhere('id', $options['agent_id'])
            : $agents->first();
        if ($agent === null) {
            throw WorkspaceException::translated('workspaces.auto.no_usable_agent');
        }

        $title = trim((string) ($options['title'] ?? '')) !== '' ? trim((string) $options['title']) : Str::limit(trim($prompt), 60, '…');
        $issue = $options['issue'] ?? null;
        $name = $issue !== null ? app(WorkspaceIssueService::class)->label((string) $issue['number'], (string) $issue['title']) : $title;

        $workspace = $this->workspaces->createWorkspace($actor, $project, mb_substr($name, 0, 120), $options['base_branch'] ?? null, $issue, $origin);

        return $this->driver->start($workspace, $actor, $agent, $prompt, $title, $options['model'] ?? null, $settings);
    }
}
