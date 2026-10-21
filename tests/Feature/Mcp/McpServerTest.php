<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\ProjectGet;
use App\Mcp\Tools\ProjectList;
use App\Mcp\Tools\ThreadMessage;
use App\Mcp\Tools\ThreadPause;
use App\Mcp\Tools\ThreadPlanDecide;
use App\Mcp\Tools\ThreadResume;
use App\Mcp\Tools\WorkspaceCreate;
use App\Mcp\Tools\WorkspaceGet;
use App\Mcp\Tools\WorkspaceList;
use App\Mcp\Tools\WorkspaceShip;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the mcp endpoint rejects unauthenticated requests with a 401 and WWW-Authenticate header', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);

    $response->assertStatus(401);
    $response->assertHeader('WWW-Authenticate');
});

test('the argos server registers the workspace and task tools', function () {
    $reflection = new \ReflectionClass(ArgosServer::class);
    $tools = $reflection->getDefaultProperties()['tools'];

    expect($tools)->toHaveCount(10)
        ->toContain(WorkspaceList::class)
        ->toContain(WorkspaceGet::class)
        ->toContain(WorkspaceCreate::class)
        ->toContain(WorkspaceShip::class)
        ->toContain(ThreadMessage::class)
        ->toContain(ThreadPlanDecide::class)
        ->toContain(ThreadPause::class)
        ->toContain(ThreadResume::class)
        ->toContain(ProjectList::class)
        ->toContain(ProjectGet::class);
});
