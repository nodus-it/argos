<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceStatus;
use App\Models\ApiClient;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A token bound to an ApiClient that runs as an Argos admin (sees every workspace). */
function adminToken(array $abilities): string
{
    $admin = User::factory()->create(['is_admin' => true]);

    return ApiClient::factory()->create(['run_as_user_id' => $admin->id])->createToken('test', $abilities)->plainTextToken;
}

/** A workspace-bound token; it acts for the workspace's automation user. */
function workspaceToken(Workspace $workspace, array $abilities): string
{
    $bot = User::factory()->create();
    WorkspaceMember::query()->create(['workspace_id' => $workspace->id, 'user_id' => $bot->id, 'role' => WorkspaceRole::Member]);
    $workspace->forceFill(['automation_user_id' => $bot->id])->save();

    return $workspace->createToken('ci', $abilities)->plainTextToken;
}

// ── Auth & abilities ─────────────────────────────────────────────────────────

test('rejects unauthenticated requests', function () {
    $this->getJson('/api/v1/workspaces')->assertUnauthorized();
});

test('answers 401 (not a redirect) even without an Accept header', function () {
    // A bare client that forgets Accept: application/json must still get 401,
    // never a 302 to the Filament login.
    $this->get('/api/v1/workspaces')->assertUnauthorized();
});

test('rejects a token missing the required ability', function () {
    $token = adminToken(['tasks:read']); // not workspaces:read

    $this->withToken($token)->getJson('/api/v1/workspaces')->assertForbidden();
});

test('the removed project endpoints are gone', function () {
    $token = adminToken(['workspaces:read']);

    $this->withToken($token)->getJson('/api/v1/projects')->assertNotFound();
});

// ── Workspaces ───────────────────────────────────────────────────────────────

test('an Argos admin lists all workspaces', function () {
    Workspace::factory()->count(2)->create();
    $token = adminToken(['workspaces:read']);

    $this->withToken($token)->getJson('/api/v1/workspaces')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('the listing filters by container status', function () {
    Workspace::factory()->create(['status' => WorkspaceStatus::Running]);
    Workspace::factory()->create(['status' => WorkspaceStatus::Stopped]);
    $token = adminToken(['workspaces:read']);

    $this->withToken($token)->getJson('/api/v1/workspaces?status=running')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'running');
});

test('a workspace-bound token only sees its own workspace', function () {
    $own = Workspace::factory()->create();
    Workspace::factory()->create(); // other workspace
    $token = workspaceToken($own, ['workspaces:read']);

    $this->withToken($token)->getJson('/api/v1/workspaces')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);
});

test('a workspace-bound token gets 404 for a foreign workspace', function () {
    $own = Workspace::factory()->create();
    $foreign = Workspace::factory()->create();
    $token = workspaceToken($own, ['workspaces:read']);

    $this->withToken($token)->getJson("/api/v1/workspaces/{$foreign->id}")->assertNotFound();
});

test('workspace detail exposes the repository and default branch', function () {
    $workspace = Workspace::factory()->create(['name' => 'widget', 'default_branch' => 'develop', 'status' => WorkspaceStatus::Running]);
    $token = adminToken(['workspaces:read']);

    $this->withToken($token)->getJson("/api/v1/workspaces/{$workspace->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'widget')
        ->assertJsonPath('data.repository', 'test-org/test-repo')
        ->assertJsonPath('data.platform', 'github')
        ->assertJsonPath('data.default_branch', 'develop')
        ->assertJsonPath('data.status', 'running')
        ->assertJsonMissingPath('data.repo_profile_id');
});
