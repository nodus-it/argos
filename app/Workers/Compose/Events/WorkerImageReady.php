<?php

declare(strict_types=1);

namespace App\Workers\Compose\Events;

use App\Models\WorkerImageBuild;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a successful worker image build. Step 4 doesn't have any
 * listeners yet (sync builds in the queue worker — Caller already
 * blocks); the event is here so step 5+ can hook async build flows.
 */
class WorkerImageReady
{
    use Dispatchable;

    public function __construct(public readonly WorkerImageBuild $build) {}
}
