<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\ArgosServer;
use App\Mcp\Tools\ProjectGet;
use App\Mcp\Tools\ProjectList;
use App\Mcp\Tools\TaskConcept;
use App\Mcp\Tools\TaskCreate;
use App\Mcp\Tools\TaskFeedback;
use App\Mcp\Tools\TaskGet;
use App\Mcp\Tools\TaskImplement;
use App\Mcp\Tools\TaskList;
use App\Mcp\Tools\TaskPr;
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

test('the argos server registers the nine task-communication tools', function () {
    $reflection = new \ReflectionClass(ArgosServer::class);
    $tools = $reflection->getDefaultProperties()['tools'];

    expect($tools)->toHaveCount(9)
        ->toContain(ProjectList::class)
        ->toContain(ProjectGet::class)
        ->toContain(TaskList::class)
        ->toContain(TaskGet::class)
        ->toContain(TaskCreate::class)
        ->toContain(TaskFeedback::class)
        ->toContain(TaskConcept::class)
        ->toContain(TaskImplement::class)
        ->toContain(TaskPr::class);
});
