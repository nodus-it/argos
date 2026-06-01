<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Enums\ProviderCredentialStatus;
use App\Models\ProviderCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderCredential>
 */
class ProviderCredentialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'label' => fake()->unique()->words(2, true),
            'provider' => IntegrationProvider::GitHub,
            'instance_url' => null,
            'token' => 'pat-'.fake()->lexify('????????????????'),
            'scopes_hint' => 'repo',
            'status' => ProviderCredentialStatus::Active,
            'last_validated_at' => null,
        ];
    }

    public function provider(IntegrationProvider $provider): static
    {
        return $this->state(['provider' => $provider]);
    }

    public function expired(): static
    {
        return $this->state(['status' => ProviderCredentialStatus::Expired]);
    }

    public function revoked(): static
    {
        return $this->state(['status' => ProviderCredentialStatus::Revoked]);
    }
}
