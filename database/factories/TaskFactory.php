<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClaudeModel;
use App\Enums\PermissionMode;
use App\Enums\SessionStatus;
use App\Enums\TaskStatus;
use App\Models\AgentCredential;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        $slug = Workspace::slugify(fake()->unique()->words(2, true), 20).'-'.Str::lower(Str::random(4));

        return [
            'workspace_id' => Workspace::factory(),
            'slug' => $slug,
            'owner_user_id' => User::factory(),
            'agent_credential_id' => AgentCredential::factory(),
            'title' => fake()->sentence(3),
            'status' => TaskStatus::Active,
            'session_status' => SessionStatus::Closed,
            'permission_mode' => PermissionMode::Default,
            'model' => ClaudeModel::Sonnet46->value,
            'branch' => 'argos/'.$slug,
            'base_branch' => 'main',
        ];
    }

    public function open(): static
    {
        return $this->state(['session_open' => true, 'session_status' => SessionStatus::Idle]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(['owner_user_id' => $user->id, 'created_by' => $user->id]);
    }
}
