<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Services\Workspace\WorkspaceShipService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkspaceShip extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'workspace_ship';

    protected string $description = 'Commits what changed in a workspace, pushes the branch and opens (or updates) its pull request now. Returns the pull request URL.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('Workspace id (ULID) or name')->required(),
            'draft' => $schema->boolean()->description('Open as draft pull request')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['workspace' => 'required|string', 'draft' => 'nullable|boolean']);
        $workspace = $this->findWorkspace((string) $request->get('workspace'));
        if ($workspace === null) {
            return Response::error('Workspace not found: '.$request->get('workspace'));
        }

        return $this->attempt(function () use ($request, $workspace): Response {
            $ship = app(WorkspaceShipService::class);
            $draft = $ship->draft($workspace, $this->user());
            $url = $ship->ship($workspace, $this->user(), $draft['subject'] !== '' ? $draft['subject'] : $workspace->name, $draft['body'], (bool) $request->get('draft'));

            return Response::text('Shipped: '.$url);
        });
    }
}
