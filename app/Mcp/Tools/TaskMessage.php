<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Services\Workspace\AutoTaskDriver;
use App\Services\Workspace\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TaskMessage extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'task_message';

    protected string $description = 'Writes into a task (e.g. review feedback). In a running automatic run this pauses automation unless `resume_auto` is true, which hands it back to automation after the message.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Task id (ULID), from workspace_get')->required(),
            'text' => $schema->string()->description('The message')->required(),
            'resume_auto' => $schema->boolean()->description('Continue automatically after this message')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['task' => 'required|string', 'text' => 'required|string', 'resume_auto' => 'nullable|boolean']);
        $task = $this->findTask((string) $request->get('task'));
        if ($task === null) {
            return Response::error('Task not found: '.$request->get('task'));
        }

        return $this->attempt(function () use ($request, $task): Response {
            app(TaskService::class)->send($task, $this->user(), (string) $request->get('text'));
            if ((bool) $request->get('resume_auto') && $task->fresh()?->auto_state !== null) {
                app(AutoTaskDriver::class)->resume($task->fresh(), $this->user());
            }

            return $this->taskJson($task->fresh() ?? $task);
        });
    }
}
