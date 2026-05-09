<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\WorkerImageEntityStatus;
use App\Filament\Admin\Support\WorkerStackBuildDispatcher;
use App\Models\WorkerStack;
use App\Workers\Agents\AgentRegistry;
use App\Workers\Compose\WorkerImageBuilder;
use App\Workers\Compose\WorkerImageResolver;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pre-warm the worker image cache for the built-in stacks. Intended to
 * run once at app boot (post-migrate, in app/entrypoint.sh) so the
 * first task phase a fresh install kicks off does not pay the 1-3 min
 * cold-build cost — by then the queue worker has already done it.
 *
 * Idempotent: skips any (stack × agent) whose image tag is already
 * present in the local Docker daemon.
 */
class WarmBuiltinWorkerImages extends Command
{
    protected $signature = 'argos:warm-builtin-images
        {--default-only : Only warm the default stack (config argos.compose.default_stack)}
        {--sync : Build synchronously instead of dispatching to the queue}';

    protected $description = 'Pre-build worker images for the built-in stacks so the first phase run is fast.';

    public function handle(
        WorkerImageResolver $resolver,
        WorkerImageBuilder $builder,
        WorkerStackBuildDispatcher $dispatcher,
    ): int {
        $stacks = $this->stacksToWarm();

        if ($stacks->isEmpty()) {
            $this->warn('No built-in stacks to warm. Run `php artisan argos:sync-builtin-images` first.');

            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $totalDispatched = 0;
        $totalSkipped = 0;
        $totalBuilt = 0;

        foreach ($stacks as $stack) {
            if ($sync) {
                [$built, $skipped] = $this->buildSync($stack, $resolver, $builder);
                $totalBuilt += $built;
                $totalSkipped += $skipped;

                continue;
            }

            $totalDispatched += $dispatcher->dispatchForStack($stack);
        }

        if ($sync) {
            $this->info("Warmed {$totalBuilt} image(s); {$totalSkipped} already cached.");
        } else {
            $this->info("Queued {$totalDispatched} build job(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Active built-in stacks, optionally narrowed to the configured default.
     *
     * @return Collection<int, WorkerStack>
     */
    private function stacksToWarm(): Collection
    {
        $query = WorkerStack::query()
            ->where('is_builtin', true)
            ->where('status', '!=', WorkerImageEntityStatus::Disabled);

        if ($this->option('default-only')) {
            $name = (string) config('argos.compose.default_stack', 'php-8.4');
            $query->where('name', $name);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * Build (synchronously) every (stack × compatible agent) pair whose
     * image tag is missing locally. Returns [built, skipped].
     *
     * @return array{0: int, 1: int}
     */
    private function buildSync(WorkerStack $stack, WorkerImageResolver $resolver, WorkerImageBuilder $builder): array
    {
        $built = 0;
        $skipped = 0;

        $registry = app(AgentRegistry::class);
        foreach ($registry->specs() as $spec) {
            try {
                $resolved = $resolver->resolveFor($stack, $spec->name);
            } catch (\Throwable $e) {
                $this->warn("skip {$stack->name} × {$spec->name->value}: {$e->getMessage()}");

                continue;
            }

            if ($builder->workerImageExists($resolved->workerTag)) {
                $skipped++;
                $this->line("✓ {$resolved->workerTag} already cached");

                continue;
            }

            $this->line("→ building {$resolved->workerTag}");
            $builder->build($resolved);
            $built++;
        }

        return [$built, $skipped];
    }
}
