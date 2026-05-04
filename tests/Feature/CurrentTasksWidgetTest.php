<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Widgets\CurrentTasksWidget;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CurrentTasksWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_widget_renders_empty_state_when_no_tasks(): void
    {
        Livewire::test(CurrentTasksWidget::class)
            ->assertSuccessful()
            ->assertSee('No tasks yet');
    }

    public function test_widget_lists_existing_tasks(): void
    {
        $task = Task::factory()->create(['name' => 'Improve checkout flow']);

        Livewire::test(CurrentTasksWidget::class)
            ->assertCanSeeTableRecords([$task])
            ->assertSee('Improve checkout flow');
    }

    public function test_tasks_waiting_for_input_appear_before_running_and_idle(): void
    {
        $idle = Task::factory()->create([
            'name' => 'Idle one',
            'workflow_status' => WorkflowStatus::Draft,
        ]);
        $running = Task::factory()->create([
            'name' => 'Running one',
            'workflow_status' => WorkflowStatus::ImplementRunning,
        ]);
        $waiting = Task::factory()->create([
            'name' => 'Waiting one',
            'workflow_status' => WorkflowStatus::ConceptReview,
        ]);

        Livewire::test(CurrentTasksWidget::class)
            ->assertCanSeeTableRecords([$waiting, $running, $idle], inOrder: true);
    }

    public function test_phase_helpers_return_expected_values(): void
    {
        $this->assertSame('Concept', CurrentTasksWidget::phaseLabel('concept'));
        $this->assertSame('—', CurrentTasksWidget::phaseLabel(null));
        $this->assertSame('info', CurrentTasksWidget::phaseColor('concept'));
        $this->assertSame('warning', CurrentTasksWidget::phaseColor('implement'));
        $this->assertSame('gray', CurrentTasksWidget::phaseColor(null));
        $this->assertSame('heroicon-m-light-bulb', CurrentTasksWidget::phaseIcon('concept'));
        $this->assertNull(CurrentTasksWidget::phaseIcon(null));
    }
}
