<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Services\Workspace\WorkspaceShipService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TaskShip extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'task_ship';

    protected string $description = 'Commits what changed in a task, pushes its branch and opens (or updates) its pull request now. Returns the pull request URL.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Task id (ULID)')->required(),
            'draft' => $schema->boolean()->description('Open as draft pull request')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['task' => 'required|string', 'draft' => 'nullable|boolean']);
        $task = $this->findTask((string) $request->get('task'));
        if ($task === null) {
            return Response::error('Task not found: '.$request->get('task'));
        }

        return $this->attempt(function () use ($request, $task): Response {
            $url = app(WorkspaceShipService::class)->shipWithDraft($task, $this->user(), (bool) $request->get('draft'));

            return Response::text('Shipped: '.$url);
        });
    }
}
