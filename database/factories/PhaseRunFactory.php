<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PhaseRun;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhaseRun>
 */
class PhaseRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'phase' => fake()->randomElement(['concept', 'implement', 'push', 'respond']),
            'iteration' => 1,
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'finished_at' => now(),
            'exit_code' => 0,
            'input_tokens' => fake()->numberBetween(100, 5000),
            'output_tokens' => fake()->numberBetween(50, 2000),
        ];
    }

    public function running(): static
    {
        return $this->state([
            'status' => 'running',
            'finished_at' => null,
            'exit_code' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => 'failed',
            'exit_code' => 1,
        ]);
    }
}
