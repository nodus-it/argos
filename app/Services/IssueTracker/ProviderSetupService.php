<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\Concerns\ParsesExternalProjectRef;

final class ProviderSetupService
{
    use ParsesExternalProjectRef;

    public function __construct(private readonly IssueTrackerRegistry $registry) {}

    /**
     * Configure the binding's sync mechanism.
     *
     * - Webhook mode: calls registerWebhook and persists the returned id + secret.
     * - Poll mode: marks the binding as Active immediately (no API call needed).
     * - Disabled mode: marks the binding as Pending and clears webhook data.
     *
     * GitHub and GitLab OAuth scopes ('repo' / 'api') already cover webhook
     * management — no additional OAuth scopes are required when calling setup().
     *
     * The binding's credential (OAuth account or PAT) is resolved by the
     * registry from the binding itself, so no token source is passed here.
     *
     * @throws \Throwable when registerWebhook fails; last_error is recorded before the rethrow.
     */
    public function setup(TaskProviderBinding $binding): void
    {
        $binding->last_error = null;

        if ($binding->mode === TaskProviderMode::Disabled) {
            $binding->sync_status = TaskProviderSyncStatus::Pending;
            $binding->webhook_id = null;
            $binding->webhook_secret = null;
            $binding->save();

            return;
        }

        if ($binding->mode === TaskProviderMode::Poll) {
            $binding->sync_status = TaskProviderSyncStatus::Active;
            $binding->webhook_id = null;
            $binding->webhook_secret = null;
            $binding->save();

            return;
        }

        // Webhook mode
        try {
            $tracker = $this->registry->make($binding->kind, $binding);
            [$owner, $project] = $this->parseRef($binding->external_project_ref ?? '');

            $secret = bin2hex(random_bytes(20));
            $webhookUrl = $this->buildWebhookUrl($binding);

            $result = $tracker->registerWebhook($owner, $project, $webhookUrl, $secret);

            $binding->webhook_id = (string) ($result['id'] ?? '');
            $binding->webhook_secret = $secret;
            $binding->sync_status = TaskProviderSyncStatus::Active;
            $binding->save();
        } catch (\Throwable $e) {
            $binding->last_error = $e->getMessage();
            $binding->save();

            throw $e;
        }
    }

    private function buildWebhookUrl(TaskProviderBinding $binding): string
    {
        return rtrim((string) config('app.url'), '/')."/webhooks/issues/{$binding->kind->value}/{$binding->id}";
    }

    /**
     * @return array{string, string}
     */
}
