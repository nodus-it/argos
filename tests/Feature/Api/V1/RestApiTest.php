<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\WorkspaceStatus;
use App\Models\ApiClient;
use App\Models\RepoProfile;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A cross-project ("full access") token bound to an ApiClient. */
function fullToken(array $abilities): string
{
    return ApiClient::factory()->create()->createToken('test', $abilities)->plainTextToken;
}

// ── Auth & abilities ─────────────────────────────────────────────────────────

test('rejects unauthenticated requests', function () {
    $this->getJson('/api/v1/projects')->assertUnauthorized();
});

test('answers 401 (not a redirect) even without an Accept header', function () {
    // A bare client that forgets Accept: application/json must still get 401,
    // never a 302 to the Filament login.
    $this->get('/api/v1/projects')->assertUnauthorized();
});

test('rejects a token missing the required ability', function () {
    $token = fullToken(['workspaces:read']); // not projects:read

    $this->withToken($token)->getJson('/api/v1/projects')->assertForbidden();
});

// ── Projects ─────────────────────────────────────────────────────────────────

test('lists all projects for a user token', function () {
    RepoProfile::factory()->count(2)->create();
    $token = fullToken(['projects:read']);

    $this->withToken($token)->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('a project-bound token only sees its own project', function () {
    $own = RepoProfile::factory()->create();
    RepoProfile::factory()->create(); // other project
    $token = $own->createToken('ci', ['projects:read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);
});

test('a project-bound token gets 404 for a foreign project', function () {
    $own = RepoProfile::factory()->create();
    $foreign = RepoProfile::factory()->create();
    $token = $own->createToken('ci', ['projects:read'])->plainTextToken;

    $this->withToken($token)->getJson("/api/v1/projects/{$foreign->id}")->assertNotFound();
});

test('project detail exposes the automation defaults and open workspaces', function () {
    config(['argos.workspaces.auto.plan' => 'auto', 'argos.workspaces.auto.ship' => 'pr']);
    $project = RepoProfile::factory()->create(['auto_settings' => ['ship' => 'draft_pr']]);
    Workspace::factory()->create(['repo_profile_id' => $project->id, 'status' => WorkspaceStatus::Running]);
    Workspace::factory()->create(['repo_profile_id' => $project->id, 'status' => WorkspaceStatus::Stopped]);
    $token = fullToken(['projects:read']);

    $this->withToken($token)->getJson("/api/v1/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.auto_settings.plan', 'auto')
        ->assertJsonPath('data.auto_settings.ship', 'draft_pr')
        ->assertJsonPath('data.open_workspaces', 1)
        ->assertJsonMissingPath('data.open_tasks');
});
