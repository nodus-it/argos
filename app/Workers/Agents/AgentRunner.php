<?php

declare(strict_types=1);

namespace App\Workers\Agents;

use App\Models\AgentCredential;

/**
 * Manager-side agent runner contract.
 *
 * Implementations describe an agent (Claude Code, Codex, …) statically
 * via spec() and translate a stored AgentCredential into the env-vars
 * the worker container needs at runtime via materializeCredential().
 *
 * The Bash-side runner (worker/lib/agents/<name>.sh) is the worker
 * container's counterpart; both are kept in sync via convention, not
 * a shared interface (different runtimes).
 */
interface AgentRunner
{
    public static function spec(): AgentSpec;

    /**
     * Translate a stored AgentCredential into the env-vars the worker
     * needs. Implementations may fall back to legacy single-token
     * config when $credential is null (claude_token env / file).
     *
     * @throws \RuntimeException when no credential can be resolved.
     */
    public function materializeCredential(?AgentCredential $credential): MaterializedAgentCredential;
}
