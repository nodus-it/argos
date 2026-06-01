<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTaskRequest;
use App\Http\Requests\Api\V1\SubmitFeedbackRequest;
use App\Http\Resources\Api\V1\TaskResource;
use App\Http\Resources\Api\V1\TaskSummaryResource;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Services\Task\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TaskController extends Controller
{
    use ResolvesApiScope;

    public function __construct(private readonly TaskService $taskService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $scoped = $this->scopedProjectId($request);

        $tasks = Task::query()
            ->with('repoProfile')
            ->when($scoped !== null, fn ($query) => $query->where('repo_profile_id', $scoped))
            ->when(
                $scoped === null && is_string($request->query('project')) && $request->query('project') !== '',
                fn ($query) => $query->where('repo_profile_id', $this->resolveProjectId((string) $request->query('project'))),
            )
            ->when(
                is_string($request->query('status')) && $request->query('status') !== '',
                fn ($query) => $query->where('workflow_status', $request->query('status')),
            )
            ->latest()
            ->get();

        return TaskSummaryResource::collection($tasks);
    }

    public function show(Request $request, Task $task): TaskResource
    {
        $this->assertTaskVisible($request, $task);

        $task->load('repoProfile')
            ->load(['phaseRuns' => fn ($query) => $query->latest('started_at')->limit(5)]);

        return new TaskResource($task);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $project = $this->resolveStoreProject($request);
        $plan = (string) $request->input('plan');

        $task = $this->taskService->createTask([
            // API-created tasks are owned by the consumer (ApiClient/RepoProfile
            // token), not a human user.
            'user_id' => null,
            'name' => $request->input('name'),
            'repo_profile_id' => $project->id,
            'description' => $plan,
            'base_branch' => $request->input('base_branch'),
            'auto_concept' => false,
        ]);

        // createTask() inserts with the DB default workflow_status ('draft') but
        // leaves it unset in memory; refresh so startPhase() transitions cleanly.
        $task->refresh();

        // Persist the plan as concept notes so the worker injects it as
        // concept.notes.md (createTask() does not take concept_notes).
        $this->taskService->saveConceptNotes($task, $plan);

        try {
            $this->taskService->startPhase($task, Phase::Concept);
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        return (new TaskResource($task->load('repoProfile')))
            ->response()
            ->setStatusCode(202);
    }

    public function feedback(SubmitFeedbackRequest $request, Task $task): JsonResponse
    {
        $this->assertTaskVisible($request, $task);

        try {
            $this->taskService->submitFeedback($task, (string) $request->input('feedback'));
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        return $this->accepted($task);
    }

    public function concept(Request $request, Task $task): JsonResponse
    {
        $this->assertTaskVisible($request, $task);
        $this->assertNotCompleted($task, 'Concept');
        $this->assertNotRunning($task);

        try {
            if ($this->latestPhaseRun($task, 'concept')?->status === PhaseStatus::Paused) {
                $maxTurns = $this->maxTurns($request, $task->max_turns_concept, (int) config('argos.concept.max_turns_default', 30));
                $this->taskService->continueConcept($task, $maxTurns);
            } else {
                $this->taskService->startPhase($task, Phase::Concept);
            }
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        return $this->accepted($task);
    }

    public function implement(Request $request, Task $task): JsonResponse
    {
        $this->assertTaskVisible($request, $task);
        $this->assertNotCompleted($task, 'Implement');

        if (! $this->hasCompletedPhase($task, 'concept')) {
            return $this->conflict('The Implement phase requires a completed Concept run first.');
        }

        $this->assertNotRunning($task);

        try {
            if ($this->latestPhaseRun($task, 'implement')?->status === PhaseStatus::Paused) {
                $maxTurns = $this->maxTurns($request, $task->max_turns_implement, (int) config('argos.implement.max_turns_default', 200));
                $this->taskService->continueImplement($task, $maxTurns);
            } else {
                $this->taskService->startPhase($task, Phase::Implement);
            }
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        return $this->accepted($task);
    }

    public function pr(Request $request, Task $task): JsonResponse
    {
        $this->assertTaskVisible($request, $task);
        $this->assertNotCompleted($task, 'Push');

        if (! $this->hasCompletedPhase($task, 'implement')) {
            return $this->conflict('The Push phase requires a completed Implement run first.');
        }

        $this->assertNotRunning($task);

        try {
            $this->taskService->startPhase($task, Phase::Push);
        } catch (RuntimeException $e) {
            return $this->conflict($e->getMessage());
        }

        return $this->accepted($task);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resolveStoreProject(StoreTaskRequest $request): RepoProfile
    {
        $scoped = $this->scopedProjectId($request);
        $ref = $request->input('project');

        if ($scoped !== null) {
            // Project-bound token: ignore/validate the payload against its scope.
            if (is_string($ref) && $ref !== '' && $this->resolveProjectId($ref) !== $scoped) {
                throw ValidationException::withMessages([
                    'project' => 'This token is bound to a different project.',
                ]);
            }

            return RepoProfile::findOrFail($scoped);
        }

        if (! is_string($ref) || $ref === '') {
            throw ValidationException::withMessages(['project' => 'The project field is required.']);
        }

        $project = RepoProfile::query()->where('id', $ref)->orWhere('name', $ref)->first();

        if ($project === null) {
            throw ValidationException::withMessages(['project' => "Project not found: {$ref}"]);
        }

        return $project;
    }

    private function resolveProjectId(string $ref): ?string
    {
        return RepoProfile::query()->where('id', $ref)->orWhere('name', $ref)->value('id');
    }

    private function latestPhaseRun(Task $task, string $phase): ?PhaseRun
    {
        return $task->phaseRuns()->where('phase', $phase)->orderByDesc('iteration')->first();
    }

    private function hasCompletedPhase(Task $task, string $phase): bool
    {
        return $task->phaseRuns()->where('phase', $phase)->where('status', 'completed')->exists();
    }

    private function maxTurns(Request $request, ?int $taskDefault, int $configDefault): int
    {
        $validated = $request->validate([
            'max_turns' => ['nullable', 'integer', 'min:10', 'max:1000'],
        ]);

        return (int) ($validated['max_turns'] ?? $taskDefault ?? $configDefault);
    }

    private function assertNotCompleted(Task $task, string $phaseLabel): void
    {
        if ($task->workflow_status->value === 'completed') {
            abort(response()->json([
                'message' => "Task is completed; the {$phaseLabel} phase cannot be run.",
            ], 409));
        }
    }

    private function assertNotRunning(Task $task): void
    {
        if ($task->current_status === PhaseStatus::Running) {
            abort(response()->json(['message' => 'A phase is already running for this task.'], 409));
        }
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }

    private function accepted(Task $task): JsonResponse
    {
        return (new TaskResource($task->fresh()->load('repoProfile')))
            ->response()
            ->setStatusCode(202);
    }
}
