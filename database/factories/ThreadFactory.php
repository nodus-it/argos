<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClaudeModel;
use App\Enums\PermissionMode;
use App\Enums\ThreadStatus;
use App\Models\AgentCredential;
use App\Models\Thread;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Thread>
 */
class ThreadFactory extends Factory
{
    protected $model = Thread::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'agent_credential_id' => AgentCredential::factory(),
            'title' => fake()->sentence(3),
            'status' => ThreadStatus::Closed,
            'permission_mode' => PermissionMode::Default,
            'model' => ClaudeModel::Sonnet46->value,
        ];
    }

    public function open(): static
    {
        return $this->state(['session_open' => true, 'status' => ThreadStatus::Idle]);
    }
}
