<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Jobs\RunPhaseJob;
use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\TaskConcept;
use App\Mcp\Tools\TaskFeedback;
use App\Mcp\Tools\TaskImplement;
use App\Mcp\Tools\TaskPr;
use App\Models\PhaseRun;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function taskPhaseMcpUser(): User
{
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use']);

    return $user;
}

beforeEach(function () {
    Process::fake();
    Queue::fake();
});

// ── task_concept ─────────────────────────────────────────────────────────────

test('task_concept starts the concept phase for a draft task', function () {
    $user = taskPhaseMcpUser();
    $task = Task::factory()->create(['name' => 'concept-task']);

    ArgosServer::actingAs($user)
        ->tool(TaskConcept::class, ['task' => 'concept-task'])
        ->assertOk()
        ->assertSee('Concept phase started');

    Queue::assertPushed(RunPhaseJob::class);
});

test('task_concept resumes a paused concept run', function () {
    $user = taskPhaseMcpUser();
    $task = Task::factory()->create(['name' => 'paused-concept']);
    PhaseRun::factory()->paused()->for($task)->create(['phase' => 'concept', 'iteration' => 1]);

    ArgosServer::actingAs($user)
        ->tool(TaskConcept::class, ['task' => 'paused-concept', 'max_turns' => 50])
        ->assertOk()
        ->assertSee('resumed');

    Queue::assertPushed(RunPhaseJob::class);
});

test('task_concept rejects a task with a running phase', function () {
    $user = taskPhaseMcpUser();
    Task::factory()->create(['name' => 'busy-task', 'current_status' => 'running']);

    ArgosServer::actingAs($user)
        ->tool(TaskConcept::class, ['task' => 'busy-task'])
        ->assertHasErrors();

    Queue::assertNotPushed(RunPhaseJob::class);
});

// ── task_implement ───────────────────────────────────────────────────────────

test('task_implement starts implement when concept has completed', function () {
    $user = taskPhaseMcpUser();
    $task = Task::factory()->conceptReady()->create(['name' => 'impl-task']);
    PhaseRun::factory()->for($task)->create(['phase' => 'concept', 'iteration' => 1, 'status' => 'completed']);

    ArgosServer::actingAs($user)
        ->tool(TaskImplement::class, ['task' => 'impl-task'])
        ->assertOk()
        ->assertSee('Implement phase started');

    Queue::assertPushed(RunPhaseJob::class);
});

test('task_implement is rejected without a completed concept run', function () {
    $user = taskPhaseMcpUser();
    Task::factory()->create(['name' => 'no-concept-task']);

    ArgosServer::actingAs($user)
        ->tool(TaskImplement::class, ['task' => 'no-concept-task'])
        ->assertHasErrors();

    Queue::assertNotPushed(RunPhaseJob::class);
});

// ── task_pr ──────────────────────────────────────────────────────────────────

test('task_pr starts the push phase when implement has completed', function () {
    $user = taskPhaseMcpUser();
    $task = Task::factory()->create([
        'name' => 'pr-task',
        'workflow_status' => 'implement_completed',
        'current_status' => 'completed',
    ]);
    PhaseRun::factory()->for($task)->create(['phase' => 'implement', 'iteration' => 1, 'status' => 'completed']);

    ArgosServer::actingAs($user)
        ->tool(TaskPr::class, ['task' => 'pr-task'])
        ->assertOk()
        ->assertSee('Push phase started');

    Queue::assertPushed(RunPhaseJob::class);
});

test('task_pr is rejected without a completed implement run', function () {
    $user = taskPhaseMcpUser();
    Task::factory()->conceptReady()->create(['name' => 'no-impl-task']);

    ArgosServer::actingAs($user)
        ->tool(TaskPr::class, ['task' => 'no-impl-task'])
        ->assertHasErrors();

    Queue::assertNotPushed(RunPhaseJob::class);
});

// ── task_feedback ────────────────────────────────────────────────────────────

test('task_feedback submits feedback and starts the respond phase', function () {
    $user = taskPhaseMcpUser();
    $task = Task::factory()->inReview()->create(['name' => 'feedback-task']);

    ArgosServer::actingAs($user)
        ->tool(TaskFeedback::class, ['task' => 'feedback-task', 'feedback' => 'Please rename the variable.'])
        ->assertOk()
        ->assertSee('Feedback submitted');

    Queue::assertPushed(RunPhaseJob::class);
});

test('task_feedback is rejected for a completed task', function () {
    $user = taskPhaseMcpUser();
    Task::factory()->completed()->create(['name' => 'done-feedback-task']);

    ArgosServer::actingAs($user)
        ->tool(TaskFeedback::class, ['task' => 'done-feedback-task', 'feedback' => 'too late'])
        ->assertHasErrors();

    Queue::assertNotPushed(RunPhaseJob::class);
});
