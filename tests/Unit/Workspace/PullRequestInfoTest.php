<?php

declare(strict_types=1);

namespace Tests\Unit\Workspace;

use App\Enums\GitProvider;
use App\Services\GitProvider\PullRequestInfo;
use PHPUnit\Framework\TestCase;

class PullRequestInfoTest extends TestCase
{
    public function test_github(): void
    {
        $open = PullRequestInfo::from(GitProvider::GitHub, ['number' => 12, 'html_url' => 'https://github.com/o/r/pull/12', 'state' => 'open', 'merged' => false]);
        $this->assertEquals(new PullRequestInfo(12, 'https://github.com/o/r/pull/12', 'open'), $open);
        $this->assertSame('merged', PullRequestInfo::from(GitProvider::GitHub, ['number' => 1, 'state' => 'closed', 'merged' => true])->state);
        $this->assertSame('merged', PullRequestInfo::from(GitProvider::GitHub, ['number' => 1, 'state' => 'closed', 'merged_at' => '2026-10-20T10:00:00Z'])->state);
        $this->assertSame('closed', PullRequestInfo::from(GitProvider::GitHub, ['number' => 1, 'state' => 'closed', 'merged' => false])->state);
    }

    public function test_gitlab(): void
    {
        $info = PullRequestInfo::from(GitProvider::GitLab, ['iid' => 7, 'id' => 999, 'web_url' => 'https://gitlab.com/g/r/-/merge_requests/7', 'state' => 'opened']);
        $this->assertEquals(new PullRequestInfo(7, 'https://gitlab.com/g/r/-/merge_requests/7', 'open'), $info);
        $this->assertSame('merged', PullRequestInfo::from(GitProvider::GitLab, ['iid' => 7, 'state' => 'merged'])->state);
    }

    public function test_bitbucket(): void
    {
        $info = PullRequestInfo::from(GitProvider::Bitbucket, ['id' => 3, 'links' => ['html' => ['href' => 'https://bitbucket.org/w/r/pull-requests/3']], 'state' => 'OPEN']);
        $this->assertEquals(new PullRequestInfo(3, 'https://bitbucket.org/w/r/pull-requests/3', 'open'), $info);
        $this->assertSame('closed', PullRequestInfo::from(GitProvider::Bitbucket, ['id' => 3, 'state' => 'DECLINED'])->state);
    }
}
