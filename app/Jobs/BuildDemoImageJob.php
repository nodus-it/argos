<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Demo\DemoImageBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queue wrapper around DemoImageBuilder::ensure() for warming the default
 * live-demo runtime image. Dispatched at boot by `argos:warm-demo-image` so the
 * first demo deploy that falls back to the default contract does not pay the
 * cold-build cost inside the deploy job.
 */
class BuildDemoImageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public int $tries = 1;

    public function handle(DemoImageBuilder $builder): void
    {
        $builder->ensure();
    }
}
