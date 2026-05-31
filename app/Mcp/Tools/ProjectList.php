<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\RepoProfile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ProjectList extends Tool
{
    protected string $name = 'project_list';

    protected string $description = 'Lists the configured repository profiles (projects) Argos can run tasks against, including their workflow defaults and the number of open tasks.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $projects = RepoProfile::query()
            ->withCount(['tasks as open_tasks' => fn (Builder $q) => $q->where('workflow_status', '!=', 'completed')])
            ->orderBy('name')
            ->get()
            ->map(fn (RepoProfile $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'url' => $p->url,
                'platform' => $p->platform->value,
                'default_branch' => $p->default_branch,
                'auto_concept' => $p->auto_concept,
                'auto_pr' => $p->auto_pr,
                'open_tasks' => (int) $p->getAttribute('open_tasks'),
            ])
            ->all();

        return Response::text(json_encode($projects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
