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

class GitHubIssueWebhookTest extends TestCase
{
    use RefreshDatabase;

    private TaskProviderBinding $binding;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitHub,
            'mode' => TaskProviderMode::Webhook,
            'sync_status' => TaskProviderSyncStatus::Active,
            'webhook_secret' => self::SECRET,
            'external_project_ref' => 'acme/widget',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function issueEnvelope(array $data = []): array
    {
        return array_merge([
            'action' => 'opened',
            'issue' => [
                'id' => 101,
                'number' => 1,
                'title' => 'Something is broken',
                'body' => 'Steps to reproduce…',
                'state' => 'open',
                'labels' => [],
                'html_url' => 'https://github.com/acme/widget/issues/1',
            ],
            'repository' => ['full_name' => 'acme/widget'],
            'sender' => ['login' => 'reporter'],
        ], $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $extraHeaders  Symfony server-var format (HTTP_*)
     */
    private function sendWebhook(array $data, array $extraHeaders = [], string $eventType = 'issues'): TestResponse
    {
        $rawBody = json_encode($data);
        $sig = 'sha256='.hash_hmac('sha256', $rawBody, self::SECRET);

        return $this->call(
            'POST',
            "/webhooks/issues/github/{$this->binding->id}",
            [],
            [],
            [],
            array_merge(
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_HUB_SIGNATURE_256' => $sig,
                    'HTTP_X_GITHUB_EVENT' => $eventType,
                ],
                $extraHeaders,
            ),
            $rawBody,
        );
    }

    // ── dispatch & ingest ────────────────────────────────────────────────────

    public function test_valid_issues_event_dispatches_job(): void
    {
        Queue::fake();

        $this->sendWebhook($this->issueEnvelope())->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class);
    }

    public function test_end_to_end_creates_external_link_and_task(): void
    {
        $envelope = $this->issueEnvelope();

        // Run the job synchronously via the job's handle method
        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, 'issues');
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        // external_id is the addressable issue number (1), not the global id (101).
        $this->assertDatabaseHas(ExternalIssueLink::class, [
            'task_provider_binding_id' => $this->binding->id,
            'external_id' => '1',
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
            "/webhooks/issues/github/{$this->binding->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalidsig',
                'HTTP_X_GITHUB_EVENT' => 'issues',
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
        $deliveryId = 'gh-delivery-abc-123';

        $this->sendWebhook($envelope, ['HTTP_X_GITHUB_DELIVERY' => $deliveryId])->assertStatus(200);
        $this->sendWebhook($envelope, ['HTTP_X_GITHUB_DELIVERY' => $deliveryId])->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class, 1);
    }

    // ── non-relevant events ──────────────────────────────────────────────────

    public function test_push_event_is_ignored_with_200(): void
    {
        Queue::fake();

        $this->sendWebhook(['ref' => 'refs/heads/main', 'commits' => []], [], 'push')->assertStatus(200);

        Queue::assertNotPushed(ProcessIncomingIssueJob::class);
    }

    public function test_ping_event_is_ignored_with_200(): void
    {
        Queue::fake();

        $this->sendWebhook(['zen' => 'Keep it logically awesome.'], [], 'ping')->assertStatus(200);

        Queue::assertNotPushed(ProcessIncomingIssueJob::class);
    }

    // ── issue_comment is accepted but yields no task (normalizeWebhookPayload returns []) ──

    public function test_issue_comment_event_dispatches_job_but_job_is_noop(): void
    {
        Queue::fake();

        // issue_comment events pass the event-type check (allowed) but are filtered inside the job
        $envelope = [
            'action' => 'created',
            'issue' => ['id' => 101, 'title' => 'Bug', 'state' => 'open'],
            'comment' => ['id' => 5, 'body' => 'Thanks for reporting'],
        ];

        $this->sendWebhook($envelope, [], 'issue_comment')->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class);
    }

    public function test_issue_comment_job_does_not_create_task(): void
    {
        $envelope = [
            'action' => 'created',
            'issue' => ['id' => 101, 'title' => 'Bug', 'state' => 'open'],
            'comment' => ['id' => 5, 'body' => 'Thanks'],
        ];

        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, 'issue_comment');
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        $this->assertDatabaseCount(ExternalIssueLink::class, 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    // ── PR envelope is discarded ──────────────────────────────────────────────

    public function test_pr_envelope_job_does_not_create_task(): void
    {
        $envelope = [
            'action' => 'opened',
            'issue' => [
                'id' => 50,
                'title' => 'A pull request',
                'state' => 'open',
                'pull_request' => ['url' => 'https://api.github.com/repos/acme/widget/pulls/50'],
            ],
        ];

        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, 'issues');
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        $this->assertDatabaseCount(ExternalIssueLink::class, 0);
        $this->assertDatabaseCount('tasks', 0);
    }
}
