<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Jobs\ProcessIncomingIssueJob;
use App\Models\TaskProviderBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IssueWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private TaskProviderBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitHub,
            'mode' => TaskProviderMode::Webhook,
            'sync_status' => TaskProviderSyncStatus::Active,
            'webhook_secret' => 'test-secret',
            'external_project_ref' => 'acme/widget',
        ]);
    }

    /**
     * Send a raw webhook request with explicit body and headers, bypassing postJson's
     * intermediate encoding. This ensures the signature is computed over the exact
     * bytes that getContent() will return in the controller.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers  Symfony server-var format (HTTP_*)
     */
    private function sendWebhook(string $kind, string $bindingId, array $data, array $headers = []): TestResponse
    {
        $rawBody = json_encode($data);

        return $this->call(
            'POST',
            "/webhooks/issues/{$kind}/{$bindingId}",
            [],
            [],
            [],
            array_merge(['CONTENT_TYPE' => 'application/json'], $headers),
            $rawBody,
        );
    }

    private function githubSig(string $body, string $secret = 'test-secret'): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public function test_valid_signature_dispatches_job(): void
    {
        $data = ['action' => 'opened', 'id' => 1, 'title' => 'Bug'];
        $rawBody = json_encode($data);
        $sig = $this->githubSig($rawBody);

        $response = $this->sendWebhook('github', $this->binding->id, $data, [
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessIncomingIssueJob::class);
    }

    public function test_invalid_signature_returns_401(): void
    {
        $response = $this->sendWebhook('github', $this->binding->id, ['id' => 1], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalidsig',
        ]);

        $response->assertStatus(401);
        Queue::assertNotPushed(ProcessIncomingIssueJob::class);
    }

    public function test_missing_signature_returns_401(): void
    {
        $response = $this->sendWebhook('github', $this->binding->id, ['id' => 1]);

        $response->assertStatus(401);
    }

    public function test_unknown_kind_returns_400(): void
    {
        $rawBody = json_encode(['id' => 1]);

        $response = $this->call(
            'POST',
            "/webhooks/issues/unknown/{$this->binding->id}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $rawBody,
        );

        $response->assertStatus(400);
    }

    public function test_duplicate_delivery_id_is_skipped(): void
    {
        $data = ['action' => 'opened', 'id' => 2];
        $rawBody = json_encode($data);
        $sig = $this->githubSig($rawBody);
        $deliveryId = 'abc-123-delivery';

        $this->sendWebhook('github', $this->binding->id, $data, [
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
            'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
        ])->assertStatus(200);

        $this->sendWebhook('github', $this->binding->id, $data, [
            'HTTP_X_HUB_SIGNATURE_256' => $sig,
            'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
        ])->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class, 1);
    }

    public function test_binding_without_secret_returns_401(): void
    {
        $binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitHub,
            'webhook_secret' => null,
        ]);

        $rawBody = json_encode(['id' => 1]);
        $response = $this->call(
            'POST',
            "/webhooks/issues/github/{$binding->id}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=anything'],
            $rawBody,
        );

        $response->assertStatus(401);
    }
}
