<?php

declare(strict_types=1);

namespace App\Services\Worker;

/**
 * Immutable handle to the sidecars started for a workspace: the private
 * network, the workspace-facing connection env, and the container names.
 * Empty (network null) when the profile enabled no services.
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
