<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Enums\AutoTaskState;
use App\Enums\TaskOrigin;
use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Models\ExternalIssueLink;
use App\Models\Task;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\DTO\ExternalIssue;
use App\Services\IssueTracker\IssueIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LaunchesIssueRuns;
use Tests\TestCase;

class IssueIngestServiceTest extends TestCase
{
    use LaunchesIssueRuns;
    use RefreshDatabase;

    private IssueIngestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IssueIngestService::class);
    }

    private function makeBinding(array $overrides = []): TaskProviderBinding
    {
        $binding = TaskProviderBinding::factory()->create(array_merge([
            'kind' => TaskProviderKind::GitHub,
            'mode' => TaskProviderMode::Poll,
            'sync_status' => TaskProviderSyncStatus::Active,
            'external_project_ref' => 'acme/widget',
            'filters' => null,
        ], $overrides));
        $this->prepareIssueRuns($binding->workspace);

        return $binding;
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

    /**
     * Wrap a raw payload into the inbound DTO at the binding's provider kind —
     * exactly what the poll/webhook jobs do at the port — and ingest it.
     *
     * @param  array<string, mixed>  $issue
     */
    private function ingest(array $issue, TaskProviderBinding $binding): ExternalIssueLink
    {
        return $this->service->ingest(ExternalIssue::fromProvider($issue, $binding->kind), $binding);
    }

    public function test_ingest_creates_external_issue_link(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(42);

        $link = $this->ingest($issue, $binding);

        $this->assertDatabaseHas(ExternalIssueLink::class, [
            'task_provider_binding_id' => $binding->id,
            'external_id' => '42',
            'external_url' => 'https://github.com/acme/widget/issues/42',
        ]);
        $this->assertSame('42', $link->external_id);
    }

    public function test_external_id_uses_github_number_not_global_id(): void
    {
        // The global id (4554618939) is not addressable by the comment API;
        // the issue number (19) is. Regression for write-back posting to a
        // non-existent issue (HTTP 404).
        $binding = $this->makeBinding(['kind' => TaskProviderKind::GitHub]);
        $issue = $this->sampleIssue(4554618939, ['number' => 19]);

        $link = $this->ingest($issue, $binding);

        $this->assertSame('19', $link->external_id);
    }

    public function test_external_id_uses_gitlab_iid_not_global_id(): void
    {
        $binding = $this->makeBinding(['kind' => TaskProviderKind::GitLab]);
        $issue = $this->sampleIssue(987654, ['iid' => 3, 'web_url' => 'https://gitlab.com/acme/widget/-/issues/3']);

        $link = $this->ingest($issue, $binding);

        $this->assertSame('3', $link->external_id);
    }

    public function test_external_id_uses_linear_node_id(): void
    {
        $binding = $this->makeBinding(['kind' => TaskProviderKind::Linear]);
        $issue = $this->sampleIssue(1, ['id' => 'lin_abc123']);

        $link = $this->ingest($issue, $binding);

        $this->assertSame('lin_abc123', $link->external_id);
    }

    public function test_ingest_is_idempotent(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(7);

        $this->ingest($issue, $binding);
        $this->ingest($issue, $binding);

        $this->assertDatabaseCount(ExternalIssueLink::class, 1);
    }

    public function test_ingest_updates_signature_on_change(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(7, ['body' => 'v1']);

        $link1 = $this->ingest($issue, $binding);
        $signatureAfterV1 = $link1->signature;

        $updatedIssue = array_merge($issue, ['body' => 'v2']);
        $link2 = $this->ingest($updatedIssue, $binding);

        $this->assertNotSame($signatureAfterV1, $link2->signature);
    }

    public function test_ingest_launches_an_automatic_run_for_a_new_matching_issue(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(5);

        $link = $this->ingest($issue, $binding);

        $this->assertNotNull($link->task_id);
        $this->assertNotNull($link->launched_at);
        $task = Task::query()->findOrFail($link->task_id);
        $this->assertSame($binding->workspace_id, $task->workspace_id);
        $this->assertSame('Issue #5', $task->title);
        $this->assertSame(TaskOrigin::Issue, $task->origin);
        $this->assertSame('Issue Bot', $task->owner->name);
        $this->assertSame('5', $task->external_ref['number']);
        $this->assertSame('github-5', $task->external_ref['key']);
        $this->assertSame(AutoTaskState::Queued, $task->auto_state);
        $this->assertStringContainsString('UNTRUSTED ISSUE', $task->auto_config['prompt']);
    }

    public function test_without_an_automation_user_nothing_launches_and_the_binding_says_why(): void
    {
        $binding = $this->makeBinding();
        $binding->workspace->forceFill(['automation_user_id' => null])->save();

        $link = $this->ingest($this->sampleIssue(9), $binding->fresh());

        $this->assertNull($link->task_id);
        $this->assertNull($link->launched_at);
        $this->assertSame(__('workspaces.issue.run.no_automation_user'), $binding->fresh()->last_error);
    }

    public function test_ingest_does_not_launch_twice_on_reimport(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(5);

        $link1 = $this->ingest($issue, $binding);
        $link2 = $this->ingest($issue, $binding);

        $this->assertSame($link1->task_id, $link2->task_id);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_state_filter_blocks_closed_issues(): void
    {
        $binding = $this->makeBinding(['filters' => ['state' => 'open']]);
        $issue = $this->sampleIssue(3, ['state' => 'closed']);

        $link = $this->ingest($issue, $binding);

        $this->assertNull($link->task_id, 'Closed issue should not create a task when filter=open');
    }

    public function test_label_filter_blocks_issues_without_required_label(): void
    {
        $binding = $this->makeBinding(['filters' => ['labels' => ['argos']]]);
        $issue = $this->sampleIssue(9, ['labels' => [['name' => 'bug']]]);

        $link = $this->ingest($issue, $binding);

        $this->assertNull($link->task_id, 'Issue without required label should not create a task');
    }

    public function test_label_filter_allows_issues_with_required_label(): void
    {
        $binding = $this->makeBinding(['filters' => ['labels' => ['argos']]]);
        $issue = $this->sampleIssue(10, ['labels' => [['name' => 'argos'], ['name' => 'bug']]]);

        $link = $this->ingest($issue, $binding);

        $this->assertNotNull($link->task_id);
    }

    public function test_label_filter_uses_or_semantics_for_multiple_labels(): void
    {
        // OR: a single matching label out of several configured is enough.
        $binding = $this->makeBinding(['filters' => ['labels' => ['argos', 'ready']]]);
        $issue = $this->sampleIssue(11, ['labels' => [['name' => 'argos'], ['name' => 'bug']]]);

        $link = $this->ingest($issue, $binding);

        $this->assertNotNull($link->task_id, 'OR semantics: one of the configured labels should be enough');
    }

    public function test_launches_when_a_previously_filtered_issue_starts_matching(): void
    {
        $binding = $this->makeBinding(['filters' => ['labels' => ['argos']]]);

        // First seen without the label → filtered: link exists, no task.
        $issue = $this->sampleIssue(7, ['labels' => []]);
        $link = $this->ingest($issue, $binding);
        $this->assertNull($link->task_id);

        // Label added later → now matches → a task is created on the same link.
        $issue['labels'] = [['name' => 'argos']];
        $link = $this->ingest($issue, $binding);
        $this->assertNotNull($link->task_id);
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_does_not_relaunch_after_the_task_is_deleted(): void
    {
        $binding = $this->makeBinding();
        $issue = $this->sampleIssue(7);

        $link = $this->ingest($issue, $binding);
        Task::query()->findOrFail($link->task_id)->forceDelete();
        $this->assertNull($link->fresh()->task_id);

        $this->ingest($issue, $binding);

        $this->assertDatabaseCount('tasks', 0);
        $this->assertNull($link->fresh()->task_id);
    }

    public function test_label_filter_blocks_issue_with_none_of_several_labels(): void
    {
        $binding = $this->makeBinding(['filters' => ['labels' => ['argos', 'ready']]]);
        $issue = $this->sampleIssue(12, ['labels' => [['name' => 'bug'], ['name' => 'wontfix']]]);

        $link = $this->ingest($issue, $binding);

        $this->assertNull($link->task_id, 'No configured label present → no task');
    }
}
