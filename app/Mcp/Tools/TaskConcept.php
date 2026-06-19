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

class TaskConcept extends Tool
{
    use InteractsWithTasks;

    protected string $name = 'task_concept';

    protected string $description = 'Runs (or re-runs) the Concept phase for a task. If the previous Concept run is paused, it resumes with a fresh turn budget instead.';

    public function __construct(private readonly TaskService $taskService) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'task' => $schema->string()->description('Task id (ULID) or slug')->required(),
            'max_turns' => $schema->integer()->description('Turn budget when resuming a paused Concept run (defaults to the task/project setting)')->nullable(),
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
            return Response::error('Task is completed; the Concept phase cannot be run.');
        }

        if ($task->current_status === PhaseStatus::Running) {
            return Response::error('A phase is already running for this task.');
        }

        try {
            if ($this->latestPhaseRun($task, 'concept')?->status === PhaseStatus::Paused) {
                $maxTurns = (int) ($request->get('max_turns')
                    ?? $task->max_turns_concept
                    ?? config('argos.concept.max_turns_default', 30));
                $this->taskService->continueConcept($task, $maxTurns);

                return Response::text("Concept phase resumed for '{$task->name}' (max_turns {$maxTurns}).");
            }

            $this->taskService->startPhase($task, Phase::Concept);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::text("Concept phase started for '{$task->name}'. Poll task_get to follow progress.");
    }
}
