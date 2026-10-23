<?php

declare(strict_types=1);

namespace App\Workers\Agents;

use App\Enums\AgentName;

/**
 * Static description of an agent — name, distribution and install hook.
 * Returned by AgentRunner::spec(); consumed by the WorkerImageResolver and by
 * Filament for forms/discovery.
 */
final readonly class AgentSpec
{
    /**
     * @param  list<string>  $requiresStackCapabilities  capabilities the host stack must provide (e.g. ['node'])
     * @param  string  $cliBinary  Name of the CLI executable installed by the npm package (used by the post-build validator to verify the image actually has it on PATH).
     */
    public function __construct(
        public AgentName $name,
        public string $label,
        public string $npmPackage,
        public string $pinnedVersion,
        public string $installScript,
        public string $cliBinary,
        public array $requiresStackCapabilities,
    ) {}
}
