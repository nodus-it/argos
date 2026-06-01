<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IntegrationProvider;
use App\Models\ProviderOAuthConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderOAuthConfig>
 */
class ProviderOAuthConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => IntegrationProvider::GitHub,
            'instance_url' => '',
            'client_id' => 'cid-'.fake()->lexify('????????'),
            'client_secret' => 'sec-'.fake()->lexify('????????????'),
            'enabled' => true,
        ];
    }

    public function provider(IntegrationProvider $provider): static
    {
        return $this->state(['provider' => $provider]);
    }

    public function instance(string $instanceUrl): static
    {
        return $this->state(['instance_url' => $instanceUrl]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
