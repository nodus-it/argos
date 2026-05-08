<?php

declare(strict_types=1);

namespace App\Workers\Agents;

use App\Enums\AgentName;

/**
 * Static description of an agent — name, distribution and install hook.
 * Returned by AgentRunner::spec(); consumed by the WorkerImageResolver
 * (Step 4+) and by Filament for forms/discovery.
 */
final readonly class AgentSpec
{
    /**
     * @param  list<string>  $requiresStackCapabilities  capabilities the host stack must provide (e.g. ['node'])
     * @param  array<string, mixed>  $configSchema  shape that `tasks.agent_config` may carry
     */
    public function __construct(
        public AgentName $name,
        public string $label,
        public string $npmPackage,
        public string $pinnedVersion,
        public string $installScript,
        public array $requiresStackCapabilities,
        public array $configSchema = [],
    ) {}
}
