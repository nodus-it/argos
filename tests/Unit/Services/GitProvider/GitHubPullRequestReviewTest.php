<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitProvider;

use App\Integrations\GitHub\Requests\GetActionsJobLogs;
use App\Integrations\GitHub\Requests\GetCombinedStatus;
use App\Integrations\GitHub\Requests\GetIssueComments;
use App\Integrations\GitHub\Requests\GetPullRequest;
use App\Integrations\GitHub\Requests\GetRepository;
use App\Integrations\GitHub\Requests\GraphQL;
use App\Integrations\GitHub\Requests\ListCheckRunAnnotations;
use App\Integrations\GitHub\Requests\ListCheckRuns;
use App\Integrations\GitHub\Requests\ListPullRequestReviewComments;
use App\Integrations\GitHub\Requests\ListPullRequestReviews;
use App\Services\GitProvider\GitHubGitService;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class GitHubPullRequestReviewTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pr(array $overrides = []): array
    {
        return array_merge([
            'number' => 7,
            'node_id' => 'PR_node7',
            'html_url' => 'https://github.com/acme/widget/pull/7',
            'state' => 'open',
            'draft' => false,
            'merged' => false,
            'merged_at' => null,
            'mergeable' => true,
            'mergeable_state' => 'clean',
            'head' => ['sha' => 'abc123'],
            'auto_merge' => null,
        ], $overrides);
    }

    private function service(): GitHubGitService
    {
        return new GitHubGitService('tok');
    }

    public function test_details_aggregate_checks_reviews_comments_and_merge_state(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr([
                'mergeable' => false,
                'mergeable_state' => 'dirty',
                'auto_merge' => ['merge_method' => 'squash'],
            ])),
            ListCheckRuns::class => MockResponse::make(['total_count' => 3, 'check_runs' => [
                ['id' => 1, 'name' => 'lint', 'status' => 'completed', 'conclusion' => 'success', 'details_url' => 'https://ci/lint'],
                ['id' => 2, 'name' => 'tests', 'status' => 'completed', 'conclusion' => 'failure', 'details_url' => 'https://ci/tests'],
                ['id' => 3, 'name' => 'build', 'status' => 'in_progress', 'conclusion' => null],
            ]]),
            GetCombinedStatus::class => MockResponse::make(['state' => 'failure', 'statuses' => [
                ['context' => 'ci/legacy', 'state' => 'error', 'target_url' => 'https://legacy'],
            ]]),
            ListPullRequestReviews::class => MockResponse::make([
                ['user' => ['login' => 'anna'], 'state' => 'APPROVED', 'body' => 'LGTM', 'submitted_at' => '2026-01-01T10:00:00Z'],
                ['user' => ['login' => 'bob', 'avatar_url' => 'https://avatars.githubusercontent.com/u/2'], 'state' => 'CHANGES_REQUESTED', 'body' => 'Fix it', 'submitted_at' => '2026-01-01T11:00:00Z'],
                ['user' => ['login' => 'anna'], 'state' => 'COMMENTED', 'body' => 'nit', 'submitted_at' => '2026-01-01T12:00:00Z'],
                ['user' => ['login' => 'carl'], 'state' => 'PENDING', 'body' => '', 'submitted_at' => null],
            ]),
            ListPullRequestReviewComments::class => MockResponse::make([
                ['id' => 501, 'user' => ['login' => 'bob'], 'body' => 'Typo here', 'path' => 'src/A.php', 'line' => null, 'original_line' => 14, 'html_url' => 'https://github.com/c/501', 'created_at' => '2026-01-01T11:00:00Z'],
            ]),
            GetIssueComments::class => MockResponse::make([
                ['id' => 900, 'user' => ['login' => 'anna'], 'body' => 'First!', 'html_url' => 'https://github.com/c/900', 'created_at' => '2026-01-01T09:00:00Z'],
            ]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 7);

        $this->assertSame('open', $details->state);
        $this->assertSame('https://github.com/acme/widget/pull/7', $details->url);
        $this->assertSame('abc123', $details->headSha);
        $this->assertTrue($details->conflicts);
        $this->assertNull($details->behindBase);
        $this->assertTrue($details->autoMerge);

        $this->assertSame('failure', $details->checks['state']);
        $this->assertSame('https://ci/tests', $details->checks['url']);
        $this->assertSame([
            ['name' => 'tests', 'url' => 'https://ci/tests'],
            ['name' => 'ci/legacy', 'url' => 'https://legacy'],
        ], $details->checks['failed']);

        // anna's later COMMENTED doesn't drop her approval; bob's change request wins.
        $this->assertSame('changes_requested', $details->reviewState);
        $this->assertCount(3, $details->reviews);
        $this->assertSame(['author' => 'bob', 'avatar' => 'https://avatars.githubusercontent.com/u/2', 'profile' => null, 'url' => null, 'state' => 'changes_requested', 'body' => 'Fix it', 'submitted_at' => '2026-01-01T11:00:00Z'], $details->reviews[1]);

        $this->assertSame(['900', '501'], array_column($details->comments, 'id'));
        $this->assertSame('src/A.php', $details->comments[1]['path']);
        $this->assertSame(14, $details->comments[1]['line']);
        $this->assertNull($details->comments[0]['path']);
    }

    public function test_details_detect_draft_behind_and_no_checks(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['draft' => true, 'mergeable_state' => 'behind'])),
            ListCheckRuns::class => MockResponse::make(['total_count' => 0, 'check_runs' => []]),
            // An empty combined status is reported as "pending" — must read as "none".
            GetCombinedStatus::class => MockResponse::make(['state' => 'pending', 'statuses' => []]),
            ListPullRequestReviews::class => MockResponse::make([]),
            ListPullRequestReviewComments::class => MockResponse::make([]),
            GetIssueComments::class => MockResponse::make([]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 7);

        $this->assertSame('draft', $details->state);
        $this->assertTrue($details->isDraft());
        $this->assertTrue($details->behindBase);
        $this->assertFalse($details->conflicts);
        $this->assertSame(['state' => 'none', 'url' => null, 'failed' => []], $details->checks);
        $this->assertNull($details->reviewState);
        $this->assertSame([], $details->comments);
        $this->assertFalse($details->autoMerge);
    }

    public function test_details_report_merged_and_green_checks(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['state' => 'closed', 'merged' => true, 'merged_at' => '2026-01-02T00:00:00Z'])),
            ListCheckRuns::class => MockResponse::make(['check_runs' => [
                ['id' => 1, 'name' => 'tests', 'status' => 'completed', 'conclusion' => 'success'],
                ['id' => 2, 'name' => 'docs', 'status' => 'completed', 'conclusion' => 'skipped'],
            ]]),
            GetCombinedStatus::class => MockResponse::make(['state' => 'pending', 'statuses' => []]),
            ListPullRequestReviews::class => MockResponse::make([
                ['user' => ['login' => 'anna'], 'state' => 'APPROVED', 'body' => '', 'submitted_at' => '2026-01-01T10:00:00Z'],
            ]),
            ListPullRequestReviewComments::class => MockResponse::make([]),
            GetIssueComments::class => MockResponse::make([]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 7);

        $this->assertSame('merged', $details->state);
        $this->assertFalse($details->conflicts);
        $this->assertSame('success', $details->checks['state']);
        $this->assertSame('https://github.com/acme/widget/pull/7/checks', $details->checks['url']);
        $this->assertSame('approved', $details->reviewState);
    }

    public function test_details_survive_unreadable_secondary_endpoints(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr()),
            ListCheckRuns::class => MockResponse::make(['message' => 'Resource not accessible by integration'], 403),
            GetCombinedStatus::class => MockResponse::make(['message' => 'Forbidden'], 403),
            ListPullRequestReviews::class => MockResponse::make([], 500),
            ListPullRequestReviewComments::class => MockResponse::make([]),
            GetIssueComments::class => MockResponse::make([]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 7);

        $this->assertSame('open', $details->state);
        $this->assertSame('none', $details->checks['state']);
        $this->assertSame([], $details->reviews);
    }

    public function test_mark_ready_sends_the_graphql_mutation_with_the_node_id(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['draft' => true])),
            GraphQL::class => MockResponse::make(['data' => ['markPullRequestReadyForReview' => ['pullRequest' => ['isDraft' => false]]]]),
        ]);

        $this->service()->markPullRequestReady('acme', 'widget', 7);

        Saloon::assertSent(fn (Request $request): bool => $request instanceof GraphQL
            && str_contains((string) $request->body()->all()['query'], 'markPullRequestReadyForReview')
            && $request->body()->all()['variables'] === ['id' => 'PR_node7']);
    }

    public function test_mark_ready_is_a_noop_for_a_non_draft(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr()),
        ]);

        $this->service()->markPullRequestReady('acme', 'widget', 7);

        Saloon::assertNotSent(GraphQL::class);
    }

    public function test_mark_ready_throws_on_graphql_errors(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['draft' => true])),
            GraphQL::class => MockResponse::make(['errors' => [['message' => 'Resource not accessible by integration']]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Resource not accessible by integration');

        $this->service()->markPullRequestReady('acme', 'widget', 7);
    }

    public function test_enable_auto_merge_uses_an_allowed_merge_method(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['mergeable_state' => 'blocked'])),
            GetRepository::class => MockResponse::make(['allow_auto_merge' => true, 'allow_merge_commit' => false, 'allow_squash_merge' => true, 'allow_rebase_merge' => true]),
            GraphQL::class => MockResponse::make(['data' => ['enablePullRequestAutoMerge' => ['pullRequest' => ['autoMergeRequest' => ['enabledAt' => '2026-01-01T00:00:00Z']]]]]),
        ]);

        $this->assertTrue($this->service()->enableAutoMerge('acme', 'widget', 7));

        Saloon::assertSent(fn (Request $request): bool => $request instanceof GraphQL
            && str_contains((string) $request->body()->all()['query'], 'enablePullRequestAutoMerge')
            && $request->body()->all()['variables'] === ['id' => 'PR_node7', 'method' => 'SQUASH']);
    }

    public function test_enable_auto_merge_returns_false_when_the_repository_disallows_it(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr()),
            GetRepository::class => MockResponse::make(['allow_auto_merge' => false, 'allow_merge_commit' => true]),
        ]);

        $this->assertFalse($this->service()->enableAutoMerge('acme', 'widget', 7));

        Saloon::assertNotSent(GraphQL::class);
    }

    public function test_enable_auto_merge_returns_false_on_the_not_allowed_graphql_error(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr()),
            // Token cannot see the repo settings → no hint up front.
            GetRepository::class => MockResponse::make(['full_name' => 'acme/widget']),
            GraphQL::class => MockResponse::make([
                'data' => ['enablePullRequestAutoMerge' => null],
                'errors' => [['type' => 'UNPROCESSABLE', 'message' => 'Pull request Auto merge is not allowed for this repository']],
            ]),
        ]);

        $this->assertFalse($this->service()->enableAutoMerge('acme', 'widget', 7));

        Saloon::assertSent(fn (Request $request): bool => $request instanceof GraphQL
            && ! array_key_exists('method', $request->body()->all()['variables']));
    }

    public function test_enable_auto_merge_is_true_when_already_enabled(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['auto_merge' => ['merge_method' => 'merge']])),
        ]);

        $this->assertTrue($this->service()->enableAutoMerge('acme', 'widget', 7));

        Saloon::assertNotSent(GraphQL::class);
    }

    public function test_disable_auto_merge(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['auto_merge' => ['merge_method' => 'merge']])),
            GraphQL::class => MockResponse::make(['data' => ['disablePullRequestAutoMerge' => ['pullRequest' => ['autoMergeRequest' => null]]]]),
        ]);

        $this->assertTrue($this->service()->disableAutoMerge('acme', 'widget', 7));

        Saloon::assertSent(fn (Request $request): bool => $request instanceof GraphQL
            && str_contains((string) $request->body()->all()['query'], 'disablePullRequestAutoMerge'));
    }

    public function test_disable_auto_merge_returns_false_on_graphql_error(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['auto_merge' => ['merge_method' => 'merge']])),
            GraphQL::class => MockResponse::make(['errors' => [['message' => 'Must have admin rights']]]),
        ]);

        $this->assertFalse($this->service()->disableAutoMerge('acme', 'widget', 7));
    }

    public function test_failed_check_log_includes_output_annotations_and_job_log(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr()),
            ListCheckRuns::class => MockResponse::make(['check_runs' => [
                ['id' => 1, 'name' => 'lint', 'status' => 'completed', 'conclusion' => 'success', 'app' => ['slug' => 'github-actions']],
                [
                    'id' => 2, 'name' => 'tests', 'status' => 'completed', 'conclusion' => 'failure',
                    'details_url' => 'https://ci/tests', 'app' => ['slug' => 'github-actions'],
                    'output' => ['title' => '2 tests failed', 'summary' => 'See annotations', 'text' => null, 'annotations_count' => 1],
                ],
            ]]),
            GetCombinedStatus::class => MockResponse::make(['state' => 'failure', 'statuses' => [
                ['context' => 'ci/legacy', 'state' => 'failure', 'description' => 'Build broke', 'target_url' => 'https://legacy'],
            ]]),
            ListCheckRunAnnotations::class => MockResponse::make([
                ['path' => 'tests/FooTest.php', 'start_line' => 42, 'annotation_level' => 'failure', 'message' => 'Failed asserting that false is true.'],
            ]),
            GetActionsJobLogs::class => MockResponse::make("\e[31mFAILED\e[0m Tests\\FooTest > it works\nTests: 1 failed"),
        ]);

        $log = $this->service()->failedCheckLog('acme', 'widget', 7);

        $this->assertStringContainsString('## tests (failure)', $log);
        $this->assertStringContainsString('https://ci/tests', $log);
        $this->assertStringContainsString('2 tests failed', $log);
        $this->assertStringContainsString('- tests/FooTest.php:42 [failure] Failed asserting that false is true.', $log);
        $this->assertStringContainsString("FAILED Tests\\FooTest > it works\nTests: 1 failed", $log);
        $this->assertStringContainsString("## ci/legacy (failure)\nBuild broke\nhttps://legacy", $log);
        $this->assertStringNotContainsString('lint', $log);
        $this->assertStringNotContainsString("\e[", $log);

        Saloon::assertSent(fn (Request $request): bool => $request instanceof GetActionsJobLogs
            && $request->resolveEndpoint() === '/repos/acme/widget/actions/jobs/2/logs');
    }

    public function test_failed_check_log_is_empty_when_nothing_failed(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr()),
            ListCheckRuns::class => MockResponse::make(['check_runs' => [
                ['id' => 1, 'name' => 'tests', 'status' => 'completed', 'conclusion' => 'success'],
            ]]),
            GetCombinedStatus::class => MockResponse::make(['state' => 'success', 'statuses' => [
                ['context' => 'ci/legacy', 'state' => 'success'],
            ]]),
        ]);

        $this->assertSame('', $this->service()->failedCheckLog('acme', 'widget', 7));
    }

    public function test_failed_check_log_is_capped_and_keeps_the_end(): void
    {
        $text = str_repeat("noise line\n", 5_000).'THE ACTUAL ERROR';

        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr()),
            ListCheckRuns::class => MockResponse::make(['check_runs' => array_map(fn (int $i): array => [
                'id' => $i, 'name' => "job{$i}", 'status' => 'completed', 'conclusion' => 'failure',
                'output' => ['title' => 'failed', 'summary' => null, 'text' => $text.$i, 'annotations_count' => 0],
            ], range(1, 5))]),
            GetCombinedStatus::class => MockResponse::make(['state' => 'pending', 'statuses' => []]),
        ]);

        $log = $this->service()->failedCheckLog('acme', 'widget', 7);

        $this->assertLessThanOrEqual(20_100, strlen($log));
        $this->assertStringEndsWith('THE ACTUAL ERROR5', $log);
        $this->assertStringStartsWith('…(truncated)', $log);
    }
}
