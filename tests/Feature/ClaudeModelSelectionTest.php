<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentName;
use App\Enums\ClaudeModel;
use App\Enums\WorkflowStatus;
use App\Filament\Admin\Resources\RepoProfileResource\Pages\EditRepoProfile;
use App\Filament\Admin\Resources\TaskResource\Pages\CreateTask;
use App\Models\RepoProfile;
use App\Models\Task;
use App\Models\User;
use App\Services\Workflow\PhaseCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class ClaudeModelSelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_repo_profile_edit_form_renders_model_fields(): void
    {
        $profile = RepoProfile::factory()->create([
            'platform' => 'github',
            'model_concept' => ClaudeModel::Sonnet46->value,
            'model_implement' => ClaudeModel::Haiku45->value,
        ]);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->assertFormFieldExists('model_concept')
            ->assertFormFieldExists('model_implement');
    }

    public function test_repo_profile_model_fields_are_saved(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $profile = RepoProfile::factory()->create(['platform' => 'github']);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->fillForm([
                'model_concept' => ClaudeModel::Sonnet46->value,
                'model_implement' => ClaudeModel::Haiku45->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('repo_profiles', [
            'id' => $profile->id,
            'model_concept' => ClaudeModel::Sonnet46->value,
            'model_implement' => ClaudeModel::Haiku45->value,
        ]);
    }

    public function test_repo_profile_model_fields_can_be_cleared(): void
    {
        Saloon::fake([
            'api.github.com/repos/test-org/test-repo/branches*' => MockResponse::make([['name' => 'main']]),
        ]);

        $profile = RepoProfile::factory()->create([
            'platform' => 'github',
            'model_concept' => ClaudeModel::Sonnet46->value,
        ]);

        Livewire::test(EditRepoProfile::class, ['record' => $profile->getKey()])
            ->fillForm(['model_concept' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('repo_profiles', [
            'id' => $profile->id,
            'model_concept' => null,
        ]);
    }

    public function test_task_create_form_renders_model_fields(): void
    {
        RepoProfile::factory()->create();

        Livewire::test(CreateTask::class)
            ->assertFormFieldExists('model_concept')
            ->assertFormFieldExists('model_implement');
    }

    public function test_task_model_fields_are_saved(): void
    {
        $profile = RepoProfile::factory()->create();

        Livewire::test(CreateTask::class)
            ->fillForm([
                'name' => 'Test Task',
                'repo_profile_id' => $profile->id,
                'description' => 'Test description',
                'model_concept' => ClaudeModel::Haiku45->value,
                'model_implement' => ClaudeModel::Sonnet46->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tasks', [
            'name' => 'Test Task',
            'model_concept' => ClaudeModel::Haiku45->value,
            'model_implement' => ClaudeModel::Sonnet46->value,
        ]);
    }

    public function test_phase_runner_resolves_concept_model_from_task(): void
    {
        $profile = RepoProfile::factory()->create([
            'model_concept' => ClaudeModel::Sonnet46->value,
        ]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'model_concept' => ClaudeModel::Haiku45->value,
        ]);

        $this->assertSame(ClaudeModel::Haiku45->value, $task->modelForPhase('concept'));
    }

    public function test_phase_runner_resolves_respond_model_based_on_concept_review_status(): void
    {
        $profile = RepoProfile::factory()->create([
            'model_concept' => ClaudeModel::Sonnet46->value,
            'model_implement' => ClaudeModel::Haiku45->value,
        ]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::ConceptReview,
        ]);

        $model = app(PhaseCommandBuilder::class)->resolveModel($task, AgentName::ClaudeCode, 'respond');

        $this->assertSame(ClaudeModel::Sonnet46->value, $model);
    }

    public function test_phase_runner_resolves_respond_model_based_on_in_review_status(): void
    {
        $profile = RepoProfile::factory()->create([
            'model_concept' => ClaudeModel::Opus47->value,
            'model_implement' => ClaudeModel::Haiku45->value,
        ]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'workflow_status' => WorkflowStatus::InReview,
        ]);

        $model = app(PhaseCommandBuilder::class)->resolveModel($task, AgentName::ClaudeCode, 'respond');

        $this->assertSame(ClaudeModel::Haiku45->value, $model);
    }

    public function test_commit_message_always_uses_haiku(): void
    {
        $task = Task::factory()->create();
        $model = app(PhaseCommandBuilder::class)->resolveModel($task, AgentName::ClaudeCode, 'commit-message');

        $this->assertSame(ClaudeModel::Haiku45->value, $model);
    }
}
