<?php

declare(strict_types=1);

namespace Tests\Unit\Services\GitProvider;

use App\Integrations\Bitbucket\Requests\GetPullRequest;
use App\Integrations\Bitbucket\Requests\GetPullRequestDiffstat;
use App\Integrations\Bitbucket\Requests\ListPullRequestComments;
use App\Integrations\Bitbucket\Requests\ListPullRequestStatuses;
use App\Integrations\Bitbucket\Requests\UpdatePullRequestAttributes;
use App\Services\GitProvider\BitbucketGitService;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class BitbucketPullRequestReviewTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pr(array $overrides = []): array
    {
        return array_merge([
            'id' => 3,
            'title' => 'Add widget',
            'state' => 'OPEN',
            'draft' => false,
            'links' => ['html' => ['href' => 'https://bitbucket.org/acme/widget/pull-requests/3']],
            'source' => ['commit' => ['hash' => 'aaa111bbb222']],
            'participants' => [],
        ], $overrides);
    }

    private function service(): BitbucketGitService
    {
        return new BitbucketGitService('user:app_password');
    }

    public function test_details_aggregate_statuses_participants_comments_and_conflicts(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr([
                'draft' => true,
                'participants' => [
                    ['user' => ['nickname' => 'anna'], 'role' => 'REVIEWER', 'approved' => true, 'state' => 'approved', 'participated_on' => '2026-01-01T10:00:00Z'],
                    ['user' => ['nickname' => 'bob'], 'role' => 'REVIEWER', 'approved' => false, 'state' => 'changes_requested', 'participated_on' => '2026-01-01T11:00:00Z'],
                    ['user' => ['nickname' => 'carl'], 'role' => 'PARTICIPANT', 'approved' => false, 'state' => null],
                ],
            ])),
            ListPullRequestStatuses::class => MockResponse::make(['values' => [
                ['key' => 'build', 'name' => 'Pipeline #4', 'state' => 'FAILED', 'url' => 'https://bb/pipelines/4'],
                ['key' => 'lint', 'name' => 'Lint', 'state' => 'SUCCESSFUL', 'url' => 'https://bb/lint'],
            ]]),
            GetPullRequestDiffstat::class => MockResponse::make(['values' => [
                ['status' => 'modified', 'new' => ['path' => 'a.php']],
                ['status' => 'merge conflict', 'new' => ['path' => 'b.php']],
            ]]),
            ListPullRequestComments::class => MockResponse::make(['values' => [
                ['id' => 22, 'user' => ['nickname' => 'bob'], 'content' => ['raw' => 'Inline note'], 'inline' => ['path' => 'b.php', 'to' => 4, 'from' => null], 'links' => ['html' => ['href' => 'https://bb/c/22']], 'created_on' => '2026-01-01T11:00:00Z', 'deleted' => false],
                ['id' => 21, 'user' => ['nickname' => 'anna'], 'content' => ['raw' => 'General'], 'links' => ['html' => ['href' => 'https://bb/c/21']], 'created_on' => '2026-01-01T09:00:00Z', 'deleted' => false],
                ['id' => 23, 'user' => ['nickname' => 'anna'], 'content' => ['raw' => ''], 'created_on' => '2026-01-01T12:00:00Z', 'deleted' => true],
            ]]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 3);

        $this->assertSame('draft', $details->state);
        $this->assertSame('https://bitbucket.org/acme/widget/pull-requests/3', $details->url);
        $this->assertSame('aaa111bbb222', $details->headSha);
        $this->assertTrue($details->conflicts);
        $this->assertNull($details->behindBase);
        $this->assertFalse($details->autoMerge);

        $this->assertSame('failure', $details->checks['state']);
        $this->assertSame('https://bb/pipelines/4', $details->checks['url']);
        $this->assertSame([['name' => 'Pipeline #4', 'url' => 'https://bb/pipelines/4']], $details->checks['failed']);

        $this->assertSame('changes_requested', $details->reviewState);
        $this->assertSame(['anna', 'bob'], array_column($details->reviews, 'author'));

        $this->assertSame(['21', '22'], array_column($details->comments, 'id'));
        $this->assertSame('b.php', $details->comments[1]['path']);
        $this->assertSame(4, $details->comments[1]['line']);
    }

    public function test_details_without_statuses_or_reviews_but_with_comments(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr()),
            ListPullRequestStatuses::class => MockResponse::make(['values' => []]),
            GetPullRequestDiffstat::class => MockResponse::make(['values' => [['status' => 'modified']]]),
            ListPullRequestComments::class => MockResponse::make(['values' => [
                ['id' => 1, 'user' => ['display_name' => 'Anna'], 'content' => ['raw' => 'Question?'], 'created_on' => '2026-01-01T09:00:00Z'],
            ]]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 3);

        $this->assertSame('open', $details->state);
        $this->assertFalse($details->conflicts);
        $this->assertSame(['state' => 'none', 'url' => null, 'failed' => []], $details->checks);
        $this->assertSame('commented', $details->reviewState);
        $this->assertSame('Anna', $details->comments[0]['author']);
    }

    public function test_details_map_declined_to_closed(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['state' => 'DECLINED'])),
            ListPullRequestStatuses::class => MockResponse::make(['values' => [['name' => 'b', 'state' => 'INPROGRESS']]]),
            ListPullRequestComments::class => MockResponse::make(['values' => []]),
        ]);

        $details = $this->service()->pullRequestDetails('acme', 'widget', 3);

        $this->assertSame('closed', $details->state);
        $this->assertSame('pending', $details->checks['state']);
        Saloon::assertNotSent(GetPullRequestDiffstat::class);
    }

    public function test_mark_ready_clears_the_draft_flag(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['draft' => true])),
            UpdatePullRequestAttributes::class => MockResponse::make($this->pr(['draft' => false])),
        ]);

        $this->service()->markPullRequestReady('acme', 'widget', 3);

        Saloon::assertSent(fn (Request $request): bool => $request instanceof UpdatePullRequestAttributes
            && $request->resolveEndpoint() === '/repositories/acme/widget/pullrequests/3'
            && $request->body()->all() === ['title' => 'Add widget', 'draft' => false]);
    }

    public function test_mark_ready_throws_when_bitbucket_keeps_the_draft(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['draft' => true])),
            UpdatePullRequestAttributes::class => MockResponse::make($this->pr(['draft' => true])),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support marking a draft pull request ready');

        $this->service()->markPullRequestReady('acme', 'widget', 3);
    }

    public function test_mark_ready_throws_when_bitbucket_rejects_the_update(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr(['draft' => true])),
            UpdatePullRequestAttributes::class => MockResponse::make(['type' => 'error', 'error' => ['message' => 'Unknown field draft']], 400),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown field draft');

        $this->service()->markPullRequestReady('acme', 'widget', 3);
    }

    public function test_mark_ready_is_a_noop_for_a_non_draft(): void
    {
        Saloon::fake([
            GetPullRequest::class => MockResponse::make($this->pr()),
        ]);

        $this->service()->markPullRequestReady('acme', 'widget', 3);

        Saloon::assertNotSent(UpdatePullRequestAttributes::class);
    }

    public function test_auto_merge_is_not_supported(): void
    {
        Saloon::fake([]);

        $this->assertFalse($this->service()->enableAutoMerge('acme', 'widget', 3));
        $this->assertFalse($this->service()->disableAutoMerge('acme', 'widget', 3));

        Saloon::assertNothingSent();
    }

    public function test_failed_check_log_lists_failed_statuses(): void
    {
        Saloon::fake([
            ListPullRequestStatuses::class => MockResponse::make(['values' => [
                ['key' => 'build', 'name' => 'Pipeline #4', 'state' => 'FAILED', 'description' => '3 tests failed', 'url' => 'https://bb/pipelines/4'],
                ['key' => 'lint', 'name' => 'Lint', 'state' => 'SUCCESSFUL', 'url' => 'https://bb/lint'],
            ]]),
        ]);

        $log = $this->service()->failedCheckLog('acme', 'widget', 3);

        $this->assertSame("## Pipeline #4 (failed)\n3 tests failed\nhttps://bb/pipelines/4", $log);
    }

    public function test_failed_check_log_is_empty_when_nothing_failed(): void
    {
        Saloon::fake([
            ListPullRequestStatuses::class => MockResponse::make(['values' => [
                ['key' => 'lint', 'name' => 'Lint', 'state' => 'SUCCESSFUL'],
            ]]),
        ]);

        $this->assertSame('', $this->service()->failedCheckLog('acme', 'widget', 3));
    }
}
