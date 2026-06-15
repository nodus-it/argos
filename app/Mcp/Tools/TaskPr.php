<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Mcp\Tools\Concerns\InteractsWithTasks;
use App\Services\Task\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TaskPr extends Tool
{
    use InteractsWithTasks;

    protected string $name = 'task_pr';

    protected string $description = 'Runs the Push phase for a task, which pushes the feature branch and opens a pull request. Requires a completed Implement run.';

    public function __construct(private readonly TaskService $taskService) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Task id (ULID) or slug')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['task' => 'required|string']);

        $task = $this->findTask((string) $request->get('task'));

        if ($task === null) {
            return Response::error('Task not found: '.$request->get('task'));
        }

        if ($task->workflow_status->value === 'completed') {
            return Response::error('Task is completed; the Push phase cannot be run.');
        }

        if (! $this->hasCompletedPhase($task, 'implement')) {
            return Response::error('The Push phase requires a completed Implement run first.');
        }

        if ($task->current_status === PhaseStatus::Running) {
            return Response::error('A phase is already running for this task.');
        }

        try {
            $this->taskService->startPhase($task, Phase::Push);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text("Push phase started for '{$task->name}'. Once it completes, task_get exposes the PR url and the checkout block.");
    }
}
