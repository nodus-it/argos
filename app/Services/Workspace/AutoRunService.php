<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use App\Enums\TaskOrigin;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Start an automatic task in a workspace — the one entry point for the REST
 * API, MCP and issue triggers. A stopped workspace is started; the task
 * waits in the queue until it is up.
 */
class AutoRunService
{
    public function __construct(
        private readonly AgentPool $pool,
        private readonly AutoTaskDriver $driver,
    ) {}

    /**
     * @param  array{title?: string|null, base_branch?: string|null, agent_id?: string|null, model?: string|null, issue?: array<string, mixed>|null}  $options
     * @param  array<string, mixed>  $settings  per-run overrides (AutoSettings keys)
     */
    public function launch(User $actor, Workspace $workspace, string $prompt, array $options = [], array $settings = [], TaskOrigin $origin = TaskOrigin::Api): Task
    {
        $agents = $this->pool->usableAgentsFor($actor);
        $agent = isset($options['agent_id']) && $options['agent_id'] !== null
            ? $agents->firstWhere('id', $options['agent_id'])
            : $agents->first();
        if ($agent === null) {
            throw WorkspaceException::translated('workspaces.auto.no_usable_agent');
        }

        $issue = $options['issue'] ?? null;
        $title = match (true) {
            trim((string) ($options['title'] ?? '')) !== '' => trim((string) $options['title']),
            $issue !== null => (string) $issue['title'],
            default => Str::limit(trim($prompt), 60, '…'),
        };

        return $this->driver->start($workspace, $actor, $agent, $prompt, mb_substr($title, 0, 120), $options['model'] ?? null, $settings, [
            'base_branch' => $options['base_branch'] ?? null,
            'external_ref' => $issue,
            'origin' => $origin,
        ]);
    }
}
