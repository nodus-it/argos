<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Mcp\Tools\Concerns\InteractsWithWorkspaces;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class WorkspaceList extends Tool
{
    use InteractsWithWorkspaces;

    protected string $name = 'workspace_list';

    protected string $description = 'Lists the workspaces (projects) you can see, by name: repository, default branch and container status.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $user = $this->user();
        $workspaces = Workspace::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('members', fn ($m) => $m->where('user_id', $user->id)))
            ->orderBy('name')
            ->limit(50)
            ->get();

        $data = WorkspaceResource::collection($workspaces)->resolve();

        return Response::text((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
