<?php

declare(strict_types=1);

namespace Tests\Feature\IssueTracker;

use App\Enums\TaskProviderKind;
use App\Integrations\GitHub\Requests\AddIssueAssignees;
use App\Integrations\GitHub\Requests\CheckAssignee;
use App\Integrations\GitHub\Requests\CloseIssue;
use App\Integrations\GitHub\Requests\CreateIssue;
use App\Integrations\GitHub\Requests\GetIssueComments;
use App\Integrations\GitHub\Requests\ListIssues;
use App\Integrations\GitHub\Requests\ListRepositories;
use App\Integrations\GitHub\Requests\RegisterWebhook;
use App\Integrations\GitHub\Requests\UnregisterWebhook;
use App\Services\IssueTracker\DTO\ExternalIssue;
use App\Services\IssueTracker\Providers\GitHubIssueTracker;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class GitHubIssueTrackerTest extends TestCase
{
    private GitHubIssueTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new GitHubIssueTracker('ghp_test_token');
    }

    public function test_close_issue_patches_state_to_closed_completed(): void
    {
        Saloon::fake([
            CloseIssue::class => MockResponse::make([], 200),
        ]);

        $this->tracker->closeIssue('acme', 'widget', 42);

        Saloon::assertSent(function (Request $request, $response): bool {
            $body = $request instanceof CloseIssue ? $request->body()->all() : [];

            return $request instanceof CloseIssue
                && $response->getPendingRequest()->getMethod()->value === 'PATCH'
                && $request->resolveEndpoint() === '/repos/acme/widget/issues/42'
                && ($body['state'] ?? null) === 'closed'
                && ($body['state_reason'] ?? null) === 'completed';
        });
    }

    // ── listReferences ───────────────────────────────────────────────────────

    public function test_list_references_maps_full_names_to_ref_options(): void
    {
        Saloon::fake([
            ListRepositories::class => MockResponse::make([
                ['full_name' => 'acme/widget'],
                ['full_name' => 'acme/gadget'],
            ]),
        ]);

        $refs = $this->tracker->listReferences();

        $this->assertSame([
            'acme/widget' => 'acme/widget',
            'acme/gadget' => 'acme/gadget',
        ], $refs);

        Saloon::assertSent(fn (Request $request, $response): bool => $request instanceof ListRepositories
            && $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer ghp_test_token');
    }

    public function test_list_references_returns_empty_when_no_repos(): void
    {
        Saloon::fake([ListRepositories::class => MockResponse::make([])]);

        $this->assertSame([], $this->tracker->listReferences());
    }

    // ── registerWebhook ──────────────────────────────────────────────────────

    public function test_register_webhook_posts_to_github_hooks_endpoint(): void
    {
        Saloon::fake([
            RegisterWebhook::class => MockResponse::make([
                'id' => 42,
                'type' => 'Repository',
                'active' => true,
            ], 201),
        ]);

        $result = $this->tracker->registerWebhook(
            'acme', 'widget',
            'https://example.com/webhooks/issues/github/binding-id',
            'super-secret',
        );

        $this->assertSame(42, $result['id']);

        Saloon::assertSent(function (Request $request, $response): bool {
            $body = $request instanceof RegisterWebhook ? $request->body()->all() : [];

            return $request instanceof RegisterWebhook
                && $request->resolveEndpoint() === '/repos/acme/widget/hooks'
                && $response->getPendingRequest()->getMethod()->value === 'POST'
                && $body['name'] === 'web'
                && $body['active'] === true
                && in_array('issues', $body['events'], true)
                && in_array('issue_comment', $body['events'], true)
                && $body['config']['content_type'] === 'json'
                && $body['config']['secret'] === 'super-secret';
        });
    }

    public function test_register_webhook_sends_bearer_auth(): void
    {
        Saloon::fake([
            RegisterWebhook::class => MockResponse::make(['id' => 1], 201),
        ]);

        $this->tracker->registerWebhook('acme', 'widget', 'https://example.com', 'secret');

        Saloon::assertSent(fn (Request $request, $response): bool => $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer ghp_test_token');
    }

    // ── unregisterWebhook ────────────────────────────────────────────────────

    public function test_unregister_webhook_sends_delete_request(): void
    {
        Saloon::fake([
            UnregisterWebhook::class => MockResponse::make('', 204),
        ]);

        $this->tracker->unregisterWebhook('acme', 'widget', 42);

        Saloon::assertSent(fn (Request $request, $response): bool => $request instanceof UnregisterWebhook
            && $request->resolveEndpoint() === '/repos/acme/widget/hooks/42'
            && $response->getPendingRequest()->getMethod()->value === 'DELETE');
    }

    // ── listIssues ───────────────────────────────────────────────────────────

    public function test_list_issues_defaults_state_to_open(): void
    {
        Saloon::fake([
            ListIssues::class => MockResponse::make([]),
        ]);

        $this->tracker->listIssues('acme', 'widget');

        Saloon::assertSent(fn (Request $request): bool => $request instanceof ListIssues
            && ($request->query()->all()['state'] ?? '') === 'open');
    }

    public function test_list_issues_filters_out_pull_requests(): void
    {
        Saloon::fake([
            ListIssues::class => MockResponse::make([
                ['id' => 1, 'title' => 'Real issue', 'state' => 'open'],
                ['id' => 2, 'title' => 'A pull request', 'state' => 'open', 'pull_request' => ['url' => 'https://github.com/…']],
                ['id' => 3, 'title' => 'Another issue', 'state' => 'open'],
            ]),
        ]);

        $issues = $this->tracker->listIssues('acme', 'widget');

        $this->assertCount(2, $issues);
        $this->assertSame(1, $issues[0]['id']);
        $this->assertSame(3, $issues[1]['id']);
    }

    public function test_list_issues_paginates_via_link_header(): void
    {
        // Sequence: first send returns a Link header pointing at page 2, second
        // send returns the final page without a Link header.
        Saloon::fake([
            MockResponse::make(
                [['id' => 1, 'title' => 'Issue 1', 'state' => 'open']],
                200,
                ['Link' => '<https://api.github.com/repos/acme/widget/issues?page=2&per_page=100>; rel="next"'],
            ),
            MockResponse::make(
                [['id' => 2, 'title' => 'Issue 2', 'state' => 'open']],
                200,
            ),
        ]);

        $issues = $this->tracker->listIssues('acme', 'widget');

        $this->assertCount(2, $issues);
        $this->assertSame(1, $issues[0]['id']);
        $this->assertSame(2, $issues[1]['id']);
    }

    public function test_list_issues_pagination_also_filters_prs(): void
    {
        Saloon::fake([
            MockResponse::make(
                [
                    ['id' => 10, 'title' => 'Issue 10', 'state' => 'open'],
                    ['id' => 11, 'title' => 'PR 11', 'state' => 'open', 'pull_request' => ['url' => 'x']],
                ],
                200,
                ['Link' => '<https://api.github.com/repos/acme/widget/issues?page=2>; rel="next"'],
            ),
            MockResponse::make(
                [['id' => 12, 'title' => 'Issue 12', 'state' => 'open']],
                200,
            ),
        ]);

        $issues = $this->tracker->listIssues('acme', 'widget');

        $this->assertCount(2, $issues);
        $ids = array_column($issues, 'id');
        $this->assertContains(10, $ids);
        $this->assertContains(12, $ids);
        $this->assertNotContains(11, $ids);
    }

    // ── verifySignature ──────────────────────────────────────────────────────

    public function test_verify_signature_accepts_valid_hmac(): void
    {
        $payload = '{"action":"opened"}';
        $secret = 'my-webhook-secret';
        $sig = 'sha256='.hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($this->tracker->verifySignature($payload, $sig, $secret));
    }

    public function test_verify_signature_rejects_tampered_payload(): void
    {
        $secret = 'my-webhook-secret';
        $sig = 'sha256='.hash_hmac('sha256', '{"action":"opened"}', $secret);

        $this->assertFalse($this->tracker->verifySignature('tampered', $sig, $secret));
    }

    public function test_verify_signature_rejects_wrong_secret(): void
    {
        $payload = '{"action":"opened"}';
        $sig = 'sha256='.hash_hmac('sha256', $payload, 'correct-secret');

        $this->assertFalse($this->tracker->verifySignature($payload, $sig, 'wrong-secret'));
    }

    // ── normalizeWebhookPayload ──────────────────────────────────────────────

    public function test_normalize_webhook_payload_extracts_issue_for_issues_event(): void
    {
        $envelope = [
            'action' => 'opened',
            'issue' => ['id' => 7, 'title' => 'Bug report', 'state' => 'open'],
            'repository' => ['full_name' => 'acme/widget'],
            'sender' => ['login' => 'user'],
        ];

        $result = $this->tracker->normalizeWebhookPayload($envelope, 'issues');

        $this->assertSame(7, $result['id']);
        $this->assertSame('Bug report', $result['title']);
        $this->assertArrayNotHasKey('action', $result);
        $this->assertArrayNotHasKey('repository', $result);
    }

    public function test_normalize_webhook_payload_returns_empty_for_issue_comment_event(): void
    {
        $envelope = [
            'action' => 'created',
            'issue' => ['id' => 7, 'title' => 'Bug report'],
            'comment' => ['body' => 'Thanks'],
        ];

        $result = $this->tracker->normalizeWebhookPayload($envelope, 'issue_comment');

        $this->assertSame([], $result);
    }

    public function test_normalize_webhook_payload_returns_empty_for_push_event(): void
    {
        $result = $this->tracker->normalizeWebhookPayload(['ref' => 'refs/heads/main'], 'push');

        $this->assertSame([], $result);
    }

    public function test_normalize_webhook_payload_returns_empty_for_null_event(): void
    {
        $result = $this->tracker->normalizeWebhookPayload(['issue' => ['id' => 1]], null);

        $this->assertSame([], $result);
    }

    public function test_normalize_webhook_payload_returns_empty_for_pr_envelope(): void
    {
        $envelope = [
            'action' => 'opened',
            'issue' => [
                'id' => 9,
                'title' => 'PR as issue',
                'pull_request' => ['url' => 'https://api.github.com/repos/acme/widget/pulls/9'],
            ],
        ];

        $result = $this->tracker->normalizeWebhookPayload($envelope, 'issues');

        $this->assertSame([], $result);
    }

    public function test_normalize_webhook_payload_returns_empty_when_no_issue_key(): void
    {
        $result = $this->tracker->normalizeWebhookPayload(['action' => 'opened'], 'issues');

        $this->assertSame([], $result);
    }

    // ── reactions & approval (👍-to-start-implement) ──────────────────────────

    public function test_get_comment_reactions_maps_content_and_user(): void
    {
        Saloon::fake([
            'https://api.github.com/repos/acme/widget/issues/comments/555/reactions*' => MockResponse::make([
                ['content' => '+1', 'user' => ['id' => 7, 'login' => 'maintainer']],
                ['content' => 'heart', 'user' => ['id' => 8, 'login' => 'fan']],
            ]),
        ]);

        $reactions = $this->tracker->getCommentReactions('acme', 'widget', 19, 555);

        $this->assertSame([
            ['emoji' => '+1', 'user_id' => '7', 'user_login' => 'maintainer'],
            ['emoji' => 'heart', 'user_id' => '8', 'user_login' => 'fan'],
        ], $reactions);
    }

    public function test_user_can_approve_only_with_write_or_admin_permission(): void
    {
        Saloon::fake([
            'https://api.github.com/repos/acme/widget/collaborators/maintainer/permission' => MockResponse::make(['permission' => 'write']),
            'https://api.github.com/repos/acme/widget/collaborators/reader/permission' => MockResponse::make(['permission' => 'read']),
        ]);

        $this->assertTrue($this->tracker->userCanApprove('acme', 'widget', ['emoji' => '+1', 'user_id' => '7', 'user_login' => 'maintainer']));
        $this->assertFalse($this->tracker->userCanApprove('acme', 'widget', ['emoji' => '+1', 'user_id' => '8', 'user_login' => 'reader']));
    }

    public function test_user_can_approve_is_false_when_permission_lookup_fails(): void
    {
        Saloon::fake([
            'https://api.github.com/repos/acme/widget/collaborators/*/permission' => MockResponse::make(['message' => 'Not Found'], 404),
        ]);

        $this->assertFalse($this->tracker->userCanApprove('acme', 'widget', ['emoji' => '+1', 'user_id' => '9', 'user_login' => 'stranger']));
    }

    public function test_comment_id_extracts_id_from_create_result(): void
    {
        $this->assertSame('12345', $this->tracker->commentId(['id' => 12345]));
        $this->assertNull($this->tracker->commentId([]));
    }

    // ── createIssue / listComments / assignIssue ─────────────────────────────

    public function test_create_issue_posts_title_and_body_and_returns_raw_issue(): void
    {
        Saloon::fake([
            CreateIssue::class => MockResponse::make([
                'number' => 77,
                'title' => 'New bug',
                'body' => 'Steps…',
                'html_url' => 'https://github.com/acme/widget/issues/77',
                'state' => 'open',
                'labels' => [],
            ], 201),
        ]);

        $raw = $this->tracker->createIssue('acme', 'widget', 'New bug', 'Steps…');

        $issue = ExternalIssue::fromProvider($raw, TaskProviderKind::GitHub);
        $this->assertSame('77', $issue->externalId);
        $this->assertSame('https://github.com/acme/widget/issues/77', $issue->url);

        Saloon::assertSent(function (Request $request, $response): bool {
            $body = $request instanceof CreateIssue ? $request->body()->all() : [];

            return $request instanceof CreateIssue
                && $response->getPendingRequest()->getMethod()->value === 'POST'
                && $request->resolveEndpoint() === '/repos/acme/widget/issues'
                && $body === ['title' => 'New bug', 'body' => 'Steps…'];
        });
    }

    public function test_list_comments_normalizes_oldest_first(): void
    {
        Saloon::fake([
            GetIssueComments::class => MockResponse::make([
                ['id' => 2, 'user' => ['login' => 'bob'], 'body' => 'second', 'created_at' => '2026-01-02T00:00:00Z', 'html_url' => 'https://github.com/c/2'],
                ['id' => 1, 'user' => ['login' => 'alice'], 'body' => 'first', 'created_at' => '2026-01-01T00:00:00Z', 'html_url' => 'https://github.com/c/1'],
            ]),
        ]);

        $comments = $this->tracker->listComments('acme', 'widget', 5);

        $this->assertSame([
            ['id' => '1', 'author' => 'alice', 'body' => 'first', 'created_at' => '2026-01-01T00:00:00Z', 'url' => 'https://github.com/c/1'],
            ['id' => '2', 'author' => 'bob', 'body' => 'second', 'created_at' => '2026-01-02T00:00:00Z', 'url' => 'https://github.com/c/2'],
        ], $comments);
    }

    public function test_assign_issue_checks_assignability_then_adds_assignee(): void
    {
        Saloon::fake([
            CheckAssignee::class => MockResponse::make('', 204),
            AddIssueAssignees::class => MockResponse::make(['number' => 5, 'assignees' => [['login' => 'Octocat']]], 201),
        ]);

        $this->assertTrue($this->tracker->assignIssue('acme', 'widget', 5, 'octocat'));

        Saloon::assertSent(fn (Request $r): bool => $r instanceof CheckAssignee
            && $r->resolveEndpoint() === '/repos/acme/widget/assignees/octocat');
        Saloon::assertSent(fn (Request $r): bool => $r instanceof AddIssueAssignees
            && $r->resolveEndpoint() === '/repos/acme/widget/issues/5/assignees'
            && $r->body()->all() === ['assignees' => ['octocat']]);
    }

    public function test_assign_issue_returns_false_when_user_is_not_assignable(): void
    {
        Saloon::fake([
            CheckAssignee::class => MockResponse::make(['message' => 'Not Found'], 404),
            AddIssueAssignees::class => MockResponse::make([], 201),
        ]);

        $this->assertFalse($this->tracker->assignIssue('acme', 'widget', 5, 'ghost'));

        Saloon::assertNotSent(AddIssueAssignees::class);
    }

    public function test_assign_issue_returns_false_when_github_drops_the_assignee(): void
    {
        Saloon::fake([
            CheckAssignee::class => MockResponse::make('', 204),
            AddIssueAssignees::class => MockResponse::make(['number' => 5, 'assignees' => []], 201),
        ]);

        $this->assertFalse($this->tracker->assignIssue('acme', 'widget', 5, 'octocat'));
    }

    public function test_assign_issue_returns_false_instead_of_throwing_on_server_error(): void
    {
        Saloon::fake([
            CheckAssignee::class => MockResponse::make('', 204),
            AddIssueAssignees::class => MockResponse::make(['message' => 'boom'], 500),
        ]);

        $this->assertFalse($this->tracker->assignIssue('acme', 'widget', 5, 'octocat'));
    }
}
