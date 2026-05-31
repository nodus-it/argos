<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Phase;
use App\Models\RepoProfile;
use App\Services\Task\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TaskCreate extends Tool
{
    protected string $name = 'task_create';

    protected string $description = 'Creates a task from a plan and starts the Concept phase. The plan is stored both as the task description and as the concept notes, so the Concept run respects it instead of reinventing it. The feature branch is created during the Concept phase.';

    public function __construct(private readonly TaskService $taskService) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Short, unique task name (used for the workspace and branch)')->required(),
            'project' => $schema->string()->description('Target project id (ULID) or exact name')->required(),
            'plan' => $schema->string()->description('The plan to implement (Markdown). Stored as description and concept notes.')->required(),
            'base_branch' => $schema->string()->description('Branch to base the work on (defaults to the project default branch)')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'project' => 'required|string',
            'plan' => 'required|string',
            'base_branch' => 'nullable|string',
        ]);

        $reference = (string) $request->get('project');
        $project = RepoProfile::query()
            ->where('id', $reference)
            ->orWhere('name', $reference)
            ->first();

        if ($project === null) {
            return Response::error("Project not found: {$reference}");
        }

        $plan = (string) $request->get('plan');

        $task = $this->taskService->createTask([
            'user_id' => auth()->id(),
            'name' => $request->get('name'),
            'repo_profile_id' => $project->id,
            'description' => $plan,
            'base_branch' => $request->get('base_branch'),
            'auto_concept' => false,
        ]);

        // createTask() inserts with the DB default workflow_status ('draft')
        // but leaves it unset on the in-memory model; refresh so startPhase()
        // can transition from a concrete status.
        $task->refresh();

        // Persist the plan as concept notes before kicking off the phase, so the
        // worker injects it as concept.notes.md. createTask() does not accept
        // concept_notes, hence the separate call.
        $this->taskService->saveConceptNotes($task, $plan);

        try {
            $this->taskService->startPhase($task, Phase::Concept);
        } catch (\RuntimeException $e) {
            return Response::error("Task '{$task->name}' created, but the Concept phase could not start: {$e->getMessage()}");
        }

        return Response::text("Task '{$task->name}' (id {$task->id}) created; Concept phase started. Poll task_get to follow progress.");
    }
}
