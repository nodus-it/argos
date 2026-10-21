<?php

declare(strict_types=1);

namespace App\Workers\Agents;

/**
 * Manager-side agent contract: a static description of the agent (Claude
 * Code, …) that the worker image build installs. The workspace daemon hosts
 * the agent at runtime.
 */
interface AgentRunner
{
    public static function spec(): AgentSpec;
}
