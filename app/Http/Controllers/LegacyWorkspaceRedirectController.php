<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Filament\App\Resources\WorkspaceResource;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/w/…` links from before the app moved to the root — in notifications,
 * pull request footers, ticket comments and bookmarks: workspace ULID plus
 * `?task=<ULID>` → `/{workspace}/{task}`. Review and file parameters stay.
 */
class LegacyWorkspaceRedirectController extends Controller
{
    public function __invoke(Request $request, ?string $path = null): RedirectResponse
    {
        $segments = array_values(array_filter(explode('/', (string) $path), fn (string $s): bool => $s !== ''));
        $query = $request->except('task');

        $target = match (true) {
            $segments === [], $segments === ['my-work'] => url('/'),
            $segments === ['profile'] => url('/profile'),
            $segments === ['workspaces'] => WorkspaceResource::getUrl('index', panel: 'app'),
            $segments === ['workspaces', 'create'] => WorkspaceResource::getUrl('create', panel: 'app'),
            ($segments[0] ?? null) === 'workspaces' && isset($segments[1]) => $this->workspaceUrl($segments[1], $segments[2] ?? null, $request->query('task')),
            default => null,
        };
        abort_if($target === null, 404);

        return redirect()->to($query !== [] ? $target.'?'.http_build_query($query) : $target, 301);
    }

    private function workspaceUrl(string $key, ?string $page, mixed $taskKey): ?string
    {
        $workspace = Workspace::query()->where('id', $key)->orWhere('slug', $key)->first();
        if ($workspace === null) {
            return null;
        }
        if ($page === 'settings') {
            return WorkspaceResource::getUrl('settings', ['record' => $workspace], panel: 'app');
        }
        $task = is_string($taskKey) ? Task::query()->where('workspace_id', $workspace->id)->where(fn ($q) => $q->where('id', $taskKey)->orWhere('slug', $taskKey))->first() : null;

        return $workspace->url($task);
    }
}
