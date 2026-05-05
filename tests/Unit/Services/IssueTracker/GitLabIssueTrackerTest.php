<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Services\IssueTracker\GitLabIssueTracker;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitLabIssueTrackerTest extends TestCase
{
    public function test_list_issues_uses_bearer_auth(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => Http::response([]),
        ]);

        (new GitLabIssueTracker('glpat-tok'))->listIssues('acme', 'widget');

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer glpat-tok');
        });
    }

    public function test_list_issues_returns_array(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => Http::response([
                ['id' => 1, 'title' => 'Bug'],
                ['id' => 2, 'title' => 'Feature'],
            ]),
        ]);

        $result = (new GitLabIssueTracker('tok'))->listIssues('acme', 'widget');

        $this->assertCount(2, $result);
    }

    public function test_get_issue_merges_notes_and_emojis(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues/42' => Http::response([
                'id' => 42, 'title' => 'Test issue',
            ]),
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues/42/notes*' => Http::response([
                ['id' => 1, 'body' => 'First comment'],
            ]),
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues/42/award_emoji*' => Http::response([
                ['id' => 1, 'name' => 'thumbsup'],
            ]),
        ]);

        $result = (new GitLabIssueTracker('tok'))->getIssue('acme', 'widget', 42);

        $this->assertSame('Test issue', $result['title']);
        $this->assertCount(1, $result['comments_data']);
        $this->assertCount(1, $result['reactions_data']);
    }

    public function test_get_issue_gracefully_handles_missing_award_emoji(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues/1' => Http::response([
                'id' => 1, 'title' => 'Issue',
            ]),
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues/1/notes*' => Http::response([]),
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues/1/award_emoji*' => Http::response(
                ['message' => '404 Not found'],
                404
            ),
        ]);

        $result = (new GitLabIssueTracker('tok'))->getIssue('acme', 'widget', 1);

        $this->assertSame([], $result['reactions_data']);
    }

    public function test_create_issue_posts_correct_payload(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues' => Http::response([
                'id' => 10, 'title' => 'New issue',
            ], 201),
        ]);

        $result = (new GitLabIssueTracker('tok'))->createIssue(
            'acme', 'widget', 'New issue', 'Description body'
        );

        $this->assertSame('New issue', $result['title']);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return $body['title'] === 'New issue' && $body['description'] === 'Description body';
        });
    }

    public function test_create_comment_posts_note(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues/5/notes' => Http::response([
                'id' => 99, 'body' => 'Hello',
            ], 201),
        ]);

        $result = (new GitLabIssueTracker('tok'))->createComment('acme', 'widget', 5, 'Hello');

        $this->assertSame(99, $result['id']);
    }

    public function test_no_private_token_header_sent(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => Http::response([]),
        ]);

        (new GitLabIssueTracker('tok'))->listIssues('acme', 'widget');

        Http::assertSent(function ($request): bool {
            return ! $request->hasHeader('PRIVATE-TOKEN');
        });
    }

    public function test_self_hosted_uses_custom_instance_url(): void
    {
        Http::fake([
            'https://git.example.com/api/v4/projects/acme%2Fwidget/issues*' => Http::response([]),
        ]);

        (new GitLabIssueTracker('tok', 'https://git.example.com'))->listIssues('acme', 'widget');

        Http::assertSent(function ($request): bool {
            return str_starts_with((string) $request->url(), 'https://git.example.com/api/v4/');
        });
    }
}
