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
    /**
     * Monotonic issue-number source. A random number collided on the
     * (task_provider_binding_id, external_id) unique index whenever a test
     * created several links on the same binding — a flaky CI failure. A
     * per-process sequence keeps the default external_id collision-free.
     */
    private static int $issueSequence = 0;

    public function definition(): array
    {
        $issueNumber = ++self::$issueSequence;

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
