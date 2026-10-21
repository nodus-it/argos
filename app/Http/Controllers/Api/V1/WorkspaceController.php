<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ActsForAutomation;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Models\ConnectedAccount;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Workspaces the token may see; Argos admins' clients may create them. */
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

    /**
     * Create a workspace (Argos admins' API clients with `workspaces:write`;
     * never a workspace-bound token). The repo inspection and the setup
     * task follow as in the UI.
     */
    public function store(Request $request, WorkspaceService $workspaces): JsonResponse
    {
        if ($this->scopedWorkspaceId($request) !== null) {
            abort(403);
        }
        $actor = $this->actor($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'repo_url' => ['required', 'url', 'max:500'],
            'platform' => ['required', Rule::enum(GitProvider::class)],
            'default_branch' => ['required', 'string', 'max:255'],
            'auth_method' => ['required', Rule::enum(AuthMethod::class)],
            'token' => ['required_if:auth_method,pat', 'nullable', 'string', 'max:500'],
        ]);
        if ($data['auth_method'] === AuthMethod::OAuth->value) {
            $account = ConnectedAccount::query()->where('user_id', $actor->id)->where('provider', $data['platform'])->first();
            if ($account === null) {
                return new JsonResponse(['message' => __('workspaces.api.no_connected_account'), 'code' => 'no_connected_account'], 422);
            }
            $data['connected_account_id'] = $account->id;
            unset($data['token']);
        }

        $workspace = $workspaces->createWorkspace($actor, $data);

        return (new WorkspaceResource($workspace))->response()->setStatusCode(201);
    }

    public function show(Request $request, Workspace $workspace): WorkspaceResource
    {
        $this->assertWorkspaceVisible($request, $workspace);

        return new WorkspaceResource($workspace);
    }
}
