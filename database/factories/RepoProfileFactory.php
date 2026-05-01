<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RepoProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepoProfile>
 */
class RepoProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'url' => 'https://github.com/test-org/test-repo',
            'token' => env('GITHUB_TOKEN', 'test-token'),
            'platform' => 'github',
            'default_branch' => 'main',
            'worker_image' => null,
            'auto_concept' => false,
            'auto_pr' => false,
        ];
    }

    public function withAutoConcept(): static
    {
        return $this->state(['auto_concept' => true]);
    }

    public function withAutoPr(): static
    {
        return $this->state(['auto_pr' => true]);
    }
}
