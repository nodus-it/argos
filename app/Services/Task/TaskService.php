<?php

declare(strict_types=1);

namespace App\Services\Task;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Events\Task\ConceptNotesUpdated;
use App\Events\Task\FeedbackSubmitted;
use App\Events\Task\ImplementNotesUpdated;
use App\Events\Task\PhaseCompleted;
use App\Events\Task\PhaseStarted;
use App\Events\Task\TaskCompleted;
use App\Events\Task\TaskCreated;
use App\Events\Task\TaskDeleted;
use App\Jobs\RunPhaseJob;
use App\Models\Task;
use App\Services\Workflow\PhaseRunner;
use App\Services\Workflow\RunResourceReaper;
use App\Services\Workflow\WorkflowService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

class TaskService
{
    public function __construct(
        private readonly WorkflowService $workflowService,
        private readonly PhaseRunner $phaseRunner,
        private readonly RunResourceReaper $reaper,
    ) {}

    /**
     * Create a task record, provision its Docker volume, fire TaskCreated,
     * and optionally auto-start the concept phase.
     *
     * @param  array<string, mixed>  $data
     */
    public function createTask(array $data): Task
    {
        $task = Task::create([
            'user_id' => $data['user_id'] ?? null,
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Task::generateSlug((string) $data['name']),
            'repo_profile_id' => $data['repo_profile_id'] ?? null,
            'description' => $data['description'],
            'base_branch' => $data['base_branch'] ?? null,
            'auto_concept' => $data['auto_concept'] ?? false,
            'max_turns_concept' => $data['max_turns_concept'] ?? null,
            'max_turns_implement' => $data['max_turns_implement'] ?? null,
            'model_concept' => $data['model_concept'] ?? null,
            'model_implement' => $data['model_implement'] ?? null,
            'worker_stack_id_override' => $data['worker_stack_id_override'] ?? null,
            'worker_agent_name_override' => $data['worker_agent_name_override'] ?? null,
            'agent_credential_id' => $data['agent_credential_id'] ?? null,
        ]);

        Process::run(['docker', 'volume', 'create', $task->volumeName()]);
        // Hand the fresh volume to the worker uid (1000). Docker leaves new
        // volumes root-owned and copy-up ownership is host-dependent, so the
        // agent container (USER agent) otherwise can't mkdir in /workspace for
        // tasks without concept notes (the note writer's own chown only covers
        // the with-notes path).
        Process::run(['docker', 'run', '--rm', '-v', $task->volumeName().':/workspace', 'alpine', 'chown', '-R', '1000:1000', '/workspace']);

        Event::dispatch(new TaskCreated($task));

        if ($task->auto_concept) {
            $task->update([
                'workflow_status' => WorkflowStatus::ConceptRunning,
                'current_phase' => 'concept',
                'current_status' => 'pending',
            ]);
            RunPhaseJob::dispatch($task->id, 'concept');
            Event::dispatch(new PhaseStarted($task, Phase::Concept));
        }

        return $task;
    }

    /**
     * Delete a task record and fire TaskDeleted.
     * Volume cleanup is handled separately (e.g. via markCompleted).
     */
    public function deleteTask(Task $task): void
    {
        $task->delete();
        Event::dispatch(new TaskDeleted($task));
    }

    /**
     * Dispatch a phase job for the given task and fire PhaseStarted.
     *
     * @param  array<string, mixed>  $flags
     *
     * @throws \RuntimeException when a phase is already running
     */
    public function startPhase(Task $task, Phase $phase, array $flags = []): void
    {
        if ($task->phaseRuns()->where('status', 'running')->exists()) {
            throw new \RuntimeException('A phase is already running for this task.');
        }

        // Order is strict: once the implement phase has run, the concept is
        // locked — there is no going back to it (M5).
        if ($phase === Phase::Concept && $task->phaseRuns()->where('phase', 'implement')->exists()) {
            throw new \RuntimeException('The concept is locked once implementation has started.');
        }

        $task->update([
            'current_phase' => $phase->value,
            'current_status' => 'running',
            'workflow_status' => $task->workflow_status->retryingPhase($phase->value),
        ]);
        RunPhaseJob::dispatch($task->id, $phase->value, $flags);
        Event::dispatch(new PhaseStarted($task, $phase));
    }

    /**
     * Resume a paused implement run with a new max_turns limit.
     *
     * @throws \RuntimeException when a phase is already running
     */
    public function continueImplement(Task $task, int $maxTurns): void
    {
        if ($task->phaseRuns()->where('status', 'running')->exists()) {
            throw new \RuntimeException('A phase is already running for this task.');
        }

        $task->update([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_phase' => 'implement',
            'current_status' => 'running',
        ]);

        RunPhaseJob::dispatch($task->id, 'implement', [
            'continue' => true,
            'max_turns' => $maxTurns,
        ]);

        Event::dispatch(new PhaseStarted($task, Phase::Implement));
    }

    /**
     * Resume a paused concept run with a new max_turns limit. Mirrors
     * continueImplement; differs only in the workflow status / phase value.
     *
     * @throws \RuntimeException when a phase is already running
     */
    public function continueConcept(Task $task, int $maxTurns): void
    {
        if ($task->phaseRuns()->where('status', 'running')->exists()) {
            throw new \RuntimeException('A phase is already running for this task.');
        }

        $task->update([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_phase' => 'concept',
            'current_status' => 'running',
        ]);

        RunPhaseJob::dispatch($task->id, 'concept', [
            'continue' => true,
            'max_turns' => $maxTurns,
        ]);

        Event::dispatch(new PhaseStarted($task, Phase::Concept));
    }

    /**
     * Force-unlock a lock-blocked implement run.
     *
     * @throws \RuntimeException when a phase is already running
     */
    public function forceUnlockImplement(Task $task): void
    {
        if ($task->phaseRuns()->where('status', 'running')->exists()) {
            throw new \RuntimeException('A phase is already running for this task.');
        }

        $task->update([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_phase' => 'implement',
            'current_status' => 'running',
        ]);

        RunPhaseJob::dispatch($task->id, 'implement', ['force_unlock' => true]);
        Event::dispatch(new PhaseStarted($task, Phase::Implement));
    }

    /**
     * Mark a task as completed and fire TaskCompleted. The follow-ups — closing
     * the source issue and removing the Docker volume — are handled by listeners
     * so this stays a pure DB operation.
     */
    public function markCompleted(Task $task): void
    {
        $task->update(['workflow_status' => WorkflowStatus::Completed]);
        Event::dispatch(new TaskCompleted($task));
    }

    /**
     * Abort a task: hard-kill its running worker/sidecar containers so the phase
     * stops immediately, close the in-flight phase run, and move the task to the
     * Aborted terminal state. The workspace volume is kept (inspection / a later
     * delete drops it) — see RunPhaseJob::failed(), which yields to this status
     * so the dying job doesn't overwrite it with Failed.
     */
    public function abortTask(Task $task): void
    {
        $this->reaper->reapTask($task->id);

        $task->phaseRuns()
            ->where('status', PhaseStatus::Running->value)
            ->update([
                'status' => PhaseStatus::Failed->value,
                'finished_at' => now(),
            ]);

        $task->update([
            'workflow_status' => WorkflowStatus::Aborted,
            'current_status' => PhaseStatus::Failed,
        ]);
    }

    /**
     * Persist concept notes and fire ConceptNotesUpdated.
     */
    public function saveConceptNotes(Task $task, string $notes): void
    {
        $task->update(['concept_notes' => $notes ?: null]);
        Event::dispatch(new ConceptNotesUpdated($task));
    }

    /**
     * Persist concept notes and immediately re-run the concept phase.
     *
     * @throws \RuntimeException when a phase is already running
     */
    public function saveConceptNotesAndRevise(Task $task, string $notes): void
    {
        $this->saveConceptNotes($task, $notes);
        $this->startPhase($task, Phase::Concept);
    }

    /**
     * Persist implement notes and fire ImplementNotesUpdated.
     */
    public function saveImplementNotes(Task $task, string $notes): void
    {
        $task->update(['implement_notes' => $notes ?: null]);
        Event::dispatch(new ImplementNotesUpdated($task));
    }

    /**
     * Persist implement notes and immediately re-run the implement phase.
     *
     * When $refine is true (the "refine implementation" action in the review
     * dock) the re-run builds on the previous iteration's working tree instead
     * of resetting to the base branch — otherwise the reviewed work would be
     * discarded. The default (false) is a clean reset, used when retrying a
     * failed implement run.
     *
     * @throws \RuntimeException when a phase is already running
     */
    public function saveImplementNotesAndRevise(Task $task, string $notes, bool $refine = false): void
    {
        $this->saveImplementNotes($task, $notes);
        $this->startPhase($task, Phase::Implement, $refine ? ['refine' => true] : []);
    }

    /**
     * Write feedback to the task volume, fire FeedbackSubmitted, then start the respond phase.
     *
     * @throws \RuntimeException when a phase is already running
     */
    public function submitFeedback(Task $task, string $feedback): void
    {
        $this->phaseRunner->writeFeedbackToVolume($task, $feedback);
        Event::dispatch(new FeedbackSubmitted($task));
        $this->startPhase($task, Phase::Respond);
    }

    /**
     * Advance workflow status after a phase run and fire PhaseCompleted.
     * Called by RunPhaseJob — the single place where PhaseCompleted is published.
     */
    public function completePhase(Task $task, string $phase, PhaseStatus $status): void
    {
        $this->workflowService->completePhase($task, $phase, $status);
        Event::dispatch(new PhaseCompleted($task, Phase::from($phase), $status));
    }

    /**
     * Resolve a task by its ULID or its (unique) slug. Used by the MCP tools via
     * InteractsWithTasks to turn a user-supplied reference into a Task. The
     * display `name` is non-unique and deliberately NOT a lookup key.
     */
    public function find(string $slugOrId): ?Task
    {
        return Task::where('id', $slugOrId)->orWhere('slug', $slugOrId)->first();
    }
}
