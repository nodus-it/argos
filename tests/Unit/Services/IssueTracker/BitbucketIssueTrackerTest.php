<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Integrations\Bitbucket\Requests\CloseIssue;
use App\Integrations\Bitbucket\Requests\CreateIssue;
use App\Integrations\Bitbucket\Requests\GetIssueComments;
use App\Integrations\Bitbucket\Requests\ListWorkspaceMembers;
use App\Integrations\Bitbucket\Requests\UpdateIssueAssignee;
use App\Services\IssueTracker\Providers\BitbucketIssueTracker;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class BitbucketIssueTrackerTest extends TestCase
{
    public function test_close_issue_sets_state_resolved(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories/acme/widget/issues/7' => MockResponse::make([], 200),
        ]);

        (new BitbucketIssueTracker('user:app-pw'))->closeIssue('acme', 'widget', 7);

        Saloon::assertSent(function (Request $r, $response): bool {
            $body = $r instanceof CloseIssue ? $r->body()->all() : [];

            return $r instanceof CloseIssue
                && $response->getPendingRequest()->getMethod()->value === 'PUT'
                && str_contains($r->resolveEndpoint(), '/issues/7')
                && ($body['state'] ?? null) === 'resolved';
        });
    }

    public function test_list_references_maps_full_names_to_ref_options(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories*' => MockResponse::make([
                'values' => [
                    ['full_name' => 'acme/widget'],
                    ['full_name' => 'acme/gadget'],
                ],
            ]),
        ]);

        $refs = (new BitbucketIssueTracker('user:app-pw'))->listReferences();

        $this->assertSame([
            'acme/widget' => 'acme/widget',
            'acme/gadget' => 'acme/gadget',
        ], $refs);
    }

    public function test_list_references_returns_empty_when_access_forbidden(): void
    {
        Saloon::fake([
            'https://api.bitbucket.org/2.0/repositories*' => MockResponse::make('', 403),
        ]);

        $this->assertSame([], (new BitbucketIssueTracker('token'))->listReferences());
    }

    // ── createIssue / listComments / assignIssue ─────────────────────────────

    public function test_create_issue_posts_title_and_raw_content(): void
    {
        Saloon::fake([
            CreateIssue::class => MockResponse::make(['id' => 8, 'title' => 'New bug', 'content' => ['raw' => 'Steps…'], 'state' => 'new'], 201),
        ]);

        $raw = (new BitbucketIssueTracker('user:app-pw'))->createIssue('acme', 'widget', 'New bug', 'Steps…');

        $this->assertSame(8, $raw['id']);
        Saloon::assertSent(fn (Request $r, $response): bool => $r instanceof CreateIssue
            && $response->getPendingRequest()->getMethod()->value === 'POST'
            && $r->resolveEndpoint() === '/repositories/acme/widget/issues'
            && $r->body()->all() === ['title' => 'New bug', 'content' => ['raw' => 'Steps…']]);
    }

    public function test_list_comments_normalizes_oldest_first(): void
    {
        Saloon::fake([
            GetIssueComments::class => MockResponse::make(['values' => [
                ['id' => 2, 'user' => ['nickname' => 'bob'], 'content' => ['raw' => 'second'], 'created_on' => '2026-01-02T00:00:00Z', 'links' => ['html' => ['href' => 'https://bitbucket.org/c/2']]],
                ['id' => 1, 'user' => ['nickname' => 'alice'], 'content' => ['raw' => 'first'], 'created_on' => '2026-01-01T00:00:00Z'],
            ]]),
        ]);

        $comments = (new BitbucketIssueTracker('user:app-pw'))->listComments('acme', 'widget', 7);

        $this->assertSame([
            ['id' => '1', 'author' => 'alice', 'body' => 'first', 'created_at' => '2026-01-01T00:00:00Z', 'url' => null],
            ['id' => '2', 'author' => 'bob', 'body' => 'second', 'created_at' => '2026-01-02T00:00:00Z', 'url' => 'https://bitbucket.org/c/2'],
        ], $comments);
    }

    public function test_assign_issue_resolves_member_uuid_by_nickname(): void
    {
        Saloon::fake([
            ListWorkspaceMembers::class => MockResponse::make(['values' => [
                ['user' => ['nickname' => 'bob', 'uuid' => '{bob-uuid}']],
                ['user' => ['nickname' => 'Alice', 'uuid' => '{alice-uuid}']],
            ]]),
            UpdateIssueAssignee::class => MockResponse::make(['id' => 7]),
        ]);

        $this->assertTrue((new BitbucketIssueTracker('user:app-pw'))->assignIssue('acme', 'widget', 7, 'alice'));

        Saloon::assertSent(fn (Request $r): bool => $r instanceof ListWorkspaceMembers
            && $r->resolveEndpoint() === '/workspaces/acme/members');
        Saloon::assertSent(fn (Request $r): bool => $r instanceof UpdateIssueAssignee
            && $r->resolveEndpoint() === '/repositories/acme/widget/issues/7'
            && $r->body()->all() === ['assignee' => ['uuid' => '{alice-uuid}']]);
    }

    public function test_assign_issue_returns_false_when_member_not_found(): void
    {
        Saloon::fake([
            ListWorkspaceMembers::class => MockResponse::make(['values' => [['user' => ['nickname' => 'bob', 'uuid' => '{bob-uuid}']]]]),
            UpdateIssueAssignee::class => MockResponse::make([]),
        ]);

        $this->assertFalse((new BitbucketIssueTracker('user:app-pw'))->assignIssue('acme', 'widget', 7, 'ghost'));

        Saloon::assertNotSent(UpdateIssueAssignee::class);
    }

    public function test_assign_issue_returns_false_when_bitbucket_refuses(): void
    {
        Saloon::fake([
            ListWorkspaceMembers::class => MockResponse::make(['values' => [['user' => ['nickname' => 'alice', 'uuid' => '{alice-uuid}']]]]),
            UpdateIssueAssignee::class => MockResponse::make(['error' => ['message' => 'nope']], 400),
        ]);

        $this->assertFalse((new BitbucketIssueTracker('user:app-pw'))->assignIssue('acme', 'widget', 7, 'alice'));
    }
}
