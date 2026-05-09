<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ClaudeModel;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskModelForPhaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_hardcoded_default_when_no_overrides_configured(): void
    {
        $task = Task::factory()->create();

        $this->assertSame(ClaudeModel::Opus47->value, $task->modelForPhase('concept'));
        $this->assertSame(ClaudeModel::Sonnet46->value, $task->modelForPhase('implement'));
    }

    public function test_repo_profile_override_takes_precedence_over_hardcoded_default(): void
    {
        $profile = RepoProfile::factory()->create([
            'model_concept' => ClaudeModel::Sonnet46->value,
            'model_implement' => ClaudeModel::Haiku45->value,
        ]);
        $task = Task::factory()->create(['repo_profile_id' => $profile->id]);

        $this->assertSame(ClaudeModel::Sonnet46->value, $task->modelForPhase('concept'));
        $this->assertSame(ClaudeModel::Haiku45->value, $task->modelForPhase('implement'));
    }

    public function test_task_override_takes_precedence_over_repo_profile(): void
    {
        $profile = RepoProfile::factory()->create([
            'model_concept' => ClaudeModel::Sonnet46->value,
            'model_implement' => ClaudeModel::Sonnet46->value,
        ]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'model_concept' => ClaudeModel::Haiku45->value,
            'model_implement' => ClaudeModel::Opus47->value,
        ]);

        $this->assertSame(ClaudeModel::Haiku45->value, $task->modelForPhase('concept'));
        $this->assertSame(ClaudeModel::Opus47->value, $task->modelForPhase('implement'));
    }

    public function test_task_override_only_for_concept_falls_back_to_profile_for_implement(): void
    {
        $profile = RepoProfile::factory()->create([
            'model_concept' => null,
            'model_implement' => ClaudeModel::Haiku45->value,
        ]);
        $task = Task::factory()->create([
            'repo_profile_id' => $profile->id,
            'model_concept' => ClaudeModel::Sonnet46->value,
            'model_implement' => null,
        ]);

        $this->assertSame(ClaudeModel::Sonnet46->value, $task->modelForPhase('concept'));
        $this->assertSame(ClaudeModel::Haiku45->value, $task->modelForPhase('implement'));
    }

    public function test_unknown_phase_returns_haiku_default(): void
    {
        $task = Task::factory()->create();

        $this->assertSame(ClaudeModel::Haiku45->value, $task->modelForPhase('respond'));
        $this->assertSame(ClaudeModel::Haiku45->value, $task->modelForPhase('push'));
    }

    public function test_task_without_repo_profile_uses_hardcoded_default(): void
    {
        $task = Task::factory()->create([
            'repo_profile_id' => null,
            'model_concept' => null,
            'model_implement' => null,
        ]);

        $this->assertSame(ClaudeModel::Opus47->value, $task->modelForPhase('concept'));
        $this->assertSame(ClaudeModel::Sonnet46->value, $task->modelForPhase('implement'));
    }
}
