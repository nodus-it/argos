<?php

declare(strict_types=1);

namespace App\Services\IssueTracker;

use App\Services\IssueTracker\Contracts\IssueTrackerContract;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GitLabIssueTracker implements IssueTrackerContract
{
    public function __construct(
        private readonly string $token,
        private readonly string $instanceUrl = 'https://gitlab.com',
    ) {}

    public function listReferences(): array
    {
        $response = $this->http()->get('/projects', [
            'membership' => true,
            'simple' => true,
            'per_page' => 100,
            'order_by' => 'last_activity_at',
        ])->throw();

        $refs = [];
        foreach ($response->json() as $project) {
            $path = (string) ($project['path_with_namespace'] ?? '');
            if ($path !== '') {
                $refs[$path] = $path;
            }
        }

        return $refs;
    }

    public function listIssues(string $owner, string $project, array $filters = []): array
    {
        if (! isset($filters['state']) || $filters['state'] === '') {
            $filters['state'] = 'opened';
        }

        $projectPath = $this->encodePath($owner, $project);
        $issues = [];
        $params = ['per_page' => 100, ...$filters];

        do {
            $response = $this->http()
                ->get("/projects/{$projectPath}/issues", $params)
                ->throw();

            $issues = array_merge($issues, $response->json());

            $nextPage = $this->nextPageNumber($response);
            if ($nextPage !== null) {
                $params = ['per_page' => 100, 'page' => $nextPage, ...$filters];
            }
        } while ($nextPage !== null);

        return $issues;
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
        $projectPath = $this->encodePath($owner, $project);

        return $this->http()
            ->post("/projects/{$projectPath}/hooks", [
                'url' => $url,
                'token' => $secret,
                'issues_events' => true,
                'confidential_issues_events' => true,
                'note_events' => false,
                'enable_ssl_verification' => true,
            ])
            ->throw()
            ->json();
    }

    public function unregisterWebhook(string $owner, string $project, int|string $webhookId): void
    {
        $projectPath = $this->encodePath($owner, $project);

        $this->http()
            ->delete("/projects/{$projectPath}/hooks/{$webhookId}")
            ->throw();
    }

    /**
     * GitLab sends issue data in object_attributes — extract it for issue events only.
     * Top-level labels (objects with 'title') are merged in as strings.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public function normalizeWebhookPayload(array $envelope, ?string $eventType): array
    {
        if (($envelope['object_kind'] ?? null) !== 'issue') {
            return [];
        }

        $issue = $envelope['object_attributes'] ?? null;

        if (! is_array($issue) || empty($issue)) {
            return [];
        }

        if (isset($envelope['labels']) && is_array($envelope['labels'])) {
            $issue['labels'] = array_map(
                fn (mixed $l): string => is_array($l) ? (string) ($l['title'] ?? '') : (string) $l,
                $envelope['labels'],
            );
        }

        return $issue;
    }

    private function nextPageNumber(Response $response): ?int
    {
        $header = $response->header('X-Next-Page');
        if ($header === null || $header === '') {
            return null;
        }

        return (int) $header;
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
