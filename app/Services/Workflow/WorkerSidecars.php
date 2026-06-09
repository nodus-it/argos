<?php

declare(strict_types=1);

namespace App\Services\Workflow;

/**
 * Immutable handle to the ephemeral sidecars started for one worker phase run:
 * the private network, the worker-facing connection env, and the container
 * names to tear down. Empty (network null) when the profile enabled no services
 * or the phase doesn't run tests.
 */
final class WorkerSidecars
{
    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $containers
     */
    public function __construct(
        public readonly ?string $network = null,
        public readonly array $env = [],
        public readonly array $containers = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->network === null;
    }
}
