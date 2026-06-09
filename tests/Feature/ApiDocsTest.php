<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The REST API docs (Scramble) are gated behind the Argos login: the UI at
// /docs/api and the OpenAPI document at /docs/api.json both require a signed-in
// user (config/scramble.php middleware + the viewApiDocs gate).

test('guests are redirected to the login when opening the API docs', function () {
    $this->get('/docs/api')
        ->assertRedirect(route('filament.admin.auth.login'));
});

test('guests cannot fetch the OpenAPI document', function () {
    $this->get('/docs/api.json')
        ->assertRedirect(route('filament.admin.auth.login'));
});

test('signed-in users can open the API docs UI', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/docs/api')->assertOk();
});

test('the OpenAPI document describes the v1 API for signed-in users', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->getJson('/docs/api.json')->assertOk();

    $document = $response->json();

    expect($document['openapi'])->toStartWith('3.')
        ->and($document['info']['title'])->toBe('Argos API')
        // Endpoints are inferred from routes/api.php (api_path = api/v1).
        ->and($document['paths'])->toHaveKeys(['/projects', '/tasks'])
        // Sanctum bearer auth is documented globally (MiddlewareAuthSecurityStrategy).
        ->and($document['security'])->not->toBeEmpty();
});
