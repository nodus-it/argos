<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskDisplayStatusTest extends TestCase
{
    use RefreshDatabase;

    // --- isWaitingForWorker ---

    public function test_is_waiting_when_concept_running_and_pending(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Pending,
        ]);

        $this->assertTrue($task->isWaitingForWorker());
    }

    public function test_is_waiting_when_implement_running_and_pending(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => PhaseStatus::Pending,
        ]);

        $this->assertTrue($task->isWaitingForWorker());
    }

    public function test_not_waiting_when_concept_running_and_running(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Running,
        ]);

        $this->assertFalse($task->isWaitingForWorker());
    }

    public function test_not_waiting_when_implement_running_and_running(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => PhaseStatus::Running,
        ]);

        $this->assertFalse($task->isWaitingForWorker());
    }

    public function test_not_waiting_when_draft_and_pending(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::Draft,
            'current_status' => PhaseStatus::Pending,
        ]);

        $this->assertFalse($task->isWaitingForWorker());
    }

    public function test_not_waiting_when_completed_and_pending(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::Completed,
            'current_status' => PhaseStatus::Pending,
        ]);

        $this->assertFalse($task->isWaitingForWorker());
    }

    public function test_not_waiting_when_current_status_is_null(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => null,
        ]);

        $this->assertFalse($task->isWaitingForWorker());
    }

    // --- displayStatusLabel ---

    public function test_label_is_concept_waiting_when_concept_running_and_pending(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Pending,
        ]);

        $this->assertSame(__('tasks.statuses.waiting.concept'), $task->displayStatusLabel());
    }

    public function test_label_is_implement_waiting_when_implement_running_and_pending(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => PhaseStatus::Pending,
        ]);

        $this->assertSame(__('tasks.statuses.waiting.implement'), $task->displayStatusLabel());
    }

    public function test_label_delegates_to_workflow_status_when_not_waiting(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Running,
        ]);

        $this->assertSame(WorkflowStatus::ConceptRunning->label(), $task->displayStatusLabel());
    }

    public function test_label_delegates_for_draft(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::Draft,
            'current_status' => null,
        ]);

        $this->assertSame(WorkflowStatus::Draft->label(), $task->displayStatusLabel());
    }

    // --- displayStatusColor ---

    public function test_color_is_info_when_waiting(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Pending,
        ]);

        $this->assertSame('info', $task->displayStatusColor());
    }

    public function test_color_delegates_to_workflow_status_when_not_waiting(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Running,
        ]);

        $this->assertSame(WorkflowStatus::ConceptRunning->color(), $task->displayStatusColor());
    }

    public function test_color_is_info_for_implement_running_and_pending(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => PhaseStatus::Pending,
        ]);

        $this->assertSame('info', $task->displayStatusColor());
    }

    public function test_color_is_success_for_completed(): void
    {
        $task = Task::factory()->create([
            'workflow_status' => WorkflowStatus::Completed,
            'current_status' => null,
        ]);

        $this->assertSame('success', $task->displayStatusColor());
    }
}
