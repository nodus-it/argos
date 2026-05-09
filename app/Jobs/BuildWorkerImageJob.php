<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AgentName;
use App\Models\WorkerStack;
use App\Workers\Compose\WorkerImageBuilder;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queue wrapper around WorkerImageBuilder for UI-driven rebuilds.
 *
 * The PhaseRunner-driven path uses WorkerImageResolver::resolveOrBuild()
 * synchronously inside its own queue job — that is fine because
 * RunPhaseJob already runs in a worker with a long timeout. This job
 * exists for cases where Filament (step 6) wants to trigger a rebuild
 * without going through a task: bumping a pinned agent version, forcing
 * a cache-bust after a stack edit, or pre-warming a (stack, agent) pair.
 */
class BuildWorkerImageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(
        public readonly string $workerStackId,
        public readonly AgentName $agentName,
    ) {}

    public function handle(WorkerImageResolver $resolver, WorkerImageBuilder $builder): void
    {
        $stack = WorkerStack::query()->findOrFail($this->workerStackId);
        $resolved = $resolver->resolveFor($stack, $this->agentName);

        $builder->build($resolved);
    }
}
