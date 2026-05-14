<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitHubIssueTracker implements IssueTrackerContract
{
    private const BASE_URL = 'https://api.github.com';

    private const API_VERSION = '2022-11-28';

    public function __construct(private readonly string $token) {}

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        return $this->http()
            ->get("/repos/{$owner}/{$project}/issues", ['per_page' => 100, ...$filters])
            ->throw()
            ->json();
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

    public function createIssue(
        string $owner,
        string $project,
        string $title,
        string $body,
        array $options = [],
    ): array {
        return $this->http()
            ->post("/repos/{$owner}/{$project}/issues", [
                'title' => $title,
                'body' => $body,
                ...$options,
            ])
            ->throw()
            ->json();
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
        throw new \LogicException('registerWebhook not implemented yet for GitHub');
    }

    public function unregisterWebhook(string $owner, string $project, int|string $webhookId): void
    {
        throw new \LogicException('unregisterWebhook not implemented yet for GitHub');
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
