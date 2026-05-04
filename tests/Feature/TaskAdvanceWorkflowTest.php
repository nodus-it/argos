<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Jobs\RunPhaseJob;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class TaskAdvanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_concept_completed_transitions_to_concept_review(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);

        $task->advanceWorkflow('concept', 'completed');

        $this->assertSame(WorkflowStatus::ConceptReview, $task->fresh()->workflow_status);
    }

    public function test_concept_failed_transitions_to_failed(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);

        $task->advanceWorkflow('concept', 'failed');

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_concept_quality_gate_failed_transitions_to_failed(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);

        $task->advanceWorkflow('concept', 'quality_gate_failed');

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_implement_completed_without_auto_pr_does_not_change_status(): void
    {
        $profile = RepoProfile::factory()->create(['auto_pr' => false]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::ImplementRunning,
        ]);

        $task->advanceWorkflow('implement', 'completed');

        // stays in ImplementRunning — user must trigger push manually
        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
        Bus::assertNothingDispatched();
    }

    public function test_implement_completed_with_auto_pr_dispatches_push_job(): void
    {
        $profile = RepoProfile::factory()->create(['auto_pr' => true]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::ImplementRunning,
        ]);

        $task->advanceWorkflow('implement', 'completed');

        Bus::assertDispatched(RunPhaseJob::class, fn ($job) => $job->phase === 'push' && $job->taskId === $task->id);
        // status stays ImplementRunning until push finishes
        $this->assertSame(WorkflowStatus::ImplementRunning, $task->fresh()->workflow_status);
    }

    public function test_implement_failed_transitions_to_failed(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementRunning]);

        $task->advanceWorkflow('implement', 'failed');

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_push_completed_transitions_to_in_review(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementRunning]);

        $task->advanceWorkflow('push', 'completed');

        $this->assertSame(WorkflowStatus::InReview, $task->fresh()->workflow_status);
    }

    public function test_push_failed_transitions_to_failed(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementRunning]);

        $task->advanceWorkflow('push', 'failed');

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_respond_completed_transitions_to_in_review(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::InReview]);

        $task->advanceWorkflow('respond', 'completed');

        $this->assertSame(WorkflowStatus::InReview, $task->fresh()->workflow_status);
    }

    public function test_respond_failed_transitions_to_failed(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::InReview]);

        $task->advanceWorkflow('respond', 'failed');

        $this->assertSame(WorkflowStatus::Failed, $task->fresh()->workflow_status);
    }

    public function test_no_changes_phase_status_returns_null_no_update(): void
    {
        $task = Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);
        $originalStatus = $task->workflow_status;

        $task->advanceWorkflow('concept', 'no_changes');

        $this->assertSame($originalStatus, $task->fresh()->workflow_status);
    }
}
