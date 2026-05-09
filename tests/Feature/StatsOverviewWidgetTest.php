<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentName;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Widgets\StatsOverviewWidget;
use App\Jobs\RunPhaseJob;
use App\Models\AgentVersion;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkerImageBuild;
use App\Models\WorkerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class StatsOverviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_widget_renders_without_error_when_empty(): void
    {
        Livewire::test(StatsOverviewWidget::class)
            ->assertSuccessful()
            ->assertSee('Running Workers')
            ->assertSee('In Progress')
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

        // Plus one agent-drift on a different agent
        $currentHash = substr(hash('sha256', $stack->dockerfile_body), 0, 8);
        AgentVersion::factory()->withUpdate()->create([
            'agent_name' => AgentName::Codex,
            'last_checked_at' => now(),
        ]);
        WorkerImageBuild::factory()->ready()->create([
            'worker_stack_id' => $stack->id,
            'agent_name' => AgentName::Codex,
            'stack_hash' => $currentHash,
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

    public function test_running_workers_counts_only_reserved_phase_jobs(): void
    {
        config(['queue.default' => 'database']);

        // Reserved RunPhaseJob → counts as a running worker.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => RunPhaseJob::class]),
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        // Queued but not reserved → does not count.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => RunPhaseJob::class]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        // Reserved job for an unrelated class → does not count.
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\OtherJob']),
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Running Workers')
            ->assertSee('Containers working');
    }

    public function test_running_workers_returns_zero_when_queue_is_not_database(): void
    {
        config(['queue.default' => 'sync']);

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('No active workers');
    }

    public function test_in_progress_counts_tasks_with_running_workflow_status(): void
    {
        Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementRunning]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::Draft]);

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('In Progress')
            ->assertSee('2 tasks running');
    }

    public function test_waiting_for_input_counts_review_and_failed_tasks(): void
    {
        Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptReview]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::InReview]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::Failed]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::Completed]);

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Waiting for you')
            ->assertSee('3');
    }

    public function test_waiting_for_input_counts_implement_paused_tasks(): void
    {
        Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementPaused]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::Draft]); // not counted

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Review or response pending')
            ->assertSee('1');
    }

    public function test_zero_states_render_calm_messages(): void
    {
        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('No active workers')
            ->assertSee('Nothing to do');
    }
}
