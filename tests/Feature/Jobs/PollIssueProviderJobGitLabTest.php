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

class PollIssueProviderJobGitLabTest extends TestCase
{
    use RefreshDatabase;

    private TaskProviderBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->binding = TaskProviderBinding::factory()->create([
            'kind' => TaskProviderKind::GitLab,
            'mode' => TaskProviderMode::Poll,
            'sync_status' => TaskProviderSyncStatus::Active,
            'external_project_ref' => 'acme/widget',
            'filters' => null,
        ]);
    }

    private function fakeIssuesEndpoint(array $issues): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => Http::response($issues),
        ]);
    }

    private function gitlabIssue(int $id, string $title = 'Test issue'): array
    {
        return [
            'id' => $id,
            'iid' => $id,
            'title' => $title,
            'description' => 'Issue body',
            'state' => 'opened',
            'labels' => [],
            'web_url' => "https://gitlab.com/acme/widget/-/issues/{$id}",
        ];
    }

    // ── happy path ───────────────────────────────────────────────────────────

    public function test_poll_creates_tasks_for_matching_issues(): void
    {
        $this->fakeIssuesEndpoint([
            $this->gitlabIssue(1, 'First issue'),
            $this->gitlabIssue(2, 'Second issue'),
        ]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        $this->assertDatabaseCount(ExternalIssueLink::class, 2);
        $this->assertDatabaseCount('tasks', 2);
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

    // ── pagination ───────────────────────────────────────────────────────────

    public function test_poll_fetches_all_pages_via_x_next_page(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => Http::sequence()
                ->push(
                    [$this->gitlabIssue(1, 'Issue 1')],
                    200,
                    ['X-Next-Page' => '2'],
                )
                ->push(
                    [$this->gitlabIssue(2, 'Issue 2')],
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

    // ── default state filter ─────────────────────────────────────────────────

    public function test_poll_sends_opened_state_by_default(): void
    {
        $this->fakeIssuesEndpoint([]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        Http::assertSent(function ($request): bool {
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?? '', $query);

            return ($query['state'] ?? '') === 'opened';
        });
    }

    // ── error handling ───────────────────────────────────────────────────────

    public function test_poll_stores_error_on_api_failure(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => Http::response(
                ['message' => '404 Not found'],
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
