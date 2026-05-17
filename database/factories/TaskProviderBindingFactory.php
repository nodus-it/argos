<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\RepoProfile;
use App\Models\TaskProviderBinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskProviderBinding>
 */
class TaskProviderBindingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'repo_profile_id' => RepoProfile::factory(),
            'kind' => TaskProviderKind::GitHub,
            'mode' => TaskProviderMode::Disabled,
            'connected_account_id' => null,
            'external_project_ref' => 'test-org/test-repo',
            'filters' => null,
            'webhook_id' => null,
            'webhook_secret' => null,
            'last_polled_at' => null,
            'last_error' => null,
            'sync_status' => TaskProviderSyncStatus::Pending,
        ];
    }

    public function active(): static
    {
        return $this->state([
            'mode' => TaskProviderMode::Poll,
            'sync_status' => TaskProviderSyncStatus::Active,
        ]);
    }

    public function webhook(): static
    {
        return $this->state([
            'mode' => TaskProviderMode::Webhook,
            'webhook_id' => '123',
            'webhook_secret' => 'secret-value',
            'sync_status' => TaskProviderSyncStatus::Active,
        ]);
    }
}
