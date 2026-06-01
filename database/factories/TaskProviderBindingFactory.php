<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuthMethod;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\ProviderCredential;
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
            'auth_method' => AuthMethod::OAuth,
            'connected_account_id' => null,
            'provider_credential_id' => null,
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

    /** Authenticate the binding via a Personal Access Token instead of OAuth. */
    public function pat(?ProviderCredential $credential = null): static
    {
        return $this->state([
            'auth_method' => AuthMethod::Pat,
            'connected_account_id' => null,
            'provider_credential_id' => $credential?->id ?? ProviderCredential::factory(),
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
