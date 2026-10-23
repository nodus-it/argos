<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\AuthMethod;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\TaskProviderBinding;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Credentials\CredentialRefResolver;
use App\Services\EntityService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Issue trackers bound to a workspace (settings → Integrations). The form's
 * credential picker value becomes the stored columns here; a connected
 * account is only accepted when it is the saving person's own — or the one
 * the binding already uses.
 */
class TaskProviderBindingService extends EntityService
{
    public function __construct(private readonly CredentialRefResolver $refs) {}

    protected function model(): string
    {
        return TaskProviderBinding::class;
    }

    /** @param  array<string, mixed>  $data  form state incl. `credential_ref` */
    public function createFor(Workspace $workspace, User $by, array $data): TaskProviderBinding
    {
        Gate::forUser($by)->authorize('update', $workspace);
        /** @var TaskProviderBinding $binding */
        $binding = $this->create([...$this->columns($data, $by, null), 'workspace_id' => $workspace->id]);

        return $binding;
    }

    /** @param  array<string, mixed>  $data  form state incl. `credential_ref` */
    public function updateBy(TaskProviderBinding $binding, User $by, array $data): TaskProviderBinding
    {
        Gate::forUser($by)->authorize('update', $binding->workspace);
        /** @var TaskProviderBinding $binding */
        $binding = $this->update($binding, $this->columns($data, $by, $binding));

        return $binding;
    }

    public function deleteBy(TaskProviderBinding $binding, User $by): void
    {
        Gate::forUser($by)->authorize('update', $binding->workspace);
        $this->delete($binding);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data, User $by, ?TaskProviderBinding $current): array
    {
        $ref = is_string($data['credential_ref'] ?? null) ? $data['credential_ref'] : null;
        unset($data['credential_ref'], $data['workspace_id']);
        $columns = CredentialRefResolver::toColumns($ref);

        $accountId = $columns['connected_account_id'];
        if ($accountId !== null && $accountId !== $current?->connected_account_id && $this->refs->account($by, $ref) === null) {
            throw ValidationException::withMessages(['credential_ref' => __('task_providers.form.credential_not_yours')]);
        }
        if ($columns['provider_credential_id'] !== null && $this->refs->credential($ref) === null) {
            throw ValidationException::withMessages(['credential_ref' => __('validation.exists', ['attribute' => __('task_providers.form.credential')])]);
        }

        $data = [...$data, ...array_filter($columns, fn ($v) => $v !== null), 'connected_account_id' => $columns['connected_account_id'], 'provider_credential_id' => $columns['provider_credential_id']];

        // Custom (webhook) trackers: no provider credential, a secret of their own.
        if (($data['kind'] ?? null) === TaskProviderKind::Custom->value) {
            $data = [...$data, 'mode' => TaskProviderMode::Webhook->value, 'auth_method' => AuthMethod::Pat->value, 'sync_status' => TaskProviderSyncStatus::Active->value];
            if ($current === null) {
                $data['webhook_secret'] = bin2hex(random_bytes(24));
            } else {
                unset($data['webhook_secret']);
            }
        }

        return $data;
    }
}
