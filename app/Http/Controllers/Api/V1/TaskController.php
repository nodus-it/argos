<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\TaskOrigin;
use App\Http\Controllers\Api\V1\Concerns\ActsForAutomation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StartAutoRunRequest;
use App\Http\Resources\Api\V1\TaskResource;
use App\Models\Task;
use App\Models\Workspace;
use App\Services\Workspace\AutoRunService;
use App\Services\Workspace\AutoTaskDriver;
use App\Services\Workspace\TaskService;
use App\Services\Workspace\WorkspaceIssueService;
use App\Services\Workspace\WorkspaceShipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Tasks of a workspace: list, start automatic ones, write, decide plans, pause/resume, ship. */
class TaskController extends Controller
{
    use ActsForAutomation;

    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->assertWorkspaceVisible($request, $workspace);

        $tasks = $workspace->tasks()
            ->with(['workspace', 'owner'])
            ->when(! $request->boolean('archived'), fn ($q) => $q->whereNull('archived_at'))
            ->when(is_string($request->query('origin')) && $request->query('origin') !== '', fn ($q) => $q->where('origin', $request->query('origin')))
            ->latest('updated_at')
            ->limit(200)
            ->get();

        return TaskResource::collection($tasks);
    }

    /**
     * Start an automatic task in the workspace (202: it continues
     * asynchronously — poll the task to follow it). `issue` links a ticket
     * of the workspace's tracker.
     */
    public function store(StartAutoRunRequest $request, Workspace $workspace, AutoRunService $runs, WorkspaceIssueService $issues): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $workspace);
        $actor = $this->actor($request);

        return $this->domain(function () use ($request, $workspace, $actor, $runs, $issues): JsonResponse {
            $issue = match (true) {
                $request->filled('issue') => $issues->load($workspace, (string) $request->input('issue')),
                is_array($request->input('ticket')) => $issues->customTicket($workspace, (array) $request->input('ticket')),
                default => null,
            };
            $task = $runs->launch($actor, $workspace, (string) $request->input('prompt'), [
                'title' => $request->input('title'),
                'base_branch' => $request->input('base_branch'),
                'agent_id' => $request->input('agent_id'),
                'model' => $request->input('model'),
                'issue' => $issue,
            ], $request->settings(), TaskOrigin::Api);

            return (new TaskResource($task->load(['workspace', 'owner'])))->response()->setStatusCode(202);
        });
    }

    public function show(Request $request, Task $task): TaskResource
    {
        $this->assertWorkspaceVisible($request, $task->workspace);

        return new TaskResource($task);
    }

    /** A comment on the task's ticket in an own system: shown as a hint in the task. */
    public function ticketComment(Request $request, Task $task, TaskService $tasks): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $task->workspace);
        $actor = $this->actor($request);
        $request->validate(['author' => ['required', 'string', 'max:120'], 'body' => ['required', 'string', 'max:20000'], 'url' => ['nullable', 'url', 'max:2000']]);

        return $this->domain(function () use ($request, $task, $actor, $tasks): JsonResponse {
            if (! $actor->can('view', $task)) {
                abort(404);
            }
            $tasks->ticketComment($task, (string) $request->input('author'), (string) $request->input('body'), $request->input('url'));

            return new JsonResponse([], 202);
        });
    }

    /** Commit, push and open/update the task's pull request now. */
    public function ship(Request $request, Task $task, WorkspaceShipService $ship): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $task->workspace);
        $actor = $this->actor($request);
        $request->validate(['draft' => ['nullable', 'boolean']]);

        return $this->domain(function () use ($task, $actor, $ship, $request): JsonResponse {
            $draft = $ship->draft($task, $actor);
            $url = $ship->ship($task, $actor, $draft['subject'] !== '' ? $draft['subject'] : $task->title, $draft['body'], (bool) $request->boolean('draft'));

            return new JsonResponse(['pull_request_url' => $url]);
        });
    }

    /** Write into the task. In a running auto-task this pauses automation unless `resume_auto` is set. */
    public function message(Request $request, Task $task, TaskService $tasks, AutoTaskDriver $driver): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $task->workspace);
        $actor = $this->actor($request);
        $request->validate(['text' => ['required', 'string', 'max:200000'], 'resume_auto' => ['nullable', 'boolean']]);

        return $this->domain(function () use ($request, $task, $actor, $tasks, $driver): JsonResponse {
            $event = $tasks->send($task, $actor, (string) $request->input('text'));
            if ($request->boolean('resume_auto') && $task->fresh()?->auto_state !== null) {
                $driver->resume($task->fresh(), $actor);
            }

            return new JsonResponse(['message_id' => $event->id], 202);
        });
    }

    /** Approve or reject a plan that waits for approval. */
    public function plan(Request $request, Task $task, AutoTaskDriver $driver): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $task->workspace);
        $actor = $this->actor($request);
        $request->validate(['decision' => ['required', 'in:approve,reject'], 'feedback' => ['nullable', 'string', 'max:20000']]);

        return $this->domain(function () use ($request, $task, $actor, $driver): JsonResponse {
            $driver->decidePlan($task, $actor, $request->input('decision') === 'approve', $request->input('feedback'));

            return (new TaskResource($task->fresh()))->response()->setStatusCode(202);
        });
    }

    public function pause(Request $request, Task $task, AutoTaskDriver $driver): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $task->workspace);
        $actor = $this->actor($request);

        return $this->domain(function () use ($task, $actor, $driver): JsonResponse {
            $driver->pause($task, $actor);

            return (new TaskResource($task->fresh()))->response();
        });
    }

    public function resume(Request $request, Task $task, AutoTaskDriver $driver): JsonResponse
    {
        $this->assertWorkspaceVisible($request, $task->workspace);
        $actor = $this->actor($request);

        return $this->domain(function () use ($task, $actor, $driver): JsonResponse {
            $driver->resume($task, $actor);

            return (new TaskResource($task->fresh()))->response()->setStatusCode(202);
        });
    }
}
