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

    public function listReferences(): array
    {
        $response = $this->http()->get('/repositories', [
            'role' => 'member',
            'pagelen' => 100,
            'sort' => '-updated_on',
        ]);

        if ($response->status() === 403 || $response->status() === 404) {
            return [];
        }

        $response->throw();

        $refs = [];
        foreach ($response->json('values', []) as $repo) {
            $fullName = (string) ($repo['full_name'] ?? '');
            if ($fullName !== '') {
                $refs[$fullName] = $fullName;
            }
        }

        return $refs;
    }

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        // Bitbucket returns 403 when the issue tracker is disabled — treat as empty.
        // Labels are filtered locally (OR) by IssueIngestService; Bitbucket
        // filters via a `q` BBQL string, so the raw filter array is not forwarded.
        $response = $this->http()
            ->get("/repositories/{$owner}/{$project}/issues", ['pagelen' => 100]);

        if ($response->status() === 403 || $response->status() === 404) {
            return [];
        }

        $response->throw();

        return $response->json('values', []);
    }

    public function getIssue(string $owner, string $project, int|string $issueNumber): array
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

    public function createComment(
        string $owner,
        string $project,
        int|string $issueNumber,
        string $body,
    ): array {
        return $this->http()
            ->post("/repositories/{$owner}/{$project}/issues/{$issueNumber}/comments", [
                'content' => ['raw' => $body],
            ])
            ->throw()
            ->json();
    }

    // Bitbucket is not wired as a task issue-provider (no TaskProviderKind),
    // so the 👍-approval flow does not apply — these satisfy the contract.

    public function commentId(array $createResult): ?string
    {
        $id = $createResult['id'] ?? null;

        return $id !== null ? (string) $id : null;
    }

    public function getCommentReactions(string $owner, string $project, int|string $issueId, int|string $commentId): array
    {
        return [];
    }

    public function userCanApprove(string $owner, string $project, array $reactor): bool
    {
        return false;
    }

    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        throw new \LogicException('verifySignature not implemented yet for Bitbucket');
    }

    public function registerWebhook(string $owner, string $project, string $url, string $secret): array
    {
        throw new \LogicException('registerWebhook not implemented yet for Bitbucket');
    }

    public function unregisterWebhook(string $owner, string $project, int|string $webhookId): void
    {
        throw new \LogicException('unregisterWebhook not implemented yet for Bitbucket');
    }

    /**
     * Bitbucket sends issue data directly in the envelope — no unwrapping needed.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $envelope, ?string $eventType): array
    {
        return $envelope;
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
