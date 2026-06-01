<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\RepoProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    use ResolvesApiScope;

    public function index(Request $request): AnonymousResourceCollection
    {
        $scoped = $this->scopedProjectId($request);

        $projects = RepoProfile::query()
            ->withCount('openTasks')
            ->when($scoped !== null, fn ($query) => $query->whereKey($scoped))
            ->orderBy('name')
            ->get();

        return ProjectResource::collection($projects);
    }

    public function show(Request $request, RepoProfile $repoProfile): ProjectResource
    {
        $this->assertProjectVisible($request, $repoProfile);

        $repoProfile->loadCount('openTasks');

        return new ProjectResource($repoProfile);
    }
}
