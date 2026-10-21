<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ActsForAutomation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartAutoRunRequest;
use App\Http\Resources\Api\V1\ThreadResource;
use App\Models\Thread;
use App\Models\Workspace;
use App\Services\Workspace\AgentPool;
use App\Services\Workspace\AutoThreadDriver;
use App\Services\Workspace\ThreadService;
use App\Services\Workspace\WorkspaceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Threads of a workspace: start automatic runs, write, decide plans, pause/resume. */
class ThreadController extends Controller
{
    use ActsForAutomation;

    /** Start another automatic run in an existing workspace (202). */
    public function store(StartAutoRunRequest $request, Workspace $workspace, AgentPool $pool, AutoThreadDriver $driver): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $workspace);
        $actor = $this->actor($request);

        return $this->domain(function () use ($request, $workspace, $actor, $pool, $driver): JsonResponse {
            $agents = $pool->usableAgentsFor($actor);
            $agent = $request->filled('agent_id') ? $agents->firstWhere('id', $request->input('agent_id')) : $agents->first();
            if ($agent === null) {
                throw WorkspaceException::translated('workspaces.auto.no_usable_agent');
            }
            $thread = $driver->start($workspace, $actor, $agent, (string) $request->input('prompt'), $request->input('title'), $request->input('model'), $request->settings());

            return (new ThreadResource($thread->load('workspace')))->response()->setStatusCode(202);
        });
    }

    public function show(Request $request, Thread $thread): ThreadResource
    {
        $this->assertWorkspaceVisible($request, $thread->workspace);

        return new ThreadResource($thread);
    }

    /** Write into the thread. In a running auto-thread this pauses automation unless `resume_auto` is set. */
    public function message(Request $request, Thread $thread, ThreadService $threads, AutoThreadDriver $driver): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $thread->workspace);
        $actor = $this->actor($request);
        $request->validate(['text' => ['required', 'string', 'max:200000'], 'resume_auto' => ['nullable', 'boolean']]);

        return $this->domain(function () use ($request, $thread, $actor, $threads, $driver): JsonResponse {
            $event = $threads->send($thread, $actor, (string) $request->input('text'));
            if ($request->boolean('resume_auto') && $thread->fresh()?->auto_state !== null) {
                $driver->resume($thread->fresh(), $actor);
            }

            return new JsonResponse(['message_id' => $event->id], 202);
        });
    }

    /** Approve or reject a plan that waits for approval. */
    public function plan(Request $request, Thread $thread, AutoThreadDriver $driver): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $thread->workspace);
        $actor = $this->actor($request);
        $request->validate(['decision' => ['required', 'in:approve,reject'], 'feedback' => ['nullable', 'string', 'max:20000']]);

        return $this->domain(function () use ($request, $thread, $actor, $driver): JsonResponse {
            $driver->decidePlan($thread, $actor, $request->input('decision') === 'approve', $request->input('feedback'));

            return (new ThreadResource($thread->fresh()))->response()->setStatusCode(202);
        });
    }

    public function pause(Request $request, Thread $thread, AutoThreadDriver $driver): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $thread->workspace);
        $actor = $this->actor($request);

        return $this->domain(function () use ($thread, $actor, $driver): JsonResponse {
            $driver->pause($thread, $actor);

            return (new ThreadResource($thread->fresh()))->response();
        });
    }

    public function resume(Request $request, Thread $thread, AutoThreadDriver $driver): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $thread->workspace);
        $actor = $this->actor($request);

        return $this->domain(function () use ($thread, $actor, $driver): JsonResponse {
            $driver->resume($thread, $actor);

            return (new ThreadResource($thread->fresh()))->response()->setStatusCode(202);
        });
    }
}
