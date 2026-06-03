<?php

declare(strict_types=1);

namespace App\Services\Demo;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Builds (and caches) the built-in default demo runtime image used when a repo
 * ships no .argos/demo.* contract.
 *
 * Unlike the worker images there is no stack × agent matrix — one fixed image
 * from .tools/docker/demo/Dockerfile. The tag carries a content hash of the
 * Dockerfile and its bundled configs, so a recipe change yields a new tag and
 * the next deploy rebuilds; an unchanged recipe is a no-op (image already
 * present). Built on demand by DemoDeployer and warmed at boot via
 * `argos:warm-demo-image`.
 *
 * Manager-side only (needs the docker socket) — analogous to WorkerImageBuilder.
 */
class DemoImageBuilder
{
    /** Cold build (apt + extensions + node copy) comfortably fits in 10 min. */
    public int $buildTimeoutSeconds = 600;

    private const DOCKERFILE = '.tools/docker/demo/Dockerfile';

    /**
     * Files baked into the image — any change must bust the cached tag.
     *
     * @var list<string>
     */
    private const CONTEXT_FILES = [
        '.tools/docker/demo/Dockerfile',
        '.tools/docker/demo/nginx.conf',
        '.tools/docker/demo/supervisord.conf',
    ];

    /** Fully-qualified, content-hashed image tag (argos-demo:<8hex>). */
    public function tag(): string
    {
        $repository = (string) config('argos.preview.default_image', 'argos-demo');

        return $repository.':'.$this->fingerprint();
    }

    /**
     * Return the runtime image tag, building it first if it is not present in
     * the local Docker daemon. Idempotent.
     */
    public function ensure(): string
    {
        $tag = $this->tag();

        if (! $this->imageExists($tag)) {
            $this->build($tag);
        }

        return $tag;
    }

    public function imageExists(string $tag): bool
    {
        $process = $this->newProcess(['docker', 'image', 'inspect', $tag, '--format={{.Id}}']);
        $process->setTimeout(15);
        $process->run();

        return $process->isSuccessful();
    }

    private function build(string $tag): void
    {
        $dockerfile = base_path(self::DOCKERFILE);
        if (! is_file($dockerfile)) {
            throw new RuntimeException("Demo Dockerfile not found: {$dockerfile}");
        }

        $process = $this->newProcess([
            'docker', 'build',
            '-t', $tag,
            '-f', $dockerfile,
            base_path(),
        ]);
        $process->setTimeout($this->buildTimeoutSeconds);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                "Demo image build failed (exit {$process->getExitCode()}):\n".$process->getErrorOutput()
            );
        }
    }

    /** 8-hex content hash over the Dockerfile and its bundled config files. */
    private function fingerprint(): string
    {
        $ctx = hash_init('sha256');
        foreach (self::CONTEXT_FILES as $rel) {
            $abs = base_path($rel);
            $body = is_file($abs) ? (string) file_get_contents($abs) : '';
            hash_update($ctx, $rel."\0".$body."\0");
        }

        return substr(hash_final($ctx), 0, 8);
    }

    /**
     * @param  list<string>  $cmd
     */
    protected function newProcess(array $cmd): Process
    {
        return new Process($cmd);
    }
}
