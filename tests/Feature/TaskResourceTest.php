<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Phase;
use App\Enums\PhaseStatus;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ListTasks;
use App\Jobs\RunPhaseJob;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class TaskResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        Bus::fake();
        Process::fake();
    }

    public function test_list_page_renders(): void
    {
        $tasks = Task::factory()->count(3)->create();

        Livewire::test(ListTasks::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($tasks);
    }

    public function test_list_shows_workflow_status_column(): void
    {
        Task::factory()->create(['workflow_status' => WorkflowStatus::Draft]);

        Livewire::test(ListTasks::class)
            ->assertSuccessful()
            ->assertSee('Draft');
    }

    public function test_create_page_renders(): void
    {
        Livewire::test(CreateTask::class)
            ->assertSuccessful();
    }

    public function test_can_create_task_without_auto_concept(): void
    {
        $profile = RepoProfile::factory()->create();

        Livewire::test(CreateTask::class)
            ->fillForm([
                'name' => 'Test Task',
                'repo_profile_id' => $profile->id,
                'description' => 'Eine Beschreibung',
                'auto_concept' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $task = Task::where('name', 'Test Task')->first();
        $this->assertNotNull($task);
        $this->assertEquals(WorkflowStatus::Draft, $task->workflow_status);
        Bus::assertNotDispatched(RunPhaseJob::class);
    }

    public function test_can_create_task_with_auto_concept(): void
    {
        $profile = RepoProfile::factory()->create();

        Livewire::test(CreateTask::class)
            ->fillForm([
                'name' => 'Auto Task',
                'repo_profile_id' => $profile->id,
                'description' => 'Beschreibung',
                'auto_concept' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $task = Task::where('name', 'Auto Task')->first();
        $this->assertEquals(WorkflowStatus::ConceptRunning, $task->workflow_status);
        $this->assertSame(Phase::Concept, $task->current_phase);
        $this->assertSame(PhaseStatus::Pending, $task->current_status);
        Bus::assertDispatched(RunPhaseJob::class, fn ($job) => $job->phase === 'concept');
    }

    public function test_create_requires_name_and_project(): void
    {
        Livewire::test(CreateTask::class)
            ->fillForm(['name' => null, 'repo_profile_id' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'repo_profile_id' => 'required']);
    }

    public function test_create_task_sets_user_id_to_authenticated_user(): void
    {
        $profile = RepoProfile::factory()->create();

        Livewire::test(CreateTask::class)
            ->fillForm([
                'name' => 'User Task',
                'repo_profile_id' => $profile->id,
                'description' => 'Test',
                'auto_concept' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $task = Task::where('name', 'User Task')->first();
        $this->assertNotNull($task);
        $this->assertSame($this->user->id, $task->user_id);
    }

    public function test_list_shows_aggregated_cost_per_task(): void
    {
        $task = Task::factory()->create();
        $task->phaseRuns()->create([
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'cost_usd' => 0.1234,
            'input_tokens' => 1000,
            'output_tokens' => 500,
        ]);
        $task->phaseRuns()->create([
            'phase' => 'implement',
            'iteration' => 1,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'cost_usd' => 0.5,
            'input_tokens' => 2000,
            'output_tokens' => 1000,
        ]);

        Livewire::test(ListTasks::class)
            ->assertSuccessful()
            ->assertSee('$0.6234');
    }
}
