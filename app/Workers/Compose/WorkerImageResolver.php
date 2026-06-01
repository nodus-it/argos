<?php

declare(strict_types=1);

namespace App\Workers\Compose;

use App\Enums\AgentName;
use App\Enums\WorkerImageEntityStatus;
use App\Enums\WorkerSource;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\WorkerStack;
use App\Services\GitProvider\GitServiceFactory;
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

    /** Repo-relative path of a BYOI stack-base recipe. */
    private const BYOI_DOCKERFILE_PATH = '.argos/worker.dockerfile';

    private function resolveStack(Task $task): WorkerStack
    {
        // An explicit per-task stack override always wins.
        if ($task->workerStackOverride !== null) {
            return $task->workerStackOverride;
        }

        $profile = $task->repoProfile;

        // BYOI: the repo ships its own stack-base recipe under
        // .argos/worker.dockerfile. Argos still layers the agent + worker code
        // on top (Dockerfile.compose), so this only replaces the FROM base.
        if ($profile?->worker_source === WorkerSource::Byoi) {
            return $this->resolveByoiStack($task, $profile);
        }

        return $profile?->workerStack ?? $this->defaultStack();
    }

    /**
     * Materialise the repo's .argos/worker.dockerfile as a WorkerStack so the
     * normal build pipeline (content-hash tag, on-demand build, image-build
     * tracking) applies unchanged. The file is read via the provider API at the
     * task's base branch — before any worker image (and thus any clone) exists.
     */
    private function resolveByoiStack(Task $task, RepoProfile $profile): WorkerStack
    {
        $ref = $task->base_branch ?? $profile->default_branch;

        $dockerfile = app(GitServiceFactory::class)
            ->fromRepoProfile($profile)
            ->getFileContents($profile->getOwnerRepo(), self::BYOI_DOCKERFILE_PATH, $ref);

        if ($dockerfile === null || trim($dockerfile) === '') {
            throw new RuntimeException(sprintf(
                "BYOI is enabled for '%s' but '%s' was not found on '%s'.",
                $profile->name,
                self::BYOI_DOCKERFILE_PATH,
                $ref,
            ));
        }

        $agent = $this->resolveAgentName($task)->spec();

        return WorkerStack::query()->updateOrCreate(
            ['name' => "byoi-{$profile->id}"],
            [
                'label' => "BYOI · {$profile->name}",
                'is_builtin' => false,
                'base_image' => $this->parseBaseImage($dockerfile),
                'dockerfile_body' => $dockerfile,
                // Trust the repo image to provide what the agent needs; the
                // build's validateWorkerImage step enforces the real tool
                // contract (bash, jq, git, …, agent CLI) and untags on failure.
                'capabilities' => $agent->requiresStackCapabilities,
                'common_tools' => [],
                'status' => WorkerImageEntityStatus::Active,
            ],
        );
    }

    /** Best-effort base image from the first FROM line — metadata only. */
    private function parseBaseImage(string $dockerfile): string
    {
        if (preg_match('/^\s*FROM\s+(\S+)/im', $dockerfile, $m) === 1) {
            return $m[1];
        }

        return 'byoi';
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
        $libHash = $this->workerLibFingerprint();
        // pinnedVersion can be 'latest' or '1.x.y'; sanitise for tag rules
        $version = preg_replace('/[^a-zA-Z0-9._-]/', '_', $agent->pinnedVersion) ?? 'unknown';

        return "argos-worker:{$stack->name}-{$stackHash}-{$libHash}-{$agent->name->value}-{$version}";
    }

    /**
     * Source paths whose content gets baked into the worker image — must
     * mirror the COPY directives in .tools/docker/worker/Dockerfile.compose
     * (the dockerfile WorkerImageBuilder actually builds). Override in tests
     * that need to feed a synthetic tree.
     *
     * @return list<string> repo-relative paths (file or directory)
     */
    protected function workerLibPaths(): array
    {
        return [
            'worker/lib',
            'worker/phases',
            'worker/prompts',
            'worker/schemas',
            '.tools/docker/worker/worker-entrypoint.sh',
            '.tools/docker/worker/Dockerfile.compose',
        ];
    }

    /**
     * Hash of every file the worker Dockerfile copies into the image. Without
     * it the image tag would only react to the *stack* dockerfile, leaving
     * worker/lib/, worker/phases/, prompts/, schemas/ and the worker
     * Dockerfile/entrypoint changes silently cached behind the same tag.
     */
    private function workerLibFingerprint(): string
    {
        $ctx = hash_init('sha256');
        foreach ($this->workerLibPaths() as $rel) {
            $this->hashSourcePath($ctx, $rel);
        }

        return substr(hash_final($ctx), 0, 8);
    }

    /**
     * @param  \HashContext  $ctx
     */
    private function hashSourcePath($ctx, string $rel): void
    {
        $abs = base_path($rel);

        if (is_file($abs)) {
            hash_update($ctx, $rel."\0".(string) file_get_contents($abs)."\0");

            return;
        }

        if (! is_dir($abs)) {
            // Missing path contributes nothing — surfaces the gap cleanly
            // when a Dockerfile COPY references a path the resolver lost.
            return;
        }

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($iter as $entry) {
            if ($entry->isFile()) {
                $files[] = (string) $entry;
            }
        }
        sort($files); // determinism: filesystem iteration order is undefined

        $base = base_path().DIRECTORY_SEPARATOR;
        foreach ($files as $file) {
            $relFile = str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
            hash_update($ctx, $relFile."\0".(string) file_get_contents($file)."\0");
        }
    }
}
