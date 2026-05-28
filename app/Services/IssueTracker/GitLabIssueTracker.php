<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GitLabIssueTracker implements IssueTrackerContract
{
    public function __construct(
        private readonly string $token,
        private readonly string $instanceUrl = 'https://gitlab.com',
    ) {}

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        $projectPath = $this->encodePath($owner, $project);

        return $this->http()
            ->get("/projects/{$projectPath}/issues", ['per_page' => 100, ...$filters])
            ->throw()
            ->json();
    }

    public function getIssue(string $owner, string $project, int|string $issueNumber): array
    {
        $projectPath = $this->encodePath($owner, $project);
        $base = "/projects/{$projectPath}/issues/{$issueNumber}";

        $issue = $this->http()->get($base)->throw()->json();
        $notes = $this->http()->get("{$base}/notes", ['per_page' => 100])->throw()->json();

        // Award emojis require a GitLab plan that supports them — treat 404/403 as empty.
        $awardEmojis = rescue(
            fn () => $this->http()->get("{$base}/award_emoji", ['per_page' => 100])->throw()->json(),
            [],
        );

        return [
            ...$issue,
            'comments_data' => $notes,
            'reactions_data' => $awardEmojis,
        ];
    }

    public function createComment(
        string $owner,
        string $project,
        int|string $issueNumber,
        string $body,
    ): array {
        $projectPath = $this->encodePath($owner, $project);

        return $this->http()
            ->post("/projects/{$projectPath}/issues/{$issueNumber}/notes", ['body' => $body])
            ->throw()
            ->json();
    }

    /**
     * GitLab sends a plain token in the X-Gitlab-Token header.
     * The $signature parameter carries the header value directly.
     */
    public function verifySignature(string $payload, string $signature, string $secret): bool
    {
        return hash_equals($secret, $signature);
    }

    public function registerWebhook(string $owner, string $project, string $url, string $secret): array
    {
        throw new \LogicException('registerWebhook not implemented yet for GitLab');
    }

    public function unregisterWebhook(string $owner, string $project, int|string $webhookId): void
    {
        throw new \LogicException('unregisterWebhook not implemented yet for GitLab');
    }

    /**
     * GitLab sends issue data directly in the envelope — no unwrapping needed.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $envelope, ?string $eventType): array
    {
        return $envelope;
    }

    private function encodePath(string $owner, string $project): string
    {
        return urlencode("{$owner}/{$project}");
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Content-Type' => 'application/json',
        ])->baseUrl("{$this->instanceUrl}/api/v4");
    }
}
