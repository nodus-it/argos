<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ActsForAutomation;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Workspaces (projects) the token may see. */
class WorkspaceController extends Controller
{
    use ActsForAutomation;

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $scoped = $this->scopedWorkspaceId($request);

        $workspaces = Workspace::query()
            ->when(! $actor->isAdmin(), fn ($q) => $q->whereHas('members', fn ($m) => $m->where('user_id', $actor->id)))
            ->when($scoped !== null, fn ($q) => $q->whereKey($scoped))
            ->when(is_string($request->query('status')) && $request->query('status') !== '', fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('name')
            ->limit(200)
            ->get();

        return WorkspaceResource::collection($workspaces);
    }

    public function show(Request $request, Workspace $workspace): WorkspaceResource
    {
        $this->assertWorkspaceVisible($request, $workspace);

        return new WorkspaceResource($workspace);
    }
}
