<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AuthMethod;
use App\Enums\GitProvider;
use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Models\PhaseRun;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use App\Support\Workflow\TaskStage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Env;

/**
 * Creates one faked task per TaskStage so every status-banner state, phase
 * stepper, dock variant and list/dashboard rendering can be eyeballed against
 * the running stack — without driving a real worker through the whole flow.
 *
 * Explicit-only (not registered in DatabaseSeeder); run it against the dev
 * stack with:
 *
 *   docker compose -f .tools/docker/docker-compose.yml \
 *     -f .tools/docker/docker-compose.dev.yml exec app \
 *     php artisan db:seed --class=WorkflowStageShowcaseSeeder
 *
 * Re-running is idempotent: it wipes every task whose name starts with the
 * showcase prefix and rebuilds the full set. These tasks have no real workspace
 * volume, so the live log / diff panels stay empty — that is expected; the
 * banner, stepper and timer are what this seeder exercises.
 */
final class WorkflowStageShowcaseSeeder extends Seeder
{
    private const NAME_PREFIX = 'Showcase';

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => (string) Env::get('SEED_USER_EMAIL', 'admin@argos.local')],
            ['name' => 'Argos Admin', 'password' => bcrypt((string) config('argos.admin_password'))],
        );

        $repo = RepoProfile::firstOrCreate(
            ['url' => 'https://github.com/nodus-it/argos-showcase.git'],
            [
                'name' => 'showcase',
                'platform' => GitProvider::GitHub->value,
                'auth_method' => AuthMethod::OAuth->value,
                'default_branch' => 'main',
                'auto_concept' => false,
                'auto_pr' => false,
            ],
        );

        // Idempotent: drop the previous showcase set (cascades phase_runs).
        Task::where('user_id', $user->id)
            ->where('name', 'like', self::NAME_PREFIX.'%')
            ->get()
            ->each(fn (Task $task) => $task->delete());

        foreach (TaskStage::cases() as $i => $stage) {
            $this->seedStage($user, $repo, $stage, $i);
        }

        $this->command?->info('Seeded '.count(TaskStage::cases()).' showcase tasks (one per workflow stage).');
    }

    private function seedStage(User $user, RepoProfile $repo, TaskStage $stage, int $index): void
    {
        [$ws, $cs, $phase] = $this->persistedTriple($stage);

        $task = Task::create([
            'user_id' => $user->id,
            'name' => sprintf('%s %02d — %s', self::NAME_PREFIX, $index + 1, $stage->label()),
            'repo_profile_id' => $repo->id,
            'description' => 'Faked task pinned to the '.$stage->value.' stage by WorkflowStageShowcaseSeeder.',
            'base_branch' => $repo->default_branch,
            'workflow_status' => $ws->value,
            'current_phase' => $phase?->value,
            'current_status' => $cs?->value,
            'concept_md' => $this->wantsConcept($stage) ? $this->sampleConcept() : null,
            'implement_summary_nontechnical' => $this->wantsImplementSummary($stage)
                ? 'Added the requested feature and wired it into the dashboard.'
                : null,
            'implement_summary_technical' => $this->wantsImplementSummary($stage)
                ? "- new Service class\n- migration + factory\n- feature test"
                : null,
            'feature_branch' => $this->wantsBranch($stage) ? 'argos/showcase-'.$stage->value : null,
            'pr_url' => $stage === TaskStage::Review || $stage === TaskStage::Done
                ? 'https://github.com/nodus-it/argos-showcase/pull/'.($index + 1)
                : null,
        ]);

        $this->seedRuns($task, $stage);
    }

    /**
     * Reverse-map a stage to the persisted (workflow_status, current_status,
     * current_phase) triple that TaskStage::for() collapses back into it.
     *
     * @return array{0: WorkflowStatus, 1: ?PhaseStatus, 2: ?Phase}
     */
    private function persistedTriple(TaskStage $stage): array
    {
        return match ($stage) {
            TaskStage::Draft => [WorkflowStatus::Draft, null, null],

            TaskStage::ConceptQueued => [WorkflowStatus::ConceptRunning, PhaseStatus::Pending, Phase::Concept],
            TaskStage::ConceptRunning => [WorkflowStatus::ConceptRunning, PhaseStatus::Running, Phase::Concept],
            TaskStage::ConceptPaused => [WorkflowStatus::ConceptRunning, PhaseStatus::Paused, Phase::Concept],
            TaskStage::ConceptReview => [WorkflowStatus::ConceptReview, PhaseStatus::Completed, Phase::Concept],
            TaskStage::ConceptFailed => [WorkflowStatus::Failed, PhaseStatus::Failed, Phase::Concept],

            TaskStage::ImplementQueued => [WorkflowStatus::ImplementRunning, PhaseStatus::Pending, Phase::Implement],
            TaskStage::ImplementRunning => [WorkflowStatus::ImplementRunning, PhaseStatus::Running, Phase::Implement],
            TaskStage::ImplementPaused => [WorkflowStatus::ImplementPaused, PhaseStatus::Paused, Phase::Implement],
            TaskStage::ImplementReview => [WorkflowStatus::ImplementCompleted, PhaseStatus::Completed, Phase::Implement],
            TaskStage::ImplementFailed => [WorkflowStatus::Failed, PhaseStatus::Failed, Phase::Implement],

            TaskStage::PushQueued => [WorkflowStatus::ImplementRunning, PhaseStatus::Pending, Phase::Push],
            TaskStage::PushRunning => [WorkflowStatus::ImplementRunning, PhaseStatus::Running, Phase::Push],
            TaskStage::PushFailed => [WorkflowStatus::Failed, PhaseStatus::Failed, Phase::Push],

            TaskStage::Review => [WorkflowStatus::InReview, PhaseStatus::Completed, Phase::Push],
            TaskStage::Done => [WorkflowStatus::Completed, PhaseStatus::Completed, Phase::Push],
        };
    }

    /**
     * Lay down the phase_runs the thread + banner read for this stage: every
     * phase the task has already passed is a completed run, and the active phase
     * gets a run whose status matches current_status.
     */
    private function seedRuns(Task $task, TaskStage $stage): void
    {
        $activePhase = $stage->phase();

        // Completed predecessors so the stepper marks them done and the thread
        // shows their history.
        if ($this->hasCompletedConcept($stage)) {
            $this->completedConceptRun($task);
        }
        if ($this->hasCompletedImplement($stage)) {
            $this->completedImplementRun($task);
        }
        if ($stage === TaskStage::Review || $stage === TaskStage::Done) {
            $this->completedPushRun($task);
        }

        if ($activePhase === null || $this->activeAlreadyCompleted($stage)) {
            return;
        }

        // The active phase's run, mirroring current_status.
        $status = match (true) {
            $stage->isQueued() => null,                 // queued: no row yet → placeholder in UI
            $stage->isRunning() => PhaseStatus::Running,
            $stage->isPaused() => PhaseStatus::Paused,
            $stage->isFailed() => PhaseStatus::Failed,
            default => null,
        };

        if ($status === null) {
            return;
        }

        PhaseRun::create([
            'task_id' => $task->id,
            'phase' => $activePhase->value,
            'iteration' => $this->nextIteration($task, $activePhase),
            'status' => $status->value,
            'started_at' => now()->subMinutes(2),
            'finished_at' => $status === PhaseStatus::Running ? null : now(),
            'exit_code' => match ($status) {
                PhaseStatus::Running => null,
                PhaseStatus::Failed => 1,
                PhaseStatus::Paused => 3,
                default => 0,
            },
            'stop_reason' => $status === PhaseStatus::Paused ? 'error_max_turns' : null,
            'error_log' => $status === PhaseStatus::Failed
                ? "Fatal: the agent exited unexpectedly.\nstderr: connection reset while pulling the base image."
                : null,
            'result_json' => $status === PhaseStatus::Paused
                ? ['subtype' => 'error_max_turns', 'is_error' => true, 'num_turns' => 51]
                : null,
            'input_tokens' => 2400,
            'output_tokens' => 1200,
        ]);
    }

    private function completedConceptRun(Task $task): void
    {
        PhaseRun::create([
            'task_id' => $task->id,
            'phase' => Phase::Concept->value,
            'iteration' => 1,
            'status' => PhaseStatus::Completed->value,
            'started_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(15),
            'exit_code' => 0,
            'concept_md' => $this->sampleConcept(),
            'input_tokens' => 3200,
            'output_tokens' => 1800,
        ]);
    }

    private function completedImplementRun(Task $task): void
    {
        PhaseRun::create([
            'task_id' => $task->id,
            'phase' => Phase::Implement->value,
            'iteration' => 1,
            'status' => PhaseStatus::Completed->value,
            'started_at' => now()->subMinutes(12),
            'finished_at' => now()->subMinutes(6),
            'exit_code' => 0,
            'implement_summary_nontechnical' => 'Added the requested feature and wired it into the dashboard.',
            'implement_summary_technical' => "- new Service class\n- migration + factory\n- feature test",
            'input_tokens' => 8200,
            'output_tokens' => 4100,
        ]);
    }

    private function completedPushRun(Task $task): void
    {
        PhaseRun::create([
            'task_id' => $task->id,
            'phase' => Phase::Push->value,
            'iteration' => 1,
            'status' => PhaseStatus::Completed->value,
            'started_at' => now()->subMinutes(4),
            'finished_at' => now()->subMinutes(3),
            'exit_code' => 0,
            'result_json' => [
                'pr_url' => $task->pr_url,
                'branch' => $task->feature_branch,
            ],
            'input_tokens' => 600,
            'output_tokens' => 200,
        ]);
    }

    private function nextIteration(Task $task, Phase $phase): int
    {
        return (int) $task->phaseRuns()->where('phase', $phase->value)->max('iteration') + 1;
    }

    private function hasCompletedConcept(TaskStage $stage): bool
    {
        return $stage->isPastConcept() || $stage === TaskStage::ConceptReview;
    }

    private function hasCompletedImplement(TaskStage $stage): bool
    {
        return in_array($stage, [
            TaskStage::ImplementReview,
            TaskStage::PushQueued, TaskStage::PushRunning, TaskStage::PushFailed,
            TaskStage::Review, TaskStage::Done,
        ], true);
    }

    /** Review/Done already have their active phase laid down as a completed run. */
    private function activeAlreadyCompleted(TaskStage $stage): bool
    {
        return in_array($stage, [
            TaskStage::ConceptReview, TaskStage::ImplementReview,
            TaskStage::Review, TaskStage::Done,
        ], true);
    }

    private function wantsConcept(TaskStage $stage): bool
    {
        return $stage->isPastConcept() || $stage === TaskStage::ConceptReview;
    }

    private function wantsImplementSummary(TaskStage $stage): bool
    {
        return in_array($stage, [
            TaskStage::ImplementReview, TaskStage::PushQueued, TaskStage::PushRunning,
            TaskStage::PushFailed, TaskStage::Review, TaskStage::Done,
        ], true);
    }

    private function wantsBranch(TaskStage $stage): bool
    {
        return $stage->isPastConcept();
    }

    private function sampleConcept(): string
    {
        return <<<'MD'
            # Concept

            ## Goal
            Add a faked status showcase so the workflow UI can be reviewed per state.

            ## Approach
            1. Resolve the presentation stage from the persisted triple.
            2. Render the banner + phase stepper from that stage.
            3. Drive the respond dock variant off the same stage.

            ## Out of scope
            Anything touching the real worker.
            MD;
    }
}
