<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExternalIssueLink>
 */
class ExternalIssueLinkFactory extends Factory
{
    public function definition(): array
    {
        $issueNumber = fake()->numberBetween(1, 9999);

        return [
            'task_provider_binding_id' => TaskProviderBinding::factory(),
            'task_id' => null,
            'external_id' => (string) $issueNumber,
            'external_url' => "https://github.com/test-org/test-repo/issues/{$issueNumber}",
            'last_synced_at' => now(),
            'signature' => null,
        ];
    }

    public function withTask(): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_id' => Task::factory(),
        ]);
    }
}
