<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\ListTasks;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskListWaitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_waiting_task_shows_concept_waiting_label(): void
    {
        Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Pending,
        ]);

        Livewire::test(ListTasks::class)
            ->assertSuccessful()
            ->assertSee(__('tasks.statuses.waiting.concept'));
    }

    public function test_waiting_implement_task_shows_implement_waiting_label(): void
    {
        Task::factory()->create([
            'workflow_status' => WorkflowStatus::ImplementRunning,
            'current_status' => PhaseStatus::Pending,
        ]);

        Livewire::test(ListTasks::class)
            ->assertSuccessful()
            ->assertSee(__('tasks.statuses.waiting.implement'));
    }

    public function test_running_task_does_not_show_waiting_label(): void
    {
        Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Running,
        ]);

        Livewire::test(ListTasks::class)
            ->assertSuccessful()
            ->assertDontSee(__('tasks.statuses.waiting.concept'));
    }

    public function test_waiting_tab_is_rendered(): void
    {
        Livewire::test(ListTasks::class)
            ->assertSuccessful()
            ->assertSee(__('tasks.tabs.waiting'));
    }

    public function test_waiting_tab_filters_only_waiting_tasks(): void
    {
        $waiting = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Pending,
        ]);

        $running = Task::factory()->create([
            'workflow_status' => WorkflowStatus::ConceptRunning,
            'current_status' => PhaseStatus::Running,
        ]);

        Livewire::test(ListTasks::class)
            ->set('activeTab', 'wartend')
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$running]);
    }
}
