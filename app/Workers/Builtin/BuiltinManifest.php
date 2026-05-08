<?php

declare(strict_types=1);

namespace App\Workers\Builtin;

use RuntimeException;

/**
 * Reads .tools/docker/worker/built-ins.php and resolves dockerfile paths
 * against the manifest's directory. Only stacks live in the manifest —
 * agents are pure code (App\Workers\Agents\AgentRegistry).
 */
class BuiltinManifest
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $stacks;

    private string $baseDir;

    public function __construct(string $manifestPath)
    {
        if (! is_file($manifestPath)) {
            throw new RuntimeException("Built-in manifest not found: {$manifestPath}");
        }

        $data = require $manifestPath;

        if (! is_array($data) || ! isset($data['stacks']) || ! is_array($data['stacks'])) {
            throw new RuntimeException(
                "Built-in manifest is malformed (missing or non-array 'stacks' key): {$manifestPath}"
            );
        }

        $this->baseDir = dirname($manifestPath);
        $this->stacks = array_values($data['stacks']);
    }

    public static function default(): self
    {
        return new self(base_path('.tools/docker/worker/built-ins.php'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stacks(): array
    {
        return $this->stacks;
    }

    public function resolvePath(string $relative): string
    {
        return $this->baseDir.DIRECTORY_SEPARATOR.$relative;
    }

    public function readFile(string $relative): string
    {
        $path = $this->resolvePath($relative);

        if (! is_file($path)) {
            throw new RuntimeException("Built-in manifest references missing file: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Built-in manifest could not read file: {$path}");
        }

        return $content;
    }
}
