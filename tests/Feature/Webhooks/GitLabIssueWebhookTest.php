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

class GitLabIssueWebhookTest extends TestCase
{
    use RefreshDatabase;

    private TaskProviderBinding $binding;

    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitLab,
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
            'object_kind' => 'issue',
            'object_attributes' => [
                'id' => 101,
                'iid' => 1,
                'title' => 'Something is broken',
                'description' => 'Steps to reproduce…',
                'state' => 'opened',
                'web_url' => 'https://gitlab.com/acme/widget/-/issues/1',
            ],
            'labels' => [],
            'project' => ['path_with_namespace' => 'acme/widget'],
        ], $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $extraHeaders  Symfony server-var format (HTTP_*)
     */
    private function sendWebhook(array $data, array $extraHeaders = []): TestResponse
    {
        $rawBody = json_encode($data);

        return $this->call(
            'POST',
            "/webhooks/issues/gitlab/{$this->binding->id}",
            [],
            [],
            [],
            array_merge(
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_X_GITLAB_TOKEN' => self::SECRET,
                    'HTTP_X_GITLAB_EVENT' => 'Issue Hook',
                ],
                $extraHeaders,
            ),
            $rawBody,
        );
    }

    // ── dispatch & ingest ────────────────────────────────────────────────────

    public function test_valid_issue_hook_dispatches_job(): void
    {
        Queue::fake();

        $this->sendWebhook($this->issueEnvelope())->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class);
    }

    public function test_end_to_end_creates_external_link_and_task(): void
    {
        $envelope = $this->issueEnvelope();

        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, null);
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        // external_id is the addressable issue iid (1), not the global id (101).
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

    // ── invalid token ────────────────────────────────────────────────────────

    public function test_invalid_token_returns_401(): void
    {
        Queue::fake();

        $rawBody = json_encode($this->issueEnvelope());
        $response = $this->call(
            'POST',
            "/webhooks/issues/gitlab/{$this->binding->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITLAB_TOKEN' => 'wrong-token',
                'HTTP_X_GITLAB_EVENT' => 'Issue Hook',
            ],
            $rawBody,
        );

        $response->assertStatus(401);
        Queue::assertNotPushed(ProcessIncomingIssueJob::class);
    }

    // ── duplicate delivery ───────────────────────────────────────────────────

    public function test_duplicate_event_uuid_is_skipped(): void
    {
        Queue::fake();

        $envelope = $this->issueEnvelope();
        $uuid = 'gl-event-uuid-abc-123';

        $this->sendWebhook($envelope, ['HTTP_X_GITLAB_EVENT_UUID' => $uuid])->assertStatus(200);
        $this->sendWebhook($envelope, ['HTTP_X_GITLAB_EVENT_UUID' => $uuid])->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class, 1);
    }

    // ── non-issue object kinds are dispatched but produce no task ─────────────

    public function test_merge_request_hook_dispatches_job_but_job_is_noop(): void
    {
        Queue::fake();

        $envelope = [
            'object_kind' => 'merge_request',
            'object_attributes' => ['id' => 50, 'title' => 'My MR', 'state' => 'opened'],
        ];

        $this->sendWebhook($envelope)->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class);
    }

    public function test_merge_request_job_does_not_create_task(): void
    {
        $envelope = [
            'object_kind' => 'merge_request',
            'object_attributes' => ['id' => 50, 'title' => 'My MR', 'state' => 'opened'],
        ];

        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, null);
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        $this->assertDatabaseCount(ExternalIssueLink::class, 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_note_hook_dispatches_job_but_job_is_noop(): void
    {
        Queue::fake();

        $envelope = [
            'object_kind' => 'note',
            'object_attributes' => ['id' => 5, 'note' => 'A comment'],
        ];

        $this->sendWebhook($envelope)->assertStatus(200);

        Queue::assertPushed(ProcessIncomingIssueJob::class);
    }

    public function test_note_job_does_not_create_task(): void
    {
        $envelope = [
            'object_kind' => 'note',
            'object_attributes' => ['id' => 5, 'note' => 'A comment'],
        ];

        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, null);
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        $this->assertDatabaseCount(ExternalIssueLink::class, 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    // ── labels are merged from top-level ────────────────────────────────────

    public function test_labels_from_top_level_are_ingested(): void
    {
        $envelope = $this->issueEnvelope([
            'labels' => [
                ['id' => 1, 'title' => 'bug', 'color' => '#FF0000'],
            ],
        ]);

        $job = new ProcessIncomingIssueJob($this->binding->id, $envelope, null);
        $job->handle(app(IssueTrackerRegistry::class), app(IssueIngestService::class));

        $link = ExternalIssueLink::where('task_provider_binding_id', $this->binding->id)->first();
        $this->assertNotNull($link);
    }
}
