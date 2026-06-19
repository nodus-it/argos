<?php

declare(strict_types=1);

use App\Events\DomainEvent;
use App\Models\ApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/** Concrete stand-in: DomainEvent is abstract and carries no domain object. */
final class DomainEventActorStub extends DomainEvent {}

it('records no actor when unauthenticated', function (): void {
    expect((new DomainEventActorStub)->actorId)->toBeNull();
});

it('records the user id when a user is authenticated', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect((new DomainEventActorStub)->actorId)->toBe($user->id);
});

it('records the model key when the actor is a non-authenticatable model', function (): void {
    // The REST API authenticates an ApiClient (HasApiTokens, not Authenticatable),
    // so getAuthIdentifier() would not exist — the resolver must fall back to the
    // model key. (The real token flow is covered end-to-end by RestApiTest.)
    $client = ApiClient::factory()->create();
    Auth::shouldReceive('user')->andReturn($client);

    expect((new DomainEventActorStub)->actorId)->toBe($client->getKey());
});
