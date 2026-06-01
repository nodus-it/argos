<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConnectedAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConnectedAccount>
 */
class ConnectedAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'github',
            'provider_id' => (string) fake()->numerify('########'),
            'token' => fake()->sha256(),
            'refresh_token' => null,
            'expires_at' => null,
            'name' => fake()->name(),
            'nickname' => fake()->userName(),
            'avatar' => fake()->imageUrl(),
            'instance_url' => '',
        ];
    }
}
