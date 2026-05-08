<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AgentCredentialStatus;
use App\Enums\AgentName;
use App\Models\AgentCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentCredential>
 */
class AgentCredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_name' => AgentName::ClaudeCode,
            'name' => fake()->words(2, true),
            'credentials' => [
                'token' => 'oat-'.fake()->lexify('????????????'),
            ],
            'status' => AgentCredentialStatus::Active,
            'last_validated_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(['status' => AgentCredentialStatus::Expired]);
    }

    public function revoked(): static
    {
        return $this->state(['status' => AgentCredentialStatus::Revoked]);
    }
}
