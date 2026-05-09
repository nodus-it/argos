<?php

declare(strict_types=1);

namespace App\Workers\Agents;

use App\Enums\AgentName;

/**
 * Static description of an agent — name, distribution, install hook, and
 * the models it offers to the manager. Returned by AgentRunner::spec();
 * consumed by the WorkerImageResolver and by Filament for forms/discovery.
 */
final readonly class AgentSpec
{
    /**
     * @param  list<string>  $requiresStackCapabilities  capabilities the host stack must provide (e.g. ['node'])
     * @param  array<string, mixed>  $configSchema  shape that `tasks.agent_config` may carry
     * @param  array<string, string>  $availableModels  model-id => display label, shown in TaskResource/RepoProfileResource selects
     * @param  array<string, string>  $defaultModelByPhase  phase ('concept'|'implement'|'commit-message') => model-id
     */
    public function __construct(
        public AgentName $name,
        public string $label,
        public string $npmPackage,
        public string $pinnedVersion,
        public string $installScript,
        public array $requiresStackCapabilities,
        public array $configSchema = [],
        public array $availableModels = [],
        public array $defaultModelByPhase = [],
    ) {}

    /**
     * Default model id for a phase, falling back to the first available
     * model when the phase has no explicit default.
     */
    public function defaultModel(string $phase): ?string
    {
        if (isset($this->defaultModelByPhase[$phase])) {
            return $this->defaultModelByPhase[$phase];
        }

        $first = array_key_first($this->availableModels);

        return is_string($first) ? $first : null;
    }
}
