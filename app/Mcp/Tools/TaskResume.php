<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Services\Workspace\AutoTaskDriver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TaskResume extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'task_resume';

    protected string $description = 'Hands a paused, failed or budget-stopped task back to automation: it checks the gates and ships.';

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
        if ($task === null) {
            return Response::error('Task not found: '.$request->get('task'));
        }

        return $this->attempt(function () use ($task): Response {
            app(AutoTaskDriver::class)->resume($task, $this->user());

            return $this->taskJson($task->fresh() ?? $task);
        });
    }
}
