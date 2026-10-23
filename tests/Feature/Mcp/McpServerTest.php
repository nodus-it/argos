<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\TaskCreate;
use App\Mcp\Tools\TaskGet;
use App\Mcp\Tools\TaskMessage;
use App\Mcp\Tools\TaskPause;
use App\Mcp\Tools\TaskPlanDecide;
use App\Mcp\Tools\TaskResume;
use App\Mcp\Tools\TaskShip;
use App\Mcp\Tools\WorkspaceGet;
use App\Mcp\Tools\WorkspaceList;
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

    expect($tools)->toHaveCount(9)
        ->toContain(WorkspaceList::class)
        ->toContain(WorkspaceGet::class)
        ->toContain(TaskCreate::class)
        ->toContain(TaskGet::class)
        ->toContain(TaskShip::class)
        ->toContain(TaskMessage::class)
        ->toContain(TaskPlanDecide::class)
        ->toContain(TaskPause::class)
        ->toContain(TaskResume::class);
});
