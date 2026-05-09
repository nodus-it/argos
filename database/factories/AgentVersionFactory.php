<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentName;
use App\Models\AgentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentVersion>
 */
class AgentVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_name' => AgentName::ClaudeCode,
            'installed_version' => '1.0.0',
            'upstream_version' => '1.0.0',
            'has_update' => false,
            'last_checked_at' => now(),
        ];
    }

    public function withUpdate(): static
    {
        return $this->state([
            'installed_version' => '1.0.0',
            'upstream_version' => '1.1.0',
            'has_update' => true,
        ]);
    }
}
