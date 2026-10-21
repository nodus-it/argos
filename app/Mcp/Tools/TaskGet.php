<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class TaskGet extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'task_get';

    protected string $description = 'Returns one task: status, owner, branch, pull request, latest gate results, linked ticket and its automatic-run state. Poll it to follow a run.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Task id (ULID)')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['task' => 'required|string']);
        $task = $this->findTask((string) $request->get('task'));

        return $task === null ? Response::error('Task not found: '.$request->get('task')) : $this->taskJson($task);
    }
}
