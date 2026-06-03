<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\BuildDemoImageJob;
use App\Services\Demo\DemoImageBuilder;
use Illuminate\Console\Command;

/**
 * Pre-warm the default live-demo runtime image. Intended to run once at app
 * boot (app/entrypoint.sh) so the first demo deploy that falls back to the
 * built-in contract does not pay the cold-build cost.
 *
 * Idempotent: no-ops when previews are disabled or the content-hashed image
 * tag is already present locally.
 */
class WarmDemoImage extends Command
{
    protected $signature = 'argos:warm-demo-image
        {--sync : Build synchronously instead of dispatching to the queue}';

    protected $description = 'Pre-build the default live-demo runtime image so the first demo deploy is fast.';

    public function handle(DemoImageBuilder $builder): int
    {
        if (! config('argos.preview.enabled')) {
            $this->info('Live demos disabled (argos.preview.enabled=false) — skipping demo image warm.');

            return self::SUCCESS;
        }

        $tag = $builder->tag();

        if ($builder->imageExists($tag)) {
            $this->line("✓ {$tag} already cached");

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $this->line("→ building {$tag}");
            $builder->ensure();
            $this->info("Built {$tag}.");

            return self::SUCCESS;
        }

        BuildDemoImageJob::dispatch();
        $this->info("Queued demo image build ({$tag}).");

        return self::SUCCESS;
    }
}
