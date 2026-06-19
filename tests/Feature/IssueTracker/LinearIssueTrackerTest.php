<?php

declare(strict_types=1);

namespace Tests\Feature\IssueTracker;

use App\Services\IssueTracker\Providers\LinearIssueTracker;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class LinearIssueTrackerTest extends TestCase
{
    private LinearIssueTracker $tracker;

    private const TEAM_KEY = 'ENG';

    private const TEAM_ID = 'team-uuid-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new LinearIssueTracker('lin_api_test_token');
    }

    public function test_close_issue_moves_to_completed_workflow_state(): void
    {
        Saloon::fake([
            MockResponse::make(['data' => ['issue' => ['team' => ['states' => ['nodes' => [['id' => 'state-done-1']]]]]]]),
            MockResponse::make(['data' => ['issueUpdate' => ['success' => true]]]),
        ]);

        $this->tracker->closeIssue('', '', 'issue-uuid-1');

        Saloon::assertSentCount(2);
        Saloon::assertSent(function (Request $request): bool {
            $body = $request->body()->all();

            return str_contains((string) ($body['query'] ?? ''), 'issueUpdate')
                && ($body['variables']['id'] ?? null) === 'issue-uuid-1'
                && ($body['variables']['stateId'] ?? null) === 'state-done-1';
        });
    }

    // ── listIssues ───────────────────────────────────────────────────────────

    public function test_list_issues_resolves_team_and_returns_normalized_issues(): void
    {
        Saloon::fake([
            MockResponse::make($this->teamResponse()),
            MockResponse::make([
                'data' => [
                    'issues' => [
                        'nodes' => [
                            [
                                'id' => 'issue-uuid-1',
                                'title' => 'Fix login bug',
                                'description' => 'Users cannot log in.',
                                'url' => 'https://linear.app/eng/issue/ENG-1',
                                'state' => ['name' => 'Todo'],
                                'labels' => ['nodes' => [['name' => 'bug']]],
                            ],
                            [
                                'id' => 'issue-uuid-2',
                                'title' => 'Add dark mode',
                                'description' => '',
                                'url' => 'https://linear.app/eng/issue/ENG-2',
                                'state' => ['name' => 'In Progress'],
                                'labels' => ['nodes' => []],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $issues = $this->tracker->listIssues(self::TEAM_KEY, '');

        $this->assertCount(2, $issues);

        $this->assertSame('issue-uuid-1', $issues[0]['id']);
        $this->assertSame('Fix login bug', $issues[0]['title']);
        $this->assertSame('Users cannot log in.', $issues[0]['body']);
        $this->assertSame('https://linear.app/eng/issue/ENG-1', $issues[0]['html_url']);
        $this->assertSame('Todo', $issues[0]['state']);
        $this->assertSame([['name' => 'bug']], $issues[0]['labels']);

        $this->assertSame('issue-uuid-2', $issues[1]['id']);
        $this->assertSame([], $issues[1]['labels']);
    }

    public function test_list_issues_sends_personal_api_key_without_bearer_prefix(): void
    {
        Saloon::fake([
            MockResponse::make($this->teamResponse()),
            MockResponse::make(['data' => ['issues' => ['nodes' => []]]]),
        ]);

        $this->tracker->listIssues(self::TEAM_KEY, '');

        // Linear personal API keys (lin_api_…) are sent raw — a Bearer prefix
        // makes Linear reject the request with a 400.
        Saloon::assertSent(fn (Request $r, $response): bool => $response->getPendingRequest()->headers()->get('Authorization') === 'lin_api_test_token');
    }

    public function test_oauth_access_token_uses_bearer_prefix(): void
    {
        Saloon::fake([
            MockResponse::make($this->teamResponse()),
            MockResponse::make(['data' => ['issues' => ['nodes' => []]]]),
        ]);

        (new LinearIssueTracker('lin_oauth_access_token'))->listIssues(self::TEAM_KEY, '');

        Saloon::assertSent(fn (Request $r, $response): bool => $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer lin_oauth_access_token');
    }

    // ── listReferences ───────────────────────────────────────────────────────

    public function test_list_references_maps_team_keys_to_labelled_options(): void
    {
        Saloon::fake([
            'https://api.linear.app/graphql' => MockResponse::make([
                'data' => [
                    'teams' => [
                        'nodes' => [
                            ['key' => 'ENG', 'name' => 'Engineering'],
                            ['key' => 'OPS', 'name' => ''],
                        ],
                    ],
                ],
            ]),
        ]);

        $refs = $this->tracker->listReferences();

        $this->assertSame([
            'ENG' => 'ENG — Engineering',
            'OPS' => 'OPS',
        ], $refs);

        Saloon::assertSent(function (Request $request, $response): bool {
            $body = $request->body()->all();

            // Linear rejects `variables: []` — a variable-less query must omit the key.
            return str_contains((string) ($body['query'] ?? ''), 'teams')
                && ! array_key_exists('variables', $body)
                && $response->getPendingRequest()->headers()->get('Authorization') === 'lin_api_test_token';
        });
    }

    // ── getIssue ─────────────────────────────────────────────────────────────

    public function test_get_issue_fetches_by_uuid(): void
    {
        Saloon::fake([
            'https://api.linear.app/graphql' => MockResponse::make([
                'data' => [
                    'issue' => [
                        'id' => 'issue-uuid-99',
                        'title' => 'Critical crash',
                        'description' => 'App crashes on startup.',
                        'url' => 'https://linear.app/eng/issue/ENG-99',
                        'state' => ['name' => 'Todo'],
                        'labels' => ['nodes' => [['name' => 'critical']]],
                        'comments' => ['nodes' => []],
                    ],
                ],
            ]),
        ]);

        $issue = $this->tracker->getIssue('', '', 'issue-uuid-99');

        $this->assertSame('issue-uuid-99', $issue['id']);
        $this->assertSame('Critical crash', $issue['title']);
        $this->assertSame([['name' => 'critical']], $issue['labels']);

        Saloon::assertSent(function (Request $request): bool {
            $body = $request->body()->all();

            return str_contains((string) ($body['query'] ?? ''), 'GetIssue')
                && ($body['variables']['id'] ?? '') === 'issue-uuid-99';
        });
    }

    // ── createComment ────────────────────────────────────────────────────────

    public function test_create_comment_sends_comment_create_mutation(): void
    {
        Saloon::fake([
            'https://api.linear.app/graphql' => MockResponse::make([
                'data' => [
                    'commentCreate' => [
                        'success' => true,
                        'comment' => ['id' => 'comment-uuid-1'],
                    ],
                ],
            ]),
        ]);

        $result = $this->tracker->createComment('', '', 'issue-uuid-1', 'This is fixed in v2.');

        $this->assertTrue($result['success']);
        $this->assertSame('comment-uuid-1', $result['comment']['id']);

        Saloon::assertSent(function (Request $request): bool {
            $body = $request->body()->all();
            $input = $body['variables']['input'] ?? [];

            return str_contains((string) ($body['query'] ?? ''), 'commentCreate')
                && $input['issueId'] === 'issue-uuid-1'
                && $input['body'] === 'This is fixed in v2.';
        });
    }

    // ── registerWebhook ──────────────────────────────────────────────────────

    public function test_register_webhook_posts_webhook_create_mutation(): void
    {
        Saloon::fake([
            MockResponse::make($this->teamResponse()),
            MockResponse::make([
                'data' => [
                    'webhookCreate' => [
                        'success' => true,
                        'webhook' => [
                            'id' => 'webhook-uuid-42',
                            'url' => 'https://example.com/webhooks/issues/linear/binding-id',
                        ],
                    ],
                ],
            ]),
        ]);

        $result = $this->tracker->registerWebhook(
            self::TEAM_KEY, '',
            'https://example.com/webhooks/issues/linear/binding-id',
            'super-secret',
        );

        $this->assertSame('webhook-uuid-42', $result['id']);

        Saloon::assertSent(function (Request $request): bool {
            $body = $request->body()->all();
            $input = $body['variables']['input'] ?? [];

            if (! str_contains((string) ($body['query'] ?? ''), 'webhookCreate')) {
                return false;
            }

            return in_array('Issue', $input['resourceTypes'] ?? [], true)
                && $input['teamId'] === self::TEAM_ID
                && $input['secret'] === 'super-secret';
        });
    }

    // ── unregisterWebhook ────────────────────────────────────────────────────

    public function test_unregister_webhook_sends_webhook_delete_mutation(): void
    {
        Saloon::fake([
            'https://api.linear.app/graphql' => MockResponse::make([
                'data' => ['webhookDelete' => ['success' => true]],
            ]),
        ]);

        $this->tracker->unregisterWebhook('', '', 'webhook-uuid-42');

        Saloon::assertSent(function (Request $request): bool {
            $body = $request->body()->all();

            return str_contains((string) ($body['query'] ?? ''), 'webhookDelete')
                && ($body['variables']['id'] ?? '') === 'webhook-uuid-42';
        });
    }

    // ── verifySignature ──────────────────────────────────────────────────────

    public function test_verify_signature_accepts_valid_hmac_hex(): void
    {
        $payload = '{"type":"Issue","action":"create"}';
        $secret = 'my-webhook-secret';
        $sig = hash_hmac('sha256', $payload, $secret);

        $this->assertTrue($this->tracker->verifySignature($payload, $sig, $secret));
    }

    public function test_verify_signature_rejects_tampered_payload(): void
    {
        $secret = 'my-webhook-secret';
        $sig = hash_hmac('sha256', '{"type":"Issue"}', $secret);

        $this->assertFalse($this->tracker->verifySignature('tampered', $sig, $secret));
    }

    public function test_verify_signature_rejects_wrong_secret(): void
    {
        $payload = '{"type":"Issue"}';
        $sig = hash_hmac('sha256', $payload, 'correct-secret');

        $this->assertFalse($this->tracker->verifySignature($payload, $sig, 'wrong-secret'));
    }

    public function test_verify_signature_rejects_github_style_prefixed_signature(): void
    {
        $payload = '{"type":"Issue"}';
        $secret = 'my-secret';
        // Linear does NOT use the sha256= prefix
        $prefixedSig = 'sha256='.hash_hmac('sha256', $payload, $secret);

        $this->assertFalse($this->tracker->verifySignature($payload, $prefixedSig, $secret));
    }

    // ── normalizeWebhookPayload ──────────────────────────────────────────────

    public function test_normalize_returns_canonical_issue_for_issue_type(): void
    {
        $envelope = [
            'type' => 'Issue',
            'action' => 'create',
            'organizationId' => 'org-uuid',
            'data' => [
                'id' => 'issue-uuid-7',
                'title' => 'Bug report',
                'description' => 'Steps to reproduce.',
                'url' => 'https://linear.app/eng/issue/ENG-7',
                'state' => ['name' => 'Todo'],
                'labels' => [['id' => 'lbl-1', 'name' => 'bug']],
            ],
        ];

        $result = $this->tracker->normalizeWebhookPayload($envelope, null);

        $this->assertSame('issue-uuid-7', $result['id']);
        $this->assertSame('Bug report', $result['title']);
        $this->assertSame('Steps to reproduce.', $result['body']);
        $this->assertSame('https://linear.app/eng/issue/ENG-7', $result['html_url']);
        $this->assertSame('Todo', $result['state']);
        $this->assertSame([['name' => 'bug']], $result['labels']);
    }

    public function test_normalize_returns_empty_for_comment_type(): void
    {
        $envelope = [
            'type' => 'Comment',
            'action' => 'create',
            'data' => ['id' => 'comment-uuid-1', 'body' => 'Thanks'],
        ];

        $this->assertSame([], $this->tracker->normalizeWebhookPayload($envelope, null));
    }

    public function test_normalize_returns_empty_for_project_type(): void
    {
        $envelope = [
            'type' => 'Project',
            'action' => 'create',
            'data' => ['id' => 'project-uuid-1', 'name' => 'New Project'],
        ];

        $this->assertSame([], $this->tracker->normalizeWebhookPayload($envelope, null));
    }

    public function test_normalize_returns_empty_when_data_is_empty(): void
    {
        $envelope = ['type' => 'Issue', 'action' => 'create', 'data' => []];

        $this->assertSame([], $this->tracker->normalizeWebhookPayload($envelope, null));
    }

    public function test_normalize_handles_missing_labels(): void
    {
        $envelope = [
            'type' => 'Issue',
            'action' => 'create',
            'data' => [
                'id' => 'issue-uuid-8',
                'title' => 'No labels',
                'description' => '',
                'url' => 'https://linear.app/eng/issue/ENG-8',
                'state' => ['name' => 'Backlog'],
            ],
        ];

        $result = $this->tracker->normalizeWebhookPayload($envelope, null);

        $this->assertSame([], $result['labels']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function teamResponse(): array
    {
        return [
            'data' => [
                'teams' => [
                    'nodes' => [
                        ['id' => self::TEAM_ID, 'key' => self::TEAM_KEY],
                    ],
                ],
            ],
        ];
    }
}
