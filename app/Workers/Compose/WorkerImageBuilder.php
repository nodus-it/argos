<?php

declare(strict_types=1);

namespace App\Workers\Compose;

use App\Enums\WorkerImageBuildStatus;
use App\Models\WorkerImageBuild;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Performs the actual `docker build` calls behind the compose pipeline.
 *
 * Two stages, in order:
 *   1. Stack image — written from WorkerStack.dockerfile_body to a temp
 *      file, built with repo root as context.
 *   2. Worker image — built from .tools/docker/worker/Dockerfile.compose
 *      with STACK_IMAGE / AGENT_INSTALL_SCRIPT / AGENT_VERSION as args.
 *
 * Stack images are cached by content hash, so repeated builds with
 * unchanged dockerfile_body skip stage 1.
 */
class WorkerImageBuilder
{
    /**
     * Default per-build timeout (seconds). 10 minutes covers a cold
     * `apt-get install + composer install + npm install -g` run.
     */
    public int $buildTimeoutSeconds = 600;

    /**
     * Build the worker image for the given resolved (stack, agent) pair.
     * Persists progress to worker_image_builds; returns the row.
     */
    public function build(ResolvedWorkerImage $resolved): WorkerImageBuild
    {
        $record = WorkerImageBuild::query()->updateOrCreate(
            [
                'worker_stack_id' => $resolved->stack->id,
                'agent_name' => $resolved->agent->name,
                'tag' => $resolved->workerTag,
            ],
            [
                'status' => WorkerImageBuildStatus::Building,
                'build_log' => null,
                'built_at' => null,
                'size_bytes' => null,
            ],
        );

        $log = '';
        try {
            $log .= $this->ensureStackImage($resolved);
            $log .= $this->buildWorkerImage($resolved);
            $log .= $this->validateWorkerImage($resolved);

            $record->forceFill([
                'status' => WorkerImageBuildStatus::Ready,
                'build_log' => $log,
                'built_at' => now(),
                'size_bytes' => $this->imageSize($resolved->workerTag),
            ])->save();
        } catch (Throwable $e) {
            $record->forceFill([
                'status' => WorkerImageBuildStatus::Failed,
                'build_log' => $log."\n\n".$e->getMessage(),
            ])->save();

            throw $e;
        }

        return $record->refresh();
    }

    /**
     * Smoke-test the freshly built worker image: every command we list
     * must exist on PATH inside the container, otherwise PhaseRunner
     * would crash mid-phase with a less-actionable error. This catches
     * dockerfile drift (apt package missing, agent install script
     * silently failing, etc.) at build time.
     *
     * Required tools (Argos baseline): bash, sh, jq, git, sed, grep, awk, curl
     * Plus the agent's CLI binary as declared by AgentSpec::cliBinary.
     *
     * The check uses `command -v`; failures propagate via Throwable so
     * build() flips the status to Failed with the missing tool list.
     */
    private function validateWorkerImage(ResolvedWorkerImage $resolved): string
    {
        $required = ['bash', 'sh', 'jq', 'git', 'sed', 'grep', 'awk', 'curl', $resolved->agent->cliBinary];

        // Build one shell script that prints "ok <name>" for present tools
        // and "MISSING <name>" otherwise, then exits non-zero if any are
        // missing — single docker invocation, full report in one log block.
        $checks = [];
        foreach ($required as $tool) {
            $escaped = escapeshellarg($tool);
            $checks[] = "if command -v {$escaped} >/dev/null 2>&1; then echo \"ok {$tool}\"; else echo \"MISSING {$tool}\"; rc=1; fi";
        }
        $script = "rc=0\n".implode("\n", $checks)."\nexit \$rc";

        $process = $this->newProcess([
            'docker', 'run', '--rm', '--entrypoint', 'sh',
            $resolved->workerTag, '-c', $script,
        ]);
        $process->setTimeout(30);
        $process->run();

        $output = $process->getOutput();

        if (! $process->isSuccessful()) {
            // Untag the broken image: workerImageExists() is the only gate
            // resolveOrBuild() consults, so an image that's tagged but
            // invalid would silently get reused on the next phase run —
            // exactly the failure mode this validator is meant to catch.
            // Best-effort; if the rmi itself fails the build is still
            // recorded as failed and the next save bumps the dockerfile
            // hash anyway.
            $this->untagImage($resolved->workerTag);

            throw new RuntimeException(
                "Worker image validation failed (exit {$process->getExitCode()}):\n".$output.$process->getErrorOutput()
            );
        }

        return "[validate]\n".$output."\n";
    }

    private function untagImage(string $tag): void
    {
        $process = $this->newProcess(['docker', 'rmi', '-f', $tag]);
        $process->setTimeout(15);
        $process->run();
    }

    public function workerImageExists(string $tag): bool
    {
        return $this->imageExists($tag);
    }

    private function ensureStackImage(ResolvedWorkerImage $resolved): string
    {
        if ($this->imageExists($resolved->stackTag)) {
            return "stack {$resolved->stackTag} already present, skipping build\n";
        }

        $tmp = tempnam(sys_get_temp_dir(), 'argos-stack-').'.dockerfile';
        file_put_contents($tmp, $resolved->stack->dockerfile_body);

        try {
            $process = $this->newProcess([
                'docker', 'build',
                '-t', $resolved->stackTag,
                '-f', $tmp,
                base_path(),
            ]);
            $process->setTimeout($this->buildTimeoutSeconds);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    "Stack image build failed (exit {$process->getExitCode()}):\n".$process->getErrorOutput()
                );
            }

            return "[stack build]\n".$process->getOutput()."\n";
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * AgentSpec::installScript carries a path relative to the manifest
     * directory (.tools/docker/worker/), but `docker build` here uses
     * the repo root as context — so we prefix the worker subtree.
     */
    private const WORKER_REPO_PREFIX = '.tools/docker/worker/';

    private function buildWorkerImage(ResolvedWorkerImage $resolved): string
    {
        $composeFile = base_path('.tools/docker/worker/Dockerfile.compose');
        if (! is_file($composeFile)) {
            throw new RuntimeException("Compose dockerfile not found: {$composeFile}");
        }

        $installScriptForBuild = self::WORKER_REPO_PREFIX.$resolved->agent->installScript;
        if (! is_file(base_path($installScriptForBuild))) {
            throw new RuntimeException("Agent install script not found in build context: {$installScriptForBuild}");
        }

        $process = $this->newProcess([
            'docker', 'build',
            '-t', $resolved->workerTag,
            '-f', $composeFile,
            '--build-arg', 'STACK_IMAGE='.$resolved->stackTag,
            '--build-arg', 'AGENT_INSTALL_SCRIPT='.$installScriptForBuild,
            '--build-arg', 'AGENT_VERSION='.$resolved->agent->pinnedVersion,
            base_path(),
        ]);
        $process->setTimeout($this->buildTimeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Worker image build failed (exit {$process->getExitCode()}):\n".$process->getErrorOutput()
            );
        }

        return "[worker build]\n".$process->getOutput()."\n";
    }

    private function imageExists(string $tag): bool
    {
        $process = $this->newProcess(['docker', 'image', 'inspect', $tag, '--format={{.Id}}']);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }

    private function imageSize(string $tag): ?int
    {
        $process = $this->newProcess(['docker', 'image', 'inspect', $tag, '--format={{.Size}}']);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return (int) trim($process->getOutput());
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }
}
