<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\ExternalIssueLink;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueIngestServiceTest extends TestCase
{
    use RefreshDatabase;

    private IssueIngestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IssueIngestService::class);
    }

    private function makeBinding(array $overrides = []): TaskProviderBinding
    {
        return TaskProviderBinding::factory()->create(array_merge([
            'kind' => TaskProviderKind::GitHub,
            'mode' => TaskProviderMode::Poll,
            'sync_status' => TaskProviderSyncStatus::Active,
            'external_project_ref' => 'acme/widget',
            'filters' => null,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function sampleIssue(int $id = 1, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'title' => "Issue #{$id}",
            'body' => "Body of issue {$id}",
            'state' => 'open',
            'labels' => [],
            'html_url' => "https://github.com/acme/widget/issues/{$id}",
        ], $overrides);
    }

    public function test_ingest_creates_external_issue_link(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(42);

        $link = $this->service->ingest($issue, $binding);

        $this->assertDatabaseHas(ExternalIssueLink::class, [
            'task_provider_binding_id' => $binding->id,
            'external_id' => '42',
            'external_url' => 'https://github.com/acme/widget/issues/42',
        ]);
        $this->assertSame('42', $link->external_id);
    }

    public function test_ingest_is_idempotent(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(7);

        $this->service->ingest($issue, $binding);
        $this->service->ingest($issue, $binding);

        $this->assertDatabaseCount(ExternalIssueLink::class, 1);
    }

    public function test_ingest_updates_signature_on_change(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(7, ['body' => 'v1']);

        $link1 = $this->service->ingest($issue, $binding);
        $signatureAfterV1 = $link1->signature;

        $updatedIssue = array_merge($issue, ['body' => 'v2']);
        $link2 = $this->service->ingest($updatedIssue, $binding);

        $this->assertNotSame($signatureAfterV1, $link2->signature);
    }

    public function test_ingest_creates_task_for_new_matching_issue(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(5);

        $link = $this->service->ingest($issue, $binding);

        $this->assertNotNull($link->task_id);
        $this->assertDatabaseHas('tasks', [
            'id' => $link->task_id,
            'name' => 'Issue #5',
        ]);
    }

    public function test_ingest_does_not_create_second_task_on_reimport(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(5);

        $link1 = $this->service->ingest($issue, $binding);
        $link2 = $this->service->ingest($issue, $binding);

        $this->assertSame($link1->task_id, $link2->task_id);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_state_filter_blocks_closed_issues(): void
    {
        $binding = $this->makeBinding(['filters' => ['state' => 'open']]);
        $issue = $this->sampleIssue(3, ['state' => 'closed']);

        $link = $this->service->ingest($issue, $binding);

        $this->assertNull($link->task_id, 'Closed issue should not create a task when filter=open');
    }

    public function test_label_filter_blocks_issues_without_required_label(): void
    {
        $binding = $this->makeBinding(['filters' => ['labels' => ['argos']]]);
        $issue = $this->sampleIssue(9, ['labels' => [['name' => 'bug']]]);

        $link = $this->service->ingest($issue, $binding);

        $this->assertNull($link->task_id, 'Issue without required label should not create a task');
    }

    public function test_label_filter_allows_issues_with_required_label(): void
    {
        $binding = $this->makeBinding(['filters' => ['labels' => ['argos']]]);
        $issue = $this->sampleIssue(10, ['labels' => [['name' => 'argos'], ['name' => 'bug']]]);

        $link = $this->service->ingest($issue, $binding);

        $this->assertNotNull($link->task_id);
    }
}
