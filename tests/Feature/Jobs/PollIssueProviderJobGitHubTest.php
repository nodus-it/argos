<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Jobs\PollIssueProviderJob;
use App\Models\ExternalIssueLink;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueIngestService;
use App\Services\IssueTracker\IssueTrackerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollIssueProviderJobGitHubTest extends TestCase
{
    use RefreshDatabase;

    private TaskProviderBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitHub,
            'mode' => TaskProviderMode::Poll,
            'sync_status' => TaskProviderSyncStatus::Active,
            'external_project_ref' => 'acme/widget',
            'filters' => null,
        ]);
    }

    private function fakeIssuesEndpoint(array $issues): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/widget/issues*' => Http::response($issues),
        ]);
    }

    // ── happy path ───────────────────────────────────────────────────────────

    public function test_poll_creates_tasks_for_matching_issues(): void
    {
        $this->fakeIssuesEndpoint([
            ['id' => 1, 'title' => 'First issue', 'body' => 'body', 'state' => 'open', 'labels' => [], 'html_url' => 'https://github.com/acme/widget/issues/1'],
            ['id' => 2, 'title' => 'Second issue', 'body' => 'body', 'state' => 'open', 'labels' => [], 'html_url' => 'https://github.com/acme/widget/issues/2'],
        ]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        $this->assertDatabaseCount(ExternalIssueLink::class, 2);
        $this->assertDatabaseCount('tasks', 2);
    }

    public function test_poll_filters_out_pull_requests(): void
    {
        $this->fakeIssuesEndpoint([
            ['id' => 10, 'title' => 'Real issue', 'body' => '', 'state' => 'open', 'labels' => [], 'html_url' => 'https://github.com/acme/widget/issues/10'],
            ['id' => 11, 'title' => 'PR disguised as issue', 'body' => '', 'state' => 'open', 'labels' => [], 'html_url' => 'https://github.com/acme/widget/issues/11', 'pull_request' => ['url' => 'x']],
        ]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        $this->assertDatabaseCount(ExternalIssueLink::class, 1);
        $link = ExternalIssueLink::first();
        $this->assertSame('10', $link->external_id);
    }

    public function test_poll_sets_last_polled_at_after_success(): void
    {
        $this->fakeIssuesEndpoint([]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        $this->binding->refresh();
        $this->assertNotNull($this->binding->last_polled_at);
        $this->assertNull($this->binding->last_error);
    }

    public function test_poll_clears_last_error_on_success(): void
    {
        $this->binding->update(['last_error' => 'previous error']);

        $this->fakeIssuesEndpoint([]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        $this->binding->refresh();
        $this->assertNull($this->binding->last_error);
    }

    // ── label filtering (regression: 422 from `labels[]` query param) ─────────

    public function test_poll_does_not_forward_labels_as_a_query_param(): void
    {
        // GitHub returns 422 when `labels` is sent as an array (labels[0]=…),
        // which is how the filter array used to leak into the query. Only
        // `state` may be forwarded; labels are filtered locally.
        $this->binding->update(['filters' => ['labels' => ['argos-demo']]]);
        $this->fakeIssuesEndpoint([]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'state=open')
            && ! str_contains($request->url(), 'labels'));

        $this->binding->refresh();
        $this->assertNull($this->binding->last_error);
    }

    public function test_poll_applies_label_filter_locally(): void
    {
        $this->binding->update(['filters' => ['labels' => ['argos-demo']]]);
        $this->fakeIssuesEndpoint([
            ['id' => 1, 'title' => 'Matches', 'body' => '', 'state' => 'open', 'labels' => [['name' => 'argos-demo']], 'html_url' => 'https://github.com/acme/widget/issues/1'],
            ['id' => 2, 'title' => 'No label', 'body' => '', 'state' => 'open', 'labels' => [['name' => 'other']], 'html_url' => 'https://github.com/acme/widget/issues/2'],
        ]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        // Both issues are linked, but only the labelled one becomes a Task.
        $this->assertDatabaseCount(ExternalIssueLink::class, 2);
        $this->assertDatabaseCount('tasks', 1);
        $this->assertNotNull(ExternalIssueLink::where('external_id', '1')->first()->task_id);
        $this->assertNull(ExternalIssueLink::where('external_id', '2')->first()->task_id);
    }

    // ── pagination ───────────────────────────────────────────────────────────

    public function test_poll_fetches_all_pages(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/widget/issues*' => Http::sequence()
                ->push(
                    [['id' => 1, 'title' => 'Issue 1', 'body' => '', 'state' => 'open', 'labels' => [], 'html_url' => 'https://github.com/acme/widget/issues/1']],
                    200,
                    ['Link' => '<https://api.github.com/repos/acme/widget/issues?page=2&per_page=100>; rel="next"'],
                )
                ->push(
                    [['id' => 2, 'title' => 'Issue 2', 'body' => '', 'state' => 'open', 'labels' => [], 'html_url' => 'https://github.com/acme/widget/issues/2']],
                    200,
                ),
        ]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        $this->assertDatabaseCount(ExternalIssueLink::class, 2);
        $this->assertDatabaseCount('tasks', 2);
    }

    // ── error handling ───────────────────────────────────────────────────────

    public function test_poll_stores_error_on_api_failure(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/widget/issues*' => Http::response(
                ['message' => 'Not Found'],
                404,
            ),
        ]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        $this->binding->refresh();
        $this->assertNotNull($this->binding->last_error);
    }

    // ── guard clauses ────────────────────────────────────────────────────────

    public function test_poll_skips_non_poll_mode_binding(): void
    {
        $this->binding->update(['mode' => TaskProviderMode::Webhook]);

        Http::fake();

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        Http::assertNothingSent();
        $this->assertDatabaseCount(ExternalIssueLink::class, 0);
    }

    public function test_poll_skips_inactive_binding(): void
    {
        $this->binding->update(['sync_status' => TaskProviderSyncStatus::Pending]);

        Http::fake();

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        Http::assertNothingSent();
    }
}
