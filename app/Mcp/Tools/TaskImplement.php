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

class TaskImplement extends Tool
{
    use InteractsWithTasks;

    protected string $name = 'task_implement';

    protected string $description = 'Runs (or re-runs) the Implement phase for a task. Requires a completed Concept run. If the previous Implement run is paused, it resumes with a fresh turn budget instead.';

    public function __construct(private readonly TaskService $taskService) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Task id (ULID) or slug')->required(),
            'max_turns' => $schema->integer()->description('Turn budget when resuming a paused Implement run (defaults to the task/project setting)')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'task' => 'required|string',
            'max_turns' => 'nullable|integer|min:10|max:1000',
        ]);

        $task = $this->findTask((string) $request->get('task'));

        if ($task === null) {
            return Response::error('Task not found: '.$request->get('task'));
        }

        if ($task->workflow_status->value === 'completed') {
            return Response::error('Task is completed; the Implement phase cannot be run.');
        }

        if (! $this->hasCompletedPhase($task, 'concept')) {
            return Response::error('The Implement phase requires a completed Concept run first.');
        }

        if ($task->current_status === PhaseStatus::Running) {
            return Response::error('A phase is already running for this task.');
        }

        try {
            if ($this->latestPhaseRun($task, 'implement')?->status === PhaseStatus::Paused) {
                $maxTurns = (int) ($request->get('max_turns')
                    ?? $task->max_turns_implement
                    ?? config('argos.implement.max_turns_default', 200));
                $this->taskService->continueImplement($task, $maxTurns);

                return Response::text("Implement phase resumed for '{$task->name}' (max_turns {$maxTurns}).");
            }

            $this->taskService->startPhase($task, Phase::Implement);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text("Implement phase started for '{$task->name}'. Poll task_get to follow progress.");
    }
}
