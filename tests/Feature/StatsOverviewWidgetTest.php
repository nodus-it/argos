<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentName;
use App\Enums\AutoTaskState;
use App\Filament\App\Clusters\System\Widgets\StatsOverviewWidget;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkerImageBuild;
use App\Models\WorkerStack;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatsOverviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_widget_renders_without_error_when_empty(): void
    {
        Livewire::test(StatsOverviewWidget::class)
            ->assertSuccessful()
            ->assertSee('Running Workspaces')
            ->assertSee('Automatic tasks')
            ->assertSee('Waiting for you')
            ->assertSee('Worker Updates');
    }

    public function test_worker_updates_stat_counts_unique_outdated_pairs(): void
    {
        // Two builds for the same (stack × agent) — both stale (stack drift)
        $stack = WorkerStack::factory()->create();
        WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => $stack->id,
            'agent_name' => AgentName::ClaudeCode,
            'stack_hash' => 'aaaaaaaa',
            'built_at' => now(),
        ]);
        WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => $stack->id,
            'agent_name' => AgentName::ClaudeCode,
            'stack_hash' => 'bbbbbbbb',
            'built_at' => now(),
        ]);

        // Plus one stale build on a different stack
        $other = WorkerStack::factory()->create();
        WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => $other->id,
            'agent_name' => AgentName::ClaudeCode,
            'stack_hash' => 'cccccccc',
            'built_at' => now()->subHour(),
        ]);

        // 3 outdated rows but only 2 unique (stack × agent) pairs
        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Worker Updates')
            ->assertSee('Image rebuilds available')
            ->assertSee('2');
    }

    public function test_worker_updates_stat_calm_when_nothing_to_update(): void
    {
        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Worker Updates')
            ->assertSee('All up to date');
    }

    public function test_running_workspaces_counts_only_running_containers(): void
    {
        Workspace::factory()->create();
        Workspace::factory()->stopped()->create();

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Running Workspaces')
            ->assertSee('Containers up');
    }

    public function test_in_progress_counts_running_auto_tasks(): void
    {
        Task::factory()->create(['auto_state' => AutoTaskState::Implementing]);
        Task::factory()->create(['auto_state' => AutoTaskState::Gating]);
        Task::factory()->create(['auto_state' => AutoTaskState::Done]);
        Task::factory()->create(['auto_state' => AutoTaskState::Paused]);
        Task::factory()->create(); // manual task

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Automatic tasks')
            ->assertSee('2 automatic tasks at work');
    }

    public function test_waiting_for_input_counts_plan_approvals_and_paused_runs(): void
    {
        Task::factory()->create(['auto_state' => AutoTaskState::AwaitingPlanApproval]);
        Task::factory()->create(['auto_state' => AutoTaskState::Paused]);
        Task::factory()->create(['auto_state' => AutoTaskState::Failed]); // final, not counted

        $stats = (fn () => $this->getStats())->call(new StatsOverviewWidget);

        $this->assertSame(2, $stats[2]->getValue());
        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Plan approval or paused automatic task');
    }

    public function test_zero_states_render_calm_messages(): void
    {
        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('No workspace running')
            ->assertSee('Nothing to do');
    }
}
