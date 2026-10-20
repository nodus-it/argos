<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceStatus;
use App\Models\RepoProfile;
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
            'repo_profile_id' => RepoProfile::factory(),
            'created_by' => User::factory(),
            'name' => fake()->words(3, true),
            'base_branch' => 'main',
            'branch' => 'ws/'.$slug,
            'status' => WorkspaceStatus::Running,
            'daemon_token' => Str::random(48),
        ];
    }

    public function stopped(): static
    {
        return $this->state(['status' => WorkspaceStatus::Stopped]);
    }
}
