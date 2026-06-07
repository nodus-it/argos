<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\TaskProviderKind;
use App\Enums\TaskProviderMode;
use App\Enums\TaskProviderSyncStatus;
use App\Integrations\GitLab\Requests\ListIssues;
use App\Jobs\PollIssueProviderJob;
use App\Models\ExternalIssueLink;
use App\Models\TaskProviderBinding;
use App\Services\IssueTracker\IssueIngestService;
use App\Services\IssueTracker\IssueTrackerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
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
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => MockResponse::make($issues),
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
        Saloon::fake([
            MockResponse::make(
                [$this->gitlabIssue(1, 'Issue 1')],
                200,
                ['X-Next-Page' => '2'],
            ),
            MockResponse::make(
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

        Saloon::assertSent(function (Request $request): bool {
            $query = $request instanceof ListIssues ? $request->query()->all() : [];

            return ($query['state'] ?? '') === 'opened';
        });
    }

    // ── error handling ───────────────────────────────────────────────────────

    public function test_poll_stores_error_on_api_failure(): void
    {
        Saloon::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => MockResponse::make(
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

        Saloon::fake([]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        Saloon::assertNothingSent();
        $this->assertDatabaseCount(ExternalIssueLink::class, 0);
    }

    public function test_poll_skips_inactive_binding(): void
    {
        $this->binding->update(['sync_status' => TaskProviderSyncStatus::Pending]);

        Saloon::fake([]);

        (new PollIssueProviderJob($this->binding->id))->handle(
            app(IssueTrackerRegistry::class),
            app(IssueIngestService::class),
        );

        Saloon::assertNothingSent();
    }
}
