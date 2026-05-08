<?php

declare(strict_types=1);

namespace App\Workers\Agents;

/**
 * Result of AgentRunner::materializeCredential() — the additional
 * env-vars (and possibly volumes/setup hooks later) the manager has
 * to pass to `docker run` so the worker container has the agent's
 * authentication available at runtime.
 *
 * Kept as env-vars only for now: file-based auth (codex auth.json)
 * is wrapped into a CODEX_AUTH_JSON_CONTENT env-var that the
 * worker-entrypoint materialises into /home/agent/.codex/auth.json.
 * That avoids cross-container file mounts which would require host-
 * visible paths from the manager container.
 */
final readonly class MaterializedAgentCredential
{
    /**
     * @param  array<string, string>  $envVars
     */
    public function __construct(public array $envVars) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->envVars, ...$other->envVars]);
    }
}
