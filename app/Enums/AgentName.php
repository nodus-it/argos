<?php

declare(strict_types=1);

namespace App\Enums;

use App\Workers\Agents\AgentRegistry;
use App\Workers\Agents\AgentRunner;
use App\Workers\Agents\AgentSpec;

/**
 * Stable identifier for a worker agent. The runner class lives in code,
 * so adding a new agent always requires a code change — making the Enum
 * the natural source of truth for "which agents exist".
 *
 * Each case maps to a registered AgentRunner via the AgentRegistry.
 * DB columns referencing an agent (`agent_name`, `worker_agent_name`,
 * `worker_agent_name_override`) are cast to this enum.
 */
enum AgentName: string
{
    case ClaudeCode = 'claude-code';

    public function label(): string
    {
        return $this->spec()->label;
    }

    public function runner(): AgentRunner
    {
        return app(AgentRegistry::class)->get($this);
    }

    public function spec(): AgentSpec
    {
        return ($this->runner())::spec();
    }
}
