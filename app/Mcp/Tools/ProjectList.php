<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\RepoProfile;
use App\Services\Workspace\AutoSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ProjectList extends Tool
{
    protected string $name = 'project_list';

    protected string $description = 'Lists the configured repository profiles (projects) Argos works on, including their automation defaults and the number of open workspaces.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $projects = RepoProfile::query()
            ->withCount('openWorkspaces')
            ->orderBy('name')
            ->get()
            ->map(fn (RepoProfile $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'url' => $p->url,
                'platform' => $p->platform->value,
                'default_branch' => $p->default_branch,
                'auto_settings' => AutoSettings::resolve($p),
                'open_workspaces' => (int) $p->getAttribute('open_workspaces_count'),
            ])
            ->all();

        return Response::text(json_encode($projects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
