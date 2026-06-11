<?php

declare(strict_types=1);

namespace App\Events;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;

/**
 * Base for Argos domain events: records when the event occurred and which user
 * triggered it. Subclasses carry the domain object(s) they concern.
 */
abstract class DomainEvent
{
    public readonly CarbonImmutable $occurredAt;

    public readonly int|string|null $actorId;

    public function __construct()
    {
        $this->occurredAt = now()->toImmutable();
        $this->actorId = Auth::id();
    }
}
