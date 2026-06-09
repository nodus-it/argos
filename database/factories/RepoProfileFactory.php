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
            'name' => fake()->unique()->words(2, true),
            'url' => 'https://github.com/test-org/test-repo',
            'token' => config('argos.factories.github_token'),
            'platform' => 'github',
            'auth_method' => 'pat',
            'connected_account_id' => null,
            'default_branch' => 'main',
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

    /**
     * @param  array<int, array{host: string, username?: string|null, token: string}>  $registries
     */
    public function withComposerRegistries(array $registries): static
    {
        return $this->state(['composer_registries' => $registries]);
    }

    /**
     * @param  array<int, array{name: string, value: string}>  $env
     */
    public function withWorkerEnv(array $env): static
    {
        return $this->state(['worker_env' => $env]);
    }

    /**
     * @param  array<int, string>  $services
     */
    public function withBackingServices(array $services): static
    {
        return $this->state(['worker_services' => $services]);
    }
}
