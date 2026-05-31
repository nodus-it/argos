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
class ProjectGet extends Tool
{
    protected string $name = 'project_get';

    protected string $description = 'Returns one repository profile (by id or name) together with an overview of its tasks.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id (ULID) or exact name')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['project' => 'required|string']);

        $reference = (string) $request->get('project');
        $project = RepoProfile::query()
            ->where('id', $reference)
            ->orWhere('name', $reference)
            ->first();

        if ($project === null) {
            return Response::error("Project not found: {$reference}");
        }

        $tasks = $project->tasks()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Task $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'workflow_status' => $t->workflow_status->value,
                'current_phase' => $t->current_phase?->value,
                'feature_branch' => $t->feature_branch,
                'pr_url' => $t->pr_url,
            ])
            ->all();

        $data = [
            'id' => $project->id,
            'name' => $project->name,
            'url' => $project->url,
            'platform' => $project->platform->value,
            'default_branch' => $project->default_branch,
            'auto_concept' => $project->auto_concept,
            'auto_pr' => $project->auto_pr,
            'tasks' => $tasks,
        ];

        return Response::text(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
