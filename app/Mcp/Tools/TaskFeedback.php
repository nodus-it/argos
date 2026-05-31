<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithTasks;
use App\Services\Task\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TaskFeedback extends Tool
{
    use InteractsWithTasks;

    protected string $name = 'task_feedback';

    protected string $description = 'Sends review feedback for a task and runs the Respond phase, which acts on the feedback.';

    public function __construct(private readonly TaskService $taskService) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Task id (ULID) or exact name')->required(),
            'feedback' => $schema->string()->description('The feedback for the agent to act on (Markdown)')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'task' => 'required|string',
            'feedback' => 'required|string',
        ]);

        $task = $this->findTask((string) $request->get('task'));

        if ($task === null) {
            return Response::error('Task not found: '.$request->get('task'));
        }

        if ($task->workflow_status->value === 'completed') {
            return Response::error('Task is completed; no further feedback can be submitted.');
        }

        try {
            $this->taskService->submitFeedback($task, (string) $request->get('feedback'));
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text("Feedback submitted for '{$task->name}'; Respond phase started. Poll task_get to follow progress.");
    }
}
