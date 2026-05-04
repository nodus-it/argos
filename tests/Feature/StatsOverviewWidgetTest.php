<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Widgets\StatsOverviewWidget;
use App\Jobs\RunPhaseJob;
use App\Models\Task;
use App\Models\User;
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
            ->assertSee('Laufende Worker')
            ->assertSee('In Bearbeitung')
            ->assertSee('Wartet auf dich');
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
            ->assertSee('Laufende Worker')
            ->assertSee('Container arbeiten gerade');
    }

    public function test_running_workers_returns_zero_when_queue_is_not_database(): void
    {
        config(['queue.default' => 'sync']);

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Keine aktiven Worker');
    }

    public function test_in_progress_counts_tasks_with_running_workflow_status(): void
    {
        Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptRunning]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementRunning]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::Draft]);

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('In Bearbeitung')
            ->assertSee('2 Tasks laufen');
    }

    public function test_waiting_for_input_counts_review_and_failed_tasks(): void
    {
        Task::factory()->create(['workflow_status' => WorkflowStatus::ConceptReview]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::InReview]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::Failed]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::Completed]);

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Wartet auf dich')
            ->assertSee('3');
    }

    public function test_waiting_for_input_counts_implement_paused_tasks(): void
    {
        Task::factory()->create(['workflow_status' => WorkflowStatus::ImplementPaused]);
        Task::factory()->create(['workflow_status' => WorkflowStatus::Draft]); // not counted

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Review oder Antwort offen')
            ->assertSee('1');
    }

    public function test_zero_states_render_calm_messages(): void
    {
        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('Keine aktiven Worker')
            ->assertSee('Nichts zu tun');
    }
}
