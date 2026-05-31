<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\TaskGet;
use App\Mcp\Tools\TaskList;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function taskReadMcpUser(): User
{
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use']);

    return $user;
}

test('task_list lists tasks and can filter by project', function () {
    $user = taskReadMcpUser();
    $a = RepoProfile::factory()->create(['name' => 'repo-a']);
    $b = RepoProfile::factory()->create(['name' => 'repo-b']);
    Task::factory()->for($a, 'repoProfile')->create(['name' => 'task-in-a']);
    Task::factory()->for($b, 'repoProfile')->create(['name' => 'task-in-b']);

    ArgosServer::actingAs($user)
        ->tool(TaskList::class, ['project' => 'repo-a'])
        ->assertOk()
        ->assertSee('task-in-a')
        ->assertDontSee('task-in-b');
});

test('task_list can filter by workflow status', function () {
    $user = taskReadMcpUser();
    Task::factory()->create(['name' => 'draft-task']);
    Task::factory()->completed()->create(['name' => 'done-task']);

    ArgosServer::actingAs($user)
        ->tool(TaskList::class, ['status' => 'completed'])
        ->assertOk()
        ->assertSee('done-task')
        ->assertDontSee('draft-task');
});

test('task_get returns the checkout block and pr url', function () {
    $user = taskReadMcpUser();
    $project = RepoProfile::factory()->create(['url' => 'https://github.com/test-org/test-repo']);
    $task = Task::factory()->for($project, 'repoProfile')->inReview()->create(['name' => 'checkout-task']);

    ArgosServer::actingAs($user)
        ->tool(TaskGet::class, ['task' => $task->name])
        ->assertOk()
        ->assertSee('argos/test-task')
        ->assertSee('https://github.com/test-org/test-repo')
        ->assertSee('pull/1');
});

test('task_get resolves by ulid as well as name', function () {
    $user = taskReadMcpUser();
    $task = Task::factory()->create(['name' => 'by-id-task']);

    ArgosServer::actingAs($user)
        ->tool(TaskGet::class, ['task' => $task->id])
        ->assertOk()
        ->assertSee('by-id-task');
});

test('task_get returns an error for an unknown task', function () {
    $user = taskReadMcpUser();

    ArgosServer::actingAs($user)
        ->tool(TaskGet::class, ['task' => 'nope'])
        ->assertHasErrors();
});
