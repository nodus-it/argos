<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkflowStatus;
use App\Models\RepoProfile;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'repo_profile_id' => RepoProfile::factory(),
            'description' => fake()->sentence(),
            'workflow_status' => WorkflowStatus::Draft,
            'current_phase' => null,
            'current_status' => null,
            'feature_branch' => null,
            'pr_url' => null,
            'auto_concept' => false,
        ];
    }

    public function inReview(): static
    {
        return $this->state([
            'workflow_status' => WorkflowStatus::InReview,
            'current_phase' => 'push',
            'current_status' => 'completed',
            'feature_branch' => 'argos/test-task',
            'pr_url' => 'https://github.com/test-org/test-repo/pull/1',
        ]);
    }

    public function conceptReady(): static
    {
        return $this->state([
            'workflow_status' => WorkflowStatus::ConceptReview,
            'current_phase' => 'concept',
            'current_status' => 'completed',
            'concept_md' => "# Konzept\n\nTest-Konzept Inhalt.",
        ]);
    }

    public function completed(): static
    {
        return $this->state(['workflow_status' => WorkflowStatus::Completed]);
    }

    public function failed(): static
    {
        return $this->state([
            'workflow_status' => WorkflowStatus::Failed,
            'current_status' => 'failed',
        ]);
    }
}
