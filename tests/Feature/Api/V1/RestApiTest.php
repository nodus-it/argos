<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Models\ApiClient;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

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

test('rejects a token missing the required ability', function () {
    $token = fullToken(['tasks:read']); // not projects:read

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

// ── Tasks: scoping ───────────────────────────────────────────────────────────

test('a project-bound token only lists its own tasks', function () {
    $own = RepoProfile::factory()->create();
    $other = RepoProfile::factory()->create();
    Task::factory()->create(['repo_profile_id' => $own->id]);
    Task::factory()->create(['repo_profile_id' => $other->id]);

    $token = $own->createToken('ci', ['tasks:read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/tasks')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('a project-bound token gets 404 for a foreign task', function () {
    $own = RepoProfile::factory()->create();
    $foreign = Task::factory()->create(['repo_profile_id' => RepoProfile::factory()->create()->id]);

    $token = $own->createToken('ci', ['tasks:read'])->plainTextToken;

    $this->withToken($token)->getJson("/api/v1/tasks/{$foreign->id}")->assertNotFound();
});

test('task detail exposes the checkout block', function () {
    $repo = RepoProfile::factory()->create(['url' => 'https://github.com/acme/widget', 'default_branch' => 'main']);
    $task = Task::factory()->create([
        'repo_profile_id' => $repo->id,
        'feature_branch' => 'argos/feature-x',
        'pr_url' => 'https://github.com/acme/widget/pull/7',
    ]);
    $token = fullToken(['tasks:read']);

    $this->withToken($token)->getJson("/api/v1/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('data.checkout.repo_url', 'https://github.com/acme/widget')
        ->assertJsonPath('data.checkout.base_branch', 'main')
        ->assertJsonPath('data.checkout.feature_branch', 'argos/feature-x')
        ->assertJsonPath('data.pr_url', 'https://github.com/acme/widget/pull/7');
});

// ── Tasks: create ────────────────────────────────────────────────────────────

test('creates a task from a plan and starts concept (202)', function () {
    Process::fake();
    Queue::fake();

    $repo = RepoProfile::factory()->create(['name' => 'target']);
    $token = fullToken(['tasks:write']);

    $this->withToken($token)->postJson('/api/v1/tasks', [
        'name' => 'api-task',
        'project' => 'target',
        'plan' => 'Refactor the widget.',
    ])
        ->assertStatus(202)
        ->assertJsonPath('data.name', 'api-task');

    $task = Task::where('name', 'api-task')->first();
    expect($task)->not->toBeNull();
    expect($task->repo_profile_id)->toBe($repo->id);
    expect($task->description)->toContain('Refactor the widget.');
    expect($task->concept_notes)->toContain('Refactor the widget.');

    Queue::assertPushed(RunPhaseJob::class);
});

test('a project-bound token creates tasks in its own project without a project field', function () {
    Process::fake();
    Queue::fake();

    $repo = RepoProfile::factory()->create();
    $token = $repo->createToken('ci', ['tasks:write'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/tasks', [
        'name' => 'ci-task',
        'plan' => 'Do the thing.',
    ])->assertStatus(202);

    expect(Task::where('name', 'ci-task')->value('repo_profile_id'))->toBe($repo->id);
});

test('create requires a project for a user token', function () {
    $token = fullToken(['tasks:write']);

    $this->withToken($token)->postJson('/api/v1/tasks', [
        'name' => 'no-project',
        'plan' => 'x',
    ])->assertStatus(422)->assertJsonValidationErrors('project');
});

// ── Tasks: phase gates ───────────────────────────────────────────────────────

test('returns 409 when a phase is already running', function () {
    $task = Task::factory()->create([
        'current_status' => PhaseStatus::Running,
        'workflow_status' => WorkflowStatus::ConceptRunning,
    ]);
    $token = fullToken(['tasks:write']);

    $this->withToken($token)->postJson("/api/v1/tasks/{$task->id}/concept")->assertStatus(409);
});

test('implement requires a completed concept run (409)', function () {
    $task = Task::factory()->create([
        'current_status' => PhaseStatus::Pending,
        'workflow_status' => WorkflowStatus::Draft,
    ]);
    $token = fullToken(['tasks:write']);

    $this->withToken($token)->postJson("/api/v1/tasks/{$task->id}/implement")
        ->assertStatus(409)
        ->assertJsonPath('message', 'The Implement phase requires a completed Concept run first.');
});

test('pr starts the push phase when implement is complete (202)', function () {
    Queue::fake();

    $task = Task::factory()->create([
        'current_status' => PhaseStatus::Completed,
        'workflow_status' => WorkflowStatus::ImplementCompleted,
    ]);
    PhaseRun::factory()->create([
        'task_id' => $task->id,
        'phase' => 'implement',
        'status' => 'completed',
    ]);
    $token = fullToken(['tasks:write']);

    $this->withToken($token)->postJson("/api/v1/tasks/{$task->id}/pr")->assertStatus(202);

    Queue::assertPushed(RunPhaseJob::class);
});

test('write endpoints reject a read-only token', function () {
    $task = Task::factory()->create();
    $token = fullToken(['tasks:read']);

    $this->withToken($token)->postJson("/api/v1/tasks/{$task->id}/concept")->assertForbidden();
});
