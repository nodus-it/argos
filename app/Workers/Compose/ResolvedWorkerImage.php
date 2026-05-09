<?php

declare(strict_types=1);

namespace App\Workers\Compose;

use App\Models\WorkerStack;
use App\Workers\Agents\AgentSpec;

/**
 * Result of WorkerImageResolver::resolve(): the stack/agent pair plus
 * the deterministic tags we need to look up or build.
 */
final readonly class ResolvedWorkerImage
{
    public function __construct(
        public WorkerStack $stack,
        public AgentSpec $agent,
        public string $stackTag,
        public string $workerTag,
    ) {}
}
