<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\TaskOrigin;
use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Services\Workspace\AutoRunService;
use App\Services\Workspace\WorkspaceIssueService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class TaskCreate extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'task_create';

    protected string $description = 'Starts an automatic task in a workspace: Claude plans (per the workspace\'s setting or `plan`), implements on its own branch, fixes failing quality gates and opens a pull request. Returns immediately — poll task_get. Runs on your own Claude subscription.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('Workspace id (ULID) or exact name')->required(),
            'prompt' => $schema->string()->description('What to implement (Markdown).')->required(),
            'title' => $schema->string()->description('Short title (defaults to the ticket title or the start of the prompt)')->nullable(),
            'issue' => $schema->string()->description('Ticket number of the workspace\'s tracker to link')->nullable(),
            'base_branch' => $schema->string()->description('Branch to start from (defaults to the workspace default)')->nullable(),
            'plan' => $schema->string()->description('off = implement right away, auto = plan then continue, approve = plan then wait for approval (default: workspace setting)')->nullable(),
            'ship' => $schema->string()->description('pr, draft_pr or none (default: workspace setting)')->nullable(),
            'auto_merge' => $schema->boolean()->description('Merge the pull request on its own once the pipeline is green and reviews allow it (provider auto-merge)')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'workspace' => 'required|string',
            'prompt' => 'required|string|max:20000',
            'title' => 'nullable|string|max:120',
            'issue' => 'nullable|string|max:64',
            'base_branch' => 'nullable|string|max:255',
            'plan' => 'nullable|in:off,auto,approve',
            'ship' => 'nullable|in:pr,draft_pr,none',
            'auto_merge' => 'nullable|boolean',
        ]);
        $workspace = $this->findWorkspace((string) $request->get('workspace'));
        if ($workspace === null) {
            return Response::error('Workspace not found: '.$request->get('workspace'));
        }

        return $this->attempt(function () use ($request, $workspace): Response {
            $issue = filled($request->get('issue')) ? app(WorkspaceIssueService::class)->load($workspace, (string) $request->get('issue')) : null;
            $task = app(AutoRunService::class)->launch($this->user(), $workspace, (string) $request->get('prompt'), [
                'title' => $request->get('title'),
                'base_branch' => $request->get('base_branch'),
                'issue' => $issue,
                'auto_merge' => (bool) $request->get('auto_merge'),
            ], ['plan' => $request->get('plan'), 'ship' => $request->get('ship')], TaskOrigin::Mcp);

            return $this->taskJson($task);
        });
    }
}
