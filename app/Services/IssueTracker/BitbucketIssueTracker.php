<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class BitbucketIssueTracker implements IssueTrackerContract
{
    private const BASE_URL = 'https://api.bitbucket.org/2.0';

    private readonly string $username;

    private readonly string $appPassword;

    private readonly bool $isOAuth;

    public function __construct(private readonly string $token)
    {
        if (str_contains($token, ':')) {
            [$this->username, $this->appPassword] = explode(':', $token, 2);
            $this->isOAuth = false;
        } else {
            $this->username = '';
            $this->appPassword = '';
            $this->isOAuth = true;
        }
    }

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        // Bitbucket returns 403 when the issue tracker is disabled — treat as empty.
        $response = $this->http()
            ->get("/repositories/{$owner}/{$project}/issues", ['pagelen' => 100, ...$filters]);

        if ($response->status() === 403 || $response->status() === 404) {
            return [];
        }

        $response->throw();

        return $response->json('values', []);
    }

    public function getIssue(string $owner, string $project, int $issueNumber): array
    {
        $base = "/repositories/{$owner}/{$project}/issues/{$issueNumber}";

        $issue = $this->http()->get($base)->throw()->json();
        $comments = $this->http()->get("{$base}/comments", ['pagelen' => 100])->throw()->json();

        return [
            ...$issue,
            'comments_data' => $comments['values'] ?? [],
            'reactions_data' => [],
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
            ->post("/repositories/{$owner}/{$project}/issues", [
                'title' => $title,
                'content' => ['raw' => $body],
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
            ->post("/repositories/{$owner}/{$project}/issues/{$issueNumber}/comments", [
                'content' => ['raw' => $body],
            ])
            ->throw()
            ->json();
    }

    private function http(): PendingRequest
    {
        if ($this->isOAuth) {
            return Http::withHeaders([
                'Authorization' => "Bearer {$this->token}",
                'Accept' => 'application/json',
            ])->baseUrl(self::BASE_URL);
        }

        return Http::withBasicAuth($this->username, $this->appPassword)
            ->withHeaders(['Accept' => 'application/json'])
            ->baseUrl(self::BASE_URL);
    }
}
