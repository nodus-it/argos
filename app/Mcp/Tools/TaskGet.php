<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithTasks;
use App\Models\PhaseRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class TaskGet extends Tool
{
    use InteractsWithTasks;

    protected string $name = 'task_get';

    protected string $description = 'Returns full detail for one task (by id or name): description, concept, implement summaries, the recent phase runs, the checkout block (repo_url, base_branch, feature_branch) and the PR url.';

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

        $task->loadMissing('repoProfile');

        $recentRuns = $task->phaseRuns()
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (PhaseRun $r): array => [
                'phase' => $r->phase->value,
                'iteration' => $r->iteration,
                'status' => $r->status->value,
                'started_at' => $r->started_at?->toJSON(),
                'finished_at' => $r->finished_at?->toJSON(),
                'stop_reason' => $r->stop_reason,
            ])
            ->all();

        $data = [
            'id' => $task->id,
            'name' => $task->name,
            'description' => $task->description,
            'workflow_status' => $task->workflow_status->value,
            'current_phase' => $task->current_phase?->value,
            'current_status' => $task->current_status?->value,
            'project' => $task->repoProfile?->name,
            'concept_md' => $task->concept_md,
            'concept_notes' => $task->concept_notes,
            'implement_summary_nontechnical' => $task->implement_summary_nontechnical,
            'implement_summary_technical' => $task->implement_summary_technical,
            'recent_phase_runs' => $recentRuns,
            'checkout' => [
                'repo_url' => $task->repoProfile?->url,
                'base_branch' => $task->base_branch ?? $task->repoProfile?->default_branch,
                'feature_branch' => $task->feature_branch,
            ],
            'pr_url' => $task->pr_url,
            'created_at' => $task->created_at?->toJSON(),
            'updated_at' => $task->updated_at?->toJSON(),
        ];

        return Response::text(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
