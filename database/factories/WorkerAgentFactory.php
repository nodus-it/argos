<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkerImageEntityStatus;
use App\Models\WorkerAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkerAgent>
 */
class WorkerAgentFactory extends Factory
{
    public function definition(): array
    {
        $slug = strtolower(fake()->unique()->lexify('agent-?????'));

        return [
            'name' => $slug,
            'label' => ucfirst($slug),
            'is_builtin' => false,
            'install_script' => "#!/usr/bin/env bash\nnpm install -g @example/agent\n",
            'runner_class' => 'ExampleRunner',
            'npm_pkg' => '@example/agent',
            'pinned_version' => '1.0.0',
            'requires_stack_capabilities' => ['node'],
            'config_schema' => null,
            'status' => WorkerImageEntityStatus::Active,
            'has_update' => false,
            'created_by_user_id' => null,
        ];
    }

    public function builtin(): static
    {
        return $this->state(['is_builtin' => true]);
    }

    public function claudeCode(): static
    {
        return $this->state([
            'name' => 'claude-code',
            'label' => 'Claude Code',
            'is_builtin' => true,
            'runner_class' => 'ClaudeCodeRunner',
            'npm_pkg' => '@anthropic-ai/claude-code',
            'requires_stack_capabilities' => ['node'],
        ]);
    }
}
