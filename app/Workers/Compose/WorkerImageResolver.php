<?php

declare(strict_types=1);

namespace App\Workers\Compose;

use App\Enums\AgentName;
use App\Enums\WorkerImageEntityStatus;
use App\Models\Task;
use App\Models\WorkerStack;
use App\Workers\Agents\AgentSpec;
use RuntimeException;

/**
 * Resolves the (stack × agent) pair that should run for a task and
 * computes deterministic image tags. Used by PhaseRunner.
 *
 * Resolution order for stack and agent: task-level override →
 * RepoProfile setting → config default.
 */
class WorkerImageResolver
{
    public function __construct(
        private readonly WorkerImageBuilder $builder,
    ) {}

    /**
     * Pure resolution — picks stack/agent and computes tags. Does not
     * touch Docker. Throws IncompatibleStackAgentException if the pair
     * doesn't satisfy the agent's required stack capabilities.
     */
    public function resolve(Task $task): ResolvedWorkerImage
    {
        return $this->resolveFor(
            $this->resolveStack($task),
            $this->resolveAgentName($task),
        );
    }

    /**
     * Stack/agent-direct variant — used by the rebuild job and tests
     * where there is no task in scope.
     */
    public function resolveFor(WorkerStack $stack, AgentName $agentName): ResolvedWorkerImage
    {
        if ($stack->status === WorkerImageEntityStatus::Disabled) {
            throw new RuntimeException("Stack '{$stack->name}' is disabled.");
        }

        $agent = $agentName->spec();

        $missing = StackAgentCompatibility::missingCapabilities($stack, $agent);
        if ($missing !== []) {
            throw IncompatibleStackAgentException::forMissing($stack, $agent, $missing);
        }

        return new ResolvedWorkerImage(
            stack: $stack,
            agent: $agent,
            stackTag: $this->stackTag($stack),
            workerTag: $this->workerTag($stack, $agent),
        );
    }

    /**
     * Resolution + build-on-demand. Returns the worker image tag ready
     * for `docker run`. Builds synchronously when the image is missing.
     */
    public function resolveOrBuild(Task $task): string
    {
        $resolved = $this->resolve($task);

        if (! $this->builder->workerImageExists($resolved->workerTag)) {
            $this->builder->build($resolved);
        }

        return $resolved->workerTag;
    }

    private function resolveStack(Task $task): WorkerStack
    {
        return $task->workerStackOverride
            ?? $task->repoProfile?->workerStack
            ?? $this->defaultStack();
    }

    private function resolveAgentName(Task $task): AgentName
    {
        return $task->worker_agent_name_override
            ?? $task->repoProfile?->worker_agent_name
            ?? AgentName::ClaudeCode;
    }

    private function defaultStack(): WorkerStack
    {
        $name = (string) config('argos.compose.default_stack', 'php-8.4');

        $stack = WorkerStack::query()
            ->where('name', $name)
            ->where('status', '!=', WorkerImageEntityStatus::Disabled)
            ->first();

        if ($stack === null) {
            throw new RuntimeException(
                "Default stack '{$name}' not found in worker_stacks. Run `argos:sync-builtin-images`."
            );
        }

        return $stack;
    }

    private function stackTag(WorkerStack $stack): string
    {
        $hash = substr(hash('sha256', $stack->dockerfile_body), 0, 8);

        return "argos-stack:{$stack->name}-{$hash}";
    }

    private function workerTag(WorkerStack $stack, AgentSpec $agent): string
    {
        $stackHash = substr(hash('sha256', $stack->dockerfile_body), 0, 8);
        // pinnedVersion can be 'latest' or '1.x.y'; sanitise for tag rules
        $version = preg_replace('/[^a-zA-Z0-9._-]/', '_', $agent->pinnedVersion) ?? 'unknown';

        return "argos-worker:{$stack->name}-{$stackHash}-{$agent->name->value}-{$version}";
    }
}
