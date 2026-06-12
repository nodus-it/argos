<?php

declare(strict_types=1);

namespace App\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Base for Argos domain events: records when the event occurred and which actor
 * triggered it. Subclasses carry the domain object(s) they concern.
 */
abstract class DomainEvent
{
    public readonly CarbonImmutable $occurredAt;

    public readonly int|string|null $actorId;

    public function __construct()
    {
        $this->occurredAt = now()->toImmutable();
        $this->actorId = $this->resolveActorId();
    }

    /**
     * The authenticated actor's identifier, or null when unauthenticated. The
     * actor is not always a User: the REST API authenticates an ApiClient
     * (a HasApiTokens model, not Authenticatable), so we read the model key
     * directly rather than assuming getAuthIdentifier() exists.
     */
    private function resolveActorId(): int|string|null
    {
        $actor = Auth::user();

        $id = match (true) {
            $actor instanceof Authenticatable => $actor->getAuthIdentifier(),
            $actor instanceof Model => $actor->getKey(),
            default => null,
        };

        return is_int($id) || is_string($id) ? $id : null;
    }
}
