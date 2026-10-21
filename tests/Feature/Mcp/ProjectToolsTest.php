<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\ProjectGet;
use App\Mcp\Tools\ProjectList;
use App\Models\RepoProfile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

function projectMcpUser(): User
{
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use']);

    return $user;
}

test('project_list returns the configured projects with open workspace counts', function () {
    $user = projectMcpUser();
    $project = RepoProfile::factory()->create(['name' => 'argos-repo']);
    Workspace::factory()->for($project, 'repoProfile')->create();
    Workspace::factory()->for($project, 'repoProfile')->stopped()->create();

    ArgosServer::actingAs($user)
        ->tool(ProjectList::class, [])
        ->assertOk()
        ->assertSee('argos-repo')
        ->assertSee('"open_workspaces": 1')
        ->assertSee('"auto_settings"');
});

test('project_get resolves a project by name and lists its workspaces', function () {
    $user = projectMcpUser();
    $project = RepoProfile::factory()->create(['name' => 'argos-repo']);
    Workspace::factory()->for($project, 'repoProfile')->create(['name' => 'ws-alpha']);

    ArgosServer::actingAs($user)
        ->tool(ProjectGet::class, ['project' => 'argos-repo'])
        ->assertOk()
        ->assertSee('argos-repo')
        ->assertSee('ws-alpha');
});

test('project_get returns an error for an unknown project', function () {
    $user = projectMcpUser();

    ArgosServer::actingAs($user)
        ->tool(ProjectGet::class, ['project' => 'does-not-exist'])
        ->assertHasErrors();
});
