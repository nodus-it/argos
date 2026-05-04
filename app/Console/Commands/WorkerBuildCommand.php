<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('worker:build {--php=* : Restrict to specific PHP variants (8.3, 8.4)}')]
#[Description('Build the local argos-worker image(s) without restarting the manager')]
class WorkerBuildCommand extends Command
{
    /**
     * Map of php variant → [tag, dockerfile target].
     */
    private const VARIANTS = [
        '8.4' => ['argos-worker:local-php8.4', 'worker-php84'],
        '8.3' => ['argos-worker:local-php8.3', 'worker'],
    ];

    public function handle(): int
    {
        $context = $this->workerContextPath();

        if ($context === null) {
            $this->error('Worker source not found. Looked in /app/worker and base_path("worker").');

            return self::FAILURE;
        }

        $variants = $this->selectedVariants();

        if ($variants === []) {
            $this->error('Unknown --php variant. Valid: '.implode(', ', array_keys(self::VARIANTS)));

            return self::FAILURE;
        }

        foreach ($variants as $php => [$tag, $target]) {
            $this->line("→ Building {$tag} (target: {$target})");

            $process = new Process(
                ['docker', 'build', '-t', $tag, '-f', 'docker/Dockerfile', '--target', $target, '.'],
                $context,
                timeout: 600,
            );

            $exit = $process->run(function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

            if ($exit !== 0) {
                $this->error("Build failed for {$tag} (exit {$exit}).");

                return self::FAILURE;
            }
        }

        $this->info('✓ Worker image(s) built.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function selectedVariants(): array
    {
        /** @var list<string> $requested */
        $requested = (array) $this->option('php');

        if ($requested === []) {
            return self::VARIANTS;
        }

        $selected = [];
        foreach ($requested as $php) {
            if (! isset(self::VARIANTS[$php])) {
                return [];
            }
            $selected[$php] = self::VARIANTS[$php];
        }

        return $selected;
    }

    private function workerContextPath(): ?string
    {
        foreach (['/app/worker', base_path('worker')] as $candidate) {
            if (is_dir($candidate.'/docker')) {
                return $candidate;
            }
        }

        return null;
    }
}
