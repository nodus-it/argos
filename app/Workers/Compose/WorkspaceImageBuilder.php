<?php

declare(strict_types=1);

namespace App\Workers\Compose;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Builds the workspace image: the resolved worker image (stack × agent, BYOI
 * included) plus the layer in .tools/docker/worker/workspace/ (workspace
 * daemon + OpenVSCode Server). The tag carries a content hash of everything
 * baked into the layer, so a daemon change rebuilds automatically.
 */
class WorkspaceImageBuilder
{
    public const LAYER_DIR = '.tools/docker/worker/workspace';

    /** Repo-relative inputs of the layer, hashed into the tag. */
    private const FINGERPRINT_PATHS = [
        self::LAYER_DIR,
        'daemon/package.json',
        'daemon/package-lock.json',
        'daemon/tsconfig.json',
        'daemon/tsconfig.build.json',
        'daemon/src',
    ];

    public int $buildTimeoutSeconds = 1200;

    public function __construct(private readonly WorkerImageBuilder $workerBuilder) {}

    public function tag(ResolvedWorkerImage $resolved): string
    {
        $workerPart = substr($resolved->workerTag, (int) strpos($resolved->workerTag, ':') + 1);

        return 'argos-workspace:'.$workerPart.'-'.$this->fingerprint();
    }

    /**
     * Make sure the workspace image exists, building the worker image first
     * when needed. Returns the tag and the build log (empty when cached).
     *
     * @return array{tag: string, log: string}
     */
    public function ensure(ResolvedWorkerImage $resolved): array
    {
        $tag = $this->tag($resolved);
        if ($this->imageExists($tag)) {
            return ['tag' => $tag, 'log' => ''];
        }

        if (! $this->workerBuilder->workerImageExists($resolved->workerTag)) {
            $this->workerBuilder->build($resolved);
        }

        $process = $this->newProcess([
            'docker', 'build',
            '-t', $tag,
            '-f', base_path(self::LAYER_DIR.'/Dockerfile'),
            '--build-arg', 'BASE_IMAGE='.$resolved->workerTag,
            '--build-arg', 'OPENVSCODE_VERSION='.config('argos.workspaces.openvscode_version'),
            base_path(),
        ]);
        $process->setTimeout($this->buildTimeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Workspace image build failed (exit {$process->getExitCode()}):\n".$this->tail($process->getErrorOutput())
            );
        }

        return ['tag' => $tag, 'log' => $this->tail($process->getOutput()."\n".$process->getErrorOutput())];
    }

    public function imageExists(string $tag): bool
    {
        $process = $this->newProcess(['docker', 'image', 'inspect', $tag, '--format={{.Id}}']);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }

    /** 8-hex content hash over the layer inputs (plus the editor version). */
    public function fingerprint(): string
    {
        $files = [];
        foreach (self::FINGERPRINT_PATHS as $path) {
            $absolute = base_path($path);
            if (is_file($absolute)) {
                $files[$path] = $absolute;

                continue;
            }
            if (! is_dir($absolute)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $files[$path.substr($file->getPathname(), strlen($absolute))] = $file->getPathname();
                }
            }
        }
        ksort($files);

        $hash = hash_init('sha256');
        hash_update($hash, (string) config('argos.workspaces.openvscode_version'));
        foreach ($files as $relative => $absolute) {
            hash_update($hash, $relative."\0");
            hash_update_file($hash, $absolute);
        }

        return substr(hash_final($hash), 0, 8);
    }

    private function tail(string $output, int $bytes = 20_000): string
    {
        return strlen($output) > $bytes ? '…'.substr($output, -$bytes) : $output;
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }
}
