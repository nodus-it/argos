<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Admin\Resources\TaskResource\Pages\ListTasks;
use App\Jobs\RunPhaseJob;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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
            ->assertSee('Entwurf');
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
        Bus::assertDispatched(RunPhaseJob::class, fn ($job) => $job->phase === 'concept');
    }

    public function test_create_requires_name_and_project(): void
    {
        Livewire::test(CreateTask::class)
            ->fillForm(['name' => null, 'repo_profile_id' => null])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'repo_profile_id' => 'required']);
    }

    public function test_table_concept_action_dispatches_job(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ListTasks::class)
            ->callAction(TestAction::make('concept')->table($task))
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($job) => $job->phase === 'concept' && $job->taskId === $task->id);
    }

    public function test_table_implement_action_dispatches_job(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ListTasks::class)
            ->callAction(TestAction::make('implement')->table($task))
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($job) => $job->phase === 'implement');
    }

    public function test_table_push_action_dispatches_job(): void
    {
        $task = Task::factory()->create();

        Livewire::test(ListTasks::class)
            ->callAction(TestAction::make('push')->table($task))
            ->assertNotified();

        Bus::assertDispatched(RunPhaseJob::class, fn ($job) => $job->phase === 'push');
    }

    public function test_phase_action_warns_when_already_running(): void
    {
        $task = Task::factory()->create();
        $task->phaseRuns()->create([
            'phase' => 'concept',
            'iteration' => 1,
            'status' => 'running',
            'started_at' => now(),
        ]);

        Livewire::test(ListTasks::class)
            ->callAction(TestAction::make('concept')->table($task))
            ->assertNotified();

        Bus::assertNotDispatched(RunPhaseJob::class);
    }
}
