<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class WorkspaceGet extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'workspace_get';

    protected string $description = 'Returns one workspace (by id or name) with its open tasks: owner, status, branch, pull request, gates, ticket and automatic-run state.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('Workspace id (ULID) or name')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['workspace' => 'required|string']);
        $workspace = $this->findWorkspace((string) $request->get('workspace'));

        return $workspace === null ? Response::error('Workspace not found: '.$request->get('workspace')) : $this->workspaceJson($workspace);
    }
}
