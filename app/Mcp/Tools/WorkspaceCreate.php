<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\WorkspaceOrigin;
use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Models\RepoProfile;
use App\Services\Workspace\AutoRunService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkspaceCreate extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'workspace_create';

    protected string $description = 'Creates a workspace for a project and starts an automatic run: Claude plans (per the project\'s setting or `plan`), implements, fixes failing quality gates and opens a pull request. Returns immediately — poll workspace_get. Runs on your own Claude subscription.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project id (ULID) or exact name')->required(),
            'prompt' => $schema->string()->description('What to implement (Markdown). Becomes the task of the run.')->required(),
            'title' => $schema->string()->description('Short title (defaults to the start of the prompt)')->nullable(),
            'base_branch' => $schema->string()->description('Branch to start from (defaults to the project default)')->nullable(),
            'plan' => $schema->string()->description('off = implement right away, auto = plan then continue, approve = plan then wait for approval (default: project setting)')->nullable(),
            'ship' => $schema->string()->description('pr, draft_pr or none (default: project setting)')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'project' => 'required|string',
            'prompt' => 'required|string|max:20000',
            'title' => 'nullable|string|max:120',
            'base_branch' => 'nullable|string|max:255',
            'plan' => 'nullable|in:off,auto,approve',
            'ship' => 'nullable|in:pr,draft_pr,none',
        ]);
        $reference = (string) $request->get('project');
        $project = RepoProfile::query()->whereKey($reference)->orWhere('name', $reference)->first();
        if ($project === null) {
            return Response::error("Project not found: {$reference}");
        }

        return $this->attempt(function () use ($request, $project): Response {
            $thread = app(AutoRunService::class)->launch($this->user(), $project, (string) $request->get('prompt'), [
                'title' => $request->get('title'),
                'base_branch' => $request->get('base_branch'),
            ], ['plan' => $request->get('plan'), 'ship' => $request->get('ship')], WorkspaceOrigin::Mcp);

            return $this->workspaceJson($thread->workspace);
        });
    }
}
