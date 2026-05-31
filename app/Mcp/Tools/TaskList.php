<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class TaskList extends Tool
{
    protected string $name = 'task_list';

    protected string $description = 'Lists tasks, optionally filtered by project (id or name) and workflow status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Filter by project id (ULID) or exact name')->nullable(),
            'status' => $schema->string()->description('Filter by workflow status, e.g. draft, concept_running, concept_review, implement_running, implement_paused, implement_completed, in_review, completed, failed')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = Task::query()->with('repoProfile')->orderByDesc('created_at');

        if ($reference = $request->get('project')) {
            $project = RepoProfile::query()
                ->where('id', $reference)
                ->orWhere('name', $reference)
                ->first();

            if ($project === null) {
                return Response::error("Project not found: {$reference}");
            }

            $query->where('repo_profile_id', $project->id);
        }

        if ($status = $request->get('status')) {
            $query->where('workflow_status', $status);
        }

        $tasks = $query->get()->map(fn (Task $t): array => [
            'id' => $t->id,
            'name' => $t->name,
            'project' => $t->repoProfile?->name,
            'workflow_status' => $t->workflow_status->value,
            'current_phase' => $t->current_phase?->value,
            'current_status' => $t->current_status?->value,
            'feature_branch' => $t->feature_branch,
            'pr_url' => $t->pr_url,
            'created_at' => $t->created_at?->toJSON(),
        ])->all();

        return Response::text(json_encode($tasks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
