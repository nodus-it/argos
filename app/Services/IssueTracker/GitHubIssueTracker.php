<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GitHubIssueTracker implements IssueTrackerContract
{
    private const BASE_URL = 'https://api.github.com';

    private const API_VERSION = '2022-11-28';

    public function __construct(private readonly string $token) {}

    public function listReferences(): array
    {
        $response = $this->http()->get('/user/repos', [
            'per_page' => 100,
            'sort' => 'updated',
            'affiliation' => 'owner,collaborator,organization_member',
        ])->throw();

        $refs = [];
        foreach ($response->json() as $repo) {
            $fullName = (string) ($repo['full_name'] ?? '');
            if ($fullName !== '') {
                $refs[$fullName] = $fullName;
            }
        }

        return $refs;
    }

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        // Only forward `state` to the API. Labels are filtered locally by
        // IssueIngestService (OR semantics); GitHub's `labels` param is AND-only
        // and must be a comma string — passing the filter array as `labels[]`
        // made the endpoint return 422.
        $state = isset($filters['state']) && is_string($filters['state']) && $filters['state'] !== ''
            ? $filters['state']
            : 'open';

        $issues = [];
        $url = "/repos/{$owner}/{$project}/issues";
        $params = ['per_page' => 100, 'state' => $state];

        // Paginate via Link header
        do {
            $response = $this->http()->get($url, $params)->throw();
            $page = $response->json();

            foreach ($page as $item) {
                // GitHub returns PRs mixed into the issues endpoint; filter them out
                if (isset($item['pull_request'])) {
                    continue;
                }
                $issues[] = $item;
            }

            $url = $this->nextPageUrl($response);
            $params = [];
        } while ($url !== null);

        return $issues;
    }

    public function getIssue(string $owner, string $project, int|string $issueNumber): array
    {
        $base = "/repos/{$owner}/{$project}/issues/{$issueNumber}";

        $issue = $this->http()->get($base)->throw()->json();
        $comments = $this->http()->get("{$base}/comments")->throw()->json();
        $reactions = $this->http()
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("{$base}/reactions")
            ->throw()
            ->json();

        return [
            ...$issue,
            'comments_data' => $comments,
            'reactions_data' => $reactions,
        ];
    }

    public function createComment(
        string $owner,
        string $project,
        int|string $issueNumber,
        string $body,
    ): array {
        return $this->http()
            ->post("/repos/{$owner}/{$project}/issues/{$issueNumber}/comments", ['body' => $body])
            ->throw()
            ->json();
    }

    public function commentId(array $createResult): ?string
    {
        $id = $createResult['id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function getCommentReactions(string $owner, string $project, int|string $issueId, int|string $commentId): array
    {
        $reactions = $this->http()
            ->get("/repos/{$owner}/{$project}/issues/comments/{$commentId}/reactions", ['per_page' => 100])
            ->throw()
            ->json();

        $out = [];
        foreach ($reactions as $reaction) {
            $out[] = [
                'emoji' => (string) ($reaction['content'] ?? ''),
                'user_id' => (string) ($reaction['user']['id'] ?? ''),
                'user_login' => (string) ($reaction['user']['login'] ?? ''),
            ];
        }

        return $out;
    }

    public function userCanApprove(string $owner, string $project, array $reactor): bool
    {
        $login = $reactor['user_login'];
        if ($login === '') {
            return false;
        }

        try {
            $data = $this->http()
                ->get("/repos/{$owner}/{$project}/collaborators/{$login}/permission")
                ->throw()
                ->json();
        } catch (\Throwable) {
            return false;
        }

        // GitHub returns "admin" | "write" | "maintain" | "triage" | "read" | "none".
        return in_array((string) ($data['permission'] ?? ''), ['admin', 'write', 'maintain'], true);
    }

    /**
     * GitHub signs payloads with HMAC-SHA256 and sends
     * "sha256=<hex>" in the X-Hub-Signature-256 header.
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    public function registerWebhook(string $owner, string $project, string $url, string $secret): array
    {
        return $this->http()
            ->post("/repos/{$owner}/{$project}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => ['issues', 'issue_comment'],
                'config' => [
                    'url' => $url,
                    'secret' => $secret,
                    'content_type' => 'json',
                    'insecure_ssl' => '0',
                ],
            ])
            ->throw()
            ->json();
    }

    public function unregisterWebhook(string $owner, string $project, int|string $webhookId): void
    {
        $this->http()
            ->delete("/repos/{$owner}/{$project}/hooks/{$webhookId}")
            ->throw();
    }

    /**
     * Extract the inner issue payload from a GitHub webhook envelope.
     *
     * GitHub sends: {action, issue:{…}, repository, sender}
     * Only 'issues' events are ingested; 'issue_comment' is ignored.
     * Returns [] for envelopes without an 'issue' key.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $envelope, ?string $eventType): array
    {
        // Only process 'issues' events
        if ($eventType !== 'issues') {
            return [];
        }

        $issue = $envelope['issue'] ?? null;

        if (! is_array($issue) || empty($issue)) {
            return [];
        }

        // Skip if the issue is actually a PR (has a pull_request key)
        if (isset($issue['pull_request'])) {
            return [];
        }

        return $issue;
    }

    /**
     * Parse the next page URL from a GitHub Link header.
     * Returns null when there are no more pages.
     */
    private function nextPageUrl(Response $response): ?string
    {
        $link = $response->header('Link');
        if ($link === null || $link === '') {
            return null;
        }

        // Link: <https://api.github.com/repos/…/issues?page=2>; rel="next", …
        if (preg_match('/<([^>]+)>;\s*rel="next"/', $link, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
        ])->baseUrl(self::BASE_URL);
    }
}
