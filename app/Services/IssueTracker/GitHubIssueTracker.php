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

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        // Default state to 'open' when no state filter is provided
        if (! isset($filters['state']) || $filters['state'] === '') {
            $filters['state'] = 'open';
        }

        $issues = [];
        $url = "/repos/{$owner}/{$project}/issues";
        $params = ['per_page' => 100, ...$filters];

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

    public function getIssue(string $owner, string $project, int $issueNumber): array
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
        int $issueNumber,
        string $body,
    ): array {
        return $this->http()
            ->post("/repos/{$owner}/{$project}/issues/{$issueNumber}/comments", ['body' => $body])
            ->throw()
            ->json();
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
