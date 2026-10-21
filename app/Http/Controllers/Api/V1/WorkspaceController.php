<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkspaceOrigin;
use App\Http\Controllers\Api\V1\Concerns\ActsForAutomation;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWorkspaceRequest;
use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Models\RepoProfile;
use App\Models\Workspace;
use App\Services\Workspace\AutoRunService;
use App\Services\Workspace\WorkspaceIssueService;
use App\Services\Workspace\WorkspaceShipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Workspaces and automatic runs (the replacement for tasks). */
class WorkspaceController extends Controller
{
    use ActsForAutomation;
    use ResolvesApiScope;

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $this->actor($request);
        $scoped = $this->scopedProjectId($request);

        $workspaces = Workspace::query()
            ->with(['repoProfile', 'threads'])
            ->when(! $actor->isAdmin(), fn ($q) => $q->whereHas('members', fn ($m) => $m->where('user_id', $actor->id)))
            ->when($scoped !== null, fn ($q) => $q->where('repo_profile_id', $scoped))
            ->when(is_string($request->query('origin')) && $request->query('origin') !== '', fn ($q) => $q->where('origin', $request->query('origin')))
            ->when(is_string($request->query('status')) && $request->query('status') !== '', fn ($q) => $q->where('status', $request->query('status')))
            ->latest()
            ->limit(200)
            ->get();

        return WorkspaceResource::collection($workspaces);
    }

    public function show(Request $request, Workspace $workspace): WorkspaceResource
    {
        $this->assertWorkspaceVisible($request, $workspace);

        return new WorkspaceResource($workspace->load(['repoProfile', 'threads']));
    }

    /**
     * Create a workspace and start an automatic run in it (202: the run
     * continues asynchronously — poll the workspace to follow it).
     */
    public function store(StoreWorkspaceRequest $request, AutoRunService $runs, WorkspaceIssueService $issues): JsonResponse
    {
        $actor = $this->actor($request);
        $project = $this->project($request, (string) $request->input('project'));

        return $this->domain(function () use ($request, $actor, $project, $runs, $issues): JsonResponse {
            $issue = $request->filled('issue') ? $issues->load($project, (string) $request->input('issue')) : null;
            $thread = $runs->launch($actor, $project, (string) $request->input('prompt'), [
                'title' => $request->input('title'),
                'base_branch' => $request->input('base_branch'),
                'agent_id' => $request->input('agent_id'),
                'model' => $request->input('model'),
                'issue' => $issue,
            ], $request->settings(), WorkspaceOrigin::Api);

            return (new WorkspaceResource($thread->workspace->load(['repoProfile', 'threads'])))->response()->setStatusCode(202);
        });
    }

    /** Commit, push and open/update the pull request now. */
    public function ship(Request $request, Workspace $workspace, WorkspaceShipService $ship): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $workspace);
        $actor = $this->actor($request);
        $request->validate(['draft' => ['nullable', 'boolean']]);

        return $this->domain(function () use ($workspace, $actor, $ship, $request): JsonResponse {
            $draft = $ship->draft($workspace, $actor);
            $url = $ship->ship($workspace, $actor, $draft['subject'] !== '' ? $draft['subject'] : $workspace->name, $draft['body'], (bool) $request->boolean('draft'));

            return new JsonResponse(['pull_request_url' => $url]);
        });
    }

    /** The project by id or exact name, within the token's scope (404 otherwise). */
    private function project(Request $request, string $reference): RepoProfile
    {
        $project = RepoProfile::query()->whereKey($reference)->orWhere('name', $reference)->first();
        if ($project === null) {
            abort(404);
        }
        $this->assertProjectVisible($request, $project);

        return $project;
    }
}
