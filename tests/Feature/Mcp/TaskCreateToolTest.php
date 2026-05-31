<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Jobs\RunPhaseJob;
use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\TaskCreate;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function taskCreateMcpUser(): User
{
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use']);

    return $user;
}

beforeEach(function () {
    Process::fake();
    Queue::fake();
});

test('task_create stores the plan as description and concept notes and starts concept', function () {
    $user = taskCreateMcpUser();
    $project = RepoProfile::factory()->create(['name' => 'target-repo']);

    ArgosServer::actingAs($user)
        ->tool(TaskCreate::class, [
            'name' => 'mcp-created-task',
            'project' => 'target-repo',
            'plan' => 'Implement the widget refactor.',
        ])
        ->assertOk()
        ->assertSee('Concept phase started');

    $task = Task::where('name', 'mcp-created-task')->first();

    expect($task)->not->toBeNull();
    expect($task->repo_profile_id)->toBe($project->id);
    expect($task->description)->toContain('widget refactor');
    expect($task->concept_notes)->toContain('widget refactor');
    expect($task->user_id)->toBe($user->id);

    Queue::assertPushed(RunPhaseJob::class);
});

test('task_create returns an error for an unknown project', function () {
    $user = taskCreateMcpUser();

    ArgosServer::actingAs($user)
        ->tool(TaskCreate::class, [
            'name' => 'orphan-task',
            'project' => 'no-such-repo',
            'plan' => 'whatever',
        ])
        ->assertHasErrors();

    expect(Task::where('name', 'orphan-task')->exists())->toBeFalse();
    Queue::assertNotPushed(RunPhaseJob::class);
});
