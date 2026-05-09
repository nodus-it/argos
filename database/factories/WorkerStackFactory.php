<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkerImageEntityStatus;
use App\Models\WorkerStack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerStack>
 */
class WorkerStackFactory extends Factory
{
    public function definition(): array
    {
        $slug = strtolower(fake()->unique()->lexify('stack-?????'));

        return [
            'name' => $slug,
            'label' => ucfirst($slug),
            'is_builtin' => false,
            'base_image' => 'php:8.4-cli-bookworm',
            'dockerfile_body' => "FROM php:8.4-cli-bookworm\nRUN apt-get update\n",
            'common_tools' => ['git', 'jq', 'curl'],
            'capabilities' => ['php', 'composer'],
            'status' => WorkerImageEntityStatus::Active,
            'has_update' => false,
            'created_by_user_id' => null,
        ];
    }

    public function builtin(): static
    {
        return $this->state(['is_builtin' => true]);
    }

    public function deprecated(): static
    {
        return $this->state(['status' => WorkerImageEntityStatus::Deprecated]);
    }
}
