<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentName;
use App\Enums\WorkerImageBuildStatus;
use App\Models\WorkerImageBuild;
use App\Models\WorkerStack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerImageBuild>
 */
class WorkerImageBuildFactory extends Factory
{
    public function definition(): array
    {
        return [
            'worker_stack_id' => WorkerStack::factory(),
            'agent_name' => AgentName::ClaudeCode,
            'tag' => 'argos-worker:'.fake()->unique()->lexify('?????'),
            'status' => WorkerImageBuildStatus::Queued,
            'build_log' => null,
            'built_at' => null,
            'size_bytes' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state([
            'status' => WorkerImageBuildStatus::Ready,
            'built_at' => now(),
            'size_bytes' => 1_200_000_000,
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => WorkerImageBuildStatus::Failed,
            'build_log' => 'docker build failed: dependency error',
        ]);
    }
}
