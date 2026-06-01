<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * Sanctum tokens are bound either to a User (full access) or a RepoProfile
 * (project-scoped). This trait centralises that distinction so every endpoint
 * confines a project-bound token to its own project.
 */
trait ResolvesApiScope
{
    /** The RepoProfile id a project-bound token is restricted to, or null for a User token. */
    protected function scopedProjectId(Request $request): ?string
    {
        $tokenable = $request->user();

        return $tokenable instanceof RepoProfile ? $tokenable->id : null;
    }

    /** 404 a resource that a project-bound token may not see, so scoping never leaks existence. */
    protected function assertProjectVisible(Request $request, RepoProfile $project): void
    {
        $scoped = $this->scopedProjectId($request);

        if ($scoped !== null && $scoped !== $project->id) {
            abort(404);
        }
    }

    protected function assertTaskVisible(Request $request, Task $task): void
    {
        $scoped = $this->scopedProjectId($request);

        if ($scoped !== null && $scoped !== $task->repo_profile_id) {
            abort(404);
        }
    }
}
