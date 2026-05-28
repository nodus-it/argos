<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IssueTracker;

use App\Services\IssueTracker\GitLabIssueTracker;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitLabIssueTrackerTest extends TestCase
{
    public function test_list_references_maps_path_with_namespace_to_ref_options(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects*' => Http::response([
                ['path_with_namespace' => 'acme/widget'],
                ['path_with_namespace' => 'acme/sub/gadget'],
            ]),
        ]);

        $refs = (new GitLabIssueTracker('glpat-tok'))->listReferences();

        $this->assertSame([
            'acme/widget' => 'acme/widget',
            'acme/sub/gadget' => 'acme/sub/gadget',
        ], $refs);

        Http::assertSent(function ($request): bool {
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?? '', $query);

            return $request->hasHeader('Authorization', 'Bearer glpat-tok')
                && str_starts_with((string) $request->url(), 'https://gitlab.com/api/v4/projects')
                && ($query['membership'] ?? '') === '1';
        });
    }

    public function test_list_references_uses_self_hosted_instance_url(): void
    {
        Http::fake([
            'https://gitlab.example.com/api/v4/projects*' => Http::response([
                ['path_with_namespace' => 'team/repo'],
            ]),
        ]);

        $refs = (new GitLabIssueTracker('tok', 'https://gitlab.example.com'))->listReferences();

        $this->assertSame(['team/repo' => 'team/repo'], $refs);
    }

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

    public function test_list_issues_defaults_state_to_opened(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => Http::response([]),
        ]);

        (new GitLabIssueTracker('tok'))->listIssues('acme', 'widget');

        Http::assertSent(function ($request): bool {
            parse_str(parse_url((string) $request->url(), PHP_URL_QUERY) ?? '', $query);

            return ($query['state'] ?? '') === 'opened';
        });
    }

    public function test_list_issues_paginates_via_x_next_page_header(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/issues*' => Http::sequence()
                ->push(
                    [['id' => 1, 'title' => 'Issue 1']],
                    200,
                    ['X-Next-Page' => '2'],
                )
                ->push(
                    [['id' => 2, 'title' => 'Issue 2']],
                    200,
                ),
        ]);

        $issues = (new GitLabIssueTracker('tok'))->listIssues('acme', 'widget');

        $this->assertCount(2, $issues);
        $this->assertSame(1, $issues[0]['id']);
        $this->assertSame(2, $issues[1]['id']);
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

    // ── registerWebhook ──────────────────────────────────────────────────────

    public function test_register_webhook_posts_to_gitlab_hooks_endpoint(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/hooks' => Http::response([
                'id' => 55,
                'url' => 'https://example.com/webhooks/issues/gitlab/binding-id',
            ], 201),
        ]);

        $result = (new GitLabIssueTracker('tok'))->registerWebhook(
            'acme', 'widget',
            'https://example.com/webhooks/issues/gitlab/binding-id',
            'my-secret',
        );

        $this->assertSame(55, $result['id']);

        Http::assertSent(function ($request): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://gitlab.com/api/v4/projects/acme%2Fwidget/hooks'
                && $request->method() === 'POST'
                && $body['url'] === 'https://example.com/webhooks/issues/gitlab/binding-id'
                && $body['token'] === 'my-secret'
                && $body['issues_events'] === true
                && $body['confidential_issues_events'] === true
                && $body['note_events'] === false
                && $body['enable_ssl_verification'] === true;
        });
    }

    public function test_register_webhook_sends_bearer_auth(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/hooks' => Http::response(['id' => 1], 201),
        ]);

        (new GitLabIssueTracker('glpat-tok'))->registerWebhook('acme', 'widget', 'https://example.com', 'secret');

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer glpat-tok'));
    }

    // ── unregisterWebhook ────────────────────────────────────────────────────

    public function test_unregister_webhook_sends_delete_request(): void
    {
        Http::fake([
            'https://gitlab.com/api/v4/projects/acme%2Fwidget/hooks/55' => Http::response(null, 204),
        ]);

        (new GitLabIssueTracker('tok'))->unregisterWebhook('acme', 'widget', 55);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://gitlab.com/api/v4/projects/acme%2Fwidget/hooks/55'
                && $request->method() === 'DELETE';
        });
    }

    // ── normalizeWebhookPayload ──────────────────────────────────────────────

    public function test_normalize_webhook_payload_extracts_issue_from_object_attributes(): void
    {
        $envelope = [
            'object_kind' => 'issue',
            'object_attributes' => [
                'id' => 301,
                'iid' => 1,
                'title' => 'Bug report',
                'state' => 'opened',
                'description' => 'Steps to reproduce…',
            ],
            'labels' => [],
        ];

        $result = (new GitLabIssueTracker('tok'))->normalizeWebhookPayload($envelope, null);

        $this->assertSame(301, $result['id']);
        $this->assertSame('Bug report', $result['title']);
        $this->assertArrayNotHasKey('object_kind', $result);
        $this->assertArrayNotHasKey('object_attributes', $result);
    }

    public function test_normalize_webhook_payload_merges_top_level_labels(): void
    {
        $envelope = [
            'object_kind' => 'issue',
            'object_attributes' => [
                'id' => 301,
                'title' => 'Labelled issue',
                'state' => 'opened',
            ],
            'labels' => [
                ['id' => 1, 'title' => 'bug', 'color' => '#FF0000'],
                ['id' => 2, 'title' => 'critical', 'color' => '#CC0000'],
            ],
        ];

        $result = (new GitLabIssueTracker('tok'))->normalizeWebhookPayload($envelope, null);

        $this->assertSame(['bug', 'critical'], $result['labels']);
    }

    public function test_normalize_webhook_payload_returns_empty_for_note_event(): void
    {
        $envelope = [
            'object_kind' => 'note',
            'object_attributes' => ['id' => 5, 'note' => 'A comment'],
        ];

        $result = (new GitLabIssueTracker('tok'))->normalizeWebhookPayload($envelope, null);

        $this->assertSame([], $result);
    }

    public function test_normalize_webhook_payload_returns_empty_for_merge_request_event(): void
    {
        $envelope = [
            'object_kind' => 'merge_request',
            'object_attributes' => ['id' => 10, 'title' => 'My MR'],
        ];

        $result = (new GitLabIssueTracker('tok'))->normalizeWebhookPayload($envelope, null);

        $this->assertSame([], $result);
    }

    public function test_normalize_webhook_payload_returns_empty_for_push_event(): void
    {
        $envelope = [
            'object_kind' => 'push',
            'commits' => [],
        ];

        $result = (new GitLabIssueTracker('tok'))->normalizeWebhookPayload($envelope, null);

        $this->assertSame([], $result);
    }

    public function test_normalize_webhook_payload_returns_empty_when_object_attributes_missing(): void
    {
        $envelope = ['object_kind' => 'issue'];

        $result = (new GitLabIssueTracker('tok'))->normalizeWebhookPayload($envelope, null);

        $this->assertSame([], $result);
    }
}
