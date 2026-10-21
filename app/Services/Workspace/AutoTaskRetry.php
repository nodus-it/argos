<?php

declare(strict_types=1);

namespace App\Services\Workspace;

use RuntimeException;

/** An auto-task step has to wait (workspace starting, agent busy, gates running). */
final class AutoTaskRetry extends RuntimeException
{
    public function __construct(public readonly int $delaySeconds, public readonly bool $giveUp = false)
    {
        parent::__construct('Auto-task step retries later');
    }
}
