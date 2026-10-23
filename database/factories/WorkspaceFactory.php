<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        $slug = Workspace::slugify(fake()->unique()->words(2, true)).'-'.Str::lower(Str::random(4));

        return [
            'slug' => $slug,
            'name' => fake()->words(2, true),
            'created_by' => User::factory(),
            'repo_url' => 'https://github.com/test-org/test-repo',
            'token' => config('argos.factories.github_token'),
            'platform' => 'github',
            'auth_method' => 'pat',
            'connected_account_id' => null,
            'default_branch' => 'main',
            'status' => WorkspaceStatus::Running,
            'daemon_token' => Str::random(48),
        ];
    }

    public function stopped(): static
    {
        return $this->state(['status' => WorkspaceStatus::Stopped]);
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

    /**
     * @param  array<string, array<string, string>>  $config
     */
    public function withServiceConfig(array $config): static
    {
        return $this->state(['worker_service_config' => $config]);
    }
}
