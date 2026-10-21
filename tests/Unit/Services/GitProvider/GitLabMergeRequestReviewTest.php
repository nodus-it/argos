<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitProvider;

use App\Integrations\GitLab\Requests\CancelMergeWhenPipelineSucceeds;
use App\Integrations\GitLab\Requests\GetJobTrace;
use App\Integrations\GitLab\Requests\GetMergeRequest;
use App\Integrations\GitLab\Requests\GetMergeRequestApprovals;
use App\Integrations\GitLab\Requests\ListFailedPipelineJobs;
use App\Integrations\GitLab\Requests\ListMergeRequestDiscussions;
use App\Integrations\GitLab\Requests\MergeWhenPipelineSucceeds;
use App\Integrations\GitLab\Requests\UpdateMergeRequestAttributes;
use App\Services\GitProvider\GitLabGitService;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class GitLabMergeRequestReviewTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mr(array $overrides = []): array
    {
        return array_merge([
            'iid' => 5,
            'title' => 'Add widget',
            'web_url' => 'https://gitlab.com/acme/widget/-/merge_requests/5',
            'state' => 'opened',
            'draft' => false,
            'sha' => 'def456',
            'has_conflicts' => false,
            'detailed_merge_status' => 'mergeable',
            'merge_when_pipeline_succeeds' => false,
            'head_pipeline' => null,
        ], $overrides);
    }

    private function service(): GitLabGitService
    {
        return new GitLabGitService('glpat');
    }

    public function test_details_aggregate_pipeline_threads_approvals_and_merge_state(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr([
                'has_conflicts' => true,
                'diverged_commits_count' => 3,
                'merge_when_pipeline_succeeds' => true,
                'head_pipeline' => ['id' => 77, 'status' => 'failed', 'web_url' => 'https://gitlab.com/acme/widget/-/pipelines/77'],
            ])),
            ListFailedPipelineJobs::class => MockResponse::make([
                ['id' => 1, 'name' => 'phpunit', 'stage' => 'test', 'allow_failure' => false, 'web_url' => 'https://gitlab.com/j/1'],
                ['id' => 2, 'name' => 'optional-lint', 'stage' => 'test', 'allow_failure' => true, 'web_url' => 'https://gitlab.com/j/2'],
            ]),
            ListMergeRequestDiscussions::class => MockResponse::make([
                ['id' => 'd1', 'notes' => [
                    ['id' => 10, 'system' => true, 'body' => 'added 1 commit', 'author' => ['username' => 'anna'], 'created_at' => '2026-01-01T08:00:00Z'],
                ]],
                ['id' => 'd2', 'notes' => [
                    [
                        'id' => 12, 'system' => false, 'type' => 'DiffNote', 'resolvable' => true, 'resolved' => false,
                        'body' => 'Rename this', 'author' => ['username' => 'bob'], 'created_at' => '2026-01-01T10:00:00Z',
                        'position' => ['new_path' => 'src/B.php', 'new_line' => 8, 'old_path' => 'src/B.php', 'old_line' => null],
                    ],
                ]],
                ['id' => 'd3', 'notes' => [
                    ['id' => 11, 'system' => false, 'resolvable' => false, 'body' => 'Nice work', 'author' => ['username' => 'anna'], 'created_at' => '2026-01-01T09:00:00Z'],
                ]],
            ]),
            GetMergeRequestApprovals::class => MockResponse::make(['approved' => true, 'approved_by' => [['user' => ['username' => 'anna']]]]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 5);

        $this->assertSame('open', $details->state);
        $this->assertSame('def456', $details->headSha);
        $this->assertTrue($details->conflicts);
        $this->assertTrue($details->behindBase);
        $this->assertTrue($details->autoMerge);

        $this->assertSame('failure', $details->checks['state']);
        $this->assertSame('https://gitlab.com/acme/widget/-/pipelines/77', $details->checks['url']);
        $this->assertSame([['name' => 'phpunit', 'url' => 'https://gitlab.com/j/1']], $details->checks['failed']);

        // Unresolved thread outweighs the approval.
        $this->assertSame('changes_requested', $details->reviewState);
        $this->assertSame([['author' => 'anna', 'state' => 'approved', 'body' => '', 'submitted_at' => null]], $details->reviews);

        $this->assertSame(['11', '12'], array_column($details->comments, 'id'));
        $this->assertSame([
            'id' => '12',
            'author' => 'bob',
            'body' => 'Rename this',
            'path' => 'src/B.php',
            'line' => 8,
            'url' => 'https://gitlab.com/acme/widget/-/merge_requests/5#note_12',
            'created_at' => '2026-01-01T10:00:00Z',
        ], $details->comments[1]);

        Saloon::assertSent(fn (Request $request): bool => $request instanceof GetMergeRequest
            && $request->query()->get('include_diverged_commits_count') === 'true');
    }

    public function test_details_detect_draft_approval_and_missing_pipeline(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr(['draft' => true, 'diverged_commits_count' => 0])),
            ListMergeRequestDiscussions::class => MockResponse::make([
                ['id' => 'd1', 'notes' => [
                    ['id' => 3, 'system' => false, 'resolvable' => true, 'resolved' => true, 'body' => 'done', 'author' => ['username' => 'bob'], 'created_at' => '2026-01-01T10:00:00Z'],
                ]],
            ]),
            GetMergeRequestApprovals::class => MockResponse::make(['approved_by' => [['user' => ['username' => 'bob']]]]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 5);

        $this->assertSame('draft', $details->state);
        $this->assertFalse($details->behindBase);
        $this->assertFalse($details->conflicts);
        $this->assertSame(['state' => 'none', 'url' => null, 'failed' => []], $details->checks);
        $this->assertSame('approved', $details->reviewState);
        $this->assertFalse($details->autoMerge);
    }

    public function test_details_map_running_pipeline_and_merged_state(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr([
                'state' => 'merged',
                'detailed_merge_status' => 'need_rebase',
                'head_pipeline' => ['id' => 9, 'status' => 'running', 'web_url' => 'https://p/9'],
            ])),
            ListFailedPipelineJobs::class => MockResponse::make([]),
            ListMergeRequestDiscussions::class => MockResponse::make([]),
            GetMergeRequestApprovals::class => MockResponse::make(['approved_by' => []]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 5);

        $this->assertSame('merged', $details->state);
        $this->assertTrue($details->behindBase);
        $this->assertSame('pending', $details->checks['state']);
        $this->assertNull($details->reviewState);
    }

    public function test_mark_ready_strips_the_draft_prefix_from_the_title(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr(['draft' => true, 'title' => 'Draft: Add widget'])),
            UpdateMergeRequestAttributes::class => MockResponse::make($this->mr(['title' => 'Add widget'])),
        ]);

        $this->service()->markPullRequestReady('acme', 'widget', 5);

        Saloon::assertSent(fn (Request $request): bool => $request instanceof UpdateMergeRequestAttributes
            && $request->resolveEndpoint() === '/projects/acme%2Fwidget/merge_requests/5'
            && $request->body()->all() === ['title' => 'Add widget']);
    }

    public function test_mark_ready_is_a_noop_for_a_non_draft(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr()),
        ]);

        $this->service()->markPullRequestReady('acme', 'widget', 5);

        Saloon::assertNotSent(UpdateMergeRequestAttributes::class);
    }

    public function test_enable_auto_merge_sets_merge_when_pipeline_succeeds(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr(['head_pipeline' => ['id' => 9, 'status' => 'running']])),
            MergeWhenPipelineSucceeds::class => MockResponse::make($this->mr(['merge_when_pipeline_succeeds' => true])),
        ]);

        $this->assertTrue($this->service()->enableAutoMerge('acme', 'widget', 5));

        Saloon::assertSent(fn (Request $request): bool => $request instanceof MergeWhenPipelineSucceeds
            && $request->resolveEndpoint() === '/projects/acme%2Fwidget/merge_requests/5/merge'
            && $request->body()->all()['merge_when_pipeline_succeeds'] === true
            && $request->body()->all()['sha'] === 'def456');
    }

    public function test_enable_auto_merge_never_merges_without_a_running_pipeline(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr(['head_pipeline' => ['id' => 9, 'status' => 'success']])),
        ]);

        $this->assertFalse($this->service()->enableAutoMerge('acme', 'widget', 5));

        Saloon::assertNotSent(MergeWhenPipelineSucceeds::class);
    }

    public function test_enable_auto_merge_returns_false_when_gitlab_refuses(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr(['head_pipeline' => ['id' => 9, 'status' => 'pending']])),
            MergeWhenPipelineSucceeds::class => MockResponse::make(['message' => '405 Method Not Allowed'], 405),
        ]);

        $this->assertFalse($this->service()->enableAutoMerge('acme', 'widget', 5));
    }

    public function test_disable_auto_merge_cancels_it(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr(['merge_when_pipeline_succeeds' => true])),
            CancelMergeWhenPipelineSucceeds::class => MockResponse::make($this->mr(), 201),
        ]);

        $this->assertTrue($this->service()->disableAutoMerge('acme', 'widget', 5));

        Saloon::assertSent(CancelMergeWhenPipelineSucceeds::class);
    }

    public function test_disable_auto_merge_is_true_when_not_set(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr()),
        ]);

        $this->assertTrue($this->service()->disableAutoMerge('acme', 'widget', 5));

        Saloon::assertNotSent(CancelMergeWhenPipelineSucceeds::class);
    }

    public function test_failed_check_log_contains_the_cleaned_trace_tail_of_failed_jobs(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr(['head_pipeline' => ['id' => 77, 'status' => 'failed']])),
            ListFailedPipelineJobs::class => MockResponse::make([
                ['id' => 1, 'name' => 'phpunit', 'stage' => 'test', 'failure_reason' => 'script_failure', 'allow_failure' => false, 'web_url' => 'https://gitlab.com/j/1'],
                ['id' => 2, 'name' => 'optional-lint', 'stage' => 'test', 'allow_failure' => true],
            ]),
            GetJobTrace::class => MockResponse::make("section_start:1700000000:step_script\r\e[0K\e[31mFAILED\e[0m tests/FooTest.php\nERROR: Job failed: exit code 1\n"),
        ]);

        $log = $this->service()->failedCheckLog('acme', 'widget', 5);

        $this->assertStringContainsString('## phpunit (stage test, failed: script_failure)', $log);
        $this->assertStringContainsString('https://gitlab.com/j/1', $log);
        $this->assertStringContainsString("FAILED tests/FooTest.php\nERROR: Job failed: exit code 1", $log);
        $this->assertStringNotContainsString('optional-lint', $log);
        $this->assertStringNotContainsString('section_start', $log);
        $this->assertStringNotContainsString("\e[", $log);

        Saloon::assertSent(fn (Request $request): bool => $request instanceof GetJobTrace
            && $request->resolveEndpoint() === '/projects/acme%2Fwidget/jobs/1/trace');
    }

    public function test_failed_check_log_is_empty_when_nothing_failed(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr(['head_pipeline' => ['id' => 77, 'status' => 'success']])),
            ListFailedPipelineJobs::class => MockResponse::make([]),
        ]);

        $this->assertSame('', $this->service()->failedCheckLog('acme', 'widget', 5));
        Saloon::assertNotSent(GetJobTrace::class);
    }

    public function test_failed_check_log_is_empty_without_a_pipeline(): void
    {
        Saloon::fake([
            GetMergeRequest::class => MockResponse::make($this->mr()),
        ]);

        $this->assertSame('', $this->service()->failedCheckLog('acme', 'widget', 5));
    }
}
