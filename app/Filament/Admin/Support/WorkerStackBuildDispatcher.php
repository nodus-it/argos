<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Enums\WorkerImageEntityStatus;
use App\Jobs\BuildWorkerImageJob;
use App\Models\WorkerStack;
use App\Workers\Agents\AgentRegistry;
use App\Workers\Compose\StackAgentCompatibility;

/**
 * Helper used by Create/Edit WorkerStack pages to fan out build jobs
 * for every registered agent that is compatible with the stack. Lives
 * outside the Filament page classes so the unit tests can hit it
 * directly (Filament page classes need a live Livewire component).
 */
class WorkerStackBuildDispatcher
{
    public function __construct(
        private readonly AgentRegistry $agents,
    ) {}

    /**
     * Dispatch one BuildWorkerImageJob per compatible (stack × agent).
     * Disabled stacks are skipped (the resolver would refuse to use them
     * anyway). Returns the number of jobs queued.
     */
    public function dispatchForStack(WorkerStack $stack): int
    {
        if ($stack->status === WorkerImageEntityStatus::Disabled) {
            return 0;
        }

        $count = 0;
        foreach ($this->agents->specs() as $spec) {
            if (! StackAgentCompatibility::isCompatible($stack, $spec)) {
                continue;
            }

            BuildWorkerImageJob::dispatch($stack->id, $spec->name);
            $count++;
        }

        return $count;
    }
}
