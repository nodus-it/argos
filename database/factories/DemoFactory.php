<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DemoStatus;
use App\Models\Demo;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Demo>
 */
class DemoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'status' => DemoStatus::Building,
            'url' => null,
            'compose_project' => 'demo-'.fake()->lexify('??????????'),
            'ttl_until' => now()->addDay(),
            'build_log' => null,
        ];
    }

    public function live(string $url = 'https://demo-x.127.0.0.1.nip.io:8080'): static
    {
        return $this->state(['status' => DemoStatus::Live, 'url' => $url]);
    }

    public function failed(): static
    {
        return $this->state(['status' => DemoStatus::Failed]);
    }
}
