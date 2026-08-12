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

test('answers 401 (not a redirect) even without an Accept header', function () {
    // A bare client that forgets Accept: application/json must still get 401,
    // never a 302 to the Filament login.
    $this->get('/api/v1/projects')->assertUnauthorized();
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

// ── Tasks: idempotent create ─────────────────────────────────────────────────

test('a fresh external_ref creates the task and reports created (202)', function () {
    Process::fake();
    Queue::fake();

    $repo = RepoProfile::factory()->create(['name' => 'target']);
    $token = fullToken(['tasks:write']);

    $this->withToken($token)->postJson('/api/v1/tasks', [
        'name' => 'keyed-task',
        'project' => 'target',
        'plan' => 'Do the thing.',
        'external_ref' => 'https://tracker.example/issues/4711',
    ])
        ->assertStatus(202)
        ->assertJsonPath('created', true)
        ->assertJsonPath('data.external_ref', 'https://tracker.example/issues/4711');

    expect(Task::where('repo_profile_id', $repo->id)->value('external_ref'))
        ->toBe('https://tracker.example/issues/4711');
});

test('a repeated external_ref returns the existing task without starting anything (200)', function () {
    Process::fake();
    Queue::fake();

    RepoProfile::factory()->create(['name' => 'target']);
    $token = fullToken(['tasks:write']);

    $payload = [
        'name' => 'keyed-task',
        'project' => 'target',
        'plan' => 'Do the thing.',
        'external_ref' => 'tracker:4711',
    ];

    $first = $this->withToken($token)->postJson('/api/v1/tasks', $payload)->assertStatus(202);

    $this->withToken($token)->postJson('/api/v1/tasks', $payload)
        ->assertStatus(200)
        ->assertJsonPath('created', false)
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Task::where('external_ref', 'tracker:4711')->count())->toBe(1);
    Queue::assertPushed(RunPhaseJob::class, 1);
});

test('a repeat under a different name still returns the original task', function () {
    Process::fake();
    Queue::fake();

    RepoProfile::factory()->create(['name' => 'target']);
    $token = fullToken(['tasks:write']);

    $this->withToken($token)->postJson('/api/v1/tasks', [
        'name' => 'first name', 'project' => 'target', 'plan' => 'a', 'external_ref' => 'tracker:4711',
    ])->assertStatus(202);

    $this->withToken($token)->postJson('/api/v1/tasks', [
        'name' => 'renamed meanwhile', 'project' => 'target', 'plan' => 'b', 'external_ref' => 'tracker:4711',
    ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'first name');

    expect(Task::count())->toBe(1);
});

test('the same external_ref in another project creates its own task', function () {
    Process::fake();
    Queue::fake();

    RepoProfile::factory()->create(['name' => 'one']);
    RepoProfile::factory()->create(['name' => 'two']);
    $token = fullToken(['tasks:write']);

    foreach (['one', 'two'] as $project) {
        $this->withToken($token)->postJson('/api/v1/tasks', [
            'name' => "task for {$project}",
            'project' => $project,
            'plan' => 'Do the thing.',
            'external_ref' => 'issue-7',
        ])->assertStatus(202);
    }

    expect(Task::where('external_ref', 'issue-7')->count())->toBe(2);
});

test('without an external_ref every call creates a new task', function () {
    Process::fake();
    Queue::fake();

    RepoProfile::factory()->create(['name' => 'target']);
    $token = fullToken(['tasks:write']);

    $payload = ['name' => 'unkeyed', 'project' => 'target', 'plan' => 'Do the thing.'];

    $this->withToken($token)->postJson('/api/v1/tasks', $payload)->assertStatus(202);
    $this->withToken($token)->postJson('/api/v1/tasks', $payload)
        ->assertStatus(202)
        ->assertJsonPath('created', true);

    expect(Task::count())->toBe(2);
});

test('an empty external_ref is treated as none, not as a shared key', function () {
    Process::fake();
    Queue::fake();

    RepoProfile::factory()->create(['name' => 'target']);
    $token = fullToken(['tasks:write']);

    $payload = ['name' => 'blank', 'project' => 'target', 'plan' => 'x', 'external_ref' => ''];

    $this->withToken($token)->postJson('/api/v1/tasks', $payload)->assertStatus(202);
    $this->withToken($token)->postJson('/api/v1/tasks', $payload)->assertStatus(202);

    expect(Task::count())->toBe(2)
        ->and(Task::whereNotNull('external_ref')->count())->toBe(0);
});

test('a concurrent create that wins the insert is returned instead of a duplicate', function () {
    Process::fake();
    Queue::fake();

    $repo = RepoProfile::factory()->create(['name' => 'target']);
    $token = fullToken(['tasks:write']);

    // A second run that slips past the lookup and inserts first.
    $raced = false;
    Task::creating(function (Task $task) use ($repo, &$raced): void {
        if ($raced) {
            return;
        }
        $raced = true;

        Task::create([
            'name' => 'winner',
            'slug' => 'winner',
            'external_ref' => 'tracker:4711',
            'repo_profile_id' => $repo->id,
            'description' => 'won the race',
        ]);
    });

    $this->withToken($token)->postJson('/api/v1/tasks', [
        'name' => 'loser',
        'project' => 'target',
        'plan' => 'Do the thing.',
        'external_ref' => 'tracker:4711',
    ])
        ->assertStatus(200)
        ->assertJsonPath('created', false)
        ->assertJsonPath('data.name', 'winner');

    expect(Task::where('external_ref', 'tracker:4711')->count())->toBe(1);
});

test('tasks can be looked up by external_ref without creating one', function () {
    $repo = RepoProfile::factory()->create();
    Task::factory()->create(['repo_profile_id' => $repo->id, 'external_ref' => 'tracker:4711']);
    Task::factory()->create(['repo_profile_id' => $repo->id, 'external_ref' => 'tracker:4712']);

    $token = fullToken(['tasks:read']);

    $this->withToken($token)->getJson('/api/v1/tasks?external_ref=tracker:4711')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.external_ref', 'tracker:4711');
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
