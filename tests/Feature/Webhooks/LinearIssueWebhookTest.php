<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Jobs\ProcessIncomingIssueJob;
use App\Models\ExternalIssueLink;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueIngestService;
use App\Services\IssueTracker\IssueTrackerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LinearIssueWebhookTest extends TestCase
{
    use RefreshDatabase;

    private TaskProviderBinding $binding;

    private const SECRET = 'test-linear-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::Linear,
            'mode' => TaskProviderMode::Webhook,
            'sync_status' => TaskProviderSyncStatus::Active,
            'webhook_secret' => self::SECRET,
            'external_project_ref' => 'ENG',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function issueEnvelope(array $data = []): array
    {
        return array_merge([
            'type' => 'Issue',
            'action' => 'create',
            'organizationId' => 'org-uuid-123',
            'createdAt' => '2026-05-21T12:00:00.000Z',
            'data' => [
                'id' => 'issue-uuid-101',
                'title' => 'Something is broken',
                'description' => 'Steps to reproduce…',
                'url' => 'https://linear.app/eng/issue/ENG-1',
                'state' => ['name' => 'Todo'],
                'labels' => [],
            ],
        ], $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $extraHeaders
     */
    private function sendWebhook(array $data, array $extraHeaders = []): TestResponse
    {
        $rawBody = json_encode($data);
        $sig = hash_hmac('sha256', $rawBody, self::SECRET);

        return $this->call(
            'POST',
            "/webhooks/issues/linear/{$this->binding->id}",
            [],
            [],
            [],
            array_merge(
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_LINEAR_SIGNATURE' => $sig,
                ],
                $extraHeaders,
            ),
            $rawBody,
        );
    }

    // ── dispatch ─────────────────────────────────────────────────────────────

    public function test_valid_issue_event_dispatches_job(): void
    {
        Queue::fake();

        $this->sendWebhook($this->issueEnvelope())->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class);
    }

    // ── end-to-end ───────────────────────────────────────────────────────────

    public function test_end_to_end_creates_external_link_and_task(): void
    {
        $envelope = $this->issueEnvelope();

        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, null);
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        $this->assertDatabaseHas(ExternalIssueLink::class, [
            'task_provider_binding_id' => $this->binding->id,
            'external_id' => 'issue-uuid-101',
        ]);

        $link = ExternalIssueLink::where('task_provider_binding_id', $this->binding->id)->first();
        $this->assertNotNull($link->task_id, 'A task should be created for the ingested issue');

        $this->assertDatabaseHas('tasks', [
            'id' => $link->task_id,
            'name' => 'Something is broken',
        ]);
    }

    // ── invalid signature ────────────────────────────────────────────────────

    public function test_invalid_signature_returns_401(): void
    {
        Queue::fake();

        $rawBody = json_encode($this->issueEnvelope());
        $response = $this->call(
            'POST',
            "/webhooks/issues/linear/{$this->binding->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_LINEAR_SIGNATURE' => 'invalidsignaturehex',
            ],
            $rawBody,
        );

        $response->assertStatus(401);
        Queue::assertNotPushed(ProcessIncomingIssueJob::class);
    }

    // ── duplicate delivery ───────────────────────────────────────────────────

    public function test_duplicate_delivery_id_is_skipped(): void
    {
        Queue::fake();

        $envelope = $this->issueEnvelope();
        $deliveryId = 'linear-delivery-abc-123';

        $this->sendWebhook($envelope, ['HTTP_LINEAR_DELIVERY' => $deliveryId])->assertStatus(200);
        $this->sendWebhook($envelope, ['HTTP_LINEAR_DELIVERY' => $deliveryId])->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class, 1);
    }

    // ── non-issue events are silently discarded by the job ───────────────────

    public function test_comment_envelope_job_does_not_create_task(): void
    {
        $envelope = [
            'type' => 'Comment',
            'action' => 'create',
            'data' => ['id' => 'comment-uuid-1', 'body' => 'Thanks for reporting'],
        ];

        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, null);
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        $this->assertDatabaseCount(ExternalIssueLink::class, 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_project_envelope_job_does_not_create_task(): void
    {
        $envelope = [
            'type' => 'Project',
            'action' => 'create',
            'data' => ['id' => 'project-uuid-1', 'name' => 'New Project'],
        ];

        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, null);
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        $this->assertDatabaseCount(ExternalIssueLink::class, 0);
        $this->assertDatabaseCount('tasks', 0);
    }
}
