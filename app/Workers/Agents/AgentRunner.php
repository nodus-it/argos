<?php

declare(strict_types=1);

namespace App\Workers\Agents;

/**
 * Manager-side agent runner contract.
 *
 * Implementations describe an agent (Claude Code, Codex, …) statically
 * via spec(). Token-handling, ENV mapping for the worker container and
 * health checks come in later steps (4+) — kept narrow now to avoid
 * over-design before WorkerImageResolver actually consumes any of it.
 *
 * The Bash-side runner (worker/lib/agents/<name>.sh) is the worker
 * container's counterpart; both are kept in sync via convention, not
 * a shared interface (different runtimes).
 */
interface AgentRunner
{
    public static function spec(): AgentSpec;
}
